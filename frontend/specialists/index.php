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
    <title>Specialist referrals | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/specialist.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/specialist.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page specialist-page" data-base-path="<?= $escape($basePath) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">SPECIALISTS</p>
            <nav class="side-nav" aria-label="Specialist navigation">
                <a class="side-link active" href="index.php"><span aria-hidden="true">＋</span> Referrals</a>
                <a class="side-link" href="../dashboard.php"><span aria-hidden="true">⌂</span> Staff workspace</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help"><span class="help-icon" aria-hidden="true">i</span><div><strong>Referral workflow</strong><small>Review the shared clinical record before recording an outcome.</small></div></div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Specialist referrals</span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span><?= $escape(str_replace('_', ' ', $staff['role_name'])) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content specialist-content" aria-labelledby="specialist-heading">
                <div class="admin-welcome specialist-welcome"><div><p class="eyebrow">SPECIALIST CARE</p><h1 id="specialist-heading">Assigned referrals</h1><p class="dashboard-subtitle">Review the patient record, accept the referral, and record a diagnosis or outside referral.</p></div><span class="specialist-count" data-referral-count>— referrals</span></div>
                <div class="admin-notice" data-specialist-message role="status" aria-live="polite" hidden></div>
                <div class="specialist-layout">
                    <section class="admin-panel specialist-queue-panel" aria-labelledby="queue-heading">
                        <div class="admin-panel-heading"><div><h2 id="queue-heading">Pending and accepted</h2><p>Specialists see only their assignments. Administrators can see all.</p></div><button class="button button-outline button-small" type="button" data-refresh>Refresh</button></div>
                        <div class="specialist-queue" data-referral-list><p class="specialist-empty">Loading referrals…</p></div>
                    </section>
                    <div class="specialist-workspace">
                        <section class="admin-panel specialist-record-panel" data-record-panel hidden>
                            <div class="admin-panel-heading"><div><h2 data-record-heading>Visit record</h2><p data-record-subheading></p></div><span class="specialist-status" data-record-status></span></div>
                            <div class="specialist-record" data-record></div>
                            <div class="specialist-accept-row" data-accept-row hidden><button class="button button-primary" type="button" data-accept>Accept referral</button></div>
                        </section>
                        <section class="admin-panel specialist-outcome-panel" data-outcome-panel hidden>
                            <div class="admin-panel-heading"><div><h2>Record specialist outcome</h2><p>Diagnosis and referral details are saved together with the visit transition.</p></div></div>
                            <form class="specialist-form" data-outcome-form>
                                <label class="specialist-field"><span>Outcome</span><select name="outcome" required><option value="diagnosed">Diagnosed</option><option value="undiagnosed">Undiagnosed — refer outside</option></select></label>
                                <div class="specialist-form-section">
                                    <h3>Specialist consultation</h3>
                                    <label class="specialist-field"><span>Presenting complaint</span><textarea name="presenting_complaint" rows="2" maxlength="5000"></textarea></label>
                                    <label class="specialist-field"><span>Clinical observations</span><textarea name="clinical_observations" rows="2" maxlength="10000"></textarea></label>
                                    <label class="specialist-field"><span>Examination findings</span><textarea name="examination_findings" rows="2" maxlength="10000"></textarea></label>
                                    <label class="specialist-field"><span>Consultation notes</span><textarea name="notes" rows="2" maxlength="10000"></textarea></label>
                                </div>
                                <div class="specialist-form-section" data-diagnosed-fields>
                                    <h3>Diagnosis and plan</h3>
                                    <label class="specialist-field"><span>Diagnosis <b aria-hidden="true">*</b></span><textarea name="diagnosis_description" rows="2" maxlength="5000"></textarea></label>
                                    <label class="specialist-field"><span>Diagnosis-specific findings</span><textarea name="clinical_findings" rows="2" maxlength="10000"></textarea></label>
                                    <label class="specialist-field"><span>Treatment plan</span><textarea name="treatment_plan" rows="2" maxlength="10000"></textarea></label>
                                    <label class="specialist-check"><input type="checkbox" name="refer_for_therapy"><span>Refer for in-house therapy before pharmacy</span></label>
                                    <div class="specialist-therapy-fields" data-therapy-fields hidden>
                                        <label class="specialist-field"><span>Therapy type</span><input name="therapy_type" maxlength="100" type="text"></label>
                                        <label class="specialist-field"><span>Therapy reason</span><textarea name="therapy_reason" rows="2" maxlength="5000"></textarea></label>
                                    </div>
                                    <label class="specialist-check"><input type="checkbox" name="create_prescription"><span>Add one prescription item</span></label>
                                    <div class="specialist-prescription-fields" data-prescription-fields hidden>
                                        <label class="specialist-field"><span>Medicine</span><select name="medicine_id" data-medicine-select></select></label>
                                        <div class="specialist-field-grid">
                                            <label class="specialist-field"><span>Quantity</span><input name="quantity" type="number" min="0.01" max="10000" step="0.01" value="1"></label>
                                            <label class="specialist-field"><span>Dosage</span><input name="dosage" maxlength="100" type="text"></label>
                                            <label class="specialist-field"><span>Frequency</span><input name="frequency" maxlength="100" type="text"></label>
                                            <label class="specialist-field"><span>Duration</span><input name="duration" maxlength="100" type="text"></label>
                                        </div>
                                        <label class="specialist-field"><span>Instructions</span><textarea name="instructions" rows="2" maxlength="2000"></textarea></label>
                                    </div>
                                </div>
                                <div class="specialist-form-section" data-outside-fields hidden>
                                    <h3>Outside referral and deferred charge</h3>
                                    <label class="specialist-field"><span>Outside hospital or doctor name <b aria-hidden="true">*</b></span><input name="provider_name" maxlength="150" type="text"></label>
                                    <label class="specialist-field"><span>Clinical summary <b aria-hidden="true">*</b></span><textarea name="clinical_summary" rows="4" maxlength="10000"></textarea></label>
                                    <label class="specialist-field"><span>Outside tests charge (manual amount) <b aria-hidden="true">*</b></span><input name="outside_tests_amount" type="number" min="0.01" max="99999999.99" step="0.01"></label>
                                    <p class="specialist-hint">The amount is entered for this referral and billed as an unpaid visit charge.</p>
                                </div>
                                <button class="button button-primary specialist-submit" type="submit">Save outcome</button>
                            </form>
                        </section>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
