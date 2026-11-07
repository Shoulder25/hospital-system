<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/nursing_page.php';

$context = requireNursingPage();
$staff = $context['user'];
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'N';
$basePath = $context['base_path'];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$roleLabel = str_replace('_', ' ', (string) $staff['role_name']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Nursing | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/nursing.css">
    <script src="../assets/js/nursing.js" defer></script>
</head>
<body class="dashboard-page admin-page nursing-page" data-base-path="<?= $escape($basePath) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php">
                <span class="brand-mark" aria-hidden="true">+</span>
                <span>Care<span class="brand-light">Bridge</span></span>
            </a>
            <p class="sidebar-caption">NURSING</p>
            <nav class="side-nav" aria-label="Nursing navigation">
                <a class="side-link active" href="index.php"><span aria-hidden="true">⌂</span> Assessment queue</a>
                <a class="side-link" href="#nursing-queue"><span aria-hidden="true">◷</span> Patients awaiting assessment</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help">
                    <span class="help-icon" aria-hidden="true">i</span>
                    <div><strong>Normal nursing queue</strong><small>Emergency and medication-only visits are excluded.</small></div>
                </div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>

        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title">
                    <span class="mobile-brand-mark" aria-hidden="true">+</span>
                    <span>Nursing / Assessment queue</span>
                </div>
                <div class="topbar-actions">
                    <span class="user-avatar"><?= $escape($initial) ?></span>
                    <div class="user-summary">
                        <strong><?= $escape($staff['display_name']) ?></strong>
                        <span><?= $escape(ucwords($roleLabel)) ?></span>
                    </div>
                    <button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button>
                </div>
            </header>

            <section class="dashboard-content admin-content nursing-content" aria-labelledby="nursing-heading">
                <div class="admin-welcome nursing-welcome">
                    <div>
                        <p class="eyebrow">NURSING ASSESSMENT</p>
                        <h1 id="nursing-heading">Assessment queue</h1>
                        <p class="dashboard-subtitle">Assess eligible patients after ticket payment and route them to the next service.</p>
                    </div>
                    <span class="nursing-date" data-current-date></span>
                </div>

                <div class="admin-notice" data-nursing-message role="status" aria-live="polite" hidden></div>
                <p class="nursing-workflow-note">Only non-emergency visits with status <strong>awaiting nurse</strong> appear here. Emergency and medication-collection-only patients follow separate routes. Measurements are recorded without automated clinical interpretation.</p>

                <section class="admin-stat-grid nursing-stat-grid" aria-label="Nursing dashboard statistics">
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-teal" aria-hidden="true">◷</div>
                        <p>Awaiting assessment</p>
                        <strong data-stat="awaiting_assessment">—</strong>
                        <span>Ticket step completed</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-blue" aria-hidden="true">✓</div>
                        <p>Assessed today</p>
                        <strong data-stat="assessed_today">—</strong>
                        <span>Assessment records saved</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-amber" aria-hidden="true">⌕</div>
                        <p>Awaiting tests</p>
                        <strong data-stat="awaiting_tests">—</strong>
                        <span>Routed to testing centre</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-violet" aria-hidden="true">➜</div>
                        <p>Sent directly to doctor</p>
                        <strong data-stat="awaiting_doctor">—</strong>
                        <span>General doctor queue</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-amber" aria-hidden="true">＋</div>
                        <p>Minor illness treated today</p>
                        <strong data-stat="minor_treated_today">—</strong>
                        <span>Charges routed for payment</span>
                    </article>
                </section>

                <section class="admin-panel nursing-queue-panel" id="nursing-queue" aria-labelledby="queue-heading">
                    <div class="admin-panel-heading nursing-queue-heading">
                        <div>
                            <h2 id="queue-heading">Patients awaiting nursing assessment</h2>
                            <p>Visits are eligible only after ticket payment and when they are not emergency or medication-only visits.</p>
                        </div>
                        <label class="search-field nursing-search">
                            <span aria-hidden="true">⌕</span>
                            <span class="visually-hidden">Search nursing queue</span>
                            <input type="search" data-queue-search maxlength="100" placeholder="Search patient or number">
                        </label>
                    </div>
                    <div class="table-wrap">
                        <table class="admin-table nursing-table">
                            <thead>
                                <tr>
                                    <th scope="col">Patient number</th>
                                    <th scope="col">Patient name</th>
                                    <th scope="col">Visit date / time</th>
                                    <th scope="col">Emergency</th>
                                    <th scope="col">Visit source</th>
                                    <th scope="col">Status</th>
                                    <th scope="col"><span class="visually-hidden">Action</span></th>
                                </tr>
                            </thead>
                            <tbody data-queue-rows>
                                <tr><td class="table-message" colspan="7">Loading nursing queue…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="admin-panel nursing-assessment-panel" data-assessment-panel hidden aria-labelledby="assessment-heading">
                    <div class="admin-panel-heading">
                        <div>
                            <h2 id="assessment-heading">Patient nursing assessment</h2>
                            <p data-assessment-visit-summary></p>
                        </div>
                        <button class="button button-outline" type="button" data-close-assessment>Close</button>
                    </div>

                    <div class="nursing-patient-summary" data-patient-summary></div>

                    <section class="nursing-history" aria-labelledby="history-heading">
                        <div class="nursing-subheading">
                            <h3 id="history-heading">Relevant previous visits</h3>
                            <p>Recent visit and nursing information for this patient.</p>
                        </div>
                        <div class="table-wrap">
                            <table class="admin-table nursing-history-table">
                                <thead>
                                    <tr><th scope="col">Visit date</th><th scope="col">Source</th><th scope="col">Status</th><th scope="col">Previous complaint / outcome</th></tr>
                                </thead>
                                <tbody data-history-rows></tbody>
                            </table>
                        </div>
                    </section>

                    <form class="nursing-form" data-assessment-form>
                        <input type="hidden" name="visit_id">
                        <div class="nursing-subheading nursing-form-heading">
                            <h3>Complaints and symptoms</h3>
                            <p>Record the patient's account; do not enter a diagnosis in this section.</p>
                        </div>
                        <div class="nursing-field nursing-field-wide">
                            <label for="nursing-complaint">Main complaint <span aria-hidden="true">*</span></label>
                            <textarea id="nursing-complaint" name="complaint" rows="2" maxlength="5000" required></textarea>
                        </div>
                        <div class="nursing-field">
                            <label for="nursing-symptoms">Symptoms</label>
                            <textarea id="nursing-symptoms" name="symptoms" rows="2" maxlength="10000"></textarea>
                        </div>
                        <div class="nursing-field">
                            <label for="nursing-duration">Duration of symptoms</label>
                            <input id="nursing-duration" name="symptom_duration" type="text" maxlength="100" placeholder="For example, 2 days">
                        </div>

                        <div class="nursing-subheading nursing-form-heading nursing-field-wide">
                            <h3>Vital signs</h3>
                            <p>Fields are optional. Values are recorded as entered and are not clinically interpreted by the system.</p>
                        </div>
                        <div class="nursing-field">
                            <label for="nursing-temperature">Temperature (°C)</label>
                            <input id="nursing-temperature" name="temperature" type="number" min="25" max="45" step="0.1">
                        </div>
                        <fieldset class="nursing-field nursing-bp-field">
                            <legend>Blood pressure (mmHg)</legend>
                            <div class="nursing-bp-inputs">
                                <label><span class="visually-hidden">Systolic pressure</span><input name="systolic" type="number" min="40" max="300" step="1" placeholder="Systolic"></label>
                                <span aria-hidden="true">/</span>
                                <label><span class="visually-hidden">Diastolic pressure</span><input name="diastolic" type="number" min="20" max="200" step="1" placeholder="Diastolic"></label>
                            </div>
                        </fieldset>
                        <div class="nursing-field">
                            <label for="nursing-pulse">Pulse rate (bpm)</label>
                            <input id="nursing-pulse" name="pulse" type="number" min="1" max="300" step="1">
                        </div>
                        <div class="nursing-field">
                            <label for="nursing-respiratory-rate">Respiratory rate (breaths/min)</label>
                            <input id="nursing-respiratory-rate" name="respiratory_rate" type="number" min="1" max="100" step="1">
                        </div>
                        <div class="nursing-field">
                            <label for="nursing-oxygen">Oxygen saturation (%)</label>
                            <input id="nursing-oxygen" name="oxygen_saturation" type="number" min="0" max="100" step="0.1">
                        </div>
                        <div class="nursing-field">
                            <label for="nursing-weight">Weight (kg)</label>
                            <input id="nursing-weight" name="weight" type="number" min="0.1" max="500" step="0.1">
                        </div>
                        <div class="nursing-field">
                            <label for="nursing-height">Height (cm)</label>
                            <input id="nursing-height" name="height" type="number" min="20" max="250" step="0.1">
                        </div>
                        <div class="nursing-field nursing-field-wide">
                            <label for="nursing-notes">Nursing notes / observations</label>
                            <textarea id="nursing-notes" name="nursing_notes" rows="3" maxlength="10000"></textarea>
                        </div>
                        <div class="nursing-field nursing-field-wide">
                            <button class="button button-primary" type="submit" data-save-assessment>Save assessment</button>
                            <span class="nursing-save-hint" data-save-hint></span>
                        </div>
                    </form>

                    <section class="nursing-decision-section" data-decision-section hidden aria-labelledby="decision-heading">
                        <div class="nursing-subheading">
                            <h3 id="decision-heading">Nurse decision</h3>
                            <p>Select one outcome. This records the route; no automated diagnosis is made.</p>
                        </div>
                        <form class="nursing-form nursing-decision-form" data-decision-form>
                            <fieldset class="nursing-outcomes">
                                <legend>Assessment outcome <span aria-hidden="true">*</span></legend>
                                <label><input type="radio" name="decision" value="minor_illness" required> Minor illness treatment</label>
                                <label><input type="radio" name="decision" value="tests"> Send for in-house tests</label>
                                <label><input type="radio" name="decision" value="general_doctor"> Send directly to general doctor</label>
                            </fieldset>
                            <fieldset class="nursing-test-options" data-test-options hidden>
                                <legend>Approved in-house tests</legend>
                                <label><input type="checkbox" name="test_types" value="blood"> Blood</label>
                                <label><input type="checkbox" name="test_types" value="glucose"> Glucose</label>
                                <label><input type="checkbox" name="test_types" value="urine"> Urine</label>
                                <label><input type="checkbox" name="test_types" value="pregnancy"> Pregnancy</label>
                                <label><input type="checkbox" name="test_types" value="pap_smear"> Pap smear</label>
                                <label><input type="checkbox" name="test_types" value="x_ray"> X-ray</label>
                                <label><input type="checkbox" name="test_types" value="ecg"> ECG</label>
                            </fieldset>
                            <div class="nursing-field nursing-field-wide">
                                <button class="button button-primary" type="submit" data-submit-decision>Confirm decision</button>
                            </div>
                        </form>
                    </section>

                    <section class="nursing-treatment-section" data-treatment-section hidden aria-labelledby="treatment-heading">
                        <div class="nursing-subheading">
                            <h3 id="treatment-heading">Minor illness treatment</h3>
                            <p>Only active medicines marked low-schedule in the database are available. Charges are routed for pharmacy payment.</p>
                        </div>
                        <form class="nursing-form nursing-treatment-form" data-treatment-form>
                            <div data-treatment-items></div>
                            <button class="button button-outline" type="button" data-add-treatment-item>Add medicine</button>
                            <div class="nursing-field nursing-field-wide">
                                <label for="treatment-notes">Treatment notes <span aria-hidden="true">*</span></label>
                                <textarea id="treatment-notes" name="treatment_notes" rows="3" maxlength="5000" required></textarea>
                            </div>
                            <div class="nursing-field nursing-field-wide">
                                <button class="button button-primary" type="submit" data-save-treatment>Record treatment and route to payment</button>
                            </div>
                        </form>
                    </section>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
