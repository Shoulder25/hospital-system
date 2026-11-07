<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/therapy_page.php';
$context = requireTherapyPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'T';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Therapy | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/therapy.css">
    <script src="../assets/js/therapy.js" defer></script>
</head>
<body class="dashboard-page admin-page therapy-page" data-base-path="<?= $escape($basePath) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">THERAPY</p>
            <nav class="side-nav" aria-label="Therapy navigation">
                <a class="side-link active" href="index.php"><span aria-hidden="true">＋</span> Therapy queue</a>
                <a class="side-link" href="../dashboard.php"><span aria-hidden="true">⌂</span> Staff workspace</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help"><span class="help-icon" aria-hidden="true">i</span><div><strong>Therapy workflow</strong><small>Record each session before completing the referral.</small></div></div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Therapy queue</span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span><?= $escape(str_replace('_', ' ', $staff['role_name'])) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content therapy-content" aria-labelledby="therapy-heading">
                <div class="admin-welcome therapy-welcome">
                    <div><p class="eyebrow">IN-HOUSE CARE</p><h1 id="therapy-heading">Therapy referrals</h1><p class="dashboard-subtitle">Review the diagnosis and referral, record each session, then complete therapy.</p></div>
                    <span class="therapy-count" data-therapy-count>— referrals</span>
                </div>
                <div class="admin-notice" data-therapy-message role="status" aria-live="polite" hidden></div>
                <div class="therapy-layout">
                    <section class="admin-panel therapy-queue-panel" aria-labelledby="queue-heading">
                        <div class="admin-panel-heading"><div><h2 id="queue-heading">All therapy referrals</h2><p>Active referrals and completed session history</p></div><button class="button button-outline button-small" type="button" data-refresh>Refresh</button></div>
                        <div class="therapy-queue" data-therapy-list><p class="therapy-empty">Loading therapy referrals…</p></div>
                    </section>
                    <section class="admin-panel therapy-record-panel" data-record-panel hidden>
                        <div class="admin-panel-heading">
                            <div><h2 data-record-heading>Visit record</h2><p data-record-subheading></p></div>
                            <span class="therapy-status" data-record-status></span>
                        </div>
                        <div class="therapy-record">
                            <section class="therapy-record-section">
                                <h3>Referral</h3>
                                <dl class="therapy-details">
                                    <div><dt>Therapy type</dt><dd data-therapy-type>—</dd></div>
                                    <div><dt>Reason</dt><dd data-referral-reason>—</dd></div>
                                    <div><dt>Referred by</dt><dd data-referrer>—</dd></div>
                                    <div><dt>Diagnosis</dt><dd data-diagnosis>—</dd></div>
                                    <div><dt>Clinical findings</dt><dd data-findings>—</dd></div>
                                    <div><dt>Treatment plan</dt><dd data-treatment-plan>—</dd></div>
                                </dl>
                            </section>
                            <section class="therapy-record-section">
                                <h3>Referring clinical notes</h3>
                                <dl class="therapy-details">
                                    <div><dt>Presenting complaint</dt><dd data-presenting-complaint>—</dd></div>
                                    <div><dt>Clinical observations</dt><dd data-clinical-observations>—</dd></div>
                                    <div><dt>Examination findings</dt><dd data-examination-findings>—</dd></div>
                                    <div><dt>Consultation notes</dt><dd data-consultation-notes>—</dd></div>
                                </dl>
                            </section>
                            <section class="therapy-record-section">
                                <h3>Recorded sessions</h3>
                                <div class="therapy-sessions" data-sessions><p class="therapy-record-empty">No sessions recorded yet.</p></div>
                            </section>
                            <section class="therapy-record-section therapy-actions">
                                <form class="therapy-session-form" data-session-form>
                                    <h3>Record a session</h3>
                                    <label class="therapy-field"><span>Session notes</span><textarea name="session_notes" rows="3" maxlength="5000" required placeholder="Describe the therapy performed and patient response"></textarea></label>
                                    <button class="button button-primary" type="submit" data-record-session>Save session</button>
                                </form>
                                <div class="therapy-complete">
                                    <p>Complete therapy after all sessions have been recorded.</p>
                                    <button class="button button-outline" type="button" data-complete disabled>Complete therapy</button>
                                </div>
                            </section>
                        </div>
                    </section>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
