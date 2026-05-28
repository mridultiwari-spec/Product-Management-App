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

$productsPerPage = 6;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$allProductsData = array();
$totalProducts = 0;
$hasNextPage = true;
$allProductsFetched = array();
$nextCursor = null;

$data = null;
$error = null;
$allProducts = array();
$totalProducts = null;

if ($access_token) {
    $countQuery = <<<GRAPHQL
query CountProducts {
  productsCount {
    count
  }
}
GRAPHQL;

    $url = "https://" . $shop . "/admin/api/" . $api_version . "/graphql.json";
    $countFields = json_encode(array('query' => $countQuery));
    $headers = array(
        "Content-Type: application/json",
        "X-Shopify-Access-Token: " . $access_token
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $countFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $countResult = curl_exec($ch);
    $countHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($countHttpCode === 200 && $countResult) {
        $countData = json_decode($countResult, true);
        if (isset($countData['data']['productsCount']['count'])) {
            $totalProducts = $countData['data']['productsCount']['count'];
        }
    }

    $allProductsData = array();
    $hasNextPage = true;
    $cursor = null;

    while ($hasNextPage) {
        $afterParam = '';
        if ($cursor) {
            $afterParam = ', after: "' . addslashes($cursor) . '"';
        }

        $query = <<<GRAPHQL
query MyQuery {
  products(first: 250$afterParam) {
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

        $fields = json_encode(array('query' => $query));

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
            break;
        } elseif ($http_code !== 200) {
            $error = "HTTP Error: " . $http_code;
            break;
        } else {
            $data = json_decode($result, true);
            if (isset($data['errors'])) {
                $error = "GraphQL Error: " . json_encode($data['errors']);
                break;
            } elseif (isset($data['data']['products'])) {
                foreach ($data['data']['products']['edges'] as $edge) {
                    $allProductsData[] = $edge;
                }
                $pageInfo = $data['data']['products']['pageInfo'];
                $hasNextPage = isset($pageInfo['hasNextPage']) ? $pageInfo['hasNextPage'] : false;
                $cursor = isset($pageInfo['endCursor']) ? $pageInfo['endCursor'] : null;
            }
        }
    }

    $allProducts = $allProductsData;

    $productsJson = array();
    foreach ($allProducts as $item) {
        $product = $item['node'];
        $image_url = '';
        if (isset($product['media']['edges'][0]['node']['image']['url'])) {
            $image_url = $product['media']['edges'][0]['node']['image']['url'];
        }

        $price = 'N/A';
        if (isset($product['priceRangeV2']['minVariantPrice']['amount'])) {
            $price = $product['priceRangeV2']['minVariantPrice']['amount'] . ' ' . $product['priceRangeV2']['minVariantPrice']['currencyCode'];
        }

        $inventory = isset($product['totalInventory']) ? $product['totalInventory'] : 0;
        $inventory_class = 'inventory-high';
        if ($inventory <= 5) {
            $inventory_class = 'inventory-low';
        } elseif ($inventory <= 20) {
            $inventory_class = 'inventory-medium';
        }

        $product_numeric_id = preg_replace('/^gid:\/\/shopify\/Product\//', '', $product['id']);

        $productsJson[] = array(
            'title' => $product['title'],
            'title_lower' => strtolower($product['title']),
            'handle' => $product['handle'],
            'image_url' => $image_url,
            'description' => substr(strip_tags($product['description']), 0, 80) . (strlen(strip_tags($product['description'])) > 80 ? '...' : ''),
            'price' => $price,
            'inventory' => $inventory,
            'inventory_class' => $inventory_class,
            'created_at' => date('M d, Y', strtotime($product['createdAt'])),
            'product_numeric_id' => $product_numeric_id,
            'shop' => $shop
        );
    }

    $totalPages = ceil($totalProducts / $productsPerPage);
}

$totalPages = 0;
if ($totalProducts) {
    $totalPages = ceil($totalProducts / $productsPerPage);
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
                <input type="text" id="searchInput" class="search-input" placeholder="Search products...">
            </div>
        </div>

        <?php if ($error): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!$access_token): ?>
            <div class="error-message">
                <strong>Authentication Error:</strong> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php elseif ($allProducts && count($allProducts) > 0): ?>
            <div class="table-actions">
                <div class="product-count-info">
                    Showing page <?php echo $currentPage; ?>
                    <?php if ($totalProducts): ?>
                        of <?php echo $totalPages; ?> pages (Total <?php echo number_format($totalProducts); ?> products)
                    <?php endif; ?>
                    - <?php echo count($allProducts); ?> products displayed
                </div>
                <a href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/create_product.php?shop=<?php echo urlencode($shop); ?>"
                    class="create-product-btn">
                    Create New Product
                </a>
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
                    </tbody>
                </table>
            </div>
            <div id="paginationContainer" class="pagination-container">
            </div>
            <?php if ($hasNextPage): ?>
                <a href="?shop=<?php echo urlencode($shop); ?>&page=<?php echo ($currentPage + 1); ?><?php echo $nextCursor ? '&cursor=' . urlencode($nextCursor) : ''; ?>"
                    class="pagination-btn">
                    Next &raquo;
                </a>
            <?php else: ?>
                <!-- <span class="pagination-btn disabled">
                    Next &raquo;
                </span> -->
            <?php endif; ?>
        </div>

    <?php elseif ($data && isset($data['data']['products']['edges']) && empty($allProducts)): ?>
        <div class="no-products">
            <p>No products found in your store.</p>
            <div style="margin-top: 20px;">
                <a href="https://<?php echo htmlspecialchars($shop); ?>/admin/products/new" target="_blank"
                    class="create-product-btn" style="display: inline-block;">
                    Create Your First Product
                </a>
            </div>
        </div>
    <?php elseif ($data && isset($data['data'])): ?>
        <pre><?php print_r($data); ?></pre>
    <?php endif; ?>
    </div>

    <script>
        var allProducts = <?php echo json_encode($productsJson); ?>;
        var productsPerPage = <?php echo $productsPerPage; ?>;
        var currentPage = <?php echo $currentPage; ?>;
        var filteredProducts = [];
        var currentFilteredProducts = [];

        function renderProductsTable() {
            var tbody = document.getElementById('productsTableBody');
            if (!tbody) return;

            tbody.innerHTML = '';

            var productsToShow = filteredProducts.length > 0 ? filteredProducts : allProducts;
            currentFilteredProducts = productsToShow;

            var totalProducts = productsToShow.length;
            var totalPages = Math.ceil(totalProducts / productsPerPage);
            var startIndex = (currentPage - 1) * productsPerPage;
            var endIndex = Math.min(startIndex + productsPerPage, totalProducts);
            var pageProducts = productsToShow.slice(startIndex, endIndex);

            var countInfo = document.querySelector('.product-count-info');
            if (countInfo) {
                if (filteredProducts.length > 0) {
                    countInfo.innerHTML = 'Showing ' + (startIndex + 1) + ' - ' + endIndex + ' of ' + totalProducts + ' filtered products';
                } else {
                    countInfo.innerHTML = 'Showing page ' + currentPage + ' of ' + totalPages + ' (Total ' + totalProducts + ' products) - ' + pageProducts.length + ' products displayed';
                }
            }
            for (var i = 0; i < pageProducts.length; i++) {
                var p = pageProducts[i];
                var row = tbody.insertRow();

                var imgCell = row.insertCell(0);
                imgCell.className = 'product-image-cell';
                if (p.image_url) {
                    imgCell.innerHTML = '<img class="product-thumbnail" src="' + escapeHtml(p.image_url) + '" alt="' + escapeHtml(p.title) + '">';
                } else {
                    imgCell.innerHTML = '<div class="product-thumbnail-placeholder">No Image</div>';
                }

                var titleCell = row.insertCell(1);
                titleCell.className = 'product-title-cell';
                titleCell.innerHTML = '<div class="product-title">' + escapeHtml(p.title) + '</div><div class="product-handle">' + escapeHtml(p.handle) + '</div>';

                var descCell = row.insertCell(2);
                descCell.className = 'product-description-cell';
                descCell.innerHTML = '<div class="product-description-preview">' + escapeHtml(p.description) + '</div>';

                var priceCell = row.insertCell(3);
                priceCell.className = 'price';
                priceCell.innerHTML = escapeHtml(p.price);

                var invCell = row.insertCell(4);
                invCell.className = 'inventory-cell';
                invCell.innerHTML = '<span class="inventory-badge ' + p.inventory_class + '">' + p.inventory.toLocaleString() + ' units</span>';

                var dateCell = row.insertCell(5);
                dateCell.className = 'date-cell';
                dateCell.innerHTML = p.created_at;

                var actionsCell = row.insertCell(6);
                actionsCell.className = 'actions-cell';
                actionsCell.innerHTML = '<div class="action-buttons">' +
                    '<a href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/view_product.php?shop=' + encodeURIComponent(p.shop) + '&product_id=' + p.product_numeric_id + '" class="btn-icon btn-view">View</a>' +
                    '<a href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/product_edit.php?shop=' + encodeURIComponent(p.shop) + '&product_id=' + p.product_numeric_id + '" class="btn-icon btn-edit">Edit</a>' +
                    '</div>';
            }
            renderPagination(totalPages);
        }
        function renderPagination(totalPages) {
            var paginationContainer = document.getElementById('paginationContainer');
            if (!paginationContainer) return;

            if (totalPages <= 1) {
                paginationContainer.innerHTML = '';
                return;
            }

            var html = '';
            if (currentPage > 1) {
                html += '<a href="javascript:void(0)" onclick="changePage(' + (currentPage - 1) + ')" class="pagination-btn">&laquo; Previous</a>';
            } else {
                html += '<span class="pagination-btn disabled">&laquo; Previous</span>';
            }
            html += '<div class="page-numbers">';

            var startPage = Math.max(1, currentPage - 2);
            var endPage = Math.min(totalPages, currentPage + 2);

            if (startPage > 1) {
                html += '<a href="javascript:void(0)" onclick="changePage(1)" class="page-number">1</a>';
                if (startPage > 2) {
                    html += '<span class="page-number disabled">...</span>';
                }
            }

            for (var i = startPage; i <= endPage; i++) {
                var activeClass = (i == currentPage) ? 'active' : '';
                html += '<a href="javascript:void(0)" onclick="changePage(' + i + ')" class="page-number ' + activeClass + '">' + i + '</a>';
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    html += '<span class="page-number disabled">...</span>';
                }
                html += '<a href="javascript:void(0)" onclick="changePage(' + totalPages + ')" class="page-number">' + totalPages + '</a>';
            }

            html += '</div>';
            if (currentPage < totalPages) {
                html += '<a href="javascript:void(0)" onclick="changePage(' + (currentPage + 1) + ')" class="pagination-btn">Next &raquo;</a>';
            } else {
                html += '<span class="pagination-btn disabled">Next &raquo;</span>';
            }

            paginationContainer.innerHTML = html;
        }
        function changePage(page) {
            currentPage = page;
            renderProductsTable();
        }
        function filterProducts() {
            var input = document.getElementById('searchInput');
            var filter = input.value.toLowerCase().trim();

            if (filter === '') {
                filteredProducts = [];
                currentPage = 1;
                renderProductsTable();
                return;
            }

            filteredProducts = [];
            for (var i = 0; i < allProducts.length; i++) {
                if (allProducts[i].title_lower.indexOf(filter) !== -1) {
                    filteredProducts.push(allProducts[i]);
                }
            }

            currentPage = 1;

            if (filteredProducts.length === 0) {
                var tbody = document.getElementById('productsTableBody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px;">No products match your search criteria.</td></tr>';
                }
                var paginationContainer = document.getElementById('paginationContainer');
                if (paginationContainer) {
                    paginationContainer.innerHTML = '';
                }
                var countInfo = document.querySelector('.product-count-info');
                if (countInfo) {
                    countInfo.innerHTML = 'No products found matching "' + escapeHtml(filter) + '"';
                }
            } else {
                renderProductsTable();
            }
        }
        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }
        document.addEventListener('DOMContentLoaded', function () {
            renderProductsTable();
            var searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', function () {
                    filterProducts();
                });
            }
        });
    </script>
</body>

</html>