<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';

session_start();
$shop = isset($_GET['shop']) ? $_GET['shop'] : (isset($_SESSION['shop']) ? $_SESSION['shop'] : '');
$segment_id = isset($_GET['segment_id']) ? $_GET['segment_id'] : '';
$segment_name = isset($_GET['segment_name']) ? $_GET['segment_name'] : '';

if (empty($shop)) {
    die("Shop parameter is missing");
}

if (empty($segment_id)) {
    die("Segment ID is missing");
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

$customers = array();
$error = null;
$hasNextPage = false;
$nextCursor = null;

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
    $full_segment_id = 'gid://shopify/Segment/' . $segment_id;
    
    // Fetch customers in the segment
    $customersQuery = <<<GRAPHQL
query GetSegmentCustomers(\$segmentId: ID!, \$first: Int!) {
    segment(id: \$segmentId) {
        id
        name
        customers(first: \$first) {
            edges {
                cursor
                node {
                    id
                    displayName
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
}
GRAPHQL;

    $variables = array(
        'segmentId' => $full_segment_id,
        'first' => 50
    );
    
    $result = executeGraphQLQuery($access_token, $shop, $customersQuery, $variables);
    
    if ($result['success'] && isset($result['data']['data']['segment'])) {
        $segmentData = $result['data']['data']['segment'];
        $segment_name = isset($segmentData['name']) ? $segmentData['name'] : $segment_name;
        
        if (isset($segmentData['customers']['edges'])) {
            foreach ($segmentData['customers']['edges'] as $edge) {
                $customer = $edge['node'];
                $customer_numeric_id = preg_replace('/^gid:\/\/shopify\/Customer\//', '', $customer['id']);
                
                // Build display name
                $displayName = $customer['displayName'];
                
                $ordersCount = isset($customer['numberOfOrders']) ? $customer['numberOfOrders'] : 0;
                
                $totalSpent = '0.00';
                if (isset($customer['amountSpent']) && isset($customer['amountSpent']['amount'])) {
                    $totalSpent = $customer['amountSpent']['amount'];
                }
                
                $currency = isset($customer['amountSpent']['currencyCode']) ? $customer['amountSpent']['currencyCode'] : 'USD';
                
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
                
                $customers[] = array(
                    'id' => $customer_numeric_id,
                    'displayName' => $displayName,
                    'email' => isset($customer['email']) ? $customer['email'] : 'N/A',
                    'phone' => isset($customer['phone']) ? $customer['phone'] : 'N/A',
                    'ordersCount' => $ordersCount,
                    'totalSpent' => $totalSpent,
                    'currency' => $currency,
                    'location' => $location,
                    'createdAt' => date('M d, Y', strtotime($customer['createdAt']))
                );
            }
            
            $pageInfo = $segmentData['customers']['pageInfo'];
            $hasNextPage = isset($pageInfo['hasNextPage']) ? $pageInfo['hasNextPage'] : false;
            $nextCursor = isset($pageInfo['endCursor']) ? $pageInfo['endCursor'] : null;
            
            error_log("SEGMENT CUSTOMERS: Fetched " . count($customers) . " customers for segment: " . $segment_name);
        } else {
            error_log("SEGMENT CUSTOMERS: No customers found or invalid structure");
        }
    } else {
        $error = "Failed to fetch customers for this segment.";
        if (isset($result['error'])) {
            error_log("SEGMENT CUSTOMERS ERROR: " . json_encode($result['error']));
            
            // Check for specific error types
            if (isset($result['error'][0]['message'])) {
                if (strpos($result['error'][0]['message'], 'customerCount') !== false) {
                    $error = "Customer count field not available in your API version.";
                } elseif (strpos($result['error'][0]['message'], 'Access denied') !== false) {
                    $error = "Access denied. Your app needs additional permissions to view segment customers.";
                } else {
                    $error = $result['error'][0]['message'];
                }
            }
        }
    }
} else {
    $error = "Authentication failed. Please reinstall the app.";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($segment_name); ?> - Customers - Shopify App</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/customers.css">
    <style>
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #6b7280;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
            transition: background 0.2s;
        }
        
        .back-button:hover {
            background: #4b5563;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .page-header h1 {
            margin: 0;
        }
        
        .error-message {
            background: #fde7e8;
            border-left: 4px solid #bf2b2b;
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 24px;
            color: #bf2b2b;
        }
        
        .no-customers {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e1e3e5;
        }
        
        .create-btn {
            background: #008060;
            color: white;
            padding: 10px 20px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            display: inline-block;
            transition: background 0.2s;
        }
        
        .create-btn:hover {
            background: #006e52;
        }
        
        .pagination-container {
            margin-top: 30px;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .pagination-btn {
            padding: 10px 20px;
            background: #008060;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }
        
        .pagination-btn:hover {
            background: #006e52;
        }
    </style>
</head>

<body>
    <?php renderNavigation($app_url, $shop); ?>

    <div class="content">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars($segment_name); ?></h1>
                <p class="subtitle">Customers in this segment (<?php echo count($customers); ?> customers)</p>
            </div>
            <div>
                <a href="customers.php?shop=<?php echo urlencode($shop); ?>&tab=segments" class="back-button">← Back to Segments</a>
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
            <?php if (!empty($customers)): ?>
                <div class="customers-table-container">
                    <table class="customers-table">
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
                                    <td><span class="total-spent"><?php echo $customer['currency']; ?> <?php echo number_format($customer['totalSpent'], 2); ?></span></td>
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
                
                <?php if ($hasNextPage && $nextCursor): ?>
                <div class="pagination-container">
                    <a href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/segment_customers.php?shop=<?php echo urlencode($shop); ?>&segment_id=<?php echo urlencode($segment_id); ?>&segment_name=<?php echo urlencode($segment_name); ?>&cursor=<?php echo urlencode($nextCursor); ?>" class="pagination-btn">Load More Customers &raquo;</a>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-customers">
                    <p>No customers found in this segment.</p>
                    <div style="margin-top: 20px;">
                        <a href="customers.php?shop=<?php echo urlencode($shop); ?>&tab=segments" class="create-btn">Back to Segments</a>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>

</html>