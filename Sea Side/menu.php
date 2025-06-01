<?php
// menu.php
session_start();
require_once 'db_config.php';
error_log("SESSION: " . print_r($_SESSION, true));

if (isset($_POST['add_to_cart']) && isset($_POST['item_id'])) {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'redirect', 'url' => 'index.php']);
        exit();
    }
    $user_id = $_SESSION['user_id'];

    
    $current_reservation_id_for_cart = $_SESSION['reservation_id'] ?? null;
    
    
    if ($current_reservation_id_for_cart !== null) {
        try {
            $stmt_validate_reservation = $pdo->prepare("SELECT id FROM reservations WHERE id = ?");
            $stmt_validate_reservation->execute([$current_reservation_id_for_cart]);
            $valid_reservation = $stmt_validate_reservation->fetch();
            if (!$valid_reservation) {
                error_log("MENU.PHP - ADD_TO_CART: \$_SESSION['reservation_id'] ('" . $current_reservation_id_for_cart . "') NOT FOUND in reservations table. Proceeding with NULL.");
                $current_reservation_id_for_cart = null; 
            } else {
                $current_reservation_id_for_cart = $valid_reservation['id'];
            }
        } catch (PDOException $e) {
            error_log("MENU.PHP - ADD_TO_CART DB VALIDATION EXCEPTION: " . $e->getMessage());
            $current_reservation_id_for_cart = null; 
        }
    }

    $item_id_from_form = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
    $quantity_to_add = isset($_POST['quantity']) ? filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT) : 1;

    if ($item_id_from_form === false || $quantity_to_add === false || $quantity_to_add <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid item data provided.']);
        exit();
    }

    try {
        $stmt_menu_item = $pdo->prepare("SELECT name, price FROM menu_items WHERE id = ?");
        $stmt_menu_item->execute([$item_id_from_form]);
        $menu_item_details = $stmt_menu_item->fetch();

        if (!$menu_item_details) {
            echo json_encode(['status' => 'error', 'message' => 'Menu item not found in our records.']);
            exit();
        }

        $item_name_from_db = $menu_item_details['name'];
        $item_price_from_db = (float)$menu_item_details['price'];

        
        $stmt_check_cart = $pdo->prepare(
            "SELECT id, quantity FROM cart_items WHERE user_id = :user_id AND item_id = :item_id AND (reservation_id = :reservation_id OR (reservation_id IS NULL AND :reservation_id IS NULL))"
        );
        $stmt_check_cart->bindValue(':user_id', $user_id);
        $stmt_check_cart->bindValue(':item_id', $item_id_from_form);
        $stmt_check_cart->bindValue(':reservation_id', $current_reservation_id_for_cart, PDO::PARAM_INT); 
        $stmt_check_cart->execute();
        $existing_cart_item = $stmt_check_cart->fetch();

        $current_total_quantity_in_cart_for_item = 0;

        if ($existing_cart_item) {
            $new_quantity = $existing_cart_item['quantity'] + $quantity_to_add;
            $update_stmt = $pdo->prepare(
                "UPDATE cart_items SET quantity = :quantity WHERE id = :cart_item_id"
            );
            $update_stmt->execute([
                ':quantity' => $new_quantity,
                ':cart_item_id' => $existing_cart_item['id']
            ]);
            $current_total_quantity_in_cart_for_item = $new_quantity;
        } else {
            $insert_stmt = $pdo->prepare(
                "INSERT INTO cart_items (user_id, item_id, quantity, reservation_id) VALUES (:user_id, :item_id, :quantity, :reservation_id)"
            );
            $insert_stmt->bindValue(':user_id', $user_id);
            $insert_stmt->bindValue(':item_id', $item_id_from_form);
            $insert_stmt->bindValue(':quantity', $quantity_to_add);
            $insert_stmt->bindValue(':reservation_id', $current_reservation_id_for_cart, PDO::PARAM_INT); // Bind as INT or NULL
            $insert_stmt->execute();
            $current_total_quantity_in_cart_for_item = $quantity_to_add;
        }

        $item_sub_total = $item_price_from_db * $current_total_quantity_in_cart_for_item;

        echo json_encode([
            'status' => 'added',
            'name' => $item_name_from_db,
            'quantity_added' => $quantity_to_add,
            'current_item_total_quantity' => $current_total_quantity_in_cart_for_item,
            'price_per_item' => number_format($item_price_from_db, 2),
            'item_sub_total' => number_format($item_sub_total, 2)
        ]);
        exit();

    } catch (PDOException $e) {
        error_log("MENU.PHP - ADD_TO_CART DB EXCEPTION: " . $e->getMessage() . " (SQLSTATE: " . $e->getCode() . ")");
        echo json_encode(['status' => 'error', 'message' => 'Could not add item to your pre-order. A database error occurred. Please try again or contact support if the issue persists.']);
        exit();
    }
}

if (isset($_POST['update_quantity']) && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);

    if ($item_id === false || $quantity === false ) {
         echo json_encode(['status' => 'error', 'message' => 'Invalid data for update.']);
         exit();
    }
    
    $current_reservation_id_for_cart_update = $_SESSION['reservation_id'] ?? null;

    try {
        if ($quantity > 0) {
            
            $stmt = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE user_id = ? AND item_id = ? AND (reservation_id = ? OR (reservation_id IS NULL AND ? IS NULL))");
            $stmt->execute([$quantity, $user_id, $item_id, $current_reservation_id_for_cart_update, $current_reservation_id_for_cart_update]);
            
            $price_stmt = $pdo->prepare("SELECT price FROM menu_items WHERE id = ?");
            $price_stmt->execute([$item_id]);
            $menu_item_price = (float)$price_stmt->fetchColumn();
            $new_total = $menu_item_price * $quantity;

            echo json_encode(['status' => 'updated', 'id' => $item_id, 'quantity' => $quantity, 'total' => number_format($new_total, 2)]);
        } else { 
            
            $stmt = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND item_id = ? AND (reservation_id = ? OR (reservation_id IS NULL AND ? IS NULL))");
            $stmt->execute([$user_id, $item_id, $current_reservation_id_for_cart_update, $current_reservation_id_for_cart_update]);
            echo json_encode(['status' => 'removed', 'id' => $item_id]);
        }
        exit();
    } catch (PDOException $e) {
        error_log("Update quantity error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Could not update item quantity.']);
        exit();
    }
}

$menu_categories_db = [];
try {
    $category_stmt = $pdo->query("SELECT DISTINCT category FROM menu_items ORDER BY category ASC");
    $db_categories = $category_stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($db_categories as $category_name) {
        $items_stmt = $pdo->prepare("SELECT id, name, price FROM menu_items WHERE category = ? ORDER BY name ASC");
        $items_stmt->execute([$category_name]);
        $menu_categories_db[$category_name] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Menu fetching error: " . $e->getMessage());
    $menu_categories_db = [];
    $database_error_message = "Error loading menu items from the database.";
}

$initial_cart_item_count = 0;
if (isset($_SESSION['user_id'])) {
    try {
        
        $current_reservation_id_for_count = $_SESSION['reservation_id'] ?? null;
        $count_stmt = $pdo->prepare(
            "SELECT SUM(quantity) as total_items FROM cart_items WHERE user_id = ? AND (reservation_id = ? OR (reservation_id IS NULL AND ? IS NULL))"
        );
        $count_stmt->execute([$_SESSION['user_id'], $current_reservation_id_for_count, $current_reservation_id_for_count]);
        $result = $count_stmt->fetch();
        $initial_cart_item_count = $result['total_items'] ?? 0;
    } catch (PDOException $e) {
        error_log("Initial cart count error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu | Seaside Restaurant</title>
    <style>
        
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Courier New', Courier, monospace; }
        html { font-size: 16px; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background: linear-gradient( #2e938e, #2e6193); padding: 1rem; color: #333; line-height: 1.6; }
        img { max-width: 100%; height: auto; }
        header { position: fixed; top: 0; left: 0; width: 100%; background-color: rgba(0, 0, 0, 0.4); color: #070354; padding: 0.5rem 1rem; display: flex; align-items: center; justify-content: space-between; z-index: 100; }
        .logo { display: flex; align-items: center; }
        .logo img { width: 3rem; height: 3rem; }
        nav ul {
    list-style: none;
    display: flex;
}

nav ul li {
    margin: 0 15px;
}

nav ul li a {
    color: white;
    text-decoration: none;
    font-size: 18px;
    font-weight: bold;
    transition: all ease 0.5s;
}

nav ul li a:hover {
    background-color: #000b41;
    padding: 5px 10px;
    border-radius: 5px;
}

        .cart-container { display: flex; justify-content: flex-end; width: 95%; max-width: 1200px; margin: 0 auto; position: relative; z-index: 101; margin-top: 5rem; padding-top: 1rem; }
        .cart { text-align: right; padding: 0.5rem 1rem; background: #0f6382; border-radius: 1.5rem; margin-bottom: 1.5rem; align-self: flex-start; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.2); }
        .cart a { color: #e0f7fa; font-weight: bold; text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; }
        .cart a span { margin-left: 0.25rem; }
        .menu-title { text-align: center; margin-top: 1rem; margin-bottom: 1.5rem; font-size: 2rem; color: #fff; }
        .menu-categories { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.5rem; margin-bottom: 1.5rem; }
        .menu-categories button { background: #538c99; color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 0.5rem; font-size: 0.9rem; cursor: pointer; transition: transform 0.2s ease, background-color 0.2s ease; }
        .menu-categories button:hover, .menu-categories button:focus { background: #006064; transform: scale(1.05); }
        .menu-list { max-width: 1000px; margin: 1rem auto; display: none; animation: fadeIn 0.3s ease-in-out; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 1rem; }
        .menu-list.active { display: grid; }
        .menu-item { background: white; border-radius: 0.75rem; padding: 1rem; box-shadow: 0 0.25rem 0.75rem rgba(0,0,0,0.1); animation: slideUp 0.3s ease; position: relative; display: flex; flex-direction: column; justify-content: space-between; }
        .menu-item h3 { margin: 0 0 0.25rem 0; font-size: 1.2rem; color: #006064; line-height: 1.3; }
        .menu-item p { margin: 0.5rem 0; font-size: 0.8rem; color: #555; flex-grow: 1; }
        .menu-item form button { background: #0f6383; color: white; padding: 0.5rem 0.75rem; border: none; border-radius: 0.3rem; cursor: pointer; transition: background-color 0.3s ease, transform 0.2s ease; font-size: 0.9rem; width: 100%; margin-top: 0.5rem; }
        .menu-item form button:hover, .menu-item form button:focus { background: #006064; transform: scale(1.02); }
        @keyframes slideUp { from { transform: translateY(0.5rem); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        h2 { font-size: 1.2rem; font-weight: bold; color: #e6e6e6; font-family: Copperplate, Papyrus, fantasy; }
        #confirmation-modal { display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background-color: white; padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.2); z-index: 1000; text-align: center; width: 90%; max-width: 400px; }
        #confirmation-modal h4 { margin-top: 0; margin-bottom: 1rem; font-size: 1.25rem; color: #006064; }
        #confirmation-modal .item-details { margin-bottom: 1rem; font-size: 0.9rem; }
        #confirmation-modal .quantity-controls { margin-bottom: 1rem; display: flex; justify-content: center; align-items: center; }
        #confirmation-modal .quantity-controls button { background: #0f6383; color: white; padding: 0.4rem 0.6rem; border: none; border-radius: 0.2rem; cursor: pointer; margin: 0 0.5rem; font-size: 1rem; line-height: 1; }
        #confirmation-modal .quantity-controls button:hover, #confirmation-modal .quantity-controls button:focus { background: #007c91; }
        #confirmation-modal .quantity-controls span { margin: 0 0.5rem; font-weight: bold; font-size: 1.1rem; }
        #confirmation-modal .price-info { font-size: 1.1rem; font-weight: bold; color: #2e6193; margin-bottom: 1.25rem; }
        #confirmation-modal .actions button { color: white; padding: 0.6rem 1rem; border: none; border-radius: 0.25rem; cursor: pointer; margin: 0 0.3rem; font-size: 0.9rem; transition: background-color 0.2s ease; }
        #confirmation-modal .actions button.confirm-btn { background: #4CAF50; }
        #confirmation-modal .actions button.confirm-btn:hover, #confirmation-modal .actions button.confirm-btn:focus { background: #45a049; }
        #confirmation-modal .actions button.cancel-btn { background: #f44336; }
        #confirmation-modal .actions button.cancel-btn:hover, #confirmation-modal .actions button.cancel-btn:focus { background: #d32f2f; }
        #confirmation-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6); z-index: 999; }
        #notification-message { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); background-color: #4CAF50; color: white; padding: 0.75rem 1.5rem; border-radius: 0.3rem; z-index: 1001; opacity: 0; transition: opacity 0.5s ease-in-out, top 0.5s ease-in-out; font-size: 0.9rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.2); }
        #notification-message.show { opacity: 1; top: 1.5rem; }
        @media (max-width: 600px) { 
            body { padding: 1rem 0.5rem; }
            header { flex-direction: column; align-items: center; padding: 0.5rem; height: auto; }
            .logo { margin-bottom: 0.5rem; }
            .logo img { margin-left: 0; }
            nav { width: 100%; }
            nav ul { flex-direction: column; width: 100%; align-items: center; }
            nav ul li { display: block; margin: 0.25rem 0; width: 90%; text-align: center; }
            nav ul li a { padding: 0.75rem 0; width: 100%; }
            .cart-container { margin-top: 8rem; padding-top: 0.5rem; width: 100%; justify-content: center; }
            .cart { text-align: center; margin: 1rem auto; border-radius: 0.5rem; width: auto; display: inline-block; }
            .menu-title { font-size: 1.75rem; }
            .menu-categories { gap: 0.3rem; }
            .menu-categories button { padding: 0.5rem 1rem; font-size: 0.85rem; flex-grow: 1; min-width: 100px; }
            .menu-list { grid-template-columns: 1fr; gap: 0.75rem; }
            .menu-item { padding: 0.75rem; }
            .menu-item h3 { font-size: 1.1rem; }
            .menu-item p { font-size: 0.75rem; }
            .menu-item form button { font-size: 0.85rem; padding: 0.5rem 0.7rem; }
            #confirmation-modal { padding: 1rem; }
            #confirmation-modal h4 { font-size: 1.1rem; }
        }
        @media (min-width: 600.02px) and (max-width: 900px) {
             .menu-list { grid-template-columns: repeat(auto-fit, minmax(11.25rem, 1fr)); gap: 0.85rem; }
            .cart-container { margin-top: 5rem; padding-top: 0.75rem; }
             .menu-item h3 { font-size: 1.15rem; }
            .menu-item p { font-size: 0.78rem; }
        }
    </style>
</head>
<body>

<header>
    <div class="logo">
        <a href="user_page.php">
            <img src="logo.png" alt="Restaurant logo">
        </a>
    </div>
    <nav>
        <ul>
            <li><a href="about.php">About</a></li>
            <li><a href="menu.php">Menu</a></li>
            <li><a href="reservation.php">Reservation</a></li>
            <li><a href="gallery.php">Gallery</a></li>
            <li><a href="index.php">Account</a></li>
        </ul>
    </nav>
</header>
<div class="cart-container">
    <div class="cart">
        <a href="<?= isset($_SESSION['user_id']) ? 'cart.php' : 'index.php' ?>">
            🛒 Review Order (<span id="cart-item-count"><?= htmlspecialchars((string)$initial_cart_item_count, ENT_QUOTES) ?></span>)
        </a>
    </div>
</div>

<section id="menu">
    <h1 class="menu-title">MENU</h1>
    <?php if (isset($database_error_message)): ?>
        <p style="color:white; text-align:center; background-color: #d32f2f; padding: 1rem; border-radius: 0.25rem;"><?= htmlspecialchars($database_error_message, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="menu-categories">
        <?php
        if (empty($menu_categories_db) && !isset($database_error_message)) {
            echo "<p style='color:white; text-align:center;'>No menu categories found. Please populate the menu_items table.</p>";
        } elseif (!empty($menu_categories_db)) {
            foreach (array_keys($menu_categories_db) as $category_name) {
                echo "<button type=\"button\" onclick=\"showMenu('category-" . htmlspecialchars(str_replace(' ', '-', $category_name), ENT_QUOTES) . "')\">" . htmlspecialchars($category_name, ENT_QUOTES) . "</button>";
            }
        }
        ?>
    </div>

    <?php foreach ($menu_categories_db as $category_name => $items): ?>
        <div class="menu-list" id="menu-category-<?= htmlspecialchars(str_replace(' ', '-', $category_name), ENT_QUOTES) ?>">
            <?php if (empty($items)): ?>
                <p style='color:white;'>No items available in this category.</p>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <div class="menu-item">
                        <h3><?= htmlspecialchars($item['name'], ENT_QUOTES) ?></h3>
                        <p>PHP <?= isset($item['price']) ? number_format((float)$item['price'], 2) : '—' ?></p>
                        <?php
                        if (isset($item['price'], $item['id'])):
                        ?>
                            <form method="POST" class="add-to-cart-form">
                                <input type="hidden" name="item_id" value="<?= htmlspecialchars((string)$item['id'], ENT_QUOTES) ?>">
                                <input type="hidden" name="item_name" value="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>">
                                <input type="hidden" name="item_price" value="<?= htmlspecialchars((string)$item['price'], ENT_QUOTES) ?>">
                                <button type="button" class="add-to-preorder-btn">Add to Pre-order</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>

<div id="confirmation-modal" style="display: none;">
    <h4>Confirm Pre-order</h4>
    <div class="item-details">
        <p><span id="modal-item-name"></span></p>
        <p>Price: PHP <span id="modal-item-price"></span></p>
    </div>
    <div class="quantity-controls">
        <button type="button" onclick="decreaseQuantityModal()" aria-label="Decrease quantity">-</button>
        <span id="modal-quantity" aria-live="polite">1</span>
        <button type="button" onclick="increaseQuantityModal()" aria-label="Increase quantity">+</button>
    </div>
    <p class="price-info">Total: PHP <span id="modal-total-price"></span></p>
    <div class="actions">
        <button type="button" class="confirm-btn" onclick="addToCart()">Confirm Add</button>
        <button type="button" class="cancel-btn" onclick="closeConfirmationModal()">Cancel</button>
    </div>
</div>

<div id="confirmation-overlay" style="display: none;" onclick="closeConfirmationModal()"></div>
<div id="notification-message" role="alert" aria-live="assertive"></div>

<script>
    let currentItem = {
        id: null, 
        name: null,
        price: null,
        quantity: 1
    };
    const confirmationModal = document.getElementById('confirmation-modal');
    const confirmationOverlay = document.getElementById('confirmation-overlay');
    const modalItemName = document.getElementById('modal-item-name');
    const modalItemPrice = document.getElementById('modal-item-price');
    const modalQuantity = document.getElementById('modal-quantity');
    const modalTotalPrice = document.getElementById('modal-total-price');
    const cartItemCountSpan = document.getElementById('cart-item-count');
    const notificationMessageDiv = document.getElementById('notification-message');

    document.querySelectorAll('.add-to-preorder-btn').forEach(button => {
        button.addEventListener('click', function() {
            const form = this.closest('.add-to-cart-form');
            currentItem.id = form.querySelector('input[name="item_id"]').value;
            currentItem.name = form.querySelector('input[name="item_name"]').value;
            currentItem.price = parseFloat(form.querySelector('input[name="item_price"]').value);
            currentItem.quantity = 1;

            modalItemName.textContent = currentItem.name;
            modalItemPrice.textContent = currentItem.price.toFixed(2);
            updateModalDisplay();
            openConfirmationModal();
        });
    });

    function openConfirmationModal() {
        confirmationModal.style.display = 'block';
        confirmationOverlay.style.display = 'block';
        const firstFocusableElement = confirmationModal.querySelector('.quantity-controls button, .actions button');
        if (firstFocusableElement) {
            firstFocusableElement.focus();
        }
    }

    function closeConfirmationModal() {
        confirmationModal.style.display = 'none';
        confirmationOverlay.style.display = 'none';
    }
    
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && confirmationModal.style.display === 'block') {
            closeConfirmationModal();
        }
    });

    function updateModalDisplay() {
        modalQuantity.textContent = currentItem.quantity;
        const total = currentItem.price * currentItem.quantity;
        modalTotalPrice.textContent = total.toFixed(2);
    }

    function increaseQuantityModal() {
        currentItem.quantity++;
        updateModalDisplay();
    }

    function decreaseQuantityModal() {
        if (currentItem.quantity > 1) {
            currentItem.quantity--;
            updateModalDisplay();
        }
    }

    function addToCart() {
        const formData = new FormData();
        formData.append('add_to_cart', 'true');
        formData.append('item_id', currentItem.id); 
        formData.append('quantity', currentItem.quantity);
        
        fetch('menu.php', {
            method: 'POST',
            body: formData,
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            closeConfirmationModal();
            if (data.status === 'added') {
                showNotification(`${data.name} (x${currentItem.quantity} added, total in cart: ${data.current_item_total_quantity}) to pre-order! Subtotal: PHP ${data.item_sub_total}`);
                updateCartCount();
            } else if (data.status === 'redirect') {
                window.location.href = data.url;
            } else {
                showNotification(data.message || 'Error adding to pre-order.', true);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('An error occurred: ' + error.message, true);
            closeConfirmationModal();
        });
    }

    function updateCartCount() {
        fetch('cart_count.php') 
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok for cart count.');
            }
            return response.text();
        })
        .then(count => {
            cartItemCountSpan.textContent = count;
        })
        .catch(error => console.error('Error fetching cart count:', error));
    }

    function showNotification(message, isError = false) {
        notificationMessageDiv.textContent = message;
        notificationMessageDiv.className = 'show';
        notificationMessageDiv.style.backgroundColor = isError ? '#f44336' : '#4CAF50';
        notificationMessageDiv.setAttribute('aria-hidden', 'false');
        setTimeout(() => {
            notificationMessageDiv.className = '';
            notificationMessageDiv.setAttribute('aria-hidden', 'true');
        }, 4000); 
    }

    function showMenu(categoryMenuId) {
        document.querySelectorAll('.menu-list').forEach(list => {
            list.classList.remove('active');
        });
        const activeMenu = document.getElementById('menu-' + categoryMenuId); 
        if (activeMenu) {
            activeMenu.classList.add('active');
        }
    }

    window.onload = function () {
        const firstCategoryButton = document.querySelector('.menu-categories button');
        if (firstCategoryButton) {
            const onclickAttr = firstCategoryButton.getAttribute('onclick');
            if (onclickAttr) {
                const match = onclickAttr.match(/showMenu\('([^']+)'\)/);
                if (match && match[1]) {
                    showMenu(match[1]); 
                }
            }
        }
        updateCartCount(); 
    };
</script>

</body>
</html>