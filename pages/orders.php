<?php
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session_token_auth.php';

session_start();
$shop = isset($_GET['shop']) ? $_GET['shop'] : (isset($_SESSION['shop']) ? $_SESSION['shop'] : '');
if (empty($shop)) {
    die("Missing shop parameter.");
}

$pdo = getDatabaseConnection();
$sessionToken = get_bearer_token_php53();

if (!$sessionToken && isset($_GET['id_token'])) {
    $sessionToken = $_GET['id_token'];
}

$tokenResult = get_valid_shop_access_token_php53($pdo, 'shops', $shop, $api_key, $api_secret, $sessionToken, false);

if (!$tokenResult['success']) {
    $error_message = $tokenResult['error'];
    $access_token = null;
} else {
    $access_token = $tokenResult['access_token'];
    $token_source = $tokenResult['source'];
}

include __DIR__ . '/../includes/navigation.php';
$api_version = '2026-04';

$ordersPerPage = 20;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$allOrdersData = array();
$totalOrders = 0;
$error = null;
$allOrders = array();

if ($access_token) {
    $countQuery = <<<GRAPHQL
query CountOrders {
  ordersCount {
    count
  }
}
GRAPHQL;

    $url = "https://" . $shop . "/admin/api/" . $api_version . "/graphql.json";
    $headers = array(
        "Content-Type: application/json",
        "X-Shopify-Access-Token: " . $access_token
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('query' => $countQuery)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $countResult = curl_exec($ch);
    $countHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($countHttpCode === 200 && $countResult) {
        $countData = json_decode($countResult, true);
        if (isset($countData['data']['ordersCount']['count'])) {
            $totalOrders = $countData['data']['ordersCount']['count'];
        }
    }
    $allOrdersData = array();
    $hasNextPage = true;
    $cursor = null;

    while ($hasNextPage) {
        $afterParam = '';
        if ($cursor) {
            $afterParam = ', after: "' . addslashes($cursor) . '"';
        }
        $query = <<<GRAPHQL
query MyQuery {
  orders(first: 250, sortKey: CREATED_AT, reverse: true$afterParam) {
    edges {
      cursor
      node {
        id
        name
        createdAt
        processedAt
        displayFinancialStatus
        displayFulfillmentStatus
        totalPriceSet {
          shopMoney {
            amount
            currencyCode
          }
        }
        subtotalPriceSet {
          shopMoney {
            amount
          }
        }
        totalTaxSet {
          shopMoney {
            amount
          }
        }
        customer {
          id
          firstName
          lastName
          email
          displayName
        }
        lineItems(first: 5) {
          edges {
            node {
              title
              quantity
              originalTotalSet {
                shopMoney {
                  amount
                }
              }
            }
          }
        }
        shippingAddress {
          city
          country
          province
          zip
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

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('query' => $query)));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
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
            } elseif (isset($data['data']['orders']['edges'])) {
                foreach ($data['data']['orders']['edges'] as $edge) {
                    $allOrdersData[] = $edge;
                }
                $pageInfo = $data['data']['orders']['pageInfo'];
                $hasNextPage = isset($pageInfo['hasNextPage']) ? $pageInfo['hasNextPage'] : false;
                $cursor = isset($pageInfo['endCursor']) ? $pageInfo['endCursor'] : null;
            }
        }
    }

    $ordersJson = array();
    foreach ($allOrdersData as $item) {
        $order = $item['node'];
        $customerName = 'Guest';
        $customerEmail = 'N/A';
        if (isset($order['customer']) && is_array($order['customer'])) {
            if (!empty($order['customer']['displayName'])) {
                $customerName = $order['customer']['displayName'];
            } elseif (!empty($order['customer']['firstName']) || !empty($order['customer']['lastName'])) {
                $firstName = isset($order['customer']['firstName']) ? $order['customer']['firstName'] : '';
                $lastName = isset($order['customer']['lastName']) ? $order['customer']['lastName'] : '';
                $customerName = trim($firstName . ' ' . $lastName);
                if (empty($customerName))
                    $customerName = 'Guest';
            }
            $customerEmail = isset($order['customer']['email']) ? $order['customer']['email'] : 'N/A';
        }
        
        $totalPrice = '0.00';
        $currency = 'USD';
        if (isset($order['totalPriceSet']['shopMoney']['amount'])) {
            $totalPrice = $order['totalPriceSet']['shopMoney']['amount'];
            $currency = isset($order['totalPriceSet']['shopMoney']['currencyCode']) ? $order['totalPriceSet']['shopMoney']['currencyCode'] : 'USD';
        }

        $subtotalPrice = isset($order['subtotalPriceSet']['shopMoney']['amount']) ? $order['subtotalPriceSet']['shopMoney']['amount'] : '0.00';
        $totalTax = isset($order['totalTaxSet']['shopMoney']['amount']) ? $order['totalTaxSet']['shopMoney']['amount'] : '0.00';

        $itemCount = 0;
        $itemSummary = array();
        if (isset($order['lineItems']['edges']) && is_array($order['lineItems']['edges'])) {
            foreach ($order['lineItems']['edges'] as $itemEdge) {
                if (isset($itemEdge['node'])) {
                    $item = $itemEdge['node'];
                    $quantity = isset($item['quantity']) ? $item['quantity'] : 0;
                    $itemCount += $quantity;
                    $itemTitle = isset($item['title']) ? $item['title'] : 'Unknown Product';
                    $itemSummary[] = $quantity . 'x ' . $itemTitle;
                }
            }
        }

        $location = '—';
        if (isset($order['shippingAddress']) && is_array($order['shippingAddress'])) {
            $locParts = array();
            if (!empty($order['shippingAddress']['city']))
                $locParts[] = $order['shippingAddress']['city'];
            if (!empty($order['shippingAddress']['province']))
                $locParts[] = $order['shippingAddress']['province'];
            if (!empty($order['shippingAddress']['country']))
                $locParts[] = $order['shippingAddress']['country'];
            if (!empty($locParts)) {
                $location = implode(', ', $locParts);
            }
        }
        $order_numeric_id = preg_replace('/^gid:\/\/shopify\/Order\//', '', $order['id']);
        $orderName = isset($order['name']) ? $order['name'] : '' . $order_numeric_id;
        $financialStatus = isset($order['displayFinancialStatus']) ? ucfirst(strtolower($order['displayFinancialStatus'])) : 'Pending';
        if (empty($financialStatus) || $financialStatus == '')
            $financialStatus = 'Pending';

        $fulfillmentStatus = isset($order['displayFulfillmentStatus']) ? ucfirst(strtolower($order['displayFulfillmentStatus'])) : 'Unfulfilled';
        if (empty($fulfillmentStatus) || $fulfillmentStatus == '')
            $fulfillmentStatus = 'Unfulfilled';

        $ordersJson[] = array(
            'id' => $order_numeric_id,
            'name' => $orderName,
            'createdAt' => isset($order['createdAt']) ? date('M d, Y H:i', strtotime($order['createdAt'])) : date('M d, Y H:i'),
            'customerName' => $customerName,
            'customerEmail' => $customerEmail,
            'customerNameLower' => strtolower($customerName),
            'customerEmailLower' => strtolower($customerEmail),
            'totalPrice' => $totalPrice,
            'currency' => $currency,
            'subtotalPrice' => $subtotalPrice,
            'totalTax' => $totalTax,
            'itemCount' => $itemCount,
            'itemSummary' => (count($itemSummary) > 0) ? implode(', ', array_slice($itemSummary, 0, 3)) . (count($itemSummary) > 3 ? '...' : '') : 'No items',
            'financialStatus' => $financialStatus,
            'fulfillmentStatus' => $fulfillmentStatus,
            'location' => $location,
            'shop' => $shop
        );
    }

    $allOrders = $ordersJson;
    $totalPages = 0;
    if ($totalOrders) {
        $totalPages = ceil($totalOrders / $ordersPerPage);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - Shopify App</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/orders.css">
</head>

<body>
    <?php renderNavigation($app_url, $shop); ?>
    <div class="content">
        <div class="table-header">
            <div>
                <h1>Orders</h1>
                <p class="subtitle">View and manage all customer orders</p>
            </div>
            <div class="search-box">
                <input type="text" id="searchInput" class="search-input"
                    placeholder="Search by customer name or email...">
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
        <?php elseif ($allOrders && count($allOrders) > 0): ?>
            <div class="table-actions">
                <div class="order-count-info" id="orderCountInfo">
                    Showing page <?php echo $currentPage; ?> of <?php echo $totalPages; ?> pages (Total
                    <?php echo number_format($totalOrders); ?> orders)
                </div>
                <a href="create_new_order.php?shop=<?php echo urlencode($shop); ?>" class="create-order-btn">
                    Create New Order
                </a>
            </div>

            <div class="orders-table-container">
                <table class="orders-table" id="ordersTable">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Fulfillment</th>
                            <th>Location</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody">
                    </tbody>
                </table>
            </div>
            <div id="paginationContainer" class="pagination-container"></div>
        <?php elseif ($allOrders && count($allOrders) == 0): ?>
            <div class="table-actions">
                <div class="order-count-info" id="orderCountInfo">
                    No orders found
                </div>
                <a href="create_new_order.php?shop=<?php echo urlencode($shop); ?>" class="create-order-btn">
                    Create New Order
                </a>
            </div>
            <div class="no-orders">
                <p>No orders found in your store.</p>
                <div style="margin-top: 20px;">
                    <a href="https://<?php echo htmlspecialchars($shop); ?>/admin/orders" target="_blank"
                        class="btn-icon btn-view" style="display: inline-block;">
                        View Orders in Shopify Admin
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        var allOrders = <?php echo json_encode($allOrders); ?>;
        var ordersPerPage = <?php echo $ordersPerPage; ?>;
        var currentPage = <?php echo $currentPage; ?>;
        var filteredOrders = [];
        var currentFilteredOrders = [];

        function getFinancialStatusClass(status) {
            var statusLower = status.toLowerCase();
            if (statusLower === 'paid') return 'status-paid';
            if (statusLower === 'pending') return 'status-pending';
            if (statusLower === 'refunded') return 'status-refunded';
            if (statusLower === 'partially_paid') return 'status-partially_paid';
            if (statusLower === 'partially_refunded') return 'status-partially_refunded';
            if (statusLower === 'voided') return 'status-voided';
            return '';
        }

        function getFulfillmentStatusClass(status) {
            var statusLower = status.toLowerCase();
            if (statusLower === 'fulfilled') return 'status-fulfilled';
            if (statusLower === 'unfulfilled') return 'status-unfulfilled';
            if (statusLower === 'partially_fulfilled') return 'status-partially_fulfilled';
            if (statusLower === 'restocked') return 'status-restocked';
            return '';
        }

        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        function renderOrdersTable() {
            var tbody = document.getElementById('ordersTableBody');
            if (!tbody) return;

            tbody.innerHTML = '';

            var ordersToShow = filteredOrders.length > 0 ? filteredOrders : allOrders;
            currentFilteredOrders = ordersToShow;

            var totalOrdersCount = ordersToShow.length;
            var totalPagesCount = Math.ceil(totalOrdersCount / ordersPerPage);
            var startIndex = (currentPage - 1) * ordersPerPage;
            var endIndex = Math.min(startIndex + ordersPerPage, totalOrdersCount);
            var pageOrders = ordersToShow.slice(startIndex, endIndex);

            var countInfo = document.getElementById('orderCountInfo');
            if (countInfo) {
                if (filteredOrders.length > 0) {
                    countInfo.innerHTML = 'Showing ' + (startIndex + 1) + ' - ' + endIndex + ' of ' + totalOrdersCount + ' filtered orders';
                } else {
                    countInfo.innerHTML = 'Showing page ' + currentPage + ' of ' + totalPagesCount + ' (Total ' + totalOrdersCount + ' orders)';
                }
            }

            for (var i = 0; i < pageOrders.length; i++) {
                var o = pageOrders[i];
                var row = tbody.insertRow();
                var orderCell = row.insertCell(0);
                orderCell.innerHTML = '<a href="https://' + escapeHtml(o.shop) + '/admin/orders/' + o.id + '" target="_blank" class="order-name">' + escapeHtml(o.name) + '</a>';
                var dateCell = row.insertCell(1);
                dateCell.className = 'date-cell';
                dateCell.innerHTML = escapeHtml(o.createdAt);
                var customerCell = row.insertCell(2);
                customerCell.innerHTML = '<div class="customer-name">' + escapeHtml(o.customerName) + '</div>' +
                    '<div class="customer-email">' + escapeHtml(o.customerEmail) + '</div>';
                var itemsCell = row.insertCell(3);
                itemsCell.innerHTML = '<div class="item-summary">' + escapeHtml(o.itemSummary) + '</div>' +
                    '<div style="font-size: 11px; color: #5c5f62; margin-top: 4px;">Total: ' + o.itemCount + ' items</div>';
                var totalCell = row.insertCell(4);
                totalCell.className = 'price';
                totalCell.innerHTML = o.currency + ' ' + parseFloat(o.totalPrice).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                var paymentCell = row.insertCell(5);
                paymentCell.innerHTML = '<span class="order-status ' + getFinancialStatusClass(o.financialStatus) + '">' + escapeHtml(o.financialStatus) + '</span>';
                var fulfillmentCell = row.insertCell(6);
                fulfillmentCell.innerHTML = '<span class="order-status ' + getFulfillmentStatusClass(o.fulfillmentStatus) + '">' + escapeHtml(o.fulfillmentStatus) + '</span>';
                var locationCell = row.insertCell(7);
                locationCell.className = 'location';
                locationCell.innerHTML = escapeHtml(o.location);
                var actionsCell = row.insertCell(8);
                actionsCell.className = 'action-buttons';
                actionsCell.innerHTML = '<a href="https://' + escapeHtml(o.shop) + '/admin/orders/' + o.id + '" target="_blank" class="btn-icon btn-view">View in Shopify</a>';
            }

            renderPagination(totalPagesCount);
        }

        function renderPagination(totalPagesCount) {
            var paginationContainer = document.getElementById('paginationContainer');
            if (!paginationContainer) return;

            if (totalPagesCount <= 1) {
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
            var endPage = Math.min(totalPagesCount, currentPage + 2);

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

            if (endPage < totalPagesCount) {
                if (endPage < totalPagesCount - 1) {
                    html += '<span class="page-number disabled">...</span>';
                }
                html += '<a href="javascript:void(0)" onclick="changePage(' + totalPagesCount + ')" class="page-number">' + totalPagesCount + '</a>';
            }

            html += '</div>';

            if (currentPage < totalPagesCount) {
                html += '<a href="javascript:void(0)" onclick="changePage(' + (currentPage + 1) + ')" class="pagination-btn">Next &raquo;</a>';
            } else {
                html += '<span class="pagination-btn disabled">Next &raquo;</span>';
            }

            paginationContainer.innerHTML = html;
        }

        function changePage(page) {
            currentPage = page;
            renderOrdersTable();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function filterOrders() {
            var input = document.getElementById('searchInput');
            var filter = input.value.toLowerCase().trim();

            if (filter === '') {
                filteredOrders = [];
                currentPage = 1;
                renderOrdersTable();
                return;
            }

            filteredOrders = [];
            for (var i = 0; i < allOrders.length; i++) {
                if (allOrders[i].customerNameLower.indexOf(filter) !== -1 ||
                    allOrders[i].customerEmailLower.indexOf(filter) !== -1) {
                    filteredOrders.push(allOrders[i]);
                }
            }

            currentPage = 1;

            if (filteredOrders.length === 0) {
                var tbody = document.getElementById('ordersTableBody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 40px;">No orders match your search criteria. </td></tr>';
                }
                var paginationContainer = document.getElementById('paginationContainer');
                if (paginationContainer) {
                    paginationContainer.innerHTML = '';
                }
                var countInfo = document.getElementById('orderCountInfo');
                if (countInfo) {
                    countInfo.innerHTML = 'No orders found matching "' + escapeHtml(filter) + '"';
                }
            } else {
                renderOrdersTable();
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            renderOrdersTable();
            var searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', function () {
                    filterOrders();
                });
            }
        });
    </script>
</body>
</html>