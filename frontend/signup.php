<?php

declare(strict_types=1);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#eff7f7">
    <title>Staff access | Hospital Patient Processing</title>
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-brand" aria-label="Hospital Patient Processing">
            <a class="brand-lockup" href="../index.php" aria-label="CareBridge home">
                <span class="brand-mark" aria-hidden="true">+</span>
                <span>Care<span class="brand-light">Bridge</span></span>
            </a>
            <div class="brand-copy">
                <p class="eyebrow">HOSPITAL PATIENT PROCESSING</p>
                <h1>Staff access is managed by your administrator.</h1>
                <p>Contact the system administrator to request an account for the hospital patient processing workspace.</p>
            </div>
            <div class="brand-note">
                <span class="status-dot" aria-hidden="true"></span>
                <span>Administrator-managed staff accounts</span>
            </div>
            <div class="brand-decoration" aria-hidden="true">
                <span></span><span></span><span></span><span></span><span></span>
                <b>+</b>
            </div>
        </section>

        <section class="auth-panel">
            <div class="auth-card auth-card-wide">
                <div class="mobile-brand brand-lockup" aria-hidden="true">
                    <span class="brand-mark">+</span>
                    <span>Care<span class="brand-light">Bridge</span></span>
                </div>
                <p class="eyebrow">REGISTRATION CLOSED</p>
                <h2>Public registration is disabled</h2>
                <p class="form-intro">Contact the system administrator to request a staff account.</p>
                <a class="button button-primary button-full" href="../auth/login.php">Go to sign in</a>
            </div>
            <footer class="panel-footer">CareBridge Hospital System <span>·</span> Academic prototype</footer>
        </section>
    </main>
</body>
</html>
