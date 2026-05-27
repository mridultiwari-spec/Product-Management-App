<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/config/session_token_auth.php';

$requiredConfig = array(
    'app_url' => isset($app_url) ? $app_url : '',
    'api_key' => isset($api_key) ? $api_key : '',
    'api_secret' => isset($api_secret) ? $api_secret : '',
    'db_host' => isset($db_host) ? $db_host : '',
    'db_user' => isset($db_user) ? $db_user : '',
    'db_name' => isset($db_name) ? $db_name : ''
);
$missingConfig = array();
foreach ($requiredConfig as $key => $value) {
    if ($value === null || $value === '') {
        $missingConfig[] = $key;
    }
}
if (!empty($missingConfig)) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/index.css">
        <title>Configuration Required</title>
    </head>
    <body>
        <div class="config-card">
            <h1>Configuration Required</h1>
            <p>Please set these values in <code>testing_app/app_config.php</code>:</p>
            <ul>
                <?php foreach ($missingConfig as $key): ?>
                    <li><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
            <p>Use <code>testing_app/app_config.example.php</code> as template.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

session_start();
$shop = isset($_GET['shop']) ? $_GET['shop'] : (isset($_SESSION['shop']) ? $_SESSION['shop'] : '');

$sessionToken = get_bearer_token_php53();
if ($sessionToken) {
    $validatedToken = validate_shopify_session_token_php53($sessionToken, $api_secret, $api_key);
    if (isset($validatedToken['success']) && $validatedToken['success']) {
        $shop = $validatedToken['shop'];
    }
}

if (!$shop) {
    die("Shop missing");
}

$_SESSION['shop'] = $shop;
session_write_close();

$pdo = getDatabaseConnection();
$table = "shops";
$stmt = $pdo->prepare("SELECT shop FROM $table WHERE shop = :shop");
$stmt->execute(array(':shop' => $shop));
$data = $stmt->fetch();

if (!$data) {
    try {
        $insertStmt = $pdo->prepare("INSERT INTO $table (shop, install_date, status) VALUES (:shop, :install_date, :status)");
        $insertStmt->execute(array(
            ':shop' => $shop,
            ':install_date' => date('Y-m-d H:i:s'),
            ':status' => 'active'
        ));
        $stmt = $pdo->prepare("SELECT shop FROM $table WHERE shop = :shop");
        $stmt->execute(array(':shop' => $shop));
        $data = $stmt->fetch();
    } catch (Exception $e) {
        die("Unable to bootstrap shop installation record.");
    }

    if (!$data) {
        die("Unable to bootstrap shop installation record.");
    }
}

$stmt = $pdo->prepare("SELECT session_access_token FROM $table WHERE shop = :shop");
$stmt->execute(array(':shop' => $shop));
$shopTokens = $stmt->fetch();
$hasValidToken = !empty($shopTokens['session_access_token']);

if (isset($_GET['bootstrap_token']) && $_GET['bootstrap_token'] == '1') {
    header('Content-Type: application/json');
    try {
        $bootstrapToken = get_bearer_token_php53();
        if (!$bootstrapToken && isset($_GET['id_token'])) {
            $bootstrapToken = $_GET['id_token'];
        }
        if ($bootstrapToken) {
            $bootstrapToken = preg_replace('/\s+/', '', trim($bootstrapToken));
        }
        $validatedBootstrap = validate_shopify_session_token_php53($bootstrapToken, $api_secret, $api_key);
        if (!isset($validatedBootstrap['success']) || !$validatedBootstrap['success']) {
            http_response_code(401);
            echo json_encode(array('success' => false, 'message' => isset($validatedBootstrap['error']) ? $validatedBootstrap['error'] : 'Missing session token'));
            exit;
        }

        $shop = $validatedBootstrap['shop'];
        $_SESSION['shop'] = $shop;

        $tokenState = get_valid_shop_access_token_php53($pdo, $table, $shop, $api_key, $api_secret, $bootstrapToken);
        if (!isset($tokenState['success']) || !$tokenState['success']) {
            http_response_code(500);
            echo json_encode(array(
                'success' => false,
                'message' => isset($tokenState['error']) ? $tokenState['error'] : 'Unable to bootstrap shop token',
                'shop' => $shop
            ));
            exit;
        }

        echo json_encode(array('success' => true, 'source' => isset($tokenState['source']) ? $tokenState['source'] : 'bootstrap'));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array(
            'success' => false,
            'message' => 'Bootstrap exception',
            'error' => $e->getMessage()
        ));
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="shopify-api-key" content="<?php echo htmlspecialchars($api_key); ?>">
    <title>Shopify App</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/index.css">
</head>
<body>

<div class="app-container">
    <div id="loadingScreen" class="loading-screen">
        <div class="spinner"></div>
        <div class="loading-text">Setting up your app...</div>
    </div>
    <div id="mainDashboard" class="hidden">
        <div class="main-content">
            <div class="hero-section">
                <h1>Welcome to Shopify App</h1>
            </div>
            <div class="content-body">
                <div class="text-center">
                    <div class="welcome-card">
                        <h2>Your app is ready to use!</h2>
                        <button class="btn btn-primary" id="getStartedBtn">
                            Get Started
                        </button>
                    </div>

                    <div class="divider"></div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    let bootstrapCompleted = <?php echo $hasValidToken ? 'true' : 'false'; ?>;

    function updateUIAfterBootstrap(success) {
        const loadingScreen = document.getElementById('loadingScreen');
        const mainDashboard = document.getElementById('mainDashboard');
        const tokenStatus = document.getElementById('tokenStatus');

        if (success) {
            loadingScreen.classList.add('hidden');
            mainDashboard.classList.remove('hidden');
            
            if (tokenStatus) {
                tokenStatus.innerHTML = '✓ App Active & Connected';
            }
        } else {
            loadingScreen.innerHTML = `
                <div style="text-align: center; max-width: 400px;">
                    <div style="font-size: 56px; margin-bottom: 20px;"></div>
                    <h3 style="margin-bottom: 10px; font-size: 20px;">Unable to complete setup</h3>
                    <p style="color: #5c5f62; margin-bottom: 24px;">There was an error setting up your app. Please try refreshing or contact support.</p>
                    <button class="btn btn-primary" onclick="location.reload()">Try Again</button>
                </div>
            `;
        }
    }

    function bootstrapApp() {
        if (bootstrapCompleted) {
            updateUIAfterBootstrap(true);
            return;
        }

        if (window.shopify && typeof window.shopify.idToken === 'function') {
            window.shopify.idToken()
                .then(function(token) {
                    if (!token) {
                        throw new Error('No session token received');
                    }
                    return fetch('?bootstrap_token=1&shop=<?php echo urlencode($shop); ?>&id_token=' + encodeURIComponent(token), {
                        method: 'GET',
                        headers: {
                            'Authorization': 'Bearer ' + token,
                            'Content-Type': 'application/json'
                        }
                    });
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data.success) {
                        console.log('App bootstrapped successfully, source:', data.source);
                        bootstrapCompleted = true;
                        updateUIAfterBootstrap(true);
                    } else {
                        throw new Error(data.message || 'Bootstrap failed');
                    }
                })
                .catch(function(error) {
                    console.error('Bootstrap error:', error);
                    updateUIAfterBootstrap(false);
                });
        } else {
            console.error('App Bridge not loaded');
            document.getElementById('loadingScreen').innerHTML = `
                <div style="text-align: center; max-width: 400px;">
                    <div style="font-size: 56px; margin-bottom: 20px;">🔒</div>
                    <h3 style="margin-bottom: 10px;">App Bridge Not Available</h3>
                    <p style="color: #5c5f62; margin-bottom: 24px;">Please ensure you are accessing this app from within the Shopify admin dashboard.</p>
                    <button class="btn btn-primary" onclick="location.reload()">Try Again</button>
                </div>
            `;
        }
    }
    document.addEventListener("DOMContentLoaded", function() {
        bootstrapApp();
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'getStartedBtn') {
                window.location.href = '/testing_app/pages/customers.php?shop=<?php echo urlencode($shop); ?>';
            }
        });
    });
</script>
</body>
</html>