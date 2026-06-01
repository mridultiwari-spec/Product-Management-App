<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';

header('Content-Type: application/json');

$shop = isset($_GET['shop']) ? $_GET['shop'] : '';

if (empty($shop)) {
    echo json_encode(array('success' => false, 'message' => 'Shop parameter missing'));
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

$allProducts = array();
$hasNextPage = true;
$cursor = null;
$url = "https://" . $shop . "/admin/api/" . $api_version . "/graphql.json";
$headers = array(
    "Content-Type: application/json",
    "X-Shopify-Access-Token: " . $access_token
);

while ($hasNextPage) {
    $afterParam = '';
    if ($cursor) {
        $afterParam = ', after: "' . addslashes($cursor) . '"';
    }
    
    $query = <<<GRAPHQL
query GetProducts {
  products(first: 250$afterParam) {
    edges {
      cursor
      node {
        id
        title
        variants(first: 1) {
          edges {
            node {
              id
              price
              inventoryQuantity
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

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('query' => $query)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $result = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($result, true);
    if (isset($data['data']['products']['edges'])) {
        foreach ($data['data']['products']['edges'] as $edge) {
            $allProducts[] = $edge['node'];
        }
        $pageInfo = $data['data']['products']['pageInfo'];
        $hasNextPage = isset($pageInfo['hasNextPage']) ? $pageInfo['hasNextPage'] : false;
        $cursor = isset($pageInfo['endCursor']) ? $pageInfo['endCursor'] : null;
    } else {
        $hasNextPage = false;
    }
}

$products = array();
foreach ($allProducts as $product) {
    $variant = isset($product['variants']['edges'][0]['node']) ? $product['variants']['edges'][0]['node'] : null;
    $product_numeric_id = preg_replace('/^gid:\/\/shopify\/Product\//', '', $product['id']);
    
    $products[] = array(
        'id' => $product_numeric_id,
        'gid' => $product['id'],
        'variant_gid' => $variant ? $variant['id'] : '',
        'title' => $product['title'],
        'price' => $variant && isset($variant['price']) ? $variant['price'] : '0.00',
        'inventory' => $variant && isset($variant['inventoryQuantity']) ? $variant['inventoryQuantity'] : 0
    );
}

echo json_encode(array('success' => true, 'products' => $products));
?>