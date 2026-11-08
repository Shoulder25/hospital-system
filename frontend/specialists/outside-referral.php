<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/specialist_page.php';
$context = requireSpecialistPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'S';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Outside referral | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/specialist.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/specialist.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page specialist-page outside-referral-page" data-base-path="<?= $escape($basePath) ?>" data-outside-referral-page>
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">SPECIALISTS</p>
            <nav class="side-nav" aria-label="Specialist navigation">
                <a class="side-link" href="index.php"><span aria-hidden="true">＋</span> Referrals</a>
                <a class="side-link" href="../dashboard.php"><span aria-hidden="true">⌂</span> Staff workspace</a>
            </nav>
            <div class="sidebar-bottom"><span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span></div>
        </aside>
        <main class="dashboard-main">
            <header class="topbar"><div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Outside referral summary</span></div><div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span><?= $escape(ucwords(str_replace('_', ' ', $staff['role_name']))) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button><button class="button button-outline button-small" type="button" data-print>Print summary</button><a class="button button-outline button-small" href="index.php">Back to referrals</a></div></header>
            <section class="dashboard-content admin-content specialist-content">
                <div class="admin-notice" data-specialist-message role="status" aria-live="polite" hidden></div>
                <article class="admin-panel outside-letter" data-outside-letter hidden>
                    <div class="outside-letter-heading"><div><p class="eyebrow">OUTSIDE REFERRAL</p><h1>CareBridge Hospital</h1><p>Clinical referral summary</p></div><span class="specialist-status">Issued</span></div>
                    <dl class="outside-letter-details">
                        <div><dt>Patient</dt><dd data-letter-patient></dd></div>
                        <div><dt>Patient number</dt><dd data-letter-patient-number></dd></div>
                        <div><dt>Visit ID</dt><dd data-letter-visit></dd></div>
                        <div><dt>Referred to</dt><dd data-letter-provider></dd></div>
                        <div><dt>Referral date</dt><dd data-letter-date></dd></div>
                        <div><dt>Issued by</dt><dd data-letter-issuer></dd></div>
                    </dl>
                    <section class="outside-letter-summary" data-letter-reason-section hidden><h2>Referral reason</h2><p data-letter-reason></p></section>
                    <section class="outside-letter-summary" data-letter-diagnosis-section hidden><h2>Relevant diagnosis</h2><p data-letter-diagnosis></p></section>
                    <section class="outside-letter-summary"><h2>Clinical summary</h2><p data-letter-summary></p></section>
                    <p class="outside-letter-note">The patient is responsible for outside test costs. This document contains fictional/sample clinical data for an academic prototype.</p>
                </article>
            </section>
        </main>
    </div>
</body>
</html>
