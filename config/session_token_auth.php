<?php

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('UTC');
}

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

if (!function_exists('base64url_decode_php53')) {
    function base64url_decode_php53($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

if (!function_exists('get_bearer_token_php53')) {
    function get_bearer_token_php53() {
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['Authorization'])) {
            $header = $_SERVER['Authorization'];
        }

        if (!$header) {
            $allHeaders = array();
            if (function_exists('getallheaders')) {
                $tmpHeaders = getallheaders();
                if (is_array($tmpHeaders)) {
                    $allHeaders = $tmpHeaders;
                }
            } elseif (function_exists('apache_request_headers')) {
                $tmpHeaders = apache_request_headers();
                if (is_array($tmpHeaders)) {
                    $allHeaders = $tmpHeaders;
                }
            }

            if (!empty($allHeaders)) {
                foreach ($allHeaders as $k => $v) {
                    if (strtolower($k) === 'authorization') {
                        $header = $v;
                        break;
                    }
                }
            }
        }

        if (!$header || stripos($header, 'Bearer ') !== 0) {
            return '';
        }
        $token = trim(substr($header, 7));
        return preg_replace('/\s+/', '', $token);
    }
}

if (!function_exists('validate_shopify_session_token_php53')) {
    function validate_shopify_session_token_php53($token, $api_secret, $api_key) {
        if (!$token) {
            return array('success' => false, 'error' => 'Missing session token');
        }
        $token = preg_replace('/\s+/', '', trim($token));

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return array('success' => false, 'error' => 'Invalid session token format');
        }

        $headerRaw = base64url_decode_php53($parts[0]);
        $payloadRaw = base64url_decode_php53($parts[1]);
        if ($headerRaw === false || $payloadRaw === false) {
            return array('success' => false, 'error' => 'Invalid session token encoding');
        }

        $header = json_decode($headerRaw, true);
        $payload = json_decode($payloadRaw, true);
        if (!is_array($header) || !is_array($payload)) {
            return array('success' => false, 'error' => 'Invalid session token payload');
        }

        if (!isset($header['alg']) || $header['alg'] !== 'HS256') {
            return array('success' => false, 'error' => 'Unsupported token algorithm');
        }

        $signingInput = $parts[0] . '.' . $parts[1];
        $expectedSig = hash_hmac('sha256', $signingInput, $api_secret, true);
        $actualSig = base64url_decode_php53($parts[2]);
        if ($actualSig === false || !hash_equals($expectedSig, $actualSig)) {
            return array('success' => false, 'error' => 'Invalid token signature');
        }

        $now = time();
        if (isset($payload['nbf']) && $now < (int) $payload['nbf']) {
            return array('success' => false, 'error' => 'Token not active yet');
        }
        if (!isset($payload['exp']) || $now >= (int) $payload['exp']) {
            return array('success' => false, 'error' => 'Session token expired');
        }
        if (!isset($payload['aud']) || $payload['aud'] !== $api_key) {
            return array('success' => false, 'error' => 'Invalid token audience');
        }
        if (!isset($payload['dest'])) {
            return array('success' => false, 'error' => 'Token missing destination');
        }

        $destHost = parse_url($payload['dest'], PHP_URL_HOST);
        if (!$destHost) {
            return array('success' => false, 'error' => 'Invalid destination in token');
        }

        return array(
            'success' => true,
            'shop' => $destHost,
            'payload' => $payload
        );
    }
}

if (!function_exists('shopify_token_request_php53')) {
    function shopify_token_request_php53($shop, $params) {
        $url = 'https://' . $shop . '/admin/oauth/access_token';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return array('success' => false, 'error' => $error, 'status' => 0);
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            return array('success' => false, 'error' => 'Invalid token response', 'status' => $httpCode);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return array('success' => true, 'data' => $json, 'status' => $httpCode);
        }

        $err = isset($json['error_description']) ? $json['error_description'] : (isset($json['error']) ? $json['error'] : 'Token API request failed');
        return array('success' => false, 'error' => $err, 'status' => $httpCode, 'data' => $json);
    }
}

if (!function_exists('exchange_session_token_for_expiring_offline_token_php53')) {
    function exchange_session_token_for_expiring_offline_token_php53($shop, $sessionToken, $api_key, $api_secret) {
        return shopify_token_request_php53($shop, array(
            'client_id' => $api_key,
            'client_secret' => $api_secret,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $sessionToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
            'expiring' => '1'
        ));
    }
}

if (!function_exists('refresh_expiring_offline_token_php53')) {
    function refresh_expiring_offline_token_php53($shop, $refreshToken, $api_key, $api_secret) {
        return shopify_token_request_php53($shop, array(
            'client_id' => $api_key,
            'client_secret' => $api_secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken
        ));
    }
}

if (!function_exists('revoke_shopify_token_php53')) {
    function revoke_shopify_token_php53($shop, $token, $api_key, $api_secret) {
        if (!$token) {
            return array('success' => false, 'error' => 'Missing token');
        }
        $url = 'https://' . $shop . '/admin/oauth/revoke';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
            'client_id' => $api_key,
            'client_secret' => $api_secret,
            'token' => $token
        )));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return array('success' => false, 'error' => $error, 'status' => 0);
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return array('success' => true, 'status' => $httpCode, 'response' => $response);
        }
        return array('success' => false, 'error' => 'Token revoke failed', 'status' => $httpCode, 'response' => $response);
    }
}

if (!function_exists('ensure_session_token_columns_php53')) {
    function ensure_session_token_columns_php53($pdo, $table) {
        static $checked = array();
        if (isset($checked[$table])) {
            return;
        }

        $required = array(
            'session_access_token' => "ALTER TABLE `$table` ADD COLUMN session_access_token TEXT NULL",
            'session_access_token_expires_at' => "ALTER TABLE `$table` ADD COLUMN session_access_token_expires_at DATETIME NULL",
            'session_refresh_token' => "ALTER TABLE `$table` ADD COLUMN session_refresh_token TEXT NULL",
            'session_refresh_token_expires_at' => "ALTER TABLE `$table` ADD COLUMN session_refresh_token_expires_at DATETIME NULL",
            'session_token_updated_at' => "ALTER TABLE `$table` ADD COLUMN session_token_updated_at DATETIME NULL"
        );

        foreach ($required as $col => $alterSql) {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col));
            $exists = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if (!$exists) {
                $pdo->exec($alterSql);
            }
        }

        $checked[$table] = true;
    }
}

if (!function_exists('persist_shop_session_tokens_php53')) {
    function persist_shop_session_tokens_php53($pdo, $table, $shop, $tokenData) {
        $accessToken = isset($tokenData['access_token']) ? $tokenData['access_token'] : null;
        $refreshToken = isset($tokenData['refresh_token']) ? $tokenData['refresh_token'] : null;
        $expiresIn = isset($tokenData['expires_in']) ? (int) $tokenData['expires_in'] : 0;
        $refreshExpiresIn = isset($tokenData['refresh_token_expires_in']) ? (int) $tokenData['refresh_token_expires_in'] : 0;

        $accessExpiresAt = null;
        $refreshExpiresAt = null;
        if ($expiresIn > 0) {
            $accessExpiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        }
        if ($refreshExpiresIn > 0) {
            $refreshExpiresAt = date('Y-m-d H:i:s', time() + $refreshExpiresIn);
        }

        $stmt = $pdo->prepare("
            UPDATE `$table`
            SET session_access_token = :session_access_token,
                session_access_token_expires_at = :session_access_token_expires_at,
                session_refresh_token = :session_refresh_token,
                session_refresh_token_expires_at = :session_refresh_token_expires_at,
                session_token_updated_at = NOW()
            WHERE shop = :shop
        ");
        $stmt->execute(array(
            ':session_access_token' => $accessToken,
            ':session_access_token_expires_at' => $accessExpiresAt,
            ':session_refresh_token' => $refreshToken,
            ':session_refresh_token_expires_at' => $refreshExpiresAt,
            ':shop' => $shop
        ));
    }
}

if (!function_exists('get_valid_shop_access_token_php53')) {
    function get_valid_shop_access_token_php53($pdo, $table, $shop, $api_key, $api_secret, $sessionToken, $forceRenew = false) {
        ensure_session_token_columns_php53($pdo, $table);

        $stmt = $pdo->prepare("
            SELECT session_access_token, session_access_token_expires_at, session_refresh_token
            FROM `$table`
            WHERE shop = :shop
            LIMIT 1
        ");
        $stmt->execute(array(':shop' => $shop));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return array('success' => false, 'error' => 'Shop not installed');
        }

        $now = time();
        $buffer = 120;
        if (!$forceRenew && !empty($row['session_access_token']) && !empty($row['session_access_token_expires_at'])) {
            $expTs = strtotime($row['session_access_token_expires_at']);
            if ($expTs && $expTs > ($now + $buffer)) {
                return array('success' => true, 'access_token' => $row['session_access_token'], 'source' => 'stored');
            }
        }

        if (!empty($row['session_refresh_token'])) {
            $refreshResult = refresh_expiring_offline_token_php53($shop, $row['session_refresh_token'], $api_key, $api_secret);
            if ($refreshResult['success']) {
                persist_shop_session_tokens_php53($pdo, $table, $shop, $refreshResult['data']);
                $refreshedToken = isset($refreshResult['data']['access_token']) ? $refreshResult['data']['access_token'] : '';
                if (!$refreshedToken) {
                    return array('success' => false, 'error' => 'Refresh token response missing access token');
                }
                return array(
                    'success' => true,
                    'access_token' => $refreshedToken,
                    'source' => 'refresh'
                );
            }
        }

        $exchangeResult = exchange_session_token_for_expiring_offline_token_php53($shop, $sessionToken, $api_key, $api_secret);
        if (!$exchangeResult['success']) {
            return array('success' => false, 'error' => $exchangeResult['error']);
        }
        persist_shop_session_tokens_php53($pdo, $table, $shop, $exchangeResult['data']);
        $exchangedToken = isset($exchangeResult['data']['access_token']) ? $exchangeResult['data']['access_token'] : '';
        if (!$exchangedToken) {
            return array('success' => false, 'error' => 'Token exchange response missing access token');
        }
        return array(
            'success' => true,
            'access_token' => $exchangedToken,
            'source' => 'exchange'
        );
    }
}
