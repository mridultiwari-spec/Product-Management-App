<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';
session_start();
$shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : (isset($_GET['shop']) ? $_GET['shop'] : '');
$segment_id = isset($_GET['segment_id']) ? $_GET['segment_id'] : '';
$segment_name = isset($_GET['segment_name']) ? urldecode($_GET['segment_name']) : 'Segment Customers';

if (!$shop || !$segment_id) {
    error_log("SEGMENT CUSTOMERS ERROR: Missing shop or segment_id");
    die("Missing required parameters");
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
    error_log("SEGMENT CUSTOMERS ERROR: Failed to get access token - " . $error_message);
} else {
    $access_token = $tokenResult['access_token'];
    error_log("SEGMENT CUSTOMERS: Access token obtained successfully for shop: " . $shop);
}

include __DIR__ . '/../includes/navigation.php';
$api_version = '2026-04';

$customersPerPage = 50;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$cursor = isset($_GET['cursor']) ? $_GET['cursor'] : null;
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$error = null;
$customers = array();
$hasNextPage = false;
$hasPreviousPage = false;
$nextCursor = null;
$previousCursor = null;
$isSearching = !empty($searchQuery);

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
    if ($isSearching) {
        $graphqlQuery = <<<GRAPHQL
query SearchSegmentCustomers(\$segmentId: ID!, \$first: Int!, \$after: String) {
  customerSegmentMembers(first: \$first, segmentId: \$segmentId, after: \$after) {
    edges {
      cursor
      node {
        id
        firstName
        lastName
        defaultEmailAddress {
          emailAddress
        }
        defaultPhoneNumber {
          phoneNumber
        }
        defaultAddress {
          address1
          address2
          city
          country
          countryCodeV2
          firstName
          lastName
          zip
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
        
        $variables = array(
            'segmentId' => 'gid://shopify/Segment/' . $segment_id,
            'first' => $customersPerPage,
            'after' => $cursor
        );
        
        $result = executeGraphQLQuery($access_token, $shop, $graphqlQuery, $variables);
        
        if ($result['success'] && isset($result['data']['data']['customerSegmentMembers']['edges'])) {
            $edges = $result['data']['data']['customerSegmentMembers']['edges'];
            $pageInfo = $result['data']['data']['customerSegmentMembers']['pageInfo'];
            $hasNextPage = isset($pageInfo['hasNextPage']) ? $pageInfo['hasNextPage'] : false;
            $nextCursor = isset($pageInfo['endCursor']) ? $pageInfo['endCursor'] : null;
            foreach ($edges as $edge) {
                $customer = $edge['node'];
                $email = isset($customer['defaultEmailAddress']['emailAddress']) ? $customer['defaultEmailAddress']['emailAddress'] : '';
                $firstName = isset($customer['firstName']) ? $customer['firstName'] : '';
                $lastName = isset($customer['lastName']) ? $customer['lastName'] : '';
                $fullName = $firstName . ' ' . $lastName;
                $searchLower = strtolower($searchQuery);
                if (strpos(strtolower($email), $searchLower) !== false || 
                    strpos(strtolower($fullName), $searchLower) !== false ||
                    strpos(strtolower($firstName), $searchLower) !== false ||
                    strpos(strtolower($lastName), $searchLower) !== false) {
                    $customers[] = formatCustomerData($customer, $edge['cursor']);
                }
            }
            $hasNextPage = false;
            
            if (empty($customers)) {
                $error = "No customers found matching '$searchQuery' in this segment";
            }
        } else {
            $error = "Failed to search customers in segment";
            if (isset($result['error'])) {
                error_log("SEARCH ERROR: " . json_encode($result['error']));
            }
        }
    } else {
        $graphqlQuery = <<<GRAPHQL
query getCustomers(\$segmentId: ID!, \$cursor: String) {
  customerSegmentMembers(first: 250, segmentId: \$segmentId, after: \$cursor) {
    edges {
      cursor
      node {
        id
        firstName
        lastName
        defaultEmailAddress {
          emailAddress
        }
        defaultPhoneNumber {
          phoneNumber
        }
        defaultAddress {
          address1
          address2
          city
          country
          countryCodeV2
          firstName
          lastName
          zip
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
        
        $variables = array(
            'segmentId' => 'gid://shopify/Segment/' . $segment_id,
            'cursor' => $cursor
        );
        
        $result = executeGraphQLQuery($access_token, $shop, $graphqlQuery, $variables);
        
        if ($result['success'] && isset($result['data']['data']['customerSegmentMembers']['edges'])) {
            $edges = $result['data']['data']['customerSegmentMembers']['edges'];
            $pageInfo = $result['data']['data']['customerSegmentMembers']['pageInfo'];
            $hasNextPage = isset($pageInfo['hasNextPage']) ? $pageInfo['hasNextPage'] : false;
            $nextCursor = isset($pageInfo['endCursor']) ? $pageInfo['endCursor'] : null;
            $cursors = array();
            $sliceStart = ($currentPage - 1) * $customersPerPage;
            $sliceEnd = $sliceStart + $customersPerPage;
            $slicedEdges = array_slice($edges, $sliceStart, $customersPerPage);
            
            foreach ($slicedEdges as $edge) {
                $customer = $edge['node'];
                $customers[] = formatCustomerData($customer, $edge['cursor']);
                $cursors[] = $edge['cursor'];
            }
            $totalEdges = count($edges);
            if ($sliceEnd >= $totalEdges && $hasNextPage) {
                $hasNextPage = true;
            } elseif ($sliceEnd < $totalEdges) {
                $hasNextPage = true;
                $nextCursor = $cursors[$sliceEnd - 1];
            } else {
                $hasNextPage = false;
            }
            if ($currentPage > 1) {
                $hasPreviousPage = true;
                if ($sliceStart > 0 && isset($cursors[$sliceStart - 1])) {
                    $previousCursor = $cursors[$sliceStart - 1];
                }
            }
            
            error_log("SEGMENT CUSTOMERS: Fetched " . count($customers) . " customers for page " . $currentPage);
            
        } else {
            $error = "Failed to fetch customers from segment";
            if (isset($result['error'])) {
                error_log("SEGMENT CUSTOMERS ERROR: " . json_encode($result['error']));
            }
        }
    }
}
function formatCustomerData($customer, $cursor) {
    $segment_member_id = $customer['id'];
    if (preg_match('/gid:\/\/shopify\/CustomerSegmentMember\/(\d+)/', $segment_member_id, $matches)) {
        $customer_numeric_id = $matches[1];
    } else {
        preg_match('/\d+/', $segment_member_id, $matches);
        $customer_numeric_id = isset($matches[0]) ? $matches[0] : '';
    }
    
    $displayName = '';
    if (!empty($customer['firstName'])) {
        $displayName = $customer['firstName'];
        if (!empty($customer['lastName'])) {
            $displayName .= ' ' . $customer['lastName'];
        }
    } elseif (!empty($customer['lastName'])) {
        $displayName = $customer['lastName'];
    } else {
        $displayName = 'Anonymous Customer';
    }
    
    $email = 'N/A';
    if (isset($customer['defaultEmailAddress']) && isset($customer['defaultEmailAddress']['emailAddress'])) {
        $email = $customer['defaultEmailAddress']['emailAddress'];
    }
    
    $phone = 'N/A';
    if (isset($customer['defaultPhoneNumber']) && isset($customer['defaultPhoneNumber']['phoneNumber'])) {
        $phone = $customer['defaultPhoneNumber']['phoneNumber'];
    }
    
    $address = '';
    $fullAddress = '';
    if (isset($customer['defaultAddress']) && is_array($customer['defaultAddress'])) {
        $addr = $customer['defaultAddress'];
        $addressParts = array();
        if (!empty($addr['city'])) $addressParts[] = $addr['city'];
        if (!empty($addr['country'])) $addressParts[] = $addr['country'];
        $address = implode(', ', $addressParts);
        
        $fullAddrParts = array();
        if (!empty($addr['address1'])) $fullAddrParts[] = $addr['address1'];
        if (!empty($addr['address2'])) $fullAddrParts[] = $addr['address2'];
        if (!empty($addr['city'])) $fullAddrParts[] = $addr['city'];
        if (!empty($addr['country'])) $fullAddrParts[] = $addr['country'];
        if (!empty($addr['zip'])) $fullAddrParts[] = $addr['zip'];
        $fullAddress = implode(', ', $fullAddrParts);
    }
    
    return array(
        'id' => $customer_numeric_id,
        'displayName' => $displayName,
        'firstName' => isset($customer['firstName']) ? $customer['firstName'] : '',
        'lastName' => isset($customer['lastName']) ? $customer['lastName'] : '',
        'email' => $email,
        'phone' => $phone,
        'location' => $address,
        'fullAddress' => $fullAddress,
        'cursor' => $cursor
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($segment_name); ?> - Customers</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/view_segments.css">
</head>
<body>
    <?php renderNavigation($app_url, $shop); ?>
    <div class="loader-overlay" id="loaderOverlay">
        <div class="loader-content">
            <div class="spinner"></div>
            <p>Fetching customers from segment...</p>
            <p style="font-size: 12px; color: #5c5f62; margin-top: 10px;">This may take a few moments for large segments</p>
        </div>
    </div>
    <div class="content">
        <a href="customers.php?shop=<?php echo urlencode($shop); ?>" class="back-link">Back to Segments</a>
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars($segment_name); ?></h1>
                <p class="subtitle">Customers in this segment</p>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!$access_token): ?>
            <div class="error-message">
                <strong>Authentication Error:</strong> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php else: ?>
            <div class="table-header">
                <div class="customer-count-info">
                    <?php if ($isSearching): ?>
                        Search results for "<strong><?php echo htmlspecialchars($searchQuery); ?></strong>"
                        <?php if (!empty($customers)): ?>
                            (<?php echo count($customers); ?> customers found)
                        <?php endif; ?>
                    <?php else: ?>
                        Showing <?php echo count($customers); ?> customers per page
                    <?php endif; ?>
                </div>
                <div class="search-box">
                    <form method="GET" action="" id="searchForm">
                        <input type="hidden" name="shop" value="<?php echo urlencode($shop); ?>">
                        <input type="hidden" name="segment_id" value="<?php echo htmlspecialchars($segment_id); ?>">
                        <input type="hidden" name="segment_name" value="<?php echo urlencode($segment_name); ?>">
                        <input type="text" name="search" id="searchInput" class="search-input"
                            placeholder="Search by name or email..." 
                            value="<?php echo htmlspecialchars($searchQuery); ?>">
                        <button type="submit" class="search-btn">Search</button>
                        <?php if ($isSearching): ?>
                            <a href="?shop=<?php echo urlencode($shop); ?>&segment_id=<?php echo urlencode($segment_id); ?>&segment_name=<?php echo urlencode($segment_name); ?>" class="clear-btn">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <?php if (!empty($customers)): ?>
                <div class="customers-table-container">
                    <table class="customers-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Contact</th>
                                <th>Location</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td data-label="Customer">
                                        <div class="customer-name"><?php echo htmlspecialchars($customer['displayName']); ?></div>
                                    </td>
                                    <td data-label="Contact">
                                        <div class="customer-email"><?php echo htmlspecialchars($customer['email']); ?></div>
                                        <?php if ($customer['phone'] != 'N/A'): ?>
                                            <div class="customer-phone"><?php echo htmlspecialchars($customer['phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Location">
                                        <div class="address-detail">
                                            <?php 
                                            if (!empty($customer['fullAddress'])) {
                                                echo htmlspecialchars($customer['fullAddress']);
                                            } elseif (!empty($customer['location'])) {
                                                echo htmlspecialchars($customer['location']);
                                            } else {
                                                echo '—';
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td data-label="Actions">
                                       <a href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/view_segment_member.php?shop=<?php echo urlencode($shop); ?>&customer_id=<?php echo urlencode($customer['id']); ?>&segment_id=<?php echo urlencode($segment_id); ?>&segment_name=<?php echo urlencode($segment_name); ?>" class="btn-icon">View Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (!$isSearching): ?>
                <div class="pagination-container">
                    <?php if ($hasPreviousPage): ?>
                        <?php 
                        $prevParams = array(
                            'shop' => $shop,
                            'segment_id' => $segment_id,
                            'segment_name' => $segment_name,
                            'page' => $currentPage - 1,
                            'cursor' => $previousCursor
                        );
                        ?>
                        <a href="?<?php echo http_build_query($prevParams); ?>" class="pagination-btn" data-loader="true">&laquo; Previous</a>
                    <?php else: ?>
                        <span class="pagination-btn disabled">&laquo; Previous</span>
                    <?php endif; ?>
                    
                    <div class="page-numbers">
                        <span class="page-number active"><?php echo $currentPage; ?></span>
                        <?php if ($hasNextPage): ?>
                            <span style="color: #5c5f62;">...</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($hasNextPage): ?>
                        <?php 
                        $nextParams = array(
                            'shop' => $shop,
                            'segment_id' => $segment_id,
                            'segment_name' => $segment_name,
                            'page' => $currentPage + 1,
                            'cursor' => $nextCursor
                        );
                        ?>
                        <a href="?<?php echo http_build_query($nextParams); ?>" class="pagination-btn" data-loader="true">Next &raquo;</a>
                    <?php else: ?>
                        <span class="pagination-btn disabled">Next &raquo;</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="no-customers">
                    <?php if ($isSearching): ?>
                        <p>No customers found matching "<strong><?php echo htmlspecialchars($searchQuery); ?></strong>" in this segment.</p>
                        <div style="margin-top: 20px;">
                            <a href="?shop=<?php echo urlencode($shop); ?>&segment_id=<?php echo urlencode($segment_id); ?>&segment_name=<?php echo urlencode($segment_name); ?>" class="clear-btn">Clear Search</a>
                        </div>
                    <?php else: ?>
                        <p>No customers found in this segment.</p>
                        <p style="margin-top: 10px; font-size: 13px; color: #5c5f62;">
                            This segment may have no customers or is empty.
                        </p>
                        <div style="margin-top: 20px;">
                            <a href="https://<?php echo htmlspecialchars($shop); ?>/admin/customers/segments" target="_blank" class="btn-icon">View Segments in Shopify</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var loader = document.getElementById('loaderOverlay');
            var searchForm = document.getElementById('searchForm');
            var allLinks = document.querySelectorAll('a[data-loader="true"], .search-btn, .clear-btn');
            
            function showLoader() {
                if (loader) {
                    loader.style.display = 'flex';
                }
            }
            if (searchForm) {
                searchForm.addEventListener('submit', function(e) {
                    showLoader();
                });
            }
            for (var i = 0; i < allLinks.length; i++) {
                if (allLinks[i].href && !allLinks[i].classList.contains('disabled')) {
                    allLinks[i].addEventListener('click', function(e) {
                        if (this.href && this.href.indexOf('javascript:') === -1) {
                            showLoader();
                        }
                    });
                }
            }
        });
    </script>
</body>
</html>