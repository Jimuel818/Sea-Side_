<?php
session_start();
require_once 'db_config.php';

$payment_status_msg = "An unexpected error occurred, or your session has expired.";
$reservation_id_confirmed = null;
$confirmed_reservation_details = null;


if (isset($_POST['manual_gcash_confirmation']) && $_POST['manual_gcash_confirmation'] == 'true') {
    if (isset($_POST['reservation_id_confirmed']) && isset($_POST['payment_amount_confirmed'])) {
        $reservation_id_confirmed = filter_var($_POST['reservation_id_confirmed'], FILTER_VALIDATE_INT);
        $payment_amount_from_qr = filter_var($_POST['payment_amount_confirmed'], FILTER_VALIDATE_FLOAT);

        if ($reservation_id_confirmed && $payment_amount_from_qr !== false) {
            $_SESSION['reservation_id'] = $reservation_id_confirmed; 
            
            
            $_SESSION['payment_status_message'] = "Your GCash payment for Reservation ID: " . htmlspecialchars((string)$reservation_id_confirmed, ENT_QUOTES) . 
                                               " (Amount: PHP " . number_format($payment_amount_from_qr, 2) . ") " .
                                                 "has been noted. Your reservation status is 'Pending' and will be updated once payment is verified by our team.";
        } else {
            $_SESSION['payment_status_message'] = "Error processing GCash confirmation. Invalid data received.";
        }
    } else {
         $_SESSION['payment_status_message'] = "GCash confirmation data missing.";
    }
} elseif (isset($_SESSION['payment_status_message'])) {
    
    $payment_status_msg = $_SESSION['payment_status_message'];
    if (isset($_SESSION['reservation_id'])) {
         $reservation_id_confirmed = $_SESSION['reservation_id'];
    }
} else {
    
    if (!isset($_SESSION['reservation_id'])) {
        
        $_SESSION['error_message'] = "Your session has expired or reservation details are missing.";
        header("Location: reservation.php");
        exit();
    }
    
    $reservation_id_confirmed = $_SESSION['reservation_id'];
    $_SESSION['payment_status_message'] = "Your reservation (ID: " . htmlspecialchars((string)$reservation_id_confirmed, ENT_QUOTES) . ") has been recorded.";
}


$payment_status_msg = $_SESSION['payment_status_message']; 


if ($reservation_id_confirmed) {
    try {
        $stmt = $pdo->prepare("SELECT r.*, p.amount_paid, p.payment_status as effective_payment_status FROM reservations r LEFT JOIN payments p ON r.id = p.order_id WHERE r.id = ?");
        $stmt->execute([$reservation_id_confirmed]);
        $confirmed_reservation_details = $stmt->fetch();
        if (!$confirmed_reservation_details) {
             $payment_status_msg .= " However, we could not retrieve the full details at this moment.";
        }
    } catch (PDOException $e) {
        error_log("Error fetching confirmed reservation in payment_confirmation.php: " . $e->getMessage());
        $payment_status_msg .= " There was an error fetching reservation details.";
    }
}



unset(
    $_SESSION['reservation_date'], 
    $_SESSION['reservation_time'], 
    $_SESSION['reservation_guests'], 
    $_SESSION['reservation_notes'],
    $_SESSION['calculated_payment_amount'],
    $_SESSION['gcash_qr_reservation_id'],      
    $_SESSION['gcash_qr_payment_amount'],     
    $_SESSION['gcash_reservation_id_pending_payment'],
    $_SESSION['checkout_reservation_id'], 
    $_SESSION['final_checkout_total'],
    $_SESSION['user_email_for_payment']
    
);



?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seaside Floating Restaurant - Payment Confirmation</title>
    <style>
               * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Courier New', Courier, monospace;
               }
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
            background: linear-gradient(var(--container-gradient-start), var(--container-gradient-end));
            border-radius: 0.625rem; 
            box-shadow: 0px 0.25rem 0.625rem rgba(0, 0, 0, 0.2);
        }

        .title {
            font-size: 2.25rem;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 1.25rem;
        }

        .message {
            font-size: 1.1rem;
            margin-bottom: 1.25rem;
            line-height: 1.6;
            background-color: rgba(0,0,0,0.1);
            padding: 1rem;
            border-radius: 0.3125rem;
        }
        .message p {
            margin: 0; 
        }

        .details-section {
            margin-top: 1.5rem;
            margin-bottom: 1.5rem;
            font-size: 1rem;
            text-align: left;
            padding: 1rem;
            background-color: rgba(0,0,0,0.1);
            border-radius: 0.3125rem;
        }
        .details-section h3 {
            margin-top: 0;
            margin-bottom: 0.75rem;
            font-size: 1.2rem;
            color: #e0f7fa;
        }
        .details-section p {
            margin: 0.5rem 0;
        }
        .details-section strong {
            color: #e0f7fa;
        }

        .actions a.button-link {
            padding: 0.75rem 1.25rem;
            font-size: 1rem;
            font-weight: bold;
            color: var(--text-color);
            background: #0f6383; 
            border: 2px solid #0f6383;
            border-radius: 0.3125rem;
            cursor: pointer;
            transition: background-color 0.3s, color 0.3s;
            text-decoration: none;
            display: inline-block;
            margin-top: 0.5rem; 
            margin-right: 0.5rem;
        }
         .actions a.button-link:last-child {
            margin-right: 0;
        }

        .actions a.button-link:hover {
            background: var(--button-hover-bg);
            color: var(--button-hover-text);
            border-color: var(--button-hover-bg);
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
    <h1 class="title">Reservation Status</h1>

    <div class="message">
        <p><?= htmlspecialchars($payment_status_msg, ENT_QUOTES) ?></p>
    </div>

    <?php if ($confirmed_reservation_details): ?>
    <div class="details-section">
        <h3>Your Reservation Details:</h3>
        <p><strong>Reservation ID:</strong> <?= htmlspecialchars((string)$confirmed_reservation_details['id'], ENT_QUOTES) ?></p>
        <p><strong>Name:</strong> <?= htmlspecialchars($confirmed_reservation_details['name'], ENT_QUOTES) ?></p>
        <p><strong>Date:</strong> <?= htmlspecialchars($confirmed_reservation_details['reservation_date'], ENT_QUOTES) ?></p>
        <p><strong>Time:</strong> <?= htmlspecialchars(date("h:i A", strtotime($confirmed_reservation_details['reservation_time'])), ENT_QUOTES) ?></p>
        <p><strong>Guests:</strong> <?= htmlspecialchars((string)$confirmed_reservation_details['guests'], ENT_QUOTES) ?></p>
        <p><strong>Overall Status:</strong> <?= htmlspecialchars($confirmed_reservation_details['status'], ENT_QUOTES) ?></p>
        <p><strong>Payment Status:</strong> <?= htmlspecialchars($confirmed_reservation_details['effective_payment_status'] ?? 'Not Available', ENT_QUOTES) ?></p>
        <p><strong>Amount Noted:</strong> PHP <?= number_format((float)($confirmed_reservation_details['amount_paid'] ?? 0), 2) ?></p>

        <?php if(!empty($confirmed_reservation_details['notes'])): ?>
             <p><strong>Notes:</strong> <?= nl2br(htmlspecialchars($confirmed_reservation_details['notes'], ENT_QUOTES)) ?></p>
        <?php endif; ?>
    </div>
    <?php elseif($reservation_id_confirmed): ?>
        <p class="message error">Could not retrieve full details for reservation ID: <?= htmlspecialchars((string)$reservation_id_confirmed, ENT_QUOTES) ?>. Please contact us if you have concerns.</p>
    <?php endif; ?>

    <p>Thank you! We look forward to serving you.</p>

    <div class="actions">
        <a href="user_page.php" class="button-link">My Dashboard</a>
        <a href="menu.php" class="button-link">View Menu / Pre-order More</a>
    </div>
</div>

<?php

unset($_SESSION['payment_status_message']);
?>

</body>
</html>
