<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session_token_auth.php';
require_once __DIR__ . '/../app_config.php';

session_start();

$shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : (isset($_GET['shop']) ? $_GET['shop'] : '');
if (empty($shop)) {
    die('Shop parameter is missing.');
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

$product_created = false;
$error_message = '';
$locations = array();

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

function updateProductVariant($access_token, $shop, $product_id, $variant_id, $price, $sku)
{
    $updateMutation = <<<GRAPHQL
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
        tracked
        sku
      }
      inventoryPolicy
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
        'price' => $price,
        'inventoryPolicy' => 'DENY'
    );

    if (!empty($sku)) {
        $variantData['inventoryItem'] = array(
            'sku' => $sku
        );
    }

    $updateVariables = array(
        'productId' => $product_id,
        'variants' => array($variantData)
    );

    $result = executeGraphQLMutation($access_token, $shop, $updateMutation, $updateVariables);
    return $result;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_product']) && $access_token) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $price = trim($_POST['price']);
    $sku = trim($_POST['sku']);
    $inventory_quantity = (int) $_POST['inventory_quantity'];
    $location_id = trim($_POST['location_id']);

    $productMutation = <<<GRAPHQL
mutation productCreate(\$input: ProductInput!) {
  productCreate(input: \$input) {
    product {
      id
      title
      handle
      descriptionHtml
      createdAt
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    $productVariables = array(
        'input' => array(
            'title' => $title,
            'descriptionHtml' => $description
        )
    );

    $productResult = executeGraphQLMutation($access_token, $shop, $productMutation, $productVariables);

    if (!$productResult['success']) {
        $error_message = "Failed to create product: " . json_encode($productResult['error']);
    } elseif (isset($productResult['data']['data']['productCreate']['userErrors']) && !empty($productResult['data']['data']['productCreate']['userErrors'])) {
        $errors = $productResult['data']['data']['productCreate']['userErrors'];
        $error_message = "Product creation failed: " . $errors[0]['message'];
    } elseif (isset($productResult['data']['data']['productCreate']['product'])) {
        $product = $productResult['data']['data']['productCreate']['product'];
        $product_id = $product['id'];

        error_log("=== PRODUCT CREATED ===");
        error_log("Product ID: " . $product_id);

                $image_uploaded = false;
        $image_url = '';

        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['product_image']['tmp_name'];
            $file_name = $_FILES['product_image']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext = array('jpg', 'jpeg', 'png', 'gif', 'webp');
            
            error_log("=== IMAGE FILE DETECTED ===");
            error_log("File name: " . $file_name);
            error_log("File size: " . $_FILES['product_image']['size']);

            if (in_array($file_ext, $allowed_ext)) {
                // Use REST API directly - most reliable approach
                $rest_url = "https://" . $shop . "/admin/api/" . $api_version . "/products/" . $product_id . "/images.json";
                
                $image_data = file_get_contents($file_tmp);
                $base64_image = base64_encode($image_data);
                
                $post_data = array(
                    'image' => array(
                        'attachment' => $base64_image,
                        'filename' => $file_name
                    )
                );
                
                $rest_headers = array(
                    "Content-Type: application/json",
                    "X-Shopify-Access-Token: " . $access_token
                );
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $rest_url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $rest_headers);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                
                $rest_result = curl_exec($ch);
                $rest_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                error_log("=== REST API IMAGE UPLOAD ===");
                error_log("HTTP Code: " . $rest_http_code);
                error_log("Response: " . $rest_result);
                
                if ($rest_http_code === 200 || $rest_http_code === 201) {
                    $rest_response = json_decode($rest_result, true);
                    if (isset($rest_response['image']['src'])) {
                        $image_uploaded = true;
                        $image_url = $rest_response['image']['src'];
                        error_log("Image uploaded via REST API. URL: " . $image_url);
                    } else {
                        error_log("Image upload returned success but no image URL found");
                    }
                } else {
                    error_log("REST API upload failed with code: " . $rest_http_code);
                }
            } else {
                error_log("Invalid file type: " . $file_ext);
            }
        } else {
            error_log("No image file uploaded");
        }

        $getVariantQuery = <<<GRAPHQL
query getProductVariant(\$productId: ID!) {
  node(id: \$productId) {
    ... on Product {
      variants(first: 1) {
        edges {
          node {
            id
            inventoryItem {
              id
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

        $getVariantVariables = array('productId' => $product_id);
        $getVariantResult = executeGraphQLMutation($access_token, $shop, $getVariantQuery, $getVariantVariables);

        if (!$getVariantResult['success']) {
            $error_message = "Product created but failed to get variant: " . json_encode($getVariantResult['error']);
        } elseif (isset($getVariantResult['data']['data']['node']['variants']['edges'][0]['node']['id'])) {
            $default_variant_id = $getVariantResult['data']['data']['node']['variants']['edges'][0]['node']['id'];
            $inventory_item_id = $getVariantResult['data']['data']['node']['variants']['edges'][0]['node']['inventoryItem']['id'];

            $enableTrackingMutation = <<<GRAPHQL
mutation inventoryItemUpdate(\$id: ID!, \$input: InventoryItemInput!) {
  inventoryItemUpdate(id: \$id, input: \$input) {
    inventoryItem {
      id
      tracked
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

            $trackingVariables = array(
                'id' => $inventory_item_id,
                'input' => array(
                    'tracked' => true
                )
            );

            $trackingResult = executeGraphQLMutation($access_token, $shop, $enableTrackingMutation, $trackingVariables);

            if (!$trackingResult['success']) {
                $error_message = "Product created but failed to enable inventory tracking: " . json_encode($trackingResult['error']);
            } elseif (isset($trackingResult['data']['data']['inventoryItemUpdate']['userErrors']) && !empty($trackingResult['data']['data']['inventoryItemUpdate']['userErrors'])) {
                $errors = $trackingResult['data']['data']['inventoryItemUpdate']['userErrors'];
                $error_message = "Product created but failed to enable inventory tracking: " . $errors[0]['message'];
            } else {
                $updateResult = updateProductVariant($access_token, $shop, $product_id, $default_variant_id, $price, $sku);

                if (!$updateResult['success']) {
                    $error_message = "Product created but variant update failed: " . json_encode($updateResult['error']);
                } elseif (isset($updateResult['data']['data']['productVariantsBulkUpdate']['userErrors']) && !empty($updateResult['data']['data']['productVariantsBulkUpdate']['userErrors'])) {
                    $errors = $updateResult['data']['data']['productVariantsBulkUpdate']['userErrors'];
                    $error_message = "Product created but variant update failed: " . $errors[0]['message'];
                } else {
                    if ($inventory_quantity > 0 && !empty($location_id) && $inventory_item_id) {
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

                        if (!$inventoryResult['success']) {
                            $error_message = "Product and variant updated but inventory update failed: " . json_encode($inventoryResult['error']);
                        } elseif (isset($inventoryResult['data']['data']['inventorySetQuantities']['userErrors']) && !empty($inventoryResult['data']['data']['inventorySetQuantities']['userErrors'])) {
                            $errors = $inventoryResult['data']['data']['inventorySetQuantities']['userErrors'];
                            $error_message = "Product and variant updated but inventory update failed: " . $errors[0]['message'];
                        } else {
                            $product_created = true;
                        }
                    } else {
                        $product_created = true;
                    }
                }
            }
        } else {
            $error_message = "Product created but no variant found";
        }
    }
    if ($product_created) {
        echo json_encode(array('success' => true, 'message' => 'Product created successfully!', 'redirect' => 'products.php?shop=' . urlencode($shop)));
        exit;
    } else {
        echo json_encode(array('success' => false, 'message' => $error_message));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Product - Shopify App</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/product_create.css">
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
                <h1>Create new product</h1>
                <p>Add a new product to your store</p>
            </div>
            <form id="createProductForm" method="POST" enctype="multipart/form-data">
                <div class="form-body">
                    <div class="form-group">
                        <label for="title">Product title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" class="form-control" required
                            placeholder="e.g., Classic T-Shirt">
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control"
                            placeholder="Describe your product..."></textarea>
                        <div class="info-text">Supports HTML formatting</div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="price">Price <span class="required">*</span></label>
                            <input type="number" id="price" name="price" step="0.01" class="form-control" required
                                placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label for="sku">SKU</label>
                            <input type="text" id="sku" name="sku" class="form-control" placeholder="SKU-001">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="inventory_quantity">Inventory quantity</label>
                            <input type="number" id="inventory_quantity" name="inventory_quantity" class="form-control"
                                value="0" min="0">
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
                    <div class="form-group">
                        <label for="product_image">Product Image (Optional)</label>
                        <div class="image-upload-container">
                            <div class="image-preview-wrapper" id="imagePreviewWrapper" style="display: none;">
                                <div class="image-preview">
                                    <img id="imagePreview" src="" alt="Product preview">
                                    <button type="button" class="remove-image-btn" id="removeImageBtn">×</button>
                                </div>
                            </div>
                            <div class="image-upload-area" id="imageUploadArea">
                                <input type="file" id="product_image" name="product_image"
                                    accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" style="display: none;">
                                <button type="button" class="btn-upload" id="uploadImageBtn"><span
                                        class="upload-icon">📷</span> Choose Image</button>
                                <div class="info-text">Recommended: Square image, at least 512x512px. Max 20MB.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary"
                        onclick="window.location.href='products.php?shop=<?php echo urlencode($shop); ?>'">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Create product</button>
                </div>
            </form>
        </div>
    </div>
    <div id="loaderOverlay" class="loader-overlay">
        <div class="loader-content">
            <div class="spinner"></div>
            <div class="loader-text">Creating product. Please wait...</div>
        </div>
    </div>
    <div id="toast" class="toast"></div>
    <script>
        document.getElementById('createProductForm').addEventListener('submit', function (e) {
            e.preventDefault();
            var title = document.getElementById('title').value.trim();
            var price = document.getElementById('price').value.trim();
            var inventoryQuantity = document.getElementById('inventory_quantity').value;
            if (!title) { showToast('Please enter a product title', 'error'); return; }
            if (!price || parseFloat(price) < 0) { showToast('Please enter a valid price', 'error'); return; }
            if (inventoryQuantity === '' || parseInt(inventoryQuantity) < 0) { showToast('Please enter a valid inventory quantity', 'error'); return; }
            showLoader(true);
            var formData = new FormData(this);
            formData.append('create_product', '1');
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
        var uploadImageBtn = document.getElementById('uploadImageBtn');
        var productImageInput = document.getElementById('product_image');
        var imagePreviewWrapper = document.getElementById('imagePreviewWrapper');
        var imagePreview = document.getElementById('imagePreview');
        var removeImageBtn = document.getElementById('removeImageBtn');
        if (uploadImageBtn) { uploadImageBtn.addEventListener('click', function () { productImageInput.click(); }); }
        if (productImageInput) {
            productImageInput.addEventListener('change', function (e) {
                var file = e.target.files[0];
                if (file) {
                    var allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
                    if (!allowedTypes.includes(file.type)) { showToast('Please select a valid image file', 'error'); productImageInput.value = ''; return; }
                    if (file.size > 20 * 1024 * 1024) { showToast('Image size should be less than 20MB', 'error'); productImageInput.value = ''; return; }
                    var reader = new FileReader();
                    reader.onload = function (e) { imagePreview.src = e.target.result; imagePreviewWrapper.style.display = 'inline-block'; };
                    reader.readAsDataURL(file);
                }
            });
        }
        if (removeImageBtn) { removeImageBtn.addEventListener('click', function () { productImageInput.value = ''; imagePreview.src = ''; imagePreviewWrapper.style.display = 'none'; showToast('Image removed', 'success'); }); }
    </script>
</body>

</html>