<?php
/**
 * expired_qr_page.php — the "QR link expired/invalid" page shown by
 * attendance_helpers.php's renderExpiredPage(). Split out on the same
 * principle as error_page.php: keep a standalone HTML template out of a
 * file otherwise full of DB/query helper functions.
 *
 * Not meant to be included directly — renderExpiredPage() sets the 403
 * status and exits right after including this.
 */
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pautan Tamat Tempoh</title>
</head>
<body style="background:#f3f4f6;color:#111827;display:flex;flex-direction:column;
             justify-content:center;align-items:center;height:100vh;text-align:center;
             font-family:sans-serif;padding:20px;">
    <div style="font-size:4rem;margin-bottom:10px;">⏱️</div>
    <h1 style="color:#b30000;margin-bottom:10px;">Pautan Tamat Tempoh</h1>
    <p style="color:#6b7280;line-height:1.5;">
        Kod QR ini telah tamat tempoh atau tidak sah.<br>
        Sila minta kod QR baharu daripada urusetia bertugas.
    </p>
</body>
</html>
