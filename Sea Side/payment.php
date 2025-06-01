<?php

session_start();
require_once 'db_config.php';


define('PER_GUEST_FEE', 250); 


if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_message'] = "Please log in to make a payment.";
    header("Location: index.php"); 
    exit();
}


if (!isset($_SESSION['reservation_date']) || !isset($_SESSION['reservation_time']) || !isset($_SESSION['reservation_guests'])) {
    $_SESSION['error_message'] = "Reservation details are missing. Please make a reservation first.";
    header("Location: reservation.php"); 
    exit();
}


$reservation_date_str = $_SESSION['reservation_date'];
$reservation_time_str = $_SESSION['reservation_time'];
$reservation_guests = (int)$_SESSION['reservation_guests'];
$logged_in_user_id = $_SESSION['user_id']; 



$user_name = 'N/A';
$user_phone = 'N/A';
$user_email = 'N/A';

try {
    $stmt_user = $pdo->prepare("SELECT name, phone_number, email FROM users WHERE id = ?");
    $stmt_user->execute([$logged_in_user_id]);
    $user = $stmt_user->fetch();
    if ($user) {
        $user_name = $user['name'];
        $user_phone = $user['phone_number'];
        $user_email = $user['email'];
    } else {
        $_SESSION['error_message'] = "Could not retrieve your user details. Please try logging in again.";
        header("Location: index.php"); 
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching user details in payment.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Error retrieving user data for payment.";
    header("Location: reservation.php"); 
    exit();
}


if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['payment_method'])) {
    $payment_method_posted = $_POST['payment_method'];

    $reservation_notes = isset($_SESSION['reservation_notes']) ? $_SESSION['reservation_notes'] : '';
    $reservation_status = 'Pending'; 
    $payment_status_db = 'Pending'; 

    try {
        $pdo->beginTransaction();

        
        $reservation_id = $_SESSION['gcash_reservation_id_pending_payment'] ?? null;

        if ($reservation_id) {
            
            $stmt_update_res = $pdo->prepare("UPDATE reservations SET name=:name, phone_number=:phone_number, guests=:guests, reservation_date=:reservation_date, reservation_time=:reservation_time, notes=:notes, status=:status WHERE id=:id");
            $stmt_update_res->execute([
                ':name' => $user_name,
                ':phone_number' => $user_phone,
                ':guests' => $reservation_guests,
                ':reservation_date' => $reservation_date_str,
                ':reservation_time' => $reservation_time_str,
                ':notes' => $reservation_notes,
                ':status' => $reservation_status,
                ':id' => $reservation_id
            ]);
        } else {
            $sql_reservation = "INSERT INTO reservations (name, phone_number, guests, reservation_date, reservation_time, notes, status)
                                VALUES (:name, :phone_number, :guests, :reservation_date, :reservation_time, :notes, :status)";
            $stmt_reservation = $pdo->prepare($sql_reservation);
            $stmt_reservation->execute([
                ':name' => $user_name, 
                ':phone_number' => $user_phone, 
                ':guests' => $reservation_guests,
                ':reservation_date' => $reservation_date_str,
                ':reservation_time' => $reservation_time_str,
                ':notes' => $reservation_notes,
                ':status' => $reservation_status
            ]);
            $reservation_id = $pdo->lastInsertId();
        }
        
        
        $_SESSION['gcash_reservation_id_pending_payment'] = $reservation_id;
        
        $_SESSION['reservation_id'] = $reservation_id;


        
        $stmt_update_cart_items = $pdo->prepare(
            "UPDATE cart_items SET reservation_id = ? WHERE user_id = ? AND reservation_id IS NULL"
        );
        $stmt_update_cart_items->execute([$reservation_id, $logged_in_user_id]);

        
        $pre_order_total = 0;
        $stmt_fetch_pre_order_total = $pdo->prepare(
            "SELECT SUM(ci.quantity * mi.price) AS total_amount
             FROM cart_items ci
             JOIN menu_items mi ON ci.item_id = mi.id
             WHERE ci.user_id = ? AND ci.reservation_id = ?"
        );
        $stmt_fetch_pre_order_total->execute([$logged_in_user_id, $reservation_id]);
        $result_total = $stmt_fetch_pre_order_total->fetch(PDO::FETCH_ASSOC);
        if ($result_total && $result_total['total_amount'] !== null) {
            $pre_order_total = (float)$result_total['total_amount'];
        }

        
        $payment_amount = 0;
       

        if ($pre_order_total > 0) {
            $payment_amount = $pre_order_total * 0.10; 
            if ($payment_amount < 0.01 && $pre_order_total > 0) { // Ensure a tiny fee if 10% is 0 but there was a pre-order
                 $payment_amount = 0.01;
            }
        } else {
            $payment_amount = $reservation_guests * PER_GUEST_FEE; 
        }
        $_SESSION['calculated_payment_amount'] = $payment_amount; // Store for QR page

        
        $stmt_check_payment = $pdo->prepare("SELECT payment_id FROM payments WHERE order_id = ?");
        $stmt_check_payment->execute([$reservation_id]);
        $existing_payment_record = $stmt_check_payment->fetch();

        if ($existing_payment_record) {
            $sql_update_payment = "UPDATE payments SET amount_paid = :amount_paid, payment_status = :payment_status, payment_date = CURDATE(), email = :email WHERE order_id = :order_id";
            $stmt_payment_update = $pdo->prepare($sql_update_payment);
            $stmt_payment_update->execute([
                ':amount_paid' => $payment_amount,
                ':payment_status' => $payment_status_db, 
                ':email' => $user_email,
                ':order_id' => $reservation_id
            ]);
        } else {
            $sql_payment_insert = "INSERT INTO payments (order_id, amount_paid, payment_date, payment_status, email)
                            VALUES (:order_id, :amount_paid, CURDATE(), :payment_status, :email)";
            $stmt_payment_insert = $pdo->prepare($sql_payment_insert);
            $stmt_payment_insert->execute([
                ':order_id' => $reservation_id,
                ':amount_paid' => $payment_amount,
                ':payment_status' => $payment_status_db, 
                ':email' => $user_email
            ]);
        }

        $pdo->commit();

        
        if ($payment_method_posted == "gcash") {
            
            $_SESSION['gcash_qr_reservation_id'] = $reservation_id;
            $_SESSION['gcash_qr_payment_amount'] = $payment_amount;
            
            header("Location: gcash_payment_qr.php");
            exit();
        }
        
        $_SESSION['payment_status_message'] = "Your reservation (ID: " . htmlspecialchars((string)$reservation_id, ENT_QUOTES) . ") has been recorded. An initial fee of PHP " . number_format($payment_amount, 2) . " is required.";
        header("Location: payment_confirmation.php");
        exit();


    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Payment processing error in payment.php: " . $e->getMessage());
        $_SESSION['error_message'] = "A database error occurred during payment processing. Please try again. Details: " . $e->getMessage();
        header("Location: payment.php"); 
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seaside Floating Restaurant - Reservation Payment</title>
    <style>
        
        :root {
            --primary-font: Arial, sans-serif;
            --text-color: white;
            --gradient-start: #0f3b53;
            --gradient-end: #145874;
            --container-gradient-start: #2e6193;
            --container-gradient-end: #2e938e;
            --button-border-color: white;
            --button-hover-bg: white;
            --button-hover-text: #2c3e50;
            --base-font-size: 16px; 
        }

        html {
            font-size: var(--base-font-size);
        }

        body {
            font-family: var(--primary-font);
            background: linear-gradient(to bottom, var(--gradient-start), var(--gradient-end));
            color: var(--text-color);
            text-align: center;
            margin: 0;
            padding: 1rem; 
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .site-header { 
            width: 100%;
            padding: 0.5rem 1rem;
            position: absolute; 
            top: 0;
            left: 0;
            display: flex;
            justify-content: flex-start; 
        }

        .site-header .logo img {
            width: 3.125rem; 
            height: 3.125rem;
            display: block; 
        }

        .container {
            width: 90%; 
            max-width: 37.5rem; 
            margin: 2rem auto; 
            padding: 1.5rem; 
            background:#169da0;
            border-radius: 0.625rem; 
            box-shadow: 0px 0.25rem 0.625rem rgba(0, 0, 0, 0.2);
        }

        .title {
            font-size: 2.25rem; 
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 1.25rem; 
        }

        .reservation-details p {
            font-size: 1rem;
            margin: 0.5rem 0; 
            text-align: left;
        }
        .reservation-details strong {
            color: #e0f7fa; 
        }

        .payment-form h3 {
            font-size: 1.25rem; 
            margin-top: 1.5rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1.25rem; 
        }

        button, .button-link { 
            padding: 0.75rem 1.25rem; 
            font-size: 1rem;    
            font-weight: bold;
            color: var(--text-color);
            background: transparent;
            border: 2px solid var(--button-border-color);
            border-radius: 0.3125rem; 
            cursor: pointer;
            transition: background-color 0.3s, color 0.3s;
            text-decoration: none; 
            display: inline-block; 
            line-height: 1.5;
            margin-top: 0.5rem;
        }

        button:hover, .button-link:hover {
            background: var(--button-hover-bg);
            color: var(--button-hover-text);
        }
        
        .gcash-button {
            background-color: #0076F7; 
            border-color: #0061C9;
        }
        .gcash-button:hover {
            background-color: #0058B4;
            color: white;
        }


        .error-message {
            background-color: #ffdddd;
            border: 1px solid #ffaaaa;
            color: #D8000C;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border-radius: 0.25rem;
            text-align: left;
        }

        
        @media (min-width: 48em) { 
            .container {
                width: 70%;
                padding: 2rem;
            }
            .title {
                font-size: 2.5rem; 
            }
        }
        @media (min-width: 64em) { 
            .container {
                width: 50%;
            }
        }
        p {
    color: white;
    font-family: 'Courier New', Courier, monospace;
        }
        * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Courier New', Courier, monospace;
        }
    </style>
</head>
<body>

<header class="site-header">
    <div class="logo">
        <a href="user_page.php">
            <img src="logo.png" alt="Seaside Restaurant Logo">
        </a>
    </div>
</header>

<div class="container">
    <h1 class="title">Reservation Payment</h1>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="error-message">
            <p><?= htmlspecialchars($_SESSION['error_message'], ENT_QUOTES) ?></p>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <div class="reservation-details">
        <h2>Your Reservation Details:</h2>
        <p><strong>Date:</strong> <?= htmlspecialchars($reservation_date_str, ENT_QUOTES) ?></p>
        <p><strong>Time:</strong> <?= htmlspecialchars(date("h:i A", strtotime($reservation_time_str)), ENT_QUOTES) ?></p>
        <p><strong>Guests:</strong> <?= htmlspecialchars((string)$reservation_guests, ENT_QUOTES) ?></p>
        <p><strong>Reserved by:</strong> <?= htmlspecialchars($user_name, ENT_QUOTES) ?> (<?= htmlspecialchars($user_phone, ENT_QUOTES) ?>)</p>
        <?php
            
            $display_pre_order_total = 0;
            $current_res_id_for_display = $_SESSION['gcash_reservation_id_pending_payment'] ?? ($_SESSION['reservation_id'] ?? null);

            if($current_res_id_for_display) { 
                $stmt_display_total = $pdo->prepare(
                    "SELECT SUM(ci.quantity * mi.price) AS total_amount
                     FROM cart_items ci
                     JOIN menu_items mi ON ci.item_id = mi.id
                     WHERE ci.user_id = ? AND ci.reservation_id = ?"
                );
                $stmt_display_total->execute([$logged_in_user_id, $current_res_id_for_display]);
                $result_display_total = $stmt_display_total->fetch(PDO::FETCH_ASSOC);
                if ($result_display_total && $result_display_total['total_amount'] !== null) {
                    $display_pre_order_total = (float)$result_display_total['total_amount'];
                }
            } else { 
                 $stmt_display_total_null = $pdo->prepare(
                    "SELECT SUM(ci.quantity * mi.price) AS total_amount
                     FROM cart_items ci
                     JOIN menu_items mi ON ci.item_id = mi.id
                     WHERE ci.user_id = ? AND ci.reservation_id IS NULL"
                );
                $stmt_display_total_null->execute([$logged_in_user_id]);
                $result_display_total_null = $stmt_display_total_null->fetch(PDO::FETCH_ASSOC);
                if ($result_display_total_null && $result_display_total_null['total_amount'] !== null) {
                    $display_pre_order_total = (float)$result_display_total_null['total_amount'];
                }
            }

            $display_fee = 0;
            if ($display_pre_order_total > 0) {
                $display_fee = $display_pre_order_total * 0.10;
                 if ($display_fee < 0.01 && $display_pre_order_total > 0) {
                    $display_fee = 0.01;
                }
            } else {
                $display_fee = $reservation_guests * PER_GUEST_FEE; 
            }
        ?>
        <p><strong>Initial Reservation Fee:</strong> PHP <?= number_format($display_fee, 2) ?></p>
    </div>

    <form method="POST" action="payment.php" class="payment-form">
        <h3>Select a Payment Method</h3>
        <div class="form-group">
            <button type="submit" name="payment_method" value="gcash" class="gcash-button">Proceed with GCash</button>
        </div>
    </form>
    <div class="form-group">
         <a href="reservation.php" class="button-link" style="background-color: #78909c; border-color: #546e7a;">Back to Reservation Details</a>
    </div>
</div>

</body>
</html>
