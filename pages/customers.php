<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';

session_start();
$shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : (isset($_GET['shop']) ? $_GET['shop'] : '');

if (!$shop) {
    error_log("CUSTOMERS PAGE ERROR: Shop parameter is missing");
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
    error_log("CUSTOMERS PAGE ERROR: Failed to get access token - " . $error_message);
} else {
    $access_token = $tokenResult['access_token'];
    error_log("CUSTOMERS PAGE: Access token obtained successfully for shop: " . $shop);
}

include __DIR__ . '/../includes/navigation.php';
$api_version = '2026-04';

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'customers';
$customersPerPage = 20;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$cursor = isset($_GET['cursor']) ? $_GET['cursor'] : null;
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';
$error = null;
$customers = array();
$totalCustomers = 0;
$segments = array();
$hasNextPage = false;
$hasPreviousPage = false;
$nextCursor = null;
$previousCursor = null;

// Function to execute GraphQL queries
function executeGraphQLQuery($access_token, $shop, $query, $variables = null) {
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log("GRAPHQL ERROR: CURL Error - " . $curl_error);
        return array('success' => false, 'error' => "CURL Error: " . $curl_error);
    }

    if ($http_code !== 200) {
        error_log("GRAPHQL ERROR: HTTP Error Code - " . $http_code);
        return array('success' => false, 'error' => "HTTP Error: " . $http_code);
    }

    $response = json_decode($result, true);
    if (isset($response['errors'])) {
        error_log("GRAPHQL ERROR: GraphQL Errors - " . json_encode($response['errors']));
        return array('success' => false, 'error' => $response['errors']);
    }

    return array('success' => true, 'data' => $response);
}

if ($access_token) {
    if ($active_tab == 'customers') {
        // Get total customers count
        $countQuery = <<<GRAPHQL
query CountCustomers {
  customersCount {
    count
  }
}
GRAPHQL;

        $countResult = executeGraphQLQuery($access_token, $shop, $countQuery);
        
        if ($countResult['success'] && isset($countResult['data']['data']['customersCount']['count'])) {
            $totalCustomers = $countResult['data']['data']['customersCount']['count'];
            error_log("CUSTOMERS: Total customers count = " . $totalCustomers);
        }
        
        // Build customers query with cursor-based pagination
        $first = $customersPerPage;
        $last = null;
        $before = null;
        $after = $cursor;
        
        if ($currentPage > 1 && isset($_GET['previous_cursor'])) {
            $last = $customersPerPage;
            $before = $_GET['previous_cursor'];
            $after = null;
        }
        
        $customersQuery = '';
        $customersVariables = array();
        
        if (!empty($searchQuery)) {
            $customersQuery = <<<GRAPHQL
query SearchCustomers(\$query: String!, \$first: Int!) {
  customers(first: \$first, query: \$query) {
    edges {
      cursor
      node {
        id
        firstName
        lastName
        email
        phone
        createdAt
        numberOfOrders
        amountSpent {
          amount
          currencyCode
        }
        defaultAddress {
          city
          country
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
            $customersVariables = array(
                'query' => $searchQuery,
                'first' => $customersPerPage
            );
        } else {
            if ($after) {
                $customersQuery = <<<GRAPHQL
query GetCustomers(\$first: Int!, \$after: String!) {
  customers(first: \$first, after: \$after) {
    edges {
      cursor
      node {
        id
        firstName
        lastName
        email
        phone
        createdAt
        numberOfOrders
        amountSpent {
          amount
          currencyCode
        }
        defaultAddress {
          city
          country
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
                $customersVariables = array(
                    'first' => $first,
                    'after' => $after
                );
            } elseif ($before) {
                $customersQuery = <<<GRAPHQL
query GetCustomers(\$last: Int!, \$before: String!) {
  customers(last: \$last, before: \$before) {
    edges {
      cursor
      node {
        id
        firstName
        lastName
        email
        phone
        createdAt
        numberOfOrders
        amountSpent {
          amount
          currencyCode
        }
        defaultAddress {
          city
          country
        }
      }
    }
    pageInfo {
      hasPreviousPage
      startCursor
    }
  }
}
GRAPHQL;
                $customersVariables = array(
                    'last' => $last,
                    'before' => $before
                );
            } else {
                $customersQuery = <<<GRAPHQL
query GetCustomers(\$first: Int!) {
  customers(first: \$first) {
    edges {
      cursor
      node {
        id
        firstName
        lastName
        email
        phone
        createdAt
        numberOfOrders
        amountSpent {
          amount
          currencyCode
        }
        defaultAddress {
          city
          country
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
                $customersVariables = array('first' => $first);
            }
        }
        
        $customersResult = executeGraphQLQuery($access_token, $shop, $customersQuery, $customersVariables);
        
        if ($customersResult['success'] && isset($customersResult['data']['data']['customers']['edges'])) {
            $edges = $customersResult['data']['data']['customers']['edges'];
            $pageInfo = $customersResult['data']['data']['customers']['pageInfo'];
            
            $allCustomers = array();
            $cursors = array();
            
            foreach ($edges as $edge) {
                $customer = $edge['node'];
                $customer_numeric_id = preg_replace('/^gid:\/\/shopify\/Customer\//', '', $customer['id']);
                
                $displayName = '';
                if (!empty($customer['firstName'])) {
                    $displayName = $customer['firstName'];
                    if (!empty($customer['lastName'])) {
                        $displayName .= ' ' . $customer['lastName'];
                    }
                } elseif (!empty($customer['lastName'])) {
                    $displayName = $customer['lastName'];
                } else {
                    $displayName = 'Anonymous Customer';
                }
                
                $ordersCount = isset($customer['numberOfOrders']) ? $customer['numberOfOrders'] : 0;
                
                $totalSpent = '0.00';
                if (isset($customer['amountSpent']) && isset($customer['amountSpent']['amount'])) {
                    $totalSpent = $customer['amountSpent']['amount'];
                }
                
                $location = '';
                if (isset($customer['defaultAddress'])) {
                    $locationParts = array();
                    if (!empty($customer['defaultAddress']['city'])) {
                        $locationParts[] = $customer['defaultAddress']['city'];
                    }
                    if (!empty($customer['defaultAddress']['country'])) {
                        $locationParts[] = $customer['defaultAddress']['country'];
                    }
                    $location = implode(', ', $locationParts);
                }
                
                $allCustomers[] = array(
                    'id' => $customer_numeric_id,
                    'displayName' => $displayName,
                    'email' => isset($customer['email']) ? $customer['email'] : 'N/A',
                    'phone' => isset($customer['phone']) ? $customer['phone'] : 'N/A',
                    'ordersCount' => $ordersCount,
                    'totalSpent' => $totalSpent,
                    'location' => $location,
                    'createdAt' => date('M d, Y', strtotime($customer['createdAt'])),
                    'cursor' => $edge['cursor']
                );
                
                $cursors[] = $edge['cursor'];
            }
            
            $customers = $allCustomers;
            
            if (!empty($searchQuery)) {
                $hasNextPage = isset($pageInfo['hasNextPage']) ? $pageInfo['hasNextPage'] : false;
                $nextCursor = isset($pageInfo['endCursor']) ? $pageInfo['endCursor'] : null;
            } else {
                if ($after) {
                    $hasNextPage = isset($pageInfo['hasNextPage']) ? $pageInfo['hasNextPage'] : false;
                    $nextCursor = isset($pageInfo['endCursor']) ? $pageInfo['endCursor'] : null;
                    $hasPreviousPage = ($currentPage > 1);
                    if ($hasPreviousPage && count($cursors) > 0) {
                        $previousCursor = $cursors[0];
                    }
                } elseif ($before) {
                    $hasPreviousPage = isset($pageInfo['hasPreviousPage']) ? $pageInfo['hasPreviousPage'] : false;
                    $hasNextPage = true;
                    if (count($cursors) > 0) {
                        $nextCursor = $cursors[count($cursors) - 1];
                    }
                } else {
                    $hasNextPage = isset($pageInfo['hasNextPage']) ? $pageInfo['hasNextPage'] : false;
                    $nextCursor = isset($pageInfo['endCursor']) ? $pageInfo['endCursor'] : null;
                    $hasPreviousPage = false;
                    if (count($cursors) > 0) {
                        $previousCursor = $cursors[0];
                    }
                }
            }
            
            error_log("CUSTOMERS: Fetched " . count($customers) . " customers for page " . $currentPage);
            
        } else {
            error_log("CUSTOMERS ERROR: Failed to fetch customers list");
            if (isset($customersResult['error'])) {
                $error_detail = json_encode($customersResult['error']);
                error_log("CUSTOMERS ERROR DETAIL: " . $error_detail);
                $error = "Failed to fetch customers. Please check your API connection.";
            } else {
                $error = "Failed to fetch customers. Please check your API connection.";
            }
        }
    } elseif ($active_tab == 'segments') {
        // Fetch customer segments
        error_log("SEGMENTS: Fetching customer segments via GraphQL");
        
        // First, try to check if segments query is available
        $testQuery = <<<GRAPHQL
query {
  __type(name: "Segment") {
    name
    fields {
      name
    }
  }
}
GRAPHQL;
        
        $testResult = executeGraphQLQuery($access_token, $shop, $testQuery);
        if ($testResult['success']) {
            error_log("SEGMENTS: Segment type exists in schema");
        } else {
            error_log("SEGMENTS: Segment type may not be available");
        }
        
        $segmentsQuery = <<<GRAPHQL
query getSegments(\$cursor: String) {
    segments(first: 250, after: \$cursor) {
        edges {
            node {
                id
                name
                creationDate
            }
        }
        pageInfo {
            hasNextPage
            endCursor
        }
    }
}
GRAPHQL;

        $cursor = null;
        $hasNextPageSegments = true;
        $segmentPageGuard = 0;
        $allSegments = array();

        while ($hasNextPageSegments) {
            $segmentPageGuard++;
            if ($segmentPageGuard > 50) {
                error_log("SEGMENTS: Pagination guard hit (50 pages). Stopping fetch.");
                break;
            }

            $variables = array('cursor' => $cursor);
            $segmentsResult = executeGraphQLQuery($access_token, $shop, $segmentsQuery, $variables);

            if ($segmentsResult['success'] && isset($segmentsResult['data']['data']['segments']['edges'])) {
                $edges = $segmentsResult['data']['data']['segments']['edges'];
                error_log("SEGMENTS: Fetched " . count($edges) . " segments in current page");
                error_log("SEGMENTS: Full response: " . json_encode($segmentsResult['data']['data']['segments']));

                foreach ($edges as $edge) {
                    if (isset($edge['node'])) {
                        $segment = $edge['node'];
                        $segment_id = preg_replace('/^gid:\/\/shopify\/Segment\//', '', $segment['id']);
                        
                        $allSegments[] = array(
                            'id' => $segment_id,
                            'name' => $segment['name'],
                            'creationDate' => isset($segment['creationDate']) ? date('M d, Y', strtotime($segment['creationDate'])) : 'N/A'
                        );
                        error_log("SEGMENTS: Added segment - ID: " . $segment_id . ", Name: " . $segment['name']);
                    }
                }

                $pageInfo = $segmentsResult['data']['data']['segments']['pageInfo'];
                $hasNextPageSegments = isset($pageInfo['hasNextPage']) ? $pageInfo['hasNextPage'] : false;
                $cursor = isset($pageInfo['endCursor']) ? $pageInfo['endCursor'] : null;

                error_log("SEGMENTS: hasNextPage = " . ($hasNextPageSegments ? 'true' : 'false'));
            } else {
                error_log("SEGMENTS ERROR: Failed to fetch segments");
                if (isset($segmentsResult['error'])) {
                    $error_detail = json_encode($segmentsResult['error']);
                    error_log("SEGMENTS ERROR DETAIL: " . $error_detail);
                    
                    if (strpos($error_detail, 'Access denied') !== false) {
                        $error = "Access denied. Your app may need additional permissions to view customer segments.";
                    } elseif (strpos($error_detail, 'segment') !== false) {
                        $error = "Unable to fetch segments. This feature may require Shopify Plus or additional API permissions.";
                    } else {
                        $error = "Failed to fetch customer segments: " . substr($error_detail, 0, 200);
                    }
                } else {
                    $error = "No response from segments query. This feature may not be available in your Shopify plan.";
                }
                $hasNextPageSegments = false;
            }
        }

        $segments = $allSegments;
        error_log("SEGMENTS: Successfully fetched total " . count($segments) . " segments");

        if (empty($segments) && !$error) {
            $error = "No customer segments found. Segments can be created in the Shopify Admin panel. Note: Customer Segments may require Shopify Plus.";
        }
    }
}

$totalPages = 0;
if ($totalCustomers > 0) {
    $totalPages = ceil($totalCustomers / $customersPerPage);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - Shopify App</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/customers.css">
</head>

<body>
    <?php renderNavigation($app_url, $shop); ?>

    <div class="content">
        <div class="page-header">
            <div>
                <h1>Customers</h1>
                <p class="subtitle">Manage your customer base and segments</p>
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
        <?php else: ?>
            <div class="tabs">
                <a href="?shop=<?php echo urlencode($shop); ?>&tab=customers"
                    class="tab-link <?php echo $active_tab == 'customers' ? 'active' : ''; ?>">
                    Customers
                </a>
                <a href="?shop=<?php echo urlencode($shop); ?>&tab=segments"
                    class="tab-link <?php echo $active_tab == 'segments' ? 'active' : ''; ?>">
                    Customer Segments
                </a>
            </div>

            <?php if ($active_tab == 'customers'): ?>
                <div class="table-header">
                    <div class="customer-count-info">
                        <?php if (!empty($searchQuery)): ?>
                            Showing search results for "<strong><?php echo htmlspecialchars($searchQuery); ?></strong>"
                            (<?php echo number_format($totalCustomers); ?> total customers)
                        <?php else: ?>
                            Total <?php echo number_format($totalCustomers); ?> customers
                        <?php endif; ?>
                    </div>
                    <div class="search-box">
                        <form method="GET" action="" style="display: flex; gap: 10px;">
                            <input type="hidden" name="shop" value="<?php echo urlencode($shop); ?>">
                            <input type="hidden" name="tab" value="customers">
                            <input type="text" name="search" id="searchInput" class="search-input"
                                placeholder="Search by name or email..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                            <button type="submit" class="search-btn">Search</button>
                            <?php if (!empty($searchQuery)): ?>
                                <a href="?shop=<?php echo urlencode($shop); ?>&tab=customers" class="clear-btn">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <?php if (!empty($customers)): ?>
                    <div class="customers-table-container">
                        <table class="customers-table" id="customersTable">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Location</th>
                                    <th>Orders</th>
                                    <th>Total Spent</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td class="customer-name-cell">
                                        <div class="customer-name"><?php echo htmlspecialchars($customer['displayName']); ?></div>
                                    </td>
                                    <td>
                                        <div class="customer-email"><?php echo htmlspecialchars($customer['email']); ?></div>
                                        <?php if ($customer['phone'] != 'N/A'): ?>
                                            <div class="customer-phone"><?php echo htmlspecialchars($customer['phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($customer['location']) ?: '—'; ?></td>
                                    <td><span class="orders-count"><?php echo $customer['ordersCount']; ?></span></td>
                                    <td><span class="total-spent">$<?php echo number_format($customer['totalSpent'], 2); ?></span></td>
                                    <td><?php echo $customer['createdAt']; ?></td>
                                    <td class="actions-cell">
                                        <div class="action-buttons">
                                            <a href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/view_customer.php?shop=<?php echo urlencode($shop); ?>&customer_id=<?php echo $customer['id']; ?>" class="btn-icon btn-view">View</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="pagination-container">
                        <?php if ($hasPreviousPage && !empty($searchQuery)): ?>
                            <a href="?shop=<?php echo urlencode($shop); ?>&tab=customers&page=<?php echo $currentPage - 1; ?>&search=<?php echo urlencode($searchQuery); ?>" class="pagination-btn">&laquo; Previous</a>
                        <?php elseif ($hasPreviousPage && $previousCursor): ?>
                            <a href="?shop=<?php echo urlencode($shop); ?>&tab=customers&page=<?php echo $currentPage - 1; ?>&previous_cursor=<?php echo urlencode($previousCursor); ?>&cursor=<?php echo urlencode($previousCursor); ?>" class="pagination-btn">&laquo; Previous</a>
                        <?php elseif ($currentPage > 1 && empty($searchQuery)): ?>
                            <a href="?shop=<?php echo urlencode($shop); ?>&tab=customers&page=<?php echo $currentPage - 1; ?>" class="pagination-btn">&laquo; Previous</a>
                        <?php else: ?>
                            <span class="pagination-btn disabled">&laquo; Previous</span>
                        <?php endif; ?>
                        
                        <div class="page-numbers">
                            <?php if ($totalPages > 0 && empty($searchQuery)): ?>
                                <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                                    <?php if ($i == $currentPage): ?>
                                        <span class="page-number active"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="?shop=<?php echo urlencode($shop); ?>&tab=customers&page=<?php echo $i; ?>" class="page-number"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            <?php else: ?>
                                <span class="page-number active">1</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($hasNextPage && !empty($searchQuery)): ?>
                            <a href="?shop=<?php echo urlencode($shop); ?>&tab=customers&page=<?php echo $currentPage + 1; ?>&search=<?php echo urlencode($searchQuery); ?>" class="pagination-btn">Next &raquo;</a>
                        <?php elseif ($hasNextPage && $nextCursor): ?>
                            <a href="?shop=<?php echo urlencode($shop); ?>&tab=customers&page=<?php echo $currentPage + 1; ?>&cursor=<?php echo urlencode($nextCursor); ?>" class="pagination-btn">Next &raquo;</a>
                        <?php else: ?>
                            <span class="pagination-btn disabled">Next &raquo;</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="no-customers">
                        <?php if (!empty($searchQuery)): ?>
                            <p>No customers found matching "<strong><?php echo htmlspecialchars($searchQuery); ?></strong>".</p>
                            <div style="margin-top: 20px;">
                                <a href="?shop=<?php echo urlencode($shop); ?>&tab=customers" class="create-btn">Clear Search</a>
                            </div>
                        <?php else: ?>
                            <p>No customers found in your store.</p>
                            <div style="margin-top: 20px;">
                                <a href="https://<?php echo htmlspecialchars($shop); ?>/admin/customers/new" target="_blank" class="create-btn">Create Your First Customer</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
            <?php elseif ($active_tab == 'segments'): ?>
                <!-- Customer Segments Table -->
                <div class="table-header">
                    <div class="customer-count-info">
                        <?php 
                        if (!empty($segments)) {
                            echo count($segments) . " customer segments found";
                        } else {
                            echo "Customer Segments";
                        }
                        ?>
                        <?php if ($error && strpos($error, 'No customer segments') !== false): ?>
                            <span style="color: #b98900; margin-left: 10px;">
                                <a href="https://<?php echo htmlspecialchars($shop); ?>/admin/customers/segments" target="_blank" style="color: #008060;">Create segments in Shopify Admin →</a>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($segments)): ?>
                    <div class="customers-table-container">
                        <table class="customers-table segments-table">
                            <thead>
                                <tr>
                                    <th>Segment Name</th>
                                    <th>Created Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($segments as $segment): ?>
                                    <tr class="segment-row" data-segment-id="<?php echo htmlspecialchars($segment['id']); ?>"
                                        data-segment-name="<?php echo htmlspecialchars($segment['name']); ?>">
                                        <td class="segment-name-cell" data-label="Segment Name">
                                            <strong><?php echo htmlspecialchars($segment['name']); ?></strong>
                                        </td>
                                        <td data-label="Created Date"><?php echo htmlspecialchars($segment['creationDate']); ?></td>
                                        <td class="actions-cell" data-label="Actions">
                                            <div class="action-buttons">
                                                <a href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/segment_customers.php?shop=<?php echo urlencode($shop); ?>&segment_id=<?php echo urlencode($segment['id']); ?>&segment_name=<?php echo urlencode($segment['name']); ?>"
                                                    class="btn-icon btn-view">View Customers</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-segments">
                        <p>No customer segments found.</p>
                        <?php if ($error && strpos($error, 'No customer segments') === false): ?>
                            <p style="color: #d82c0d; margin-top: 10px; font-size: 13px;">
                                <strong>Note:</strong> <?php echo htmlspecialchars($error); ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!$error || strpos($error, 'No customer segments') !== false): ?>
                            <p style="margin-top: 10px; font-size: 13px; color: #5c5f62;">
                                Customer segments allow you to group customers based on their behavior and attributes.
                                This feature may require Shopify Plus.
                            </p>
                        <?php endif; ?>
                        <div style="margin-top: 20px;">
                            <a href="https://<?php echo htmlspecialchars($shop); ?>/admin/customers/segments" target="_blank"
                                class="create-btn">Create Customer Segment in Shopify Admin</a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <style>
        .search-btn, .clear-btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-block;
        }
        
        .search-btn {
            background: #008060;
            color: white;
        }
        
        .search-btn:hover {
            background: #006e52;
        }
        
        .clear-btn {
            background: #6b7280;
            color: white;
        }
        
        .clear-btn:hover {
            background: #4b5563;
        }
        
        .search-box form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .segments-table tbody tr {
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .segments-table tbody tr:hover {
            background: #fafbfb;
        }
        
        .segment-name-cell strong {
            color: #202223;
        }
        
        .no-segments {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e1e3e5;
        }
        
        @media (max-width: 768px) {
            .search-box form {
                flex-direction: column;
                width: 100%;
            }
            
            .search-input {
                width: 100%;
            }
            
            .segments-table,
            .segments-table thead,
            .segments-table tbody,
            .segments-table tr,
            .segments-table td {
                display: block;
            }
            
            .segments-table thead tr {
                display: none;
            }
            
            .segments-table tr {
                margin-bottom: 15px;
                border: 1px solid #e1e3e5;
                border-radius: 8px;
                padding: 10px;
            }
            
            .segments-table td {
                padding: 8px;
                text-align: left;
                border-bottom: none;
            }
            
            .segments-table td:before {
                content: attr(data-label);
                font-weight: 600;
                display: inline-block;
                width: 120px;
            }
        }
    </style>

    <script>
        // Make segment rows clickable
        document.addEventListener('DOMContentLoaded', function() {
            var segmentRows = document.querySelectorAll('.segment-row');
            for (var i = 0; i < segmentRows.length; i++) {
                segmentRows[i].addEventListener('click', function(e) {
                    if (e.target.tagName === 'A' || e.target.classList.contains('btn-view')) {
                        return;
                    }
                    var segmentId = this.getAttribute('data-segment-id');
                    var segmentName = this.getAttribute('data-segment-name');
                    if (segmentId) {
                        window.location.href = '<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/segment_customers.php?shop=<?php echo urlencode($shop); ?>&segment_id=' + encodeURIComponent(segmentId) + '&segment_name=' + encodeURIComponent(segmentName);
                    }
                });
            }
        });
    </script>
</body>

</html>