<?php
// cart.php
session_start();
require_once 'db_config.php'; // Your database connection


if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_message'] = "Please log in to view your cart.";
    header("Location: index.php"); 
    exit();
}
$user_id = $_SESSION['user_id'];


$hasActiveReservation = isset($_SESSION['reservation_id']);
$current_reservation_id = $_SESSION['reservation_id'] ?? null; 


$cart_items_db = [];
$total_cart_price = 0;


if (isset($_POST['remove_item']) && isset($_POST['item_id_to_remove'])) {
    $item_id_to_remove = filter_input(INPUT_POST, 'item_id_to_remove', FILTER_VALIDATE_INT);
    if ($item_id_to_remove) {
        try {
            
            $stmt = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND item_id = ? AND (reservation_id = ? OR (reservation_id IS NULL AND ? IS NULL))");
            $stmt->execute([$user_id, $item_id_to_remove, $current_reservation_id, $current_reservation_id]);
            $_SESSION['success_message'] = "Item removed from your order.";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error removing item: " . $e->getMessage();
        }
        header("Location: cart.php");
        exit();
    }
}


if (isset($_POST['update_quantity_action']) && isset($_POST['item_id_to_update'])) {
    $item_id_to_update = filter_input(INPUT_POST, 'item_id_to_update', FILTER_VALIDATE_INT);
    $change = ($_POST['update_quantity_action'] === 'increase') ? 1 : -1;

    if ($item_id_to_update) {
        try {
            
            $stmt_curr = $pdo->prepare("SELECT quantity FROM cart_items WHERE user_id = ? AND item_id = ? AND (reservation_id = ? OR (reservation_id IS NULL AND ? IS NULL))");
            $stmt_curr->execute([$user_id, $item_id_to_update, $current_reservation_id, $current_reservation_id]);
            $current_item = $stmt_curr->fetch();

            if ($current_item) {
                $new_quantity = $current_item['quantity'] + $change;
                if ($new_quantity > 0) {
                    
                    $stmt_update = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE user_id = ? AND item_id = ? AND (reservation_id = ? OR (reservation_id IS NULL AND ? IS NULL))");
                    $stmt_update->execute([$new_quantity, $user_id, $item_id_to_update, $current_reservation_id, $current_reservation_id]);
                    $_SESSION['success_message'] = "Quantity updated.";
                } else { 
                    
                    $stmt_delete = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND item_id = ? AND (reservation_id = ? OR (reservation_id IS NULL AND ? IS NULL))");
                    $stmt_delete->execute([$user_id, $item_id_to_update, $current_reservation_id, $current_reservation_id]);
                    $_SESSION['success_message'] = "Item removed as quantity reached zero.";
                }
            }
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating quantity: " . $e->getMessage();
        }
        header("Location: cart.php");
        exit();
    }
}

    
if (isset($_POST['clear_cart'])) {
    try {
        
        $stmt = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND (reservation_id = ? OR (reservation_id IS NULL AND ? IS NULL))");
        $stmt->execute([$user_id, $current_reservation_id, $current_reservation_id]);
        $_SESSION['success_message'] = "Your order has been cleared.";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error clearing order: " . $e->getMessage();
    }
    header("Location: cart.php");
    exit();
}


try {
    $stmt = $pdo->prepare(
        "SELECT ci.item_id, ci.quantity, mi.name, mi.price
         FROM cart_items ci
         JOIN menu_items mi ON ci.item_id = mi.id
         WHERE ci.user_id = ? AND (ci.reservation_id = ? OR (ci.reservation_id IS NULL AND ? IS NULL))"
    );
    $stmt->execute([$user_id, $current_reservation_id, $current_reservation_id]);
    $cart_items_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cart_items_db as $item) {
        $total_cart_price += $item['price'] * $item['quantity'];
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error fetching cart items: " . $e->getMessage();
    $cart_items_db = []; 
}


$canProceedToCheckout = $hasActiveReservation; 

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Order | Seaside Restaurant</title>
    <style>
        :root {
            --primary-font: 'Segoe UI', sans-serif;
            --bg-gradient-start: #e0f7fa;
            --bg-gradient-end: #b2ebf2;
            --container-bg: white;
            --primary-text-color: #006064; 
            --secondary-text-color: #37474f; 
            --table-header-bg: #e0f2f1;
            --table-header-text: #00695c;
            --border-color: #ccc;
            --button-danger-bg: #d32f2f;
            --button-danger-hover-bg: #b71c1c;
            --button-warning-bg: #ff7043;
            --button-warning-hover-bg: #d84315;
            --button-success-bg: #4CAF50;
            --button-success-hover-bg: #45a049;
            --button-info-bg: #1e88e5;
            --button-info-hover-bg: #1565c0;
            --button-disabled-bg: #9e9e9e;
            --base-font-size: 16px;
        }
        * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Courier New', Courier, monospace;
}

        html {
            font-size: var(--base-font-size);
            scroll-behavior: smooth;
        }
        body {
            font-family: var(--primary-font);
            background: linear-gradient(#2e6193, #2e938e);
            margin: 0;
            padding: 1.25rem; 
            color: var(--secondary-text-color);
            line-height: 1.6;
        }
        .container {
            max-width: 50rem;
            margin: 1rem auto; 
            background: var(--container-bg);
            padding: 1.5rem; 
            border-radius: 0.75rem; 
            box-shadow: 0 0.25rem 0.75rem rgba(0,0,0,0.1);
        }
        h1 {
            text-align: center;
            color: var(--primary-text-color);
            margin-top: 0;
            margin-bottom: 1.875rem; 
            font-size: 2rem; 
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.25rem; 
        }
        table th, table td {
            padding: 0.75rem; 
            border-bottom: 1px solid var(--border-color);
            text-align: left;
            vertical-align: middle;
        }
        table th {
            background: var(--table-header-bg);
            color: var(--table-header-text);
            font-size: 0.9rem;
            text-transform: uppercase;
        }
        .item-details span {
            
            font-weight: 500;
        }
        .quantity-controls {
            display: flex;
            align-items: center;
            white-space: nowrap; 
        }
        .quantity-controls button, .quantity-controls input[type="submit"] {
            background: none;
            border: 1px solid var(--border-color);
            padding: 0.3125rem 0.625rem; 
            margin: 0 0.3125rem; 
            cursor: pointer;
            border-radius: 0.25rem; 
            font-size: 1rem;
            line-height: 1;
        }
         .quantity-controls button:hover, .quantity-controls input[type="submit"]:hover {
            background-color: #f0f0f0;
        }
        .quantity-controls input[type="number"] { 
            width: 3.125rem; 
            text-align: center;
            padding: 0.3125rem; 
            border: 1px solid var(--border-color);
            border-radius: 0.25rem; 
            -moz-appearance: textfield;
        }
        .quantity-controls input[type="number"]::-webkit-outer-spin-button,
        .quantity-controls input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .actions {
            display: flex;
            flex-wrap: wrap; 
            justify-content: space-between;
            align-items: center;
            margin-top: 1.25rem; 
            gap: 0.625rem; 
        }
        .actions form, .actions a { 
            margin: 0;
        }
        .action-button { 
            color: white;
            padding: 0.625rem 1rem; 
            border: none;
            border-radius: 0.375rem; 
            cursor: pointer;
            transition: background 0.3s ease, transform 0.2s ease;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-block;
        }
        .action-button:hover {
            transform: scale(1.03);
        }
        .clear-btn { background: var(--button-danger-bg); }
        .clear-btn:hover { background: var(--button-danger-hover-bg); }
        .menu-btn { background: var(--button-info-bg); } 
        .menu-btn:hover { background: var(--button-info-hover-bg); }
        .remove-btn { background: var(--button-warning-bg); font-size: 0.8rem; padding: 0.4rem 0.8rem;}
        .remove-btn:hover { background: var(--button-warning-hover-bg); }
        .checkout-btn { background: var(--button-success-bg); }
        .checkout-btn:hover { background: var(--button-success-hover-bg); }
        .disabled-btn, .disabled-btn:hover {
            background: var(--button-disabled-bg);
            cursor: not-allowed;
            transform: none;
        }
        .total {
            text-align: right;
            margin-top: 1.25rem; 
            font-size: 1.125rem; 
            font-weight: bold;
            color: var(--table-header-text); 
        }
        .message {
            text-align: center;
            padding: 1rem;
            margin-bottom: 1.25rem; 
            border-radius: 0.375rem; 
        }
        .message.error { color: #D8000C; background-color: #FFD2D2; }
        .message.success { color: #4F8A10; background-color: #DFF2BF; }
        .message.info { color: #00529B; background-color: #BDE5F8; }

        .message a.link-button { 
            color: white;
            text-decoration: none;
            padding: 0.5rem 0.9375rem;
            border-radius: 0.3125rem; 
            background: var(--button-info-bg);
            display: inline-block;
            margin-left: 0.5rem;
        }
        .message a.link-button:hover {
             background: var(--button-info-hover-bg);
        }

        
        @media screen and (max-width: 40em) { 
            table {
                font-size: 0.85rem;
            }
            table th, table td {
                padding: 0.5rem 0.4rem; 
            }
            .quantity-controls input[type="number"] {
                width: 2.5rem; 
            }
            .actions {
                flex-direction: column; 
                align-items: stretch; 
            }
            .actions form, .actions a, .actions .action-button {
                width: 100%; 
                margin-bottom: 0.625rem; 
            }
            .actions .action-button {
                text-align: center;
            }
            .total {
                text-align: center; 
                font-size: 1.25rem;
            }
            h1 {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h1>Your Order</h1>

    <?php if (isset($_SESSION['error_message'])): ?>
        <p class="message error"><?= htmlspecialchars($_SESSION['error_message'], ENT_QUOTES) ?></p>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['success_message'])): ?>
        <p class="message success"><?= htmlspecialchars($_SESSION['success_message'], ENT_QUOTES) ?></p>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>


    <?php if (!$hasActiveReservation): ?>
        <p class="message info">You need to <a href="reservation.php" class="link-button">make a reservation</a> before you can proceed to checkout with your pre-order.</p>
    <?php elseif (empty($cart_items_db)): ?>
        <p style="text-align: center;" class="message">Your order is empty for the current reservation.</p>
        <div style="text-align: center; margin-top: 1.25rem;">
            <a href="menu.php" class="action-button menu-btn">← Browse Menu</a>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th>Total</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cart_items_db as $item):
                    $item_total = $item['price'] * $item['quantity'];
                ?>
                    <tr>
                        <td>
                            <div class="item-details">
                                <span><?= htmlspecialchars($item['name'], ENT_QUOTES) ?></span>
                            </div>
                        </td>
                        <td>PHP <?= number_format($item['price'], 2) ?></td>
                        <td>
                            <div class="quantity-controls">
                                <form method="post" action="cart.php" style="display: inline;">
                                    <input type="hidden" name="item_id_to_update" value="<?= $item['item_id'] ?>">
                                    <button type="submit" name="update_quantity_action" value="decrease" aria-label="Decrease quantity of <?= htmlspecialchars($item['name']) ?>">-</button>
                                </form>
                                <input type="number" value="<?= $item['quantity'] ?>" size="2" min="0"
                                       aria-label="Quantity for <?= htmlspecialchars($item['name']) ?>" readonly>
                                       <form method="post" action="cart.php" style="display: inline;">
                                    <input type="hidden" name="item_id_to_update" value="<?= $item['item_id'] ?>">
                                    <button type="submit" name="update_quantity_action" value="increase" aria-label="Increase quantity of <?= htmlspecialchars($item['name']) ?>">+</button>
                                </form>
                            </div>
                        </td>
                        <td>PHP <?= number_format($item_total, 2) ?></td>
                        <td>
                            <form method="post" action="cart.php" style="display: inline;">
                                <input type="hidden" name="item_id_to_remove" value="<?= $item['item_id'] ?>">
                                <button type="submit" name="remove_item" class="action-button remove-btn" aria-label="Remove <?= htmlspecialchars($item['name']) ?>">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total">
            Grand Total: PHP <?= number_format($total_cart_price, 2) ?>
        </div>

        <div class="actions">
            <form method="post" action="cart.php">
                <button type="submit" name="clear_cart" class="action-button clear-btn">❌ Clear Order</button>
            </form>
            <a href="menu.php" class="action-button menu-btn">← Continue Ordering</a>
            
            <?php if ($canProceedToCheckout): ?>
                <form action="checkout.php" method="POST"> <input type="hidden" name="reservation_id_for_checkout" value="<?= htmlspecialchars($current_reservation_id ?? '', ENT_QUOTES) ?>">
                     <button type="submit" class="action-button checkout-btn">Proceed to Checkout</button>
                </form>
            <?php else: ?>
                <button class="action-button disabled-btn" disabled>Proceed to Checkout</button>
                <small style="display: block; width:100%; text-align:center;">(Reservation required to checkout)</small>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    
    
     <?php echo json_encode($current_reservation_id); ?>;
    <?php echo json_encode($user_id); ?>;
</script>
</body>
</html>