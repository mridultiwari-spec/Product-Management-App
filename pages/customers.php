<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';

session_start();
$shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : (isset($_GET['shop']) ? $_GET['shop'] : '');

if (!$shop) {
    die("Shop missing");
}
include __DIR__ . '/../includes/navigation.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - Shopify App</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/customers.css">
</head>
<body>
    <?php renderNavigation($app_url, $shop); ?>
    
    <div class="content">
        <h1>Customers Page</h1>
        <div class="card">
            <p>This is the customers page. Navigation should work properly.</p>
            <div class="current-url">
                Current URL: <?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>
            </div>
        </div>
    </div>
</body>
</html>