<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';

session_start();
$shop = isset($_GET['shop']) ? $_GET['shop'] : (isset($_SESSION['shop']) ? $_SESSION['shop'] : '');
$product_id = isset($_GET['product_id']) ? $_GET['product_id'] : '';

if (!$shop) {
    die("Shop missing");
}

if (!$product_id) {
    die("Product ID missing");
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

$product = null;
$error = null;
$variants = array();
$images = array();

if ($access_token) {
    // Build the full GID
    $full_product_id = 'gid://shopify/Product/' . $product_id;
    
    $query = <<<GRAPHQL
query getProduct {
  node(id: "$full_product_id") {
    ... on Product {
      id
      title
      handle
      description
      descriptionHtml
      createdAt
      updatedAt
      productType
      vendor
      tags
      totalInventory
      status
      priceRangeV2 {
        minVariantPrice {
          amount
          currencyCode
        }
        maxVariantPrice {
          amount
          currencyCode
        }
      }
      variants(first: 50) {
        edges {
          node {
            id
            title
            price
            sku
            inventoryQuantity
            inventoryPolicy
            taxable
            position
            selectedOptions {
              name
              value
            }
          }
        }
      }
      images(first: 20) {
        edges {
          node {
            id
            originalSrc
            altText
            width
            height
          }
        }
      }
      media(first: 20) {
        edges {
          node {
            ... on MediaImage {
              id
              image {
                url
                altText
                width
                height
              }
            }
          }
        }
      }
      options {
        id
        name
        values
      }
    }
  }
}
GRAPHQL;

    $url = "https://" . $shop . "/admin/api/" . $api_version . "/graphql.json";
    $fields = json_encode(array('query' => $query));
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
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        $error = "CURL Error: " . $curl_error;
    } elseif ($http_code !== 200) {
        $error = "HTTP Error: " . $http_code;
    } else {
        $data = json_decode($result, true);
        if (isset($data['errors'])) {
            $error = "GraphQL Error: " . json_encode($data['errors']);
        } elseif (isset($data['data']['node'])) {
            $product = $data['data']['node'];
            
            // Extract variants
            if (isset($product['variants']['edges'])) {
                foreach ($product['variants']['edges'] as $variant) {
                    $variants[] = $variant['node'];
                }
            }
            
            // Extract images
            if (isset($product['images']['edges'])) {
                foreach ($product['images']['edges'] as $image) {
                    $images[] = $image['node'];
                }
            }
        } else {
            $error = "Product not found";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product ? $product['title'] : 'Product Details'); ?> - Shopify App</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/view_product.css">
</head>
<body>
    <?php renderNavigation($app_url, $shop); ?>
    
    <div class="content">
        <?php if ($error): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($product): ?>
            <div class="product-container">
                <div class="product-header">
                    <div>
                        <h1><?php echo htmlspecialchars($product['title']); ?></h1>
                        <div class="handle">Handle: <?php echo htmlspecialchars($product['handle']); ?></div>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <a href="products.php?shop=<?php echo urlencode($shop); ?>" class="back-btn">← Back to Products</a>
                        <a href="https://<?php echo htmlspecialchars($shop); ?>/admin/products/<?php echo htmlspecialchars($product_id); ?>/edit" target="_blank" class="edit-btn">Edit Product</a>
                    </div>
                </div>
                
                <div class="product-body">
                    <div class="product-main">
                        <div class="product-images">
                            <?php if (!empty($images)): ?>
                                <img id="mainImage" class="main-image" src="<?php echo htmlspecialchars($images[0]['originalSrc']); ?>" alt="<?php echo htmlspecialchars($product['title']); ?>">
                                <?php if (count($images) > 1): ?>
                                    <div class="thumbnail-list">
                                        <?php foreach ($images as $index => $image): ?>
                                            <img class="thumbnail" src="<?php echo htmlspecialchars($image['originalSrc']); ?>" alt="Thumbnail" onclick="document.getElementById('mainImage').src='<?php echo htmlspecialchars($image['originalSrc']); ?>'">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="padding: 100px 20px; color: #5c5f62;">No images available</div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="product-info">
                            <div class="info-section">
                                <div class="info-label">Price Range</div>
                                <div class="price-range">
                                    <?php 
                                    $minPrice = $product['priceRangeV2']['minVariantPrice']['amount'];
                                    $maxPrice = $product['priceRangeV2']['maxVariantPrice']['amount'];
                                    $currency = $product['priceRangeV2']['minVariantPrice']['currencyCode'];
                                    if ($minPrice == $maxPrice) {
                                        echo $currency . ' ' . number_format($minPrice, 2);
                                    } else {
                                        echo $currency . ' ' . number_format($minPrice, 2) . ' - ' . number_format($maxPrice, 2);
                                    }
                                    ?>
                                </div>
                            </div>
                            
                            <div class="info-section">
                                <div class="info-label">Status</div>
                                <div>
                                    <span class="status-badge status-<?php echo strtolower($product['status']); ?>">
                                        <?php echo ucfirst($product['status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="info-section">
                                <div class="info-label">Total Inventory</div>
                                <div class="info-value"><?php echo number_format($product['totalInventory']); ?> units</div>
                            </div>
                            
                            <?php if (!empty($product['productType'])): ?>
                            <div class="info-section">
                                <div class="info-label">Product Type</div>
                                <div class="info-value"><?php echo htmlspecialchars($product['productType']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($product['vendor'])): ?>
                            <div class="info-section">
                                <div class="info-label">Vendor</div>
                                <div class="info-value"><?php echo htmlspecialchars($product['vendor']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($product['tags'])): ?>
                            <div class="info-section">
                                <div class="info-label">Tags</div>
                                <div class="info-value">
                                    <?php 
                                    $tags = explode(',', $product['tags']);
                                    foreach ($tags as $tag) {
                                        echo '<span class="tag">' . htmlspecialchars(trim($tag)) . '</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="info-section">
                                <div class="info-label">Created</div>
                                <div class="info-value"><?php echo date('F d, Y', strtotime($product['createdAt'])); ?></div>
                            </div>
                            
                            <div class="info-section">
                                <div class="info-label">Last Updated</div>
                                <div class="info-value"><?php echo date('F d, Y', strtotime($product['updatedAt'])); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <div class="info-label">Description</div>
                        <div class="info-value">
                            <?php 
                            if (!empty($product['descriptionHtml'])) {
                                echo $product['descriptionHtml'];
                            } else {
                                echo 'No description provided.';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($variants)): ?>
                    <div class="info-section">
                        <div class="info-label">Variants</div>
                        <table class="variants-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Price</th>
                                    <th>SKU</th>
                                    <th>Inventory</th>
                                    <th>Policy</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($variants as $variant): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($variant['title']); ?></td>
                                    <td><?php echo htmlspecialchars($variant['price']); ?></td>
                                    <td><?php echo htmlspecialchars($variant['sku'] ?: '—'); ?></td>
                                    <td><?php echo number_format($variant['inventoryQuantity']); ?></td>
                                    <td><?php echo $variant['inventoryPolicy'] == 'DENY' ? 'Deny' : 'Continue'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif (!$error): ?>
            <div class="error-message">
                Product not found.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>