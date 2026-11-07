<?php

declare(strict_types=1);

$projectPath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$projectPath = rtrim($projectPath, '/');
if ($projectPath === '.' || $projectPath === '/') {
    $projectPath = '';
}

$loginUrl = $projectPath . '/auth/login.php';
$healthUrl = $projectPath . '/backend/index.php?route=%2Fapi%2Fhealth';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#eff7f7">
    <title>Hospital Integrated Patient Processing System</title>
    <style>
        :root {
            color-scheme: light;
            font-family: "Segoe UI", Arial, sans-serif;
            color: #19333b;
            background: #f3f8f7;
        }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; }
        main { width: min(100% - 36px, 900px); margin: 0 auto; padding: 48px 0; }
        .brand { display: flex; align-items: center; gap: 12px; color: #19333b; font-size: 19px; font-weight: 700; }
        .brand-mark { display: grid; width: 38px; height: 38px; place-items: center; border-radius: 12px; background: #117e78; color: white; font-size: 27px; font-weight: 400; }
        .hero { margin-top: 70px; padding: clamp(28px, 6vw, 58px); border: 1px solid #e0ebea; border-radius: 18px; background: white; box-shadow: 0 16px 45px rgb(26 63 67 / 6%); }
        .eyebrow { margin: 0 0 13px; color: #17847b; font-size: 11px; font-weight: 700; letter-spacing: 1.4px; }
        h1 { max-width: 700px; margin: 0; font-size: clamp(32px, 6vw, 52px); letter-spacing: -1.8px; line-height: 1.12; }
        .intro { max-width: 680px; margin: 18px 0 0; color: #687e82; font-size: 16px; line-height: 1.75; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
        .button { display: inline-flex; min-height: 45px; align-items: center; justify-content: center; padding: 0 18px; border: 1px solid #117e78; border-radius: 8px; background: #117e78; color: white; font-size: 13px; font-weight: 700; text-decoration: none; }
        .button-secondary { border-color: #d6e5e3; background: #fff; color: #28706c; }
        .details { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-top: 28px; }
        .detail { padding: 17px; border: 1px solid #e7eeee; border-radius: 10px; background: #fbfdfd; }
        .detail h2 { margin: 0 0 7px; font-size: 13px; }
        .detail p { margin: 0; color: #718589; font-size: 12px; line-height: 1.6; }
        footer { padding: 22px 4px; color: #859699; font-size: 11px; }
        @media (max-width: 640px) {
            main { padding: 26px 0; }
            .hero { margin-top: 38px; }
            .details { grid-template-columns: 1fr; }
            .button { flex: 1 1 100%; }
        }
    </style>
</head>
<body>
    <main>
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">+</span>
            <span>CareBridge Hospital System</span>
        </div>

        <section class="hero" aria-labelledby="page-title">
            <p class="eyebrow">CSC411 · ACADEMIC PROTOTYPE</p>
            <h1 id="page-title">Hospital Integrated Patient Processing System</h1>
            <p class="intro">
                A staff workspace supporting the patient journey from arrival and
                assessment through tests, diagnosis, referrals, pharmacy and payment.
                This is an academic prototype; hospital workflow modules are being
                implemented incrementally.
            </p>
            <div class="actions">
                <a class="button" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Staff sign in</a>
                <a class="button button-secondary" href="<?= htmlspecialchars($healthUrl, ENT_QUOTES, 'UTF-8') ?>">Check API status</a>
            </div>

            <div class="details">
                <article class="detail">
                    <h2>Staff access</h2>
                    <p>Sign in with an account created by the system administrator.</p>
                </article>
                <article class="detail">
                    <h2>Patient workflow</h2>
                    <p>Emergency, medication collection, nursing, tests, referrals, therapy and pharmacy flows are in scope.</p>
                </article>
                <article class="detail">
                    <h2>Current stage</h2>
                    <p>Database, backend foundation and initial authentication pages are available; department modules are pending.</p>
                </article>
            </div>
        </section>

        <footer>Fictional data only · Not for production or clinical use</footer>
    </main>
</body>
</html>
