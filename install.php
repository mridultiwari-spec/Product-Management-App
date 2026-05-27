<?php
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('UTC');
}
session_start();
session_write_close();
require_once __DIR__ . '/app_config.php';

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

if (!isset($_GET['shop'])) {
    die("Invalid install request");
}
$shop = $_GET['shop'];
if (!isValidShopDomain($shop)) {
    die("Invalid shop domain");
}

if (isset($_GET['embedded']) && $_GET['embedded'] === '1' && !isset($_GET['escape_iframe'])) {
    $query = $_GET;
    $query['escape_iframe'] = '1';
    $breakoutUrl = $app_url . '/install.php?' . http_build_query($query);
    echo "<script>window.top.location.href = '" . $breakoutUrl . "';</script>";
    exit;
    exit;
}
$redirectUrl = $app_url . '/index.php?shop=' . urlencode($shop);
if (isset($_GET['host'])) {
    $redirectUrl .= '&host=' . urlencode($_GET['host']);
}
header("Location: " . $redirectUrl, true, 302);
exit;