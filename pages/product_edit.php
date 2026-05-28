<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session_token_auth.php';
require_once __DIR__ . '/../app_config.php';

session_start();

$shop = isset($_GET['shop']) ? $_GET['shop'] : (isset($_SESSION['shop']) ? $_SESSION['shop'] : '');
$product_id = isset($_GET['product_id']) ? $_GET['product_id'] : '';

if (empty($shop)) {
    die('Shop parameter is missing.');
}

if (empty($product_id)) {
    die('Product ID is missing.');
}

$pdo = getDatabaseConnection();
$sessionToken = get_bearer_token_php53();
if (!$sessionToken && isset($_GET['id_token'])) {
    $sessionToken = $_GET['id_token'];
}
$tokenResult = get_valid_shop_access_token_php53(
    $pdo,
    'shops',
    $shop,
    $api_key,
    $api_secret,
    $sessionToken,
    false
);

if (!$tokenResult['success']) {
    $error_message = $tokenResult['error'];
    $access_token = null;
} else {
    $access_token = $tokenResult['access_token'];
    $token_source = $tokenResult['source'];
}

include __DIR__ . '/../includes/navigation.php';
$api_version = '2026-04';

$product_updated = false;
$error_message = '';
$locations = array();
$product_data = null;
$variants_data = array();
$images_data = array();

function executeGraphQLMutation($access_token, $shop, $query, $variables = null)
{
    global $api_version;
    $url = "https://" . $shop . "/admin/api/" . $api_version . "/graphql.json";

    $payload = array('query' => $query);
    if ($variables) {
        $payload['variables'] = $variables;
    }

    $data = json_encode($payload);
    $headers = array(
        "Content-Type: application/json",
        "X-Shopify-Access-Token: " . $access_token
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        return array('success' => false, 'error' => "HTTP Error: " . $http_code);
    }

    $response = json_decode($result, true);
    if (isset($response['errors'])) {
        return array('success' => false, 'error' => $response['errors']);
    }

    return array('success' => true, 'data' => $response);
}

// Fetch locations for inventory management
if ($access_token) {
    $locationQuery = <<<GRAPHQL
query {
  locations(first: 10) {
    edges {
      node {
        id
        name
        isActive
      }
    }
  }
}
GRAPHQL;

    $locationResult = executeGraphQLMutation($access_token, $shop, $locationQuery);
    if ($locationResult['success'] && isset($locationResult['data']['data']['locations']['edges'])) {
        $locations = $locationResult['data']['data']['locations']['edges'];
    }
}

// Fetch product data for editing
if ($access_token && !$_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_product_id = 'gid://shopify/Product/' . $product_id;
    
    $getProductQuery = <<<GRAPHQL
query getProduct {
  node(id: "$full_product_id") {
    ... on Product {
      id
      title
      descriptionHtml
      variants(first: 10) {
        edges {
          node {
            id
            price
            sku
            inventoryQuantity
            inventoryItem {
              id
            }
          }
        }
      }
      images(first: 1) {
        edges {
          node {
            originalSrc
          }
        }
      }
    }
  }
}
GRAPHQL;

    $url = "https://" . $shop . "/admin/api/" . $api_version . "/graphql.json";
    $fields = json_encode(array('query' => $getProductQuery));
    $headers = array(
        "Content-Type: application/json",
        "X-Shopify-Access-Token: " . $access_token
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $data = json_decode($result, true);
        if (isset($data['data']['node'])) {
            $product_data = $data['data']['node'];
            if (isset($product_data['variants']['edges'])) {
                foreach ($product_data['variants']['edges'] as $variant) {
                    $variants_data[] = $variant['node'];
                }
            }
            if (isset($product_data['images']['edges']) && !empty($product_data['images']['edges'])) {
                $images_data = $product_data['images']['edges'];
            }
        }
    }
}

// Handle form submission for updating product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product']) && $access_token) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $price = trim($_POST['price']);
    $sku = trim($_POST['sku']);
    $inventory_quantity = (int) $_POST['inventory_quantity'];
    $location_id = trim($_POST['location_id']);
    $variant_id = trim($_POST['variant_id']);
    $inventory_item_id = trim($_POST['inventory_item_id']);

    // Step 1: Update product title and description
    $updateProductMutation = <<<GRAPHQL
mutation productUpdate(\$input: ProductInput!) {
  productUpdate(input: \$input) {
    product {
      id
      title
      descriptionHtml
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    $updateProductVariables = array(
        'input' => array(
            'id' => 'gid://shopify/Product/' . $product_id,
            'title' => $title,
            'descriptionHtml' => $description
        )
    );

    $updateProductResult = executeGraphQLMutation($access_token, $shop, $updateProductMutation, $updateProductVariables);

    if (!$updateProductResult['success']) {
        $error_message = "Failed to update product: " . json_encode($updateProductResult['error']);
    } elseif (isset($updateProductResult['data']['data']['productUpdate']['userErrors']) && !empty($updateProductResult['data']['data']['productUpdate']['userErrors'])) {
        $errors = $updateProductResult['data']['data']['productUpdate']['userErrors'];
        $error_message = "Product update failed: " . $errors[0]['message'];
    } else {
        // Step 2: Update variant
        $updateVariantMutation = <<<GRAPHQL
mutation productVariantsBulkUpdate(
  \$productId: ID!,
  \$variants: [ProductVariantsBulkInput!]!
) {
  productVariantsBulkUpdate(
    productId: \$productId,
    variants: \$variants
  ) {
    productVariants {
      id
      price
      sku
      inventoryItem {
        id
      }
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $variantData = array(
            'id' => $variant_id,
            'price' => $price
        );

        if (!empty($sku)) {
            $variantData['inventoryItem'] = array(
                'sku' => $sku
            );
        }

        $updateVariantVariables = array(
            'productId' => 'gid://shopify/Product/' . $product_id,
            'variants' => array($variantData)
        );

        $updateVariantResult = executeGraphQLMutation($access_token, $shop, $updateVariantMutation, $updateVariantVariables);

        if (!$updateVariantResult['success']) {
            $error_message = "Product updated but variant update failed: " . json_encode($updateVariantResult['error']);
        } elseif (isset($updateVariantResult['data']['data']['productVariantsBulkUpdate']['userErrors']) && !empty($updateVariantResult['data']['data']['productVariantsBulkUpdate']['userErrors'])) {
            $errors = $updateVariantResult['data']['data']['productVariantsBulkUpdate']['userErrors'];
            $error_message = "Product updated but variant update failed: " . $errors[0]['message'];
        } else {
            // Step 3: Update inventory if provided
            if ($inventory_quantity > 0 && !empty($location_id) && !empty($inventory_item_id)) {
                $idempotency_key = uniqid('inventory_', true);

                $inventoryMutation = <<<GRAPHQL
mutation inventorySetQuantities(\$input: InventorySetQuantitiesInput!, \$idempotencyKey: String!) {
  inventorySetQuantities(input: \$input) @idempotent(key: \$idempotencyKey) {
    inventoryAdjustmentGroup {
      id
      reason
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

                $inventoryVariables = array(
                    'input' => array(
                        'reason' => 'correction',
                        'name' => 'available',
                        'quantities' => array(
                            array(
                                'inventoryItemId' => $inventory_item_id,
                                'locationId' => $location_id,
                                'quantity' => $inventory_quantity,
                                'changeFromQuantity' => null
                            )
                        )
                    ),
                    'idempotencyKey' => $idempotency_key
                );

                $inventoryResult = executeGraphQLMutation($access_token, $shop, $inventoryMutation, $inventoryVariables);
            }
            $product_updated = true;
        }
    }

    if ($product_updated) {
        echo json_encode(array('success' => true, 'message' => 'Product updated successfully!', 'redirect' => 'products.php?shop=' . urlencode($shop)));
        exit;
    } else {
        echo json_encode(array('success' => false, 'message' => $error_message));
        exit;
    }
}

// Get first variant data for the form
$default_variant = !empty($variants_data) ? $variants_data[0] : null;
$current_image = !empty($images_data) ? $images_data[0]['node']['originalSrc'] : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - Shopify App</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/product_create.css">
    <style>
        .image-upload-container {
            margin-top: 5px;
        }
        .image-preview-wrapper {
            margin-bottom: 15px;
            position: relative;
            display: inline-block;
        }
        .image-preview {
            position: relative;
            display: inline-block;
        }
        .image-preview img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            border: 1px solid #e1e3e5;
            padding: 4px;
            background: #fafafb;
        }
        .remove-image-btn {
            position: absolute;
            top: -10px;
            right: -10px;
            width: 28px;
            height: 28px;
            background: #d82c0d;
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .remove-image-btn:hover {
            background: #b52306;
            transform: scale(1.1);
        }
        .btn-upload {
            background: #f1f2f4;
            border: 1px dashed #c9cccf;
            padding: 12px 24px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #202223;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-upload:hover {
            background: #e6e8eb;
            border-color: #008060;
        }
    </style>
</head>
<body>
    <?php renderNavigation($app_url, $shop); ?>
    <div class="content">
        <div class="form-container">
            <div class="form-header">
                <h1>Edit Product</h1>
                <p>Update your product information</p>
            </div>
            <form id="editProductForm" method="POST" enctype="multipart/form-data">
                <div class="form-body">
                    <div class="form-group">
                        <label for="title">Product title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" class="form-control" required
                            placeholder="e.g., Classic T-Shirt" value="<?php echo htmlspecialchars($product_data ? $product_data['title'] : ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control"
                            placeholder="Describe your product..."><?php echo htmlspecialchars($product_data ? $product_data['descriptionHtml'] : ''); ?></textarea>
                        <div class="info-text">Supports HTML formatting</div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="price">Price <span class="required">*</span></label>
                            <input type="number" id="price" name="price" step="0.01" class="form-control" required
                                placeholder="0.00" value="<?php echo htmlspecialchars($default_variant ? $default_variant['price'] : ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="sku">SKU</label>
                            <input type="text" id="sku" name="sku" class="form-control" 
                                placeholder="SKU-001" value="<?php echo htmlspecialchars($default_variant ? $default_variant['sku'] : ''); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="inventory_quantity">Inventory quantity</label>
                            <input type="number" id="inventory_quantity" name="inventory_quantity" class="form-control"
                                value="<?php echo htmlspecialchars($default_variant ? $default_variant['inventoryQuantity'] : '0'); ?>" min="0">
                            <div class="info-text">Set to 0 if you don't want to track inventory</div>
                        </div>
                        <div class="form-group">
                            <label for="location_id">Location (for inventory)</label>
                            <select id="location_id" name="location_id" class="form-control">
                                <option value="">No location (skip inventory)</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo htmlspecialchars($location['node']['id']); ?>">
                                        <?php echo htmlspecialchars($location['node']['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Hidden fields for variant and inventory item IDs -->
                    <input type="hidden" id="variant_id" name="variant_id" value="<?php echo htmlspecialchars($default_variant ? $default_variant['id'] : ''); ?>">
                    <input type="hidden" id="inventory_item_id" name="inventory_item_id" value="<?php echo htmlspecialchars($default_variant && isset($default_variant['inventoryItem']) ? $default_variant['inventoryItem']['id'] : ''); ?>">
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary"
                        onclick="window.location.href='products.php?shop=<?php echo urlencode($shop); ?>'">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Update Product</button>
                </div>
            </form>
        </div>
    </div>
    <div id="loaderOverlay" class="loader-overlay">
        <div class="loader-content">
            <div class="spinner"></div>
            <div class="loader-text">Updating product. Please wait...</div>
        </div>
    </div>
    <div id="toast" class="toast"></div>
    <script>
        document.getElementById('editProductForm').addEventListener('submit', function (e) {
            e.preventDefault();
            var title = document.getElementById('title').value.trim();
            var price = document.getElementById('price').value.trim();
            var inventoryQuantity = document.getElementById('inventory_quantity').value;
            if (!title) { showToast('Please enter a product title', 'error'); return; }
            if (!price || parseFloat(price) < 0) { showToast('Please enter a valid price', 'error'); return; }
            if (inventoryQuantity === '' || parseInt(inventoryQuantity) < 0) { showToast('Please enter a valid inventory quantity', 'error'); return; }
            showLoader(true);
            var formData = new FormData(this);
            formData.append('update_product', '1');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    showLoader(false);
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                showToast(response.message, 'success');
                                setTimeout(function () { window.location.href = response.redirect; }, 2000);
                            } else { showToast(response.message, 'error'); }
                        } catch (e) { showToast('An error occurred. Please try again.', 'error'); }
                    } else { showToast('Network error. Please try again.', 'error'); }
                }
            };
            xhr.send(formData);
        });
        function showLoader(show) { var loader = document.getElementById('loaderOverlay'); if (show) { loader.classList.add('show'); } else { loader.classList.remove('show'); } }
        function showToast(message, type) { var toast = document.getElementById('toast'); toast.textContent = message; toast.className = 'toast show ' + type; setTimeout(function () { toast.className = 'toast'; }, 3000); }
    </script>
</body>
</html>