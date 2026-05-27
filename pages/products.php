<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';

session_start();
$shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : (isset($_GET['shop']) ? $_GET['shop'] : '');

if (!$shop) {
    die("Shop missing");
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

$data = null;
$error = null;

if ($access_token) {
    $query = <<<GRAPHQL
query MyQuery {
  products(first: 250) {
    edges {
      cursor
      node {
        id
        title
        handle
        description
        createdAt
        totalInventory
        priceRangeV2 {
          minVariantPrice {
            amount
            currencyCode
          }
        }
        media(first: 1) {
          edges {
            node {
              ... on MediaImage {
                image {
                  url
                  altText
                }
              }
            }
          }
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
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
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Shopify App</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/products.css">
</head>

<body>
    <?php renderNavigation($app_url, $shop); ?>

    <div class="content">
        <div class="table-header">
            <div>
                <h1>Products</h1>
                <p class="subtitle">Manage your product catalog and inventory</p>
            </div>
            <div class="search-box">
                <input type="text" id="searchInput" class="search-input" placeholder="Search products..." onkeyup="filterProducts()">
            </div>
        </div>

        <?php if (isset($token_source)): ?>
            <!-- <div class="token-status">
                ✓ Token source: <?php echo htmlspecialchars($token_source); ?>
            </div> -->
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!$access_token): ?>
            <div class="error-message">
                <strong>Authentication Error:</strong> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php elseif ($data && isset($data['data']['products']['edges'])): ?>
            <?php $products = $data['data']['products']['edges']; ?>
            <?php if (empty($products)): ?>
                <div class="no-products">
                    <p>No products found in your store.</p>
                </div>
            <?php else: ?>
                <div class="product-count">
                    Showing <span id="visibleCount"><?php echo count($products); ?></span> of <?php echo count($products); ?>
                    products
                </div>
                <div class="products-table-container">
                    <table class="products-table" id="productsTable">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Product</th>
                                <th>Description</th>
                                <th>Price</th>
                                <th>Inventory</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="productsTableBody">
                            <?php foreach ($products as $item): ?>
                                <?php
                                $product = $item['node'];
                                $image_url = '';
                                if (isset($product['media']['edges'][0]['node']['image']['url'])) {
                                    $image_url = $product['media']['edges'][0]['node']['image']['url'];
                                }

                                // Get price
                                $price = 'N/A';
                                if (isset($product['priceRangeV2']['minVariantPrice']['amount'])) {
                                    $price = $product['priceRangeV2']['minVariantPrice']['amount'] . ' ' . $product['priceRangeV2']['minVariantPrice']['currencyCode'];
                                }

                                // Determine inventory class
                                $inventory = $product['totalInventory'];
                                $inventory_class = 'inventory-high';
                                if ($inventory <= 5) {
                                    $inventory_class = 'inventory-low';
                                } elseif ($inventory <= 20) {
                                    $inventory_class = 'inventory-medium';
                                }
                                ?>
                                <tr class="product-row"
                                    data-product-title="<?php echo strtolower(htmlspecialchars($product['title'])); ?>">
                                    <td>
                                        <?php if ($image_url): ?>
                                            <img class="product-thumbnail" src="<?php echo htmlspecialchars($image_url); ?>"
                                                alt="<?php echo htmlspecialchars($product['title']); ?>">
                                        <?php else: ?>
                                            <div class="product-thumbnail-placeholder">
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="product-title-cell">
                                        <div class="product-title"><?php echo htmlspecialchars($product['title']); ?></div>
                                        <div class="product-handle"><?php echo htmlspecialchars($product['handle']); ?></div>
                                    </td>
                                    <td>
                                        <div class="product-description-preview">
                                            <?php
                                            $description = strip_tags($product['description']);
                                            echo htmlspecialchars(substr($description, 0, 80)) . (strlen($description) > 80 ? '...' : '');
                                            ?>
                                        </div>
                                    </td>
                                    <td class="price"><?php echo htmlspecialchars($price); ?></td>
                                    <td>
                                        <span class="inventory-badge <?php echo $inventory_class; ?>">
                                            <?php echo number_format($inventory); ?> units
                                        </span>
                                    </td>
                                    <td class="date-cell">
                                        <?php echo date('M d, Y', strtotime($product['createdAt'])); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php
                                            $product_numeric_id = preg_replace('/^gid:\/\/shopify\/Product\//', '', $product['id']);
                                            ?>
                                            <a href="https://<?php echo htmlspecialchars($shop); ?>/admin/products/<?php echo htmlspecialchars($product_numeric_id); ?>"
                                                target="_blank" class="btn-icon btn-view">
                                                View
                                            </a>
                                            <a href="https://<?php echo htmlspecialchars($shop); ?>/admin/products/<?php echo htmlspecialchars($product_numeric_id); ?>/edit"
                                                target="_blank" class="btn-icon btn-edit">
                                                Edit
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php elseif ($data && isset($data['data'])): ?>
            <pre><?php print_r($data); ?></pre>
        <?php endif; ?>
    </div>

    <script>
        function filterProducts() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('productsTableBody');
            const rows = table.getElementsByTagName('tr');
            let visibleCount = 0;

            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const productTitle = row.getAttribute('data-product-title') || '';

                if (productTitle.includes(filter)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }

            document.getElementById('visibleCount').innerText = visibleCount;
        }
    </script>
</body>
</html>