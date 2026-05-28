<?php
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('UTC');
}
session_start();
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/config/db.php';

if (!function_exists('hash_equals')) {
    function hash_equals($known_string, $user_string) {
        if (!is_string($known_string)) {
            $known_string = (string) $known_string;
        }
        if (!is_string($user_string)) {
            $user_string = (string) $user_string;
        }
        if (strlen($known_string) !== strlen($user_string)) {
            return false;
        }
        $res = 0;
        $len = strlen($known_string);
        for ($i = 0; $i < $len; $i++) {
            $res |= ord($known_string[$i]) ^ ord($user_string[$i]);
        }
        return $res === 0;
    }
}

function isValidShopDomain($shop) {
    return is_string($shop) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop);
}

function isValidHmac($queryParams, $secret) {
    if (!isset($queryParams['hmac'])) {
        return false;
    }
    $hmac = $queryParams['hmac'];
    unset($queryParams['hmac'], $queryParams['signature']);
    ksort($queryParams);
    $calculated = hash_hmac('sha256', http_build_query($queryParams), $secret);
    return hash_equals($hmac, $calculated);
}

function exchangeCodeForToken($shop, $code, $api_key, $api_secret, &$error_msg) {
    $url = 'https://' . $shop . '/admin/oauth/access_token';
    $params = array(
        'client_id' => $api_key,
        'client_secret' => $api_secret,
        'code' => $code
    );
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        $error_msg = "CURL error: " . $curlError;
        error_log("Token exchange CURL error for $shop: " . $curlError);
        return false;
    }
    
    $tokenData = json_decode($response, true);
    if ($httpCode !== 200 || !is_array($tokenData) || !isset($tokenData['access_token'])) {
        $error_msg = "Token exchange failed: HTTP $httpCode, Response: " . substr($response, 0, 200);
        error_log("Token exchange failed for $shop: " . $error_msg);
        return false;
    }
    
    error_log("Successfully exchanged token for $shop");
    return $tokenData;
}

function saveShopTokens($pdo, $shop, $tokenData, &$error_msg) {
    $table = "shops";
    $accessToken = isset($tokenData['access_token']) ? $tokenData['access_token'] : '';
    $scope = isset($tokenData['scope']) ? $tokenData['scope'] : '';
    
    if (empty($accessToken)) {
        $error_msg = "No access token received";
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT shop FROM $table WHERE shop = :shop");
        $stmt->execute(array(':shop' => $shop));
        $exists = $stmt->fetch();
        
        if ($exists) {
            $updateStmt = $pdo->prepare("UPDATE $table SET session_access_token = :token, scope = :scope, updated_at = NOW() WHERE shop = :shop");
            $updateStmt->execute(array(
                ':token' => $accessToken,
                ':scope' => $scope,
                ':shop' => $shop
            ));
            error_log("Updated token for existing shop: $shop");
        } else {
            $insertStmt = $pdo->prepare("INSERT INTO $table (shop, session_access_token, scope, install_date, status) VALUES (:shop, :token, :scope, NOW(), 'active')");
            $insertStmt->execute(array(
                ':shop' => $shop,
                ':token' => $accessToken,
                ':scope' => $scope
            ));
            error_log("Created new shop record with token: $shop");
        }
        return true;
    } catch (Exception $e) {
        $error_msg = "Database error: " . $e->getMessage();
        error_log("Database error for $shop: " . $e->getMessage());
        return false;
    }
}

// Main installation logic
$error_log_msg = "";

// Check for OAuth callback with code
if (isset($_GET['code']) && isset($_GET['shop']) && isset($_GET['timestamp'])) {
    error_log("Processing OAuth callback for shop: " . $_GET['shop']);
    
    // Verify HMAC
    if (!isValidHmac($_GET, $api_secret)) {
        error_log("HMAC validation failed for shop: " . $_GET['shop']);
        die("Invalid request signature");
    }
    
    $shop = $_GET['shop'];
    if (!isValidShopDomain($shop)) {
        error_log("Invalid shop domain: " . $shop);
        die("Invalid shop domain");
    }
    
    $code = $_GET['code'];
    
    // Exchange code for token
    $tokenData = exchangeCodeForToken($shop, $code, $api_key, $api_secret, $error_log_msg);
    if (!$tokenData) {
        die("Failed to get access token: " . htmlspecialchars($error_log_msg));
    }
    
    // Save to database
    $pdo = getDatabaseConnection();
    if (!saveShopTokens($pdo, $shop, $tokenData, $error_log_msg)) {
        die("Failed to save shop data: " . htmlspecialchars($error_log_msg));
    }
    
    $_SESSION['shop'] = $shop;
    session_write_close();
    
    // Redirect to app
    $redirectUrl = $app_url . '/index.php?shop=' . urlencode($shop);
    if (isset($_GET['host'])) {
        $redirectUrl .= '&host=' . urlencode($_GET['host']);
    }
    
    error_log("Installation complete for $shop, redirecting to index.php");
    header("Location: " . $redirectUrl);
    exit;
}

// If no code, this is initial install request
if (!isset($_GET['shop'])) {
    die("Invalid install request");
}

$shop = $_GET['shop'];
if (!isValidShopDomain($shop)) {
    die("Invalid shop domain");
}

// Handle embedded breakout
if (isset($_GET['embedded']) && $_GET['embedded'] === '1' && !isset($_GET['escape_iframe'])) {
    $query = $_GET;
    $query['escape_iframe'] = '1';
    $breakoutUrl = $app_url . '/install.php?' . http_build_query($query);
    echo "<script>window.top.location.href = '" . addslashes($breakoutUrl) . "';</script>";
    exit;
}

// Build OAuth URL for installation
$scopes = 'write_products,read_products'; // Add your required scopes
$redirectUri = $app_url . '/install.php';
$oauthUrl = 'https://' . $shop . '/admin/oauth/authorize?client_id=' . urlencode($api_key) . 
            '&scope=' . urlencode($scopes) . 
            '&redirect_uri=' . urlencode($redirectUri);

error_log("Redirecting to OAuth for $shop: " . $oauthUrl);
header("Location: " . $oauthUrl);
exit;
?>