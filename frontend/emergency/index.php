<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/emergency_page.php';
$context = requireEmergencyPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'E';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Emergency Department | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/emergency.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/emergency.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page emergency-page"
      data-base-path="<?= $escape($basePath) ?>"
      data-role="<?= $escape($staff['role_name']) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="../dashboard.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">EMERGENCY</p>
            <nav class="side-nav" aria-label="Emergency department navigation">
                <a class="side-link active" href="index.php"><span aria-hidden="true">＋</span> Emergency queue</a>
                <a class="side-link" href="../dashboard.php"><span aria-hidden="true">⌂</span> Staff workspace</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help"><span class="help-icon" aria-hidden="true">i</span><div><strong>Treat before payment</strong><small>Emergency charges are deferred to the pharmacy payment point.</small></div></div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Emergency Department</span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span><?= $escape(str_replace('_', ' ', $staff['role_name'])) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content emergency-content" aria-labelledby="emergency-heading">
                <div class="admin-welcome emergency-welcome">
                    <div><p class="eyebrow">EMERGENCY CARE</p><h1 id="emergency-heading">Emergency queue</h1><p class="dashboard-subtitle">Record emergency care before payment. Treatment charges remain deferred until the pharmacy payment point.</p></div>
                    <span class="emergency-badge">NO TICKET FEE</span>
                </div>
                <div class="admin-notice" data-emergency-message role="status" aria-live="polite" hidden></div>
                <section class="admin-panel emergency-queue-panel" aria-labelledby="queue-heading">
                    <div class="admin-panel-heading emergency-panel-heading">
                        <div><h2 id="queue-heading">In-progress emergency visits</h2><p>Temporary patient records are included; complete their details through Reception when appropriate.</p></div>
                        <div class="emergency-search">
                            <label for="emergency-search">Search patient number, name or visit ID</label>
                            <div><input id="emergency-search" type="search" maxlength="100" placeholder="Search queue"><button class="button button-outline button-small" type="button" data-search-button>Search</button></div>
                        </div>
                    </div>
                    <div class="emergency-table-wrap">
                        <table class="admin-table emergency-table">
                            <thead><tr><th scope="col">Patient</th><th scope="col">Visit</th><th scope="col">Arrived</th><th scope="col">Encounter</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
                            <tbody data-emergency-rows><tr><td class="table-message" colspan="6">Loading emergency queue…</td></tr></tbody>
                        </table>
                    </div>
                </section>
                <section class="admin-panel emergency-detail" data-emergency-detail hidden aria-labelledby="detail-heading">
                    <div class="admin-panel-heading">
                        <div><p class="eyebrow">EMERGENCY VISIT</p><h2 id="detail-heading">Visit details</h2><p data-detail-summary></p></div>
                        <button class="button button-outline button-small" type="button" data-close-detail>Close</button>
                    </div>
                    <dl class="emergency-facts">
                        <div><dt>Patient number</dt><dd data-patient-number></dd></div>
                        <div><dt>Visit ID</dt><dd data-visit-id></dd></div>
                        <div><dt>Arrived</dt><dd data-arrived-at></dd></div>
                        <div><dt>Current status</dt><dd data-visit-status></dd></div>
                        <div><dt>Encounter</dt><dd data-encounter-status></dd></div>
                        <div><dt>Emergency doctor</dt><dd data-encounter-doctor></dd></div>
                        <div><dt>Triage level</dt><dd data-triage-level></dd></div>
                        <div><dt>Encounter started</dt><dd data-encounter-started></dd></div>
                    </dl>
                    <p class="emergency-encounter-notes" data-stabilization-notes hidden></p>
                    <p class="emergency-temp-note" data-temporary-note hidden>Temporary patient. <a href="../reception/index.php">Open the existing Reception completion workflow</a> (Reception or administrator access required); the patient and visit records are preserved.</p>
                    <section class="emergency-subsection" data-encounter-create-section hidden>
                        <h3>Start emergency encounter</h3>
                        <form class="emergency-form" data-encounter-form>
                            <label>Triage level <input name="triage_level" maxlength="40" required></label>
                            <label>Stabilization notes <textarea name="stabilization_notes" rows="3" maxlength="10000"></textarea></label>
                            <button class="button button-primary" type="submit">Start encounter and defer care charge</button>
                        </form>
                    </section>
                    <section class="emergency-subsection" data-nurse-section hidden>
                        <h3>Attending emergency nurses</h3>
                        <ul class="emergency-nurse-list" data-nurse-list></ul>
                        <form class="emergency-form emergency-inline-form" data-nurse-form>
                            <label>Assign nurse
                                <select name="nurse_user_id" required><option value="">Select an active emergency nurse</option></select>
                            </label>
                            <button class="button button-outline" type="submit">Assign nurse</button>
                        </form>
                    </section>
                    <section class="emergency-subsection" data-prescription-section hidden>
                        <h3>Optional emergency prescription</h3>
                        <p>Prescriptions are saved to the visit for the pharmacy. This screen does not dispense medication.</p>
                        <form class="emergency-form" data-prescription-form>
                            <label>Medicine
                                <select name="medicine_id" required><option value="">Select an active medicine</option></select>
                            </label>
                            <div class="emergency-form-grid">
                                <label>Quantity <input name="quantity" type="number" min="0.01" max="10000" step="0.01" required></label>
                                <label>Dosage <input name="dosage" maxlength="100"></label>
                                <label>Frequency <input name="frequency" maxlength="100"></label>
                                <label>Duration <input name="duration" maxlength="100"></label>
                            </div>
                            <label>Instructions <textarea name="instructions" rows="2" maxlength="2000"></textarea></label>
                            <button class="button button-outline" type="submit">Save prescription</button>
                        </form>
                    </section>
                    <section class="emergency-subsection" data-prescriptions-section hidden>
                        <h3>Prescriptions on this visit</h3>
                        <ul class="emergency-prescription-list" data-prescription-list></ul>
                    </section>
                    <div class="emergency-detail-actions" data-finish-section hidden>
                        <p>Finish the encounter to route the visit to Pharmacy or the payment point. No payment is collected here.</p>
                        <button class="button button-primary" type="button" data-finish-encounter>Finish emergency encounter</button>
                    </div>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
