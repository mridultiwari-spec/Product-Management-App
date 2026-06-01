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
function executeGraphQLQuery($access_token, $shop, $query, $variables = null)
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
        $acceptsMarketing = false;
        if (isset($customerData['emailMarketingConsent']['marketingState'])) {
            $acceptsMarketing = ($customerData['emailMarketingConsent']['marketingState'] === 'SUBSCRIBED');
        }
        $addressList = array();
        if (isset($customerData['addresses']) && is_array($customerData['addresses'])) {
            foreach ($customerData['addresses'] as $addr) {
                if (!empty($addr['address1']) || !empty($addr['city'])) {
                    $addressList[] = $addr;
                }
            }
        }
        $orderList = array();
        if (isset($customerData['orders']['edges'])) {
            foreach ($customerData['orders']['edges'] as $orderEdge) {
                $order = $orderEdge['node'];
                $order_numeric_id = preg_replace('/^gid:\/\/shopify\/Order\//', '', $order['id']);
                $totalPrice = '0.00';
                if (isset($order['totalPriceSet']['shopMoney']['amount'])) {
                    $totalPrice = $order['totalPriceSet']['shopMoney']['amount'];
                }
                $currency = 'USD';
                if (isset($order['totalPriceSet']['shopMoney']['currencyCode'])) {
                    $currency = $order['totalPriceSet']['shopMoney']['currencyCode'];
                }
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
    <title><?php echo $customer ? htmlspecialchars($customer['displayName']) : 'Customer Details'; ?> - Shopify App
    </title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/view_customer.css">
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
            <?php
            $back_segment_id = isset($_GET['segment_id']) ? $_GET['segment_id'] : '';
            $back_segment_name = isset($_GET['segment_name']) ? urldecode($_GET['segment_name']) : '';

            $back_url = "segment_customers.php?shop=" . urlencode($shop);
            if (!empty($back_segment_id)) {
                $back_url .= "&segment_id=" . urlencode($back_segment_id);
                if (!empty($back_segment_name)) {
                    $back_url .= "&segment_name=" . urlencode($back_segment_name);
                }
            }
            ?>
            <a href="<?php echo $back_url; ?>" class="back-button">← Back to
                <?php echo !empty($back_segment_name) ? htmlspecialchars($back_segment_name) : 'Customers'; ?></a>

            <div class="page-header">
                <h1><?php echo htmlspecialchars($customer['displayName']); ?></h1>
                <p class="subtitle">Customer ID: <?php echo htmlspecialchars($customer['id']); ?></p>
            </div>
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
                    <div class="info-value"><?php echo $customer['currency']; ?>
                        <?php echo number_format($customer['amountSpent'], 2); ?></div>
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
            <?php if ($customer['defaultAddress'] && (!empty($customer['defaultAddress']['address1']) || !empty($customer['defaultAddress']['city']))): ?>
                <div class="section-title">Default Address</div>
                <div class="address-card">
                    <?php
                    $addr = $customer['defaultAddress'];
                    $addressLines = array();
                    if (!empty($addr['address1']))
                        $addressLines[] = $addr['address1'];
                    if (!empty($addr['address2']))
                        $addressLines[] = $addr['address2'];
                    if (!empty($addr['city']))
                        $addressLines[] = $addr['city'];
                    if (!empty($addr['province']))
                        $addressLines[] = $addr['province'];
                    if (!empty($addr['zip']))
                        $addressLines[] = $addr['zip'];
                    if (!empty($addr['country']))
                        $addressLines[] = $addr['country'];
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
            <?php if (!empty($customer['addresses'])): ?>
                <div class="section-title">All Addresses (<?php echo count($customer['addresses']); ?>)</div>
                <div class="info-grid">
                    <?php foreach ($customer['addresses'] as $addr): ?>
                        <div class="address-card">
                            <?php
                            $addressLines = array();
                            if (!empty($addr['address1']))
                                $addressLines[] = $addr['address1'];
                            if (!empty($addr['address2']))
                                $addressLines[] = $addr['address2'];
                            if (!empty($addr['city']))
                                $addressLines[] = $addr['city'];
                            if (!empty($addr['province']))
                                $addressLines[] = $addr['province'];
                            if (!empty($addr['zip']))
                                $addressLines[] = $addr['zip'];
                            if (!empty($addr['country']))
                                $addressLines[] = $addr['country'];
                            ?>
                            <p><?php echo implode(', ', $addressLines); ?></p>
                            <?php if (!empty($addr['phone'])): ?>
                                <p><strong>Phone:</strong> <?php echo htmlspecialchars($addr['phone']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
                                        <?php echo $order['currency']; ?>             <?php echo number_format($order['totalPrice'], 2); ?>
                                        <br>
                                        <small style="color: #5c5f62;">
                                            (Subtotal: <?php echo number_format($order['subtotalPrice'], 2); ?>
                                            <br>Tax: <?php echo number_format($order['totalTax'], 2); ?>)
                                        </small>
                                    </td>
                                    <td>
                                        <span
                                            class="order-status status-<?php echo str_replace(' ', '_', strtolower($order['financialStatus'])); ?>">
                                            <?php echo htmlspecialchars($order['financialStatus']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span
                                            class="order-status status-<?php echo str_replace(' ', '_', strtolower($order['fulfillmentStatus'])); ?>">
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
                        Showing last 50 orders. <a
                            href="https://<?php echo htmlspecialchars($shop); ?>/admin/customers/<?php echo $customer_id; ?>"
                            target="_blank">View all in Shopify Admin</a>
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