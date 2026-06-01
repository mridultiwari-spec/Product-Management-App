<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';

session_start();
$shop = isset($_GET['shop']) ? $_GET['shop'] : (isset($_SESSION['shop']) ? $_SESSION['shop'] : '');

if (empty($shop)) {
    die("Shop parameter is missing");
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
} else {
    $access_token = $tokenResult['access_token'];
    $token_source = $tokenResult['source'];
}

include __DIR__ . '/../includes/navigation.php';
$api_version = '2026-04';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Order - Shopify App</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/assets/css/create_order.css">
</head>

<body>
    <?php renderNavigation($app_url, $shop); ?>
    <div class="content">
        <a href="orders.php?shop=<?php echo urlencode($shop); ?>" class="back-link">Back to Orders</a>

        <h1>Create New Order</h1>
        <p class="subtitle">Fill in the details to create a new order</p>

        <?php if (!$access_token): ?>
            <div class="error-message">
                <strong>Authentication Error:</strong> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php else: ?>
            <div class="order-container">
                <div class="panel">
                    <div class="panel-header">
                        <h2>Customer Information</h2>
                    </div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label>Search Customer</label>
                            <div class="customer-search-container">
                                <input type="text" id="customerSearch" class="customer-search"
                                    placeholder="Type to search customer..." autocomplete="off" disabled>
                                <div id="customerDropdown" class="customer-dropdown"></div>
                            </div>
                            <div id="customerLoadingMsg" style="font-size: 12px; color: #5c5f62; margin-top: 5px;">Loading
                                customers...</div>
                        </div>
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" id="firstName" readonly>
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" id="lastName" readonly>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" id="email" readonly>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" id="phone" readonly>
                        </div>
                        <div class="form-group">
                            <label>Address</label>
                            <textarea id="address" rows="3" readonly></textarea>
                        </div>
                        <input type="hidden" id="customerGid">
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header">
                        <h2>Products & Billing</h2>
                    </div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label>Select Product</label>
                            <select id="productSelect" onchange="prepareAddProduct()" disabled>
                                <option value="">-- Loading products... --</option>
                            </select>
                        </div>

                        <div id="addProductSection" style="display: none;">
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" id="productQuantity" min="1" value="1">
                            </div>
                            <button type="button" class="add-product-btn" onclick="addProductToCart()">+ Add to
                                Cart</button>
                        </div>

                        <div id="cartItems" style="margin-top: 20px;">
                            <h3 style="font-size: 16px; margin-bottom: 10px;">Cart Items</h3>
                            <div id="cartItemsList"></div>
                        </div>

                        <div style="margin-top: 24px;">
                            <div class="billing-row">
                                <span class="billing-label">Subtotal:</span>
                                <span class="billing-value" id="subtotal">$0.00</span>
                            </div>
                            <div class="billing-row">
                                <span class="billing-label">Tax (estimated):</span>
                                <span class="billing-value" id="tax">$0.00</span>
                            </div>
                            <div class="billing-row total">
                                <span class="billing-label">Total:</span>
                                <span class="billing-value" id="total">$0.00</span>
                            </div>
                        </div>
                        <button type="button" class="submit-btn" onclick="showPaymentModal()">Create Order</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Mark Order As</h3>
            </div>
            <div class="modal-body">
                <p>How would you like to mark this order?</p>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
                <button class="modal-btn modal-btn-unpaid" onclick="createOrder('UNPAID')">Unpaid</button>
                <button class="modal-btn modal-btn-paid" onclick="createOrder('PAID')">Paid</button>
            </div>
        </div>
    </div>

    <div id="loader" class="loader-overlay">
        <div class="loader-content">
            <div class="spinner"></div>
            <p>Creating order...</p>
        </div>
    </div>

    <div id="dataLoader" class="loader-overlay">
        <div class="loader-content">
            <div class="spinner"></div>
            <p>Loading customers and products...</p>
            <p style="font-size: 12px; color: #5c5f62; margin-top: 10px;">This may take a moment for large stores</p>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        var allCustomers = [];
        var allProducts = [];
        var cartItems = [];
        var selectedCustomer = null;
        var taxRate = 0.08;
        var pendingProduct = null;

        function showToast(message, type) {
            var toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast show ' + type;
            setTimeout(function () {
                toast.className = 'toast';
            }, 3000);
        }

        function fetchAllCustomers() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'fetch_customers.php?shop=<?php echo urlencode($shop); ?>', true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                allCustomers = response.customers;
                                document.getElementById('customerLoadingMsg').innerHTML = allCustomers.length + ' customers loaded. Start typing to search.';
                                document.getElementById('customerSearch').disabled = false;
                                showToast('Loaded ' + allCustomers.length + ' customers', 'success');
                            } else {
                                document.getElementById('customerLoadingMsg').innerHTML = 'Error loading customers: ' + response.message;
                                showToast('Error loading customers', 'error');
                            }
                        } catch (e) {
                            document.getElementById('customerLoadingMsg').innerHTML = 'Error parsing customer data';
                            showToast('Error loading customers', 'error');
                        }
                    } else {
                        document.getElementById('customerLoadingMsg').innerHTML = 'Failed to load customers';
                        showToast('Failed to load customers', 'error');
                    }
                }
            };
            xhr.send();
        }

        function fetchAllProducts() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'fetch_products.php?shop=<?php echo urlencode($shop); ?>', true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                allProducts = response.products;
                                var productSelect = document.getElementById('productSelect');
                                productSelect.innerHTML = '<option value="">-- Select a product --</option>';
                                for (var i = 0; i < allProducts.length; i++) {
                                    var product = allProducts[i];
                                    var option = document.createElement('option');
                                    option.value = JSON.stringify(product);
                                    option.setAttribute('data-price', product.price);
                                    option.setAttribute('data-id', product.id);
                                    option.setAttribute('data-variant', product.variant_gid);
                                    option.setAttribute('data-inventory', product.inventory);
                                    option.innerHTML = product.title + ' - $' + parseFloat(product.price).toFixed(2);
                                    productSelect.appendChild(option);
                                }
                                productSelect.disabled = false;
                                showToast('Loaded ' + allProducts.length + ' products', 'success');
                            } else {
                                showToast('Error loading products: ' + response.message, 'error');
                            }
                        } catch (e) {
                            showToast('Error parsing product data', 'error');
                        }
                    } else {
                        showToast('Failed to load products', 'error');
                    }
                }
            };
            xhr.send();
        }

        var customersLoaded = false;
        var productsLoaded = false;

        function checkAllLoaded() {
            if (customersLoaded && productsLoaded) {
                setTimeout(function () {
                    document.getElementById('dataLoader').classList.remove('active');
                }, 500);
            }
        }

        function fetchAllCustomersWithTracking() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'fetch_customers.php?shop=<?php echo urlencode($shop); ?>', true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                allCustomers = response.customers;
                                document.getElementById('customerLoadingMsg').innerHTML = allCustomers.length + ' customers loaded. Start typing to search.';
                                document.getElementById('customerSearch').disabled = false;
                            } else {
                                document.getElementById('customerLoadingMsg').innerHTML = 'Error loading customers: ' + response.message;
                            }
                        } catch (e) {
                            document.getElementById('customerLoadingMsg').innerHTML = 'Error parsing customer data';
                        }
                    } else {
                        document.getElementById('customerLoadingMsg').innerHTML = 'Failed to load customers';
                    }
                    customersLoaded = true;
                    checkAllLoaded();
                }
            };
            xhr.send();
        }

        function fetchAllProductsWithTracking() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'fetch_products.php?shop=<?php echo urlencode($shop); ?>', true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                allProducts = response.products;
                                var productSelect = document.getElementById('productSelect');
                                productSelect.innerHTML = '<option value="">-- Select a product --</option>';
                                for (var i = 0; i < allProducts.length; i++) {
                                    var product = allProducts[i];
                                    var option = document.createElement('option');
                                    option.value = JSON.stringify(product);
                                    option.setAttribute('data-price', product.price);
                                    option.setAttribute('data-id', product.id);
                                    option.setAttribute('data-variant', product.variant_gid);
                                    option.setAttribute('data-inventory', product.inventory);
                                    option.innerHTML = product.title + ' - $' + parseFloat(product.price).toFixed(2);
                                    productSelect.appendChild(option);
                                }
                                productSelect.disabled = false;
                            } else {
                                showToast('Error loading products: ' + response.message, 'error');
                            }
                        } catch (e) {
                            showToast('Error parsing product data', 'error');
                        }
                    } else {
                        showToast('Failed to load products', 'error');
                    }
                    productsLoaded = true;
                    checkAllLoaded();
                }
            };
            xhr.send();
        }

        var customerSearch = document.getElementById('customerSearch');
        var customerDropdown = document.getElementById('customerDropdown');

        if (customerSearch) {
            customerSearch.addEventListener('input', function () {
                var searchTerm = this.value.toLowerCase();
                var filteredCustomers = [];
                for (var i = 0; i < allCustomers.length; i++) {
                    if (allCustomers[i].name.toLowerCase().indexOf(searchTerm) !== -1 ||
                        allCustomers[i].email.toLowerCase().indexOf(searchTerm) !== -1) {
                        filteredCustomers.push(allCustomers[i]);
                    }
                }

                if (searchTerm.length > 0 && filteredCustomers.length > 0) {
                    customerDropdown.classList.add('active');
                    customerDropdown.innerHTML = '';
                    for (var i = 0; i < filteredCustomers.length; i++) {
                        var customer = filteredCustomers[i];
                        var div = document.createElement('div');
                        div.className = 'customer-dropdown-item';
                        div.innerHTML = '<div class="customer-name">' + escapeHtml(customer.name) + '</div>' +
                            '<div class="customer-email">' + escapeHtml(customer.email) + '</div>';
                        div.onclick = (function (c) { return function () { selectCustomer(c); }; })(customer);
                        customerDropdown.appendChild(div);
                    }
                } else if (searchTerm.length > 0 && filteredCustomers.length === 0) {
                    customerDropdown.classList.add('active');
                    customerDropdown.innerHTML = '<div class="customer-dropdown-item">No customers found</div>';
                } else {
                    customerDropdown.classList.remove('active');
                }
            });
        }

        document.addEventListener('click', function (e) {
            if (!customerSearch || !customerDropdown) return;
            if (e.target !== customerSearch && !customerDropdown.contains(e.target)) {
                customerDropdown.classList.remove('active');
            }
        });

        function selectCustomer(customer) {
            selectedCustomer = customer;
            document.getElementById('customerSearch').value = customer.name;
            document.getElementById('firstName').value = customer.firstName || '';
            document.getElementById('lastName').value = customer.lastName || '';
            document.getElementById('email').value = customer.email || '';
            document.getElementById('phone').value = customer.phone || '';
            document.getElementById('customerGid').value = customer.gid;

            var address = '';
            if (customer.address) {
                address = (customer.address.address1 || '') + '\n' +
                    (customer.address.address2 ? customer.address.address2 + '\n' : '') +
                    (customer.address.city || '') + ', ' + (customer.address.province || '') + '\n' +
                    (customer.address.country || '') + ' ' + (customer.address.zip || '');
            }
            document.getElementById('address').value = address;

            customerDropdown.classList.remove('active');
        }

        function onProductSelect() {
            var select = document.getElementById('productSelect');
            var selectedValue = select.value;

            if (!selectedValue) {
                document.getElementById('selectedProductInfo').style.display = 'none';
                document.getElementById('quantityGroup').style.display = 'none';
                return;
            }

            var product = JSON.parse(selectedValue);
            document.getElementById('selectedProductName').innerHTML = product.title + ' - $' + parseFloat(product.price).toFixed(2);
            document.getElementById('selectedProductInfo').style.display = 'block';
            document.getElementById('quantityGroup').style.display = 'block';
            document.getElementById('productQuantity').value = 1;

            selectedProduct = product;
            selectedQuantity = 1;
            calculateTotal();
        }

        function updateQuantityValue() {
            var quantityInput = document.getElementById('productQuantity');
            var quantity = parseInt(quantityInput.value);
            if (isNaN(quantity) || quantity < 1) quantity = 1;
            if (selectedProduct && quantity > selectedProduct.inventory && selectedProduct.inventory > 0) {
                showToast('Not enough inventory. Only ' + selectedProduct.inventory + ' available.', 'error');
                quantity = selectedProduct.inventory;
                quantityInput.value = quantity;
            }
            selectedQuantity = quantity;
            calculateTotal();
        }

        function addSingleProduct() {
            if (!selectedProduct) {
                showToast('Please select a product', 'error');
                return;
            }

            calculateTotal();
            showToast('Product selected: ' + selectedProduct.title + ' (Qty: ' + selectedQuantity + ')', 'success');
        }

        function calculateTotal() {
            var subtotal = 0;
            if (selectedProduct) {
                subtotal = parseFloat(selectedProduct.price) * selectedQuantity;
            }
            var tax = subtotal * taxRate;
            var total = subtotal + tax;

            document.getElementById('subtotal').innerHTML = '$' + subtotal.toFixed(2);
            document.getElementById('tax').innerHTML = '$' + tax.toFixed(2);
            document.getElementById('total').innerHTML = '$' + total.toFixed(2);
        }

        function showPaymentModal() {
            if (!selectedCustomer) {
                showToast('Please select a customer', 'error');
                return;
            }

            if (cartItems.length === 0) {
                showToast('Please add at least one product to cart', 'error');
                return;
            }

            document.getElementById('paymentModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('paymentModal').classList.remove('active');
        }

        // function createOrder(paymentStatus) {
        //     closeModal();

        //     if (cartItems.length === 0) {
        //         showToast('Please add at least one product to cart', 'error');
        //         return;
        //     }

        //     var loader = document.getElementById('loader');
        //     loader.classList.add('active');

        //     var lineItems = [];
        //     for (var i = 0; i < cartItems.length; i++) {
        //         lineItems.push({
        //             variantId: cartItems[i].variant_gid,
        //             quantity: cartItems[i].quantity
        //         });
        //     }

        //     var subtotal = 0;
        //     for (var i = 0; i < cartItems.length; i++) {
        //         subtotal += cartItems[i].price * cartItems[i].quantity;
        //     }
        //     var tax = subtotal * taxRate;
        //     var total = subtotal + tax;

        //     var orderData = {
        //         shop: '<?php echo $shop; ?>',
        //         customer_id: document.getElementById('customerGid').value,
        //         line_items: lineItems,
        //         financial_status: paymentStatus,
        //         subtotal: subtotal,
        //         tax: tax,
        //         total: total,
        //         currency: 'USD'
        //     };

        //     var xhr = new XMLHttpRequest();
        //     xhr.open('POST', '<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/create_order_api.php', true);
        //     xhr.setRequestHeader('Content-Type', 'application/json');

        //     xhr.onreadystatechange = function () {
        //         if (xhr.readyState === 4) {
        //             loader.classList.remove('active');
        //             if (xhr.status === 200) {
        //                 try {
        //                     var response = JSON.parse(xhr.responseText);
        //                     if (response.success) {
        //                         showToast('Order created successfully! Order #: ' + response.order_name, 'success');
        //                         setTimeout(function () {
        //                             window.location.href = 'orders.php?shop=<?php echo urlencode($shop); ?>';
        //                         }, 2000);
        //                     } else {
        //                         showToast('Error creating order: ' + response.message, 'error');
        //                     }
        //                 } catch (e) {
        //                     showToast('Error creating order. Please try again.', 'error');
        //                 }
        //             } else {
        //                 showToast('Network error. Please try again.', 'error');
        //             }
        //         }
        //     };

        //     xhr.send(JSON.stringify(orderData));
        // }
        function createOrder(paymentStatus) {
            closeModal();

            if (cartItems.length === 0) {
                showToast('Please add at least one product to cart', 'error');
                return;
            }

            var loader = document.getElementById('loader');
            loader.classList.add('active');

            var lineItems = [];
            for (var i = 0; i < cartItems.length; i++) {
                lineItems.push({
                    variantId: cartItems[i].variant_gid,
                    quantity: cartItems[i].quantity
                });
            }

            var subtotal = 0;
            for (var i = 0; i < cartItems.length; i++) {
                subtotal += cartItems[i].price * cartItems[i].quantity;
            }
            var tax = subtotal * taxRate;
            var total = subtotal + tax;

            // Get address from the customer's default address
            var addressObj = selectedCustomer.address || {};

            var orderData = {
                shop: '<?php echo $shop; ?>',
                customer_id: document.getElementById('customerGid').value,
                line_items: lineItems,
                financial_status: paymentStatus,
                subtotal: subtotal,
                tax: tax,
                total: total,
                currency: 'USD',
                // Add shipping address fields
                shipping_address: {
                    first_name: selectedCustomer.firstName || '',
                    last_name: selectedCustomer.lastName || '',
                    address1: addressObj.address1 || '',
                    address2: addressObj.address2 || '',
                    city: addressObj.city || '',
                    province: addressObj.province || '',
                    country: addressObj.country || '',
                    zip: addressObj.zip || '',
                    phone: selectedCustomer.phone || ''
                }
            };

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/pages/create_order_api.php', true);
            xhr.setRequestHeader('Content-Type', 'application/json');

            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    loader.classList.remove('active');
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                showToast('Order created successfully! Order #: ' + response.order_name, 'success');
                                setTimeout(function () {
                                    window.location.href = 'orders.php?shop=<?php echo urlencode($shop); ?>';
                                }, 2000);
                            } else {
                                showToast('Error creating order: ' + response.message, 'error');
                            }
                        } catch (e) {
                            showToast('Error creating order. Please try again.', 'error');
                        }
                    } else {
                        showToast('Network error. Please try again.', 'error');
                    }
                }
            };

            xhr.send(JSON.stringify(orderData));
        }

        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('dataLoader').classList.add('active');
            fetchAllCustomersWithTracking();
            fetchAllProductsWithTracking();
        });
        function prepareAddProduct() {
            var select = document.getElementById('productSelect');
            var selectedValue = select.value;

            if (!selectedValue) {
                document.getElementById('addProductSection').style.display = 'none';
                return;
            }

            var product = JSON.parse(selectedValue);
            pendingProduct = product;
            document.getElementById('productQuantity').value = 1;
            document.getElementById('addProductSection').style.display = 'block';
        }

        function addProductToCart() {
            if (!pendingProduct) {
                showToast('Please select a product first', 'error');
                return;
            }

            var quantityInput = document.getElementById('productQuantity');
            var quantity = parseInt(quantityInput.value);
            if (isNaN(quantity) || quantity < 1) quantity = 1;
            if (quantity > pendingProduct.inventory && pendingProduct.inventory > 0) {
                showToast('Not enough inventory. Only ' + pendingProduct.inventory + ' available.', 'error');
                quantity = pendingProduct.inventory;
                quantityInput.value = quantity;
            }
            var existingIndex = -1;
            for (var i = 0; i < cartItems.length; i++) {
                if (cartItems[i].id === pendingProduct.id) {
                    existingIndex = i;
                    break;
                }
            }

            if (existingIndex !== -1) {
                var newQuantity = cartItems[existingIndex].quantity + quantity;
                if (newQuantity > pendingProduct.inventory && pendingProduct.inventory > 0) {
                    showToast('Total quantity would exceed inventory. Max: ' + pendingProduct.inventory, 'error');
                    return;
                }
                cartItems[existingIndex].quantity = newQuantity;
                showToast('Updated ' + pendingProduct.title + ' quantity to ' + newQuantity, 'success');
            } else {
                cartItems.push({
                    id: pendingProduct.id,
                    gid: pendingProduct.gid,
                    variant_gid: pendingProduct.variant_gid,
                    title: pendingProduct.title,
                    price: parseFloat(pendingProduct.price),
                    quantity: quantity,
                    inventory: pendingProduct.inventory
                });
                showToast('Added ' + pendingProduct.title + ' (Qty: ' + quantity + ') to cart', 'success');
            }

            document.getElementById('productSelect').value = '';
            document.getElementById('addProductSection').style.display = 'none';
            pendingProduct = null;

            renderCart();
            calculateTotal();
        }

        function updateCartItemQuantity(index, newQuantity) {
            newQuantity = parseInt(newQuantity);
            if (isNaN(newQuantity) || newQuantity < 1) newQuantity = 1;

            var product = cartItems[index];
            if (newQuantity > product.inventory && product.inventory > 0) {
                showToast('Not enough inventory. Only ' + product.inventory + ' available.', 'error');
                newQuantity = product.inventory;
            }

            cartItems[index].quantity = newQuantity;
            renderCart();
            calculateTotal();
        }

        function removeFromCart(index) {
            var removedProduct = cartItems[index];
            cartItems.splice(index, 1);
            showToast('Removed ' + removedProduct.title + ' from cart', 'success');
            renderCart();
            calculateTotal();
        }

        function renderCart() {
            var container = document.getElementById('cartItemsList');
            if (!container) return;

            if (cartItems.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 20px; color: #5c5f62; border: 1px dashed #e1e3e5; border-radius: 8px;">No items in cart</div>';
                return;
            }

            var html = '';
            for (var i = 0; i < cartItems.length; i++) {
                var item = cartItems[i];
                var itemTotal = item.price * item.quantity;
                html += '<div class="cart-item" style="background: #fafbfb; border: 1px solid #e1e3e5; border-radius: 8px; padding: 12px; margin-bottom: 12px;">' +
                    '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">' +
                    '<span style="font-weight: 500; color: #202223;">' + escapeHtml(item.title) + '</span>' +
                    '<span style="color: #008060; font-weight: 600;">$' + item.price.toFixed(2) + '</span>' +
                    '</div>' +
                    '<div style="display: flex; justify-content: space-between; align-items: center;">' +
                    '<div style="display: flex; align-items: center; gap: 8px;">' +
                    '<label style="font-size: 12px; color: #5c5f62;">Quantity:</label>' +
                    '<input type="number" min="1" value="' + item.quantity + '" onchange="updateCartItemQuantity(' + i + ', this.value)" style="width: 70px; padding: 4px 6px; border: 1px solid #d9d9d9; border-radius: 4px;">' +
                    '</div>' +
                    '<div>' +
                    '<span style="font-weight: 600;">$' + itemTotal.toFixed(2) + '</span>' +
                    '<button onclick="removeFromCart(' + i + ')" style="background: #d82c0d; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; margin-left: 10px; font-size: 11px;">Remove</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>';
            }
            container.innerHTML = html;
        }

        function calculateTotal() {
            var subtotal = 0;
            for (var i = 0; i < cartItems.length; i++) {
                subtotal += cartItems[i].price * cartItems[i].quantity;
            }
            var tax = subtotal * taxRate;
            var total = subtotal + tax;

            document.getElementById('subtotal').innerHTML = '$' + subtotal.toFixed(2);
            document.getElementById('tax').innerHTML = '$' + tax.toFixed(2);
            document.getElementById('total').innerHTML = '$' + total.toFixed(2);
        }
    </script>
</body>

</html>