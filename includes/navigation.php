<?php
// includes/navigation.php
function renderNavigation($app_url, $shop) {
    ?>
    <style>
        .nav-wrapper {
            background: #ffffff;
            border-bottom: 1px solid #e1e3e5;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .navbar {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 32px;
        }
        
        .nav-box {
            display: flex;
            gap: 32px;
            margin: 0;
            padding: 0;
        }
        
        .nav-link {
            position: relative;
            padding: 20px 0 16px 0;
            font-size: 15px;
            font-weight: 500;
            color: #5c5f62;
            text-decoration: none;
            background: transparent;
            border: none;
            transition: all 0.15s ease;
            cursor: pointer;
            letter-spacing: -0.01em;
        }
        
        .nav-link:hover {
            color: #202223;
            text-decoration: none;
        }
        
        .nav-link.active {
            color: #008060;
        }
        
        .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: #008060;
            border-radius: 3px 3px 0 0;
        }
        
        @media (max-width: 768px) {
            .navbar {
                padding: 0 16px;
            }
            
            .nav-box {
                gap: 24px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .nav-link {
                padding: 16px 0 12px 0;
                font-size: 14px;
                white-space: nowrap;
            }
        }
    </style>
    
    <nav class="nav-wrapper">
        <div class="navbar">
            <div class="nav-box">
                <a class="nav-link" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/products.php?shop=<?php echo urlencode($shop); ?>">Products</a>
                <a class="nav-link" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/customers.php?shop=<?php echo urlencode($shop); ?>">Customers</a>
                <!-- <a class="nav-link" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/settings.php?shop=<?php echo urlencode($shop); ?>">Settings</a> -->
            </div>
        </div>
    </nav>
    
    <script>
        (function() {
            var links = document.querySelectorAll(".nav-link");
            var currentPath = window.location.pathname.split("/").pop();
            
            if (currentPath === "" || currentPath === "/" || currentPath === "index.php") {
                currentPath = "products.php";
            }
            
            currentPath = currentPath.split("?")[0];
            
            for (var i = 0; i < links.length; i++) {
                var href = links[i].getAttribute("href");
                var linkPath = href.split("/").pop();
                linkPath = linkPath.split("?")[0];
                
                links[i].classList.remove("active");
                
                if (linkPath === currentPath) {
                    links[i].classList.add("active");
                }
            }
        })();
    </script>
    <?php
}
?>