<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';

session_start();
$shop = isset($_GET['shop']) ? $_GET['shop'] : (isset($_SESSION['shop']) ? $_SESSION['shop'] : '');
$customer_id = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';

if (empty($shop)) {
    die("Shop parameter is missing");
}

if (empty($customer_id)) {
    die("Customer ID is missing");
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

$customer = null;
$orders = array();
$error = null;
$addresses = array();

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
    $full_customer_id = 'gid://shopify/Customer/' . $customer_id;
    $customerQuery = <<<GRAPHQL
query GetCustomerDetails(\$customerId: ID!) {
  customer(id: \$customerId) {
    id
    firstName
    lastName
    email
    phone
    createdAt
    updatedAt
    emailMarketingConsent {
      marketingState
    }
    taxExempt
    numberOfOrders
    amountSpent {
      amount
      currencyCode
    }
    defaultAddress {
      id
      address1
      address2
      city
      province
      provinceCode
      country
      countryCodeV2
      zip
      phone
    }
    addresses(first: 20) {
      address1
      address2
      city
      province
      provinceCode
      country
      countryCodeV2
      zip
      phone
    }
    orders(first: 50, sortKey: CREATED_AT, reverse: true) {
      edges {
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
              currencyCode
            }
          }
          totalTaxSet {
            shopMoney {
              amount
              currencyCode
            }
          }
          lineItems(first: 10) {
            edges {
              node {
                title
                quantity
                originalTotalSet {
                  shopMoney {
                    amount
                    currencyCode
                  }
                }
              }
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

    $variables = array('customerId' => $full_customer_id);
    $result = executeGraphQLQuery($access_token, $shop, $customerQuery, $variables);
    
    if ($result['success'] && isset($result['data']['data']['customer'])) {
        $customerData = $result['data']['data']['customer'];
        
        // Build display name
        $displayName = '';
        if (!empty($customerData['firstName'])) {
            $displayName = $customerData['firstName'];
            if (!empty($customerData['lastName'])) {
                $displayName .= ' ' . $customerData['lastName'];
            }
        } elseif (!empty($customerData['lastName'])) {
            $displayName = $customerData['lastName'];
        } else {
            $displayName = 'Anonymous Customer';
        }
        
        // Get marketing consent
        $acceptsMarketing = false;
        if (isset($customerData['emailMarketingConsent']['marketingState'])) {
            $acceptsMarketing = ($customerData['emailMarketingConsent']['marketingState'] === 'SUBSCRIBED');
        }
        
        // Extract addresses - Now directly an array, not with edges
        $addressList = array();
        if (isset($customerData['addresses']) && is_array($customerData['addresses'])) {
            foreach ($customerData['addresses'] as $addr) {
                if (!empty($addr['address1']) || !empty($addr['city'])) {
                    $addressList[] = $addr;
                }
            }
        }
        
        // Extract orders
        $orderList = array();
        if (isset($customerData['orders']['edges'])) {
            foreach ($customerData['orders']['edges'] as $orderEdge) {
                $order = $orderEdge['node'];
                $order_numeric_id = preg_replace('/^gid:\/\/shopify\/Order\//', '', $order['id']);
                
                // Get total price
                $totalPrice = '0.00';
                if (isset($order['totalPriceSet']['shopMoney']['amount'])) {
                    $totalPrice = $order['totalPriceSet']['shopMoney']['amount'];
                }
                
                // Get currency
                $currency = 'USD';
                if (isset($order['totalPriceSet']['shopMoney']['currencyCode'])) {
                    $currency = $order['totalPriceSet']['shopMoney']['currencyCode'];
                }
                
                // Get line items
                $lineItems = array();
                if (isset($order['lineItems']['edges'])) {
                    foreach ($order['lineItems']['edges'] as $itemEdge) {
                        $item = $itemEdge['node'];
                        $lineItems[] = array(
                            'title' => $item['title'],
                            'quantity' => $item['quantity'],
                            'total' => isset($item['originalTotalSet']['shopMoney']['amount']) ? $item['originalTotalSet']['shopMoney']['amount'] : '0.00'
                        );
                    }
                }
                
                $orderList[] = array(
                    'id' => $order_numeric_id,
                    'name' => $order['name'],
                    'createdAt' => date('M d, Y H:i', strtotime($order['createdAt'])),
                    'financialStatus' => isset($order['displayFinancialStatus']) ? ucfirst(strtolower($order['displayFinancialStatus'])) : 'Pending',
                    'fulfillmentStatus' => isset($order['displayFulfillmentStatus']) ? ucfirst(strtolower($order['displayFulfillmentStatus'])) : 'Unfulfilled',
                    'totalPrice' => $totalPrice,
                    'currency' => $currency,
                    'subtotalPrice' => isset($order['subtotalPriceSet']['shopMoney']['amount']) ? $order['subtotalPriceSet']['shopMoney']['amount'] : '0.00',
                    'totalTax' => isset($order['totalTaxSet']['shopMoney']['amount']) ? $order['totalTaxSet']['shopMoney']['amount'] : '0.00',
                    'lineItems' => $lineItems
                );
            }
        }
        
        $customer = array(
            'id' => $customer_id,
            'displayName' => $displayName,
            'firstName' => isset($customerData['firstName']) ? $customerData['firstName'] : '',
            'lastName' => isset($customerData['lastName']) ? $customerData['lastName'] : '',
            'email' => isset($customerData['email']) ? $customerData['email'] : 'N/A',
            'phone' => isset($customerData['phone']) ? $customerData['phone'] : 'N/A',
            'emailMarketingConsent' => isset($customerData['emailMarketingConsent']['marketingState']) ? $customerData['emailMarketingConsent']['marketingState'] : 'NOT_SUBSCRIBED',
            'acceptsMarketing' => $acceptsMarketing,
            'taxExempt' => isset($customerData['taxExempt']) ? $customerData['taxExempt'] : false,
            'numberOfOrders' => isset($customerData['numberOfOrders']) ? $customerData['numberOfOrders'] : 0,
            'amountSpent' => isset($customerData['amountSpent']['amount']) ? $customerData['amountSpent']['amount'] : '0.00',
            'currency' => isset($customerData['amountSpent']['currencyCode']) ? $customerData['amountSpent']['currencyCode'] : 'USD',
            'createdAt' => date('F d, Y', strtotime($customerData['createdAt'])),
            'updatedAt' => date('F d, Y', strtotime($customerData['updatedAt'])),
            'defaultAddress' => isset($customerData['defaultAddress']) ? $customerData['defaultAddress'] : null,
            'addresses' => $addressList,
            'orders' => $orderList
        );
        
    } else {
        $error = "Customer not found or unable to fetch customer data.";
        if (isset($result['error'])) {
            error_log("VIEW CUSTOMER ERROR: " . json_encode($result['error']));
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
    <title><?php echo $customer ? htmlspecialchars($customer['displayName']) : 'Customer Details'; ?> - Shopify App</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/customers.css">
    <style>
        /* Additional inline styles for better display */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #008060;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
            border: none;
            cursor: pointer;
        }
        
        .back-button:hover {
            background: #006e52;
        }
        
        .orders-table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .orders-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .orders-table th,
        .orders-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e1e3e5;
        }
        
        .orders-table th {
            background: #fafbfb;
            font-weight: 600;
            color: #5c5f62;
            font-size: 13px;
        }
        
        .order-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-paid, .status-paidth, .status-partially_paid, .status-paidth_partially {
            background: #e6f7f0;
            color: #008060;
        }
        
        .status-pending {
            background: #fff4e5;
            color: #b98900;
        }
        
        .status-refunded, .status-partially_refunded {
            background: #fde7e8;
            color: #bf2b2b;
        }
        
        .status-fulfilled {
            background: #e6f7f0;
            color: #008060;
        }
        
        .status-unfulfilled, .status-partially_fulfilled {
            background: #fff4e5;
            color: #b98900;
        }
        
        .address-card {
            background: #fafbfb;
            border: 1px solid #e1e3e5;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .address-card p {
            margin: 5px 0;
            font-size: 14px;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            margin-left: 8px;
        }
        
        .badge-success {
            background: #e6f7f0;
            color: #008060;
        }
        
        .badge-warning {
            background: #fff4e5;
            color: #b98900;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #202223;
            margin: 24px 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e1e3e5;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .info-card {
            background: #fafbfb;
            border: 1px solid #e1e3e5;
            border-radius: 8px;
            padding: 20px;
        }
        
        .info-label {
            font-size: 12px;
            color: #5c5f62;
            margin-bottom: 4px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 500;
            color: #202223;
        }
        
        .no-orders {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e1e3e5;
            margin-top: 20px;
        }
        
        .error-message {
            background: #fde7e8;
            border-left: 4px solid #bf2b2b;
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 24px;
            color: #bf2b2b;
        }
        
        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .orders-table-container {
                font-size: 12px;
            }
            
            .orders-table th,
            .orders-table td {
                padding: 8px;
            }
        }
    </style>
</head>

<body>
    <?php renderNavigation($app_url, $shop); ?>
    
    <div class="content">
        <?php if ($error): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                <div style="margin-top: 20px;">
                    <a href="customers.php?shop=<?php echo urlencode($shop); ?>" class="back-button">Back to Customers</a>
                </div>
            </div>
        <?php elseif ($customer): ?>
            <a href="customers.php?shop=<?php echo urlencode($shop); ?>" class="back-button">Back to Customers</a>
            
            <div class="page-header">
                <h1><?php echo htmlspecialchars($customer['displayName']); ?></h1>
                <p class="subtitle">Customer ID: <?php echo htmlspecialchars($customer['id']); ?></p>
            </div>
            
            <!-- Customer Information Section -->
            <div class="section-title">Customer Information</div>
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-label">Email</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($customer['email']); ?>
                        <?php if ($customer['emailMarketingConsent'] === 'SUBSCRIBED'): ?>
                            <span class="badge badge-success">Subscribed</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-label">Phone</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['phone']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Total Orders</div>
                    <div class="info-value"><?php echo number_format($customer['numberOfOrders']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Total Spent</div>
                    <div class="info-value"><?php echo $customer['currency']; ?> <?php echo number_format($customer['amountSpent'], 2); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Accepts Marketing</div>
                    <div class="info-value">
                        <?php echo $customer['acceptsMarketing'] ? 'Yes' : 'No'; ?>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-label">Tax Exempt</div>
                    <div class="info-value">
                        <?php echo $customer['taxExempt'] ? 'Yes' : 'No'; ?>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-label">Customer Since</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['createdAt']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Last Updated</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['updatedAt']); ?></div>
                </div>
            </div>
            
            <!-- Default Address Section -->
            <?php if ($customer['defaultAddress'] && (!empty($customer['defaultAddress']['address1']) || !empty($customer['defaultAddress']['city']))): ?>
            <div class="section-title">Default Address</div>
            <div class="address-card">
                <?php
                $addr = $customer['defaultAddress'];
                $addressLines = array();
                if (!empty($addr['address1'])) $addressLines[] = $addr['address1'];
                if (!empty($addr['address2'])) $addressLines[] = $addr['address2'];
                if (!empty($addr['city'])) $addressLines[] = $addr['city'];
                if (!empty($addr['province'])) $addressLines[] = $addr['province'];
                if (!empty($addr['zip'])) $addressLines[] = $addr['zip'];
                if (!empty($addr['country'])) $addressLines[] = $addr['country'];
                ?>
                <?php if (!empty($addressLines)): ?>
                    <p><?php echo implode(', ', $addressLines); ?></p>
                <?php else: ?>
                    <p>No address details available</p>
                <?php endif; ?>
                <?php if (!empty($addr['phone'])): ?>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($addr['phone']); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- All Addresses Section -->
            <?php if (!empty($customer['addresses'])): ?>
            <div class="section-title">All Addresses (<?php echo count($customer['addresses']); ?>)</div>
            <div class="info-grid">
                <?php foreach ($customer['addresses'] as $addr): ?>
                <div class="address-card">
                    <?php
                    $addressLines = array();
                    if (!empty($addr['address1'])) $addressLines[] = $addr['address1'];
                    if (!empty($addr['address2'])) $addressLines[] = $addr['address2'];
                    if (!empty($addr['city'])) $addressLines[] = $addr['city'];
                    if (!empty($addr['province'])) $addressLines[] = $addr['province'];
                    if (!empty($addr['zip'])) $addressLines[] = $addr['zip'];
                    if (!empty($addr['country'])) $addressLines[] = $addr['country'];
                    ?>
                    <p><?php echo implode(', ', $addressLines); ?></p>
                    <?php if (!empty($addr['phone'])): ?>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($addr['phone']); ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Orders Section -->
            <div class="section-title">Order History (<?php echo count($customer['orders']); ?> orders)</div>
            
            <?php if (!empty($customer['orders'])): ?>
                <div class="orders-table-container">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Payment Status</th>
                                <th>Fulfillment Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customer['orders'] as $order): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($order['name']); ?></strong>
                                 </td>
                                 <td><?php echo htmlspecialchars($order['createdAt']); ?></td>
                                 <td>
                                    <?php 
                                    $itemCount = count($order['lineItems']);
                                    $itemSummary = array();
                                    foreach ($order['lineItems'] as $item) {
                                        $itemSummary[] = $item['quantity'] . 'x ' . htmlspecialchars($item['title']);
                                    }
                                    echo implode('<br>', array_slice($itemSummary, 0, 3));
                                    if (count($itemSummary) > 3) {
                                        echo '<br>+' . (count($itemSummary) - 3) . ' more items';
                                    }
                                    ?>
                                 </td>
                                 <td>
                                    <?php echo $order['currency']; ?> <?php echo number_format($order['totalPrice'], 2); ?>
                                    <br>
                                    <small style="color: #5c5f62;">
                                        (Subtotal: <?php echo number_format($order['subtotalPrice'], 2); ?>
                                        <br>Tax: <?php echo number_format($order['totalTax'], 2); ?>)
                                    </small>
                                 </td>
                                 <td>
                                    <span class="order-status status-<?php echo str_replace(' ', '_', strtolower($order['financialStatus'])); ?>">
                                        <?php echo htmlspecialchars($order['financialStatus']); ?>
                                    </span>
                                 </td>
                                 <td>
                                    <span class="order-status status-<?php echo str_replace(' ', '_', strtolower($order['fulfillmentStatus'])); ?>">
                                        <?php echo htmlspecialchars($order['fulfillmentStatus']); ?>
                                    </span>
                                 </td>
                             </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (count($customer['orders']) == 50): ?>
                <p style="margin-top: 10px; text-align: center; color: #5c5f62; font-size: 13px;">
                    Showing last 50 orders. <a href="https://<?php echo htmlspecialchars($shop); ?>/admin/customers/<?php echo $customer_id; ?>" target="_blank">View all in Shopify Admin</a>
                </p>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-orders">
                    <p>No orders found for this customer.</p>
                </div>
            <?php endif; ?>
            
        <?php endif; ?>
    </div>
</body>
</html>