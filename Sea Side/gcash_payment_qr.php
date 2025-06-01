<?php
session_start();
require_once 'db_config.php'; 


if (!isset($_SESSION['gcash_qr_reservation_id']) || !isset($_SESSION['gcash_qr_payment_amount'])) {
    $_SESSION['error_message'] = "Payment details are missing. Please start over.";
    header("Location: payment.php");
    exit();
}

$reservation_id = $_SESSION['gcash_qr_reservation_id'];
$payment_amount = $_SESSION['gcash_qr_payment_amount'];
$user_name = $_SESSION['user_name'] ?? 'Valued Customer'; 


$qr_code_image_url = "https://placehold.co/300x300/E8E8E8/000000?text=Scan+GCash+QR"; 


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GCash Payment | Seaside Restaurant</title>
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
            --container-bg: #169da0;
            --button-bg: #0076F7; 
            --button-hover-bg: #0058B4;
            --button-secondary-bg: #78909c;
            --button-secondary-hover-bg: #546e7a;
            --button-confirm-bg: #4CAF50; 
            --button-confirm-hover-bg: #45a049;
            --button-disabled-bg: #BDBDBD;
            --base-font-size: 16px;
            --border-radius: 0.3125rem;
        }
        body {
            font-family: var(--primary-font);
            background: linear-gradient(to bottom, var(--gradient-start), var(--gradient-end));
            color: var(--text-color);
            margin: 0;
            padding: 1rem;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .container {
            background: var(--container-bg);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: 0 0.25rem 0.625rem rgba(0,0,0,0.2);
            width: 90%;
            max-width: 450px;
        }
        h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        h2 {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            font-weight: normal;
        }
        .qr-code-container img {
            max-width: 100%;
            height: auto;
            border: 5px solid white;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            width: 250px; 
            height: 250px;
        }
        .payment-details p {
            font-size: 1.1rem;
            margin: 0.5rem 0;
        }
        .payment-details strong {
            color: #e0f7fa;
        }
        .actions button, .actions a, .modal-actions button {
            display: block;
            width: 100%;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            font-size: 1rem;
            font-weight: bold;
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.3s;
        }
        .actions button:last-child, .modal-actions button:last-child {
            margin-bottom: 0;
        }
        .open-gcash-btn { background-color: var(--button-bg); }
        .open-gcash-btn:hover { background-color: var(--button-hover-bg); }
        .payment-done-btn { background-color: var(--button-confirm-bg); }
        .payment-done-btn:hover { background-color: var(--button-confirm-hover-bg); }
        .back-btn { background-color: var(--button-secondary-bg); }
        .back-btn:hover { background-color: var(--button-secondary-hover-bg); }

        #toastMessage {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(0,0,0,0.7);
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        #toastMessage.show {
            opacity: 1;
        }

        #warningModal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: white;
            color: #333;
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            z-index: 1001;
            width: 90%;
            max-width: 400px;
            text-align: center;
        }
        #warningModal p {
            margin-bottom: 1.5rem;
            font-size: 1rem;
            line-height: 1.5;
        }
        .modal-actions button[disabled] {
            background-color: var(--button-disabled-bg);
            cursor: not-allowed;
        }
        .modal-actions .confirm-btn { background-color: var(--button-confirm-bg); }
        .modal-actions .confirm-btn:hover:not([disabled]) { background-color: var(--button-confirm-hover-bg); }
        .modal-actions .modal-back-btn { background-color: var(--button-secondary-bg); }
        .modal-actions .modal-back-btn:hover { background-color: var(--button-secondary-hover-bg); }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
            display: none; 
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Pay with GCash</h1>
        <h2>Scan the QR code below or open the GCash app.</h2>

        <div class="qr-code-container">
            <img src="<?= htmlspecialchars($qr_code_image_url) ?>" alt="GCash QR Code">
        </div>

        <div class="payment-details">
            <p><strong>Reservation ID:</strong> <?= htmlspecialchars($reservation_id) ?></p>
            <p><strong>Amount to Pay:</strong> PHP <?= number_format($payment_amount, 2) ?></p>
            <p><strong>Account Name:</strong> Seaside Floating Restaurant</p>
            <p><strong>GCash Number:</strong> 09*********    </p>
        </div>

        <div class="actions">
            <button type="button" id="openGcashBtn" class="open-gcash-btn">Open GCash App</button>
            <button type="button" id="paymentDoneBtn" class="payment-done-btn">I Have Paid via GCash</button>
            <a href="payment.php" class="back-btn">Back to Payment Options</a>
        </div>
    </div>

    <div id="toastMessage"></div>

    <div class="overlay" id="warningModalOverlay" style="display:none;"></div>
    <div id="warningModal" style="display:none;">
        <p><strong>Important:</strong> If payment has not been successfully made and confirmed by our system, your reservation status will remain 'Pending'.</p>
        <div class="modal-actions">
            <form action="payment_confirmation.php" method="POST" style="display:contents;">
                <input type="hidden" name="manual_gcash_confirmation" value="true">
                <input type="hidden" name="reservation_id_confirmed" value="<?= htmlspecialchars($reservation_id); ?>">
                <input type="hidden" name="payment_amount_confirmed" value="<?= htmlspecialchars($payment_amount); ?>">
                <button type="submit" id="warningConfirmBtn" class="confirm-btn" disabled>Confirm Payment</button>
            </form>
            <button type="button" id="warningBackBtn" class="modal-back-btn">Cancel</button>
        </div>
    </div>

    <script>
        const openGcashBtn = document.getElementById('openGcashBtn');
        const paymentDoneBtn = document.getElementById('paymentDoneBtn');
        const toastMessage = document.getElementById('toastMessage');
        const warningModal = document.getElementById('warningModal');
        const warningModalOverlay = document.getElementById('warningModalOverlay');
        const warningConfirmBtn = document.getElementById('warningConfirmBtn');
        const warningBackBtn = document.getElementById('warningBackBtn');
        let confirmTimerInterval;

        openGcashBtn.addEventListener('click', () => {
            
            window.location.href = 'gcash://'; 

            toastMessage.textContent = 'Attempting to open GCash. If it doesn\'t open, please open the app manually and use the QR code.';
            toastMessage.classList.add('show');
            setTimeout(() => {
                toastMessage.classList.remove('show');
            }, 7000); 
        });

        paymentDoneBtn.addEventListener('click', () => {
            warningModal.style.display = 'block';
            warningModalOverlay.style.display = 'block';
            warningConfirmBtn.disabled = true;
            
            let countdown = 5;
            warningConfirmBtn.textContent = `Confirm Payment (${countdown}s)`;

            confirmTimerInterval = setInterval(() => {
                countdown--;
                if (countdown > 0) {
                    warningConfirmBtn.textContent = `Confirm Payment (${countdown}s)`;
                } else {
                    clearInterval(confirmTimerInterval);
                    warningConfirmBtn.disabled = false;
                    warningConfirmBtn.textContent = 'Confirm Payment';
                }
            }, 1000);
        });

        function closeWarningModal() {
            warningModal.style.display = 'none';
            warningModalOverlay.style.display = 'none';
            clearInterval(confirmTimerInterval); 
            warningConfirmBtn.disabled = true; 
            warningConfirmBtn.textContent = 'Confirm Payment';
        }

        warningBackBtn.addEventListener('click', closeWarningModal);
        warningModalOverlay.addEventListener('click', closeWarningModal); 

        
    </script>
</body>
</html>
