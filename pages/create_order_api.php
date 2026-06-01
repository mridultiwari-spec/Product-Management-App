<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$shop = isset($input['shop']) ? $input['shop'] : '';
$customer_id = isset($input['customer_id']) ? $input['customer_id'] : '';
$line_items = isset($input['line_items']) ? $input['line_items'] : array();
$financial_status = isset($input['financial_status']) ? $input['financial_status'] : 'PENDING';
$shipping_address = isset($input['shipping_address']) ? $input['shipping_address'] : array();

if (empty($shop) || empty($customer_id) || empty($line_items)) {
    echo json_encode(array('success' => false, 'message' => 'Missing required parameters'));
    exit;
}

$pdo = getDatabaseConnection();

$sessionToken = get_bearer_token_php53();
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
    echo json_encode(array('success' => false, 'message' => 'Authentication failed'));
    exit;
}

$access_token = $tokenResult['access_token'];
$api_version = '2026-04';

$lineItemsFormatted = array();
foreach ($line_items as $item) {
    $lineItemsFormatted[] = array(
        'variantId' => $item['variantId'],
        'quantity' => (int)$item['quantity']
    );
}
$orderInput = array(
    'customerId' => $customer_id,
    'lineItems' => $lineItemsFormatted,
    'financialStatus' => $financial_status
);
if (!empty($shipping_address)) {
    $shippingAddr = array();
    if (!empty($shipping_address['first_name'])) $shippingAddr['firstName'] = $shipping_address['first_name'];
    if (!empty($shipping_address['last_name'])) $shippingAddr['lastName'] = $shipping_address['last_name'];
    if (!empty($shipping_address['address1'])) $shippingAddr['address1'] = $shipping_address['address1'];
    if (!empty($shipping_address['address2'])) $shippingAddr['address2'] = $shipping_address['address2'];
    if (!empty($shipping_address['city'])) $shippingAddr['city'] = $shipping_address['city'];
    if (!empty($shipping_address['province'])) $shippingAddr['province'] = $shipping_address['province'];
    if (!empty($shipping_address['country'])) $shippingAddr['country'] = $shipping_address['country'];
    if (!empty($shipping_address['zip'])) $shippingAddr['zip'] = $shipping_address['zip'];
    if (!empty($shipping_address['phone'])) $shippingAddr['phone'] = $shipping_address['phone'];
    
    if (!empty($shippingAddr)) {
        $orderInput['shippingAddress'] = $shippingAddr;
    }
}
$mutation = <<<GRAPHQL
mutation orderCreate(\$order: OrderCreateOrderInput!) {
  orderCreate(order: \$order) {
    order {
      id
      name
      createdAt
      totalPriceSet {
        shopMoney {
          amount
          currencyCode
        }
      }
      shippingAddress {
        address1
        address2
        city
        province
        country
        zip
      }
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

$variables = array(
    'order' => $orderInput
);

$url = "https://" . $shop . "/admin/api/" . $api_version . "/graphql.json";
$headers = array(
    "Content-Type: application/json",
    "X-Shopify-Access-Token: " . $access_token
);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('query' => $mutation, 'variables' => $variables)));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$result = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    echo json_encode(array('success' => false, 'message' => 'HTTP Error: ' . $http_code));
    exit;
}

$response = json_decode($result, true);

if (isset($response['errors'])) {
    echo json_encode(array('success' => false, 'message' => json_encode($response['errors'])));
    exit;
}

$data = isset($response['data']['orderCreate']) ? $response['data']['orderCreate'] : null;

if ($data && isset($data['userErrors']) && count($data['userErrors']) > 0) {
    echo json_encode(array('success' => false, 'message' => $data['userErrors'][0]['message']));
    exit;
}

if ($data && isset($data['order'])) {
    $orderName = $data['order']['name'];
    echo json_encode(array('success' => true, 'order_name' => $orderName));
} else {
    echo json_encode(array('success' => false, 'message' => 'Unknown error creating order'));
}
?>