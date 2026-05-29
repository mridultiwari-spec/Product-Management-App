<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session_token_auth.php';
require_once __DIR__ . '/../app_config.php';

session_start();

// Set header to return JSON
header('Content-Type: application/json');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(array('success' => false, 'message' => 'Invalid request data'));
    exit;
}

$shop = isset($input['shop']) ? $input['shop'] : (isset($_SESSION['shop']) ? $_SESSION['shop'] : '');
$product_id = isset($input['product_id']) ? $input['product_id'] : '';

if (empty($shop)) {
    echo json_encode(array('success' => false, 'message' => 'Shop parameter is missing'));
    exit;
}

if (empty($product_id)) {
    echo json_encode(array('success' => false, 'message' => 'Product ID is missing'));
    exit;
}

// Get database connection
$pdo = getDatabaseConnection();

// Get access token
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
    echo json_encode(array('success' => false, 'message' => $tokenResult['error']));
    exit;
}

$access_token = $tokenResult['access_token'];
$api_version = '2026-04';

// Function to execute GraphQL mutation
function executeGraphQLDelete($access_token, $shop, $query, $variables = null)
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

// Prepare GraphQL mutation to delete product
$full_product_id = 'gid://shopify/Product/' . $product_id;

$deleteMutation = <<<GRAPHQL
mutation productDelete(\$input: ProductDeleteInput!) {
  productDelete(input: \$input) {
    deletedProductId
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

$variables = array(
    'input' => array(
        'id' => $full_product_id
    )
);

$result = executeGraphQLDelete($access_token, $shop, $deleteMutation, $variables);

if (!$result['success']) {
    echo json_encode(array('success' => false, 'message' => 'Failed to delete product: ' . json_encode($result['error'])));
    exit;
}

if (isset($result['data']['data']['productDelete']['userErrors']) && !empty($result['data']['data']['productDelete']['userErrors'])) {
    $errors = $result['data']['data']['productDelete']['userErrors'];
    echo json_encode(array('success' => false, 'message' => 'Delete failed: ' . $errors[0]['message']));
    exit;
}

if (isset($result['data']['data']['productDelete']['deletedProductId'])) {
    echo json_encode(array('success' => true, 'message' => 'Product deleted successfully'));
    //echo "<script>window.location.href = '" . htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8') . "/pages/products.php?shop=" . urlencode($shop) . "';</script>";
    exit;
} else {
    echo json_encode(array('success' => false, 'message' => 'Product deletion failed'));
    exit;
}
?>