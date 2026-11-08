<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/doctor_page.php';

$context = requireDoctorPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'D';
$roleLabel = str_replace('_', ' ', (string) $staff['role_name']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>General Doctor | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/doctor.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/doctor.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page doctor-page" data-base-path="<?= $escape($basePath) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php">
                <span class="brand-mark" aria-hidden="true">+</span>
                <span>Care<span class="brand-light">Bridge</span></span>
            </a>
            <p class="sidebar-caption">GENERAL DOCTOR</p>
            <nav class="side-nav" aria-label="Doctor navigation">
                <a class="side-link active" href="index.php"><span aria-hidden="true">⌂</span> Doctor dashboard</a>
                <a class="side-link" href="#doctor-queue"><span aria-hidden="true">◷</span> Consultation queue</a>
                <a class="side-link" href="#clinical-record"><span aria-hidden="true">＋</span> Clinical record</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help">
                    <span class="help-icon" aria-hidden="true">i</span>
                    <div><strong>Clinical records</strong><small>Use the correct visit before recording care.</small></div>
                </div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>

        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title">
                    <span class="mobile-brand-mark" aria-hidden="true">+</span>
                    <span>General doctor</span>
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

            <section class="dashboard-content admin-content doctor-content" aria-labelledby="doctor-heading">
                <div class="admin-welcome doctor-welcome">
                    <div>
                        <p class="eyebrow">CONSULTATIONS / DIAGNOSES / TREATMENT</p>
                        <h1 id="doctor-heading">General doctor dashboard</h1>
                        <p class="dashboard-subtitle">Review the visit record, document clinical findings and route the patient to the next step.</p>
                    </div>
                    <span class="doctor-date" data-current-date></span>
                </div>

                <div class="admin-notice" data-doctor-message role="status" aria-live="polite" hidden></div>

                <section class="admin-stat-grid doctor-stat-grid" aria-label="Doctor workflow statistics">
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-teal" aria-hidden="true">◷</div>
                        <p>Awaiting consultation</p>
                        <strong data-stat="awaiting_consultation">—</strong>
                        <span>Eligible patient visits</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-blue" aria-hidden="true">✓</div>
                        <p>Consultations today</p>
                        <strong data-stat="consultations_today">—</strong>
                        <span>General doctor records</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-amber" aria-hidden="true">⌕</div>
                        <p>Awaiting test results</p>
                        <strong data-stat="awaiting_test_results">—</strong>
                        <span>Visits in testing workflow</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-violet" aria-hidden="true">＋</div>
                        <p>Further investigations</p>
                        <strong data-stat="further_investigations">—</strong>
                        <span>Visits awaiting tests</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-blue" aria-hidden="true">↗</div>
                        <p>Specialist referrals</p>
                        <strong data-stat="specialist_referrals">—</strong>
                        <span>Pending referrals</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-teal" aria-hidden="true">✚</div>
                        <p>Patients with completed diagnoses</p>
                        <strong data-stat="patients_with_diagnosis">—</strong>
                        <span>Visits with a recorded diagnosis</span>
                    </article>
                </section>

                <section class="admin-panel doctor-panel" id="doctor-queue" aria-labelledby="queue-heading">
                    <div class="admin-panel-heading">
                        <div>
                            <h2 id="queue-heading">Consultation queue</h2>
                            <p>Only visits ready for doctor review are listed. Test visits need completed orders and a final result.</p>
                        </div>
                        <label class="doctor-search">
                            <span class="visually-hidden">Search doctor queue</span>
                            <input type="search" maxlength="100" placeholder="Search patient or number" data-doctor-search>
                        </label>
                    </div>
                    <div class="doctor-table-wrap">
                        <table class="doctor-table">
                            <thead>
                                <tr>
                                    <th>Patient number</th>
                                    <th>Patient</th>
                                    <th>Visit</th>
                                    <th>Date / time</th>
                                    <th>Source</th>
                                    <th>Status</th>
                                    <th>Assessment / results</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody data-queue-rows>
                                <tr><td class="table-message" colspan="8">Loading doctor queue…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="admin-panel doctor-panel doctor-record" id="clinical-record" data-doctor-record hidden aria-labelledby="record-heading">
                    <div class="admin-panel-heading doctor-record-heading">
                        <div>
                            <p class="eyebrow">PATIENT / VISIT RECORD</p>
                            <h2 id="record-heading">Clinical record</h2>
                            <p data-record-summary></p>
                        </div>
                        <button class="button button-outline" type="button" data-close-record>Close record</button>
                    </div>

                    <div class="doctor-record-grid">
                        <section class="doctor-record-card" aria-labelledby="patient-info-heading">
                            <h3 id="patient-info-heading">Patient and visit</h3>
                            <dl class="doctor-details" data-patient-details></dl>
                        </section>
                        <section class="doctor-record-card" aria-labelledby="nursing-heading">
                            <h3 id="nursing-heading">Nursing assessment</h3>
                            <dl class="doctor-details" data-nursing-details></dl>
                        </section>
                    </div>

                    <section class="doctor-record-card doctor-wide-card" aria-labelledby="results-heading">
                        <h3 id="results-heading">Tests and results</h3>
                        <div class="doctor-result-list" data-test-results></div>
                    </section>
                    <section class="doctor-record-card doctor-wide-card" aria-labelledby="history-heading">
                        <h3 id="history-heading">Previous visits and diagnoses</h3>
                        <div class="doctor-history-list" data-visit-history></div>
                    </section>
                    <section class="doctor-record-card doctor-wide-card" aria-labelledby="current-records-heading">
                        <h3 id="current-records-heading">Consultations and diagnoses for this visit</h3>
                        <div class="doctor-history-list" data-current-clinical-records></div>
                    </section>

                    <form class="doctor-form" data-consultation-form>
                        <div class="doctor-form-heading">
                            <div>
                                <h3>Record consultation</h3>
                                <p>Clinical text is saved against this visit and your authenticated account.</p>
                            </div>
                        </div>
                        <label>
                            <span>Presenting complaint <b aria-hidden="true">*</b></span>
                            <textarea name="presenting_complaint" rows="3" maxlength="5000" required></textarea>
                        </label>
                        <div class="doctor-form-grid">
                            <label>
                                <span>Clinical observations</span>
                                <textarea name="clinical_observations" rows="4" maxlength="10000"></textarea>
                            </label>
                            <label>
                                <span>Examination findings</span>
                                <textarea name="examination_findings" rows="4" maxlength="10000"></textarea>
                            </label>
                        </div>
                        <label>
                            <span>Consultation notes</span>
                            <textarea name="notes" rows="3" maxlength="10000"></textarea>
                        </label>
                        <button class="button button-primary" type="submit" data-consultation-submit>Save consultation</button>
                    </form>

                    <form class="doctor-form doctor-decision-form" data-diagnosis-form hidden>
                        <input type="hidden" name="consultation_id">
                        <div class="doctor-form-heading">
                            <div>
                                <h3>Diagnosis and next step</h3>
                                <p>Choose the next workflow step. Earlier consultations, diagnoses and results are retained.</p>
                            </div>
                        </div>
                        <label class="doctor-inline-field">
                            <span>Was a diagnosis made?</span>
                            <select name="diagnosis_made" data-diagnosis-made required>
                                <option value="yes">Yes</option>
                                <option value="no">No</option>
                            </select>
                        </label>
                        <label>
                            <span>Next step</span>
                            <select name="outcome" data-doctor-outcome required>
                                <option value="diagnosis">Diagnosis and treatment plan</option>
                                <option value="more_tests">Order more in-house tests</option>
                                <option value="specialist_referral">Refer to resident / locum specialist</option>
                            </select>
                        </label>

                        <div class="doctor-outcome-group" data-diagnosis-fields>
                            <label>
                                <span>Diagnosis description <b aria-hidden="true">*</b></span>
                                <textarea name="diagnosis_description" rows="2" maxlength="5000"></textarea>
                            </label>
                            <label>
                                <span>Clinical findings</span>
                                <textarea name="clinical_findings" rows="3" maxlength="10000"></textarea>
                            </label>
                            <label>
                                <span>Treatment plan <b aria-hidden="true">*</b></span>
                                <textarea name="treatment_plan" rows="3" maxlength="10000"></textarea>
                            </label>
                            <label>
                                <span>Additional diagnosis notes</span>
                                <textarea name="diagnosis_notes" rows="2" maxlength="10000"></textarea>
                            </label>
                            <label class="doctor-checkbox">
                                <input type="checkbox" name="refer_for_therapy" value="yes" data-therapy-toggle>
                                <span>Refer for in-house therapy</span>
                            </label>
                            <div class="doctor-form-grid" data-therapy-fields hidden>
                                <label>
                                    <span>Therapy type <b aria-hidden="true">*</b></span>
                                    <input type="text" name="therapy_type" maxlength="100">
                                </label>
                                <label>
                                    <span>Therapy referral reason <b aria-hidden="true">*</b></span>
                                    <textarea name="therapy_reason" rows="2" maxlength="5000"></textarea>
                                </label>
                            </div>
                            <div class="doctor-prescription">
                                <div class="doctor-subheading">
                                    <div><h4>Prescription (optional)</h4><p>Creating a prescription routes the visit to pharmacy; this page does not dispense medicines.</p></div>
                                    <button class="button button-outline button-small" type="button" data-add-prescription-item>Add medicine</button>
                                </div>
                                <div class="doctor-prescription-items" data-prescription-items></div>
                            </div>
                        </div>

                        <fieldset class="doctor-outcome-group doctor-test-options" data-test-fields hidden>
                            <legend>Approved in-house tests</legend>
                            <label><input type="checkbox" name="test_types[]" value="blood"> Blood</label>
                            <label><input type="checkbox" name="test_types[]" value="glucose"> Glucose</label>
                            <label><input type="checkbox" name="test_types[]" value="urine"> Urine</label>
                            <label><input type="checkbox" name="test_types[]" value="pregnancy"> Pregnancy</label>
                            <label><input type="checkbox" name="test_types[]" value="pap_smear"> Pap smear</label>
                            <label><input type="checkbox" name="test_types[]" value="x_ray"> X-ray</label>
                            <label><input type="checkbox" name="test_types[]" value="ecg"> ECG</label>
                            <p>New test orders are linked to this visit. A repeat test is recorded as a new order.</p>
                        </fieldset>

                        <div class="doctor-outcome-group doctor-referral-fields" data-specialist-fields hidden>
                            <label>
                                <span>Resident or locum specialist <b aria-hidden="true">*</b></span>
                                <select name="specialist_user_id" data-specialist-options>
                                    <option value="">Choose an active specialist</option>
                                </select>
                            </label>
                            <label>
                                <span>Referral reason <b aria-hidden="true">*</b></span>
                                <textarea name="referral_reason" rows="2" maxlength="5000"></textarea>
                            </label>
                            <label>
                                <span>Clinical summary</span>
                                <textarea name="clinical_summary" rows="3" maxlength="10000"></textarea>
                            </label>
                        </div>
                        <div class="doctor-form-actions">
                            <button class="button button-primary" type="submit" data-diagnosis-submit>Save decision</button>
                            <button class="button button-outline" type="button" data-cancel-decision>Cancel</button>
                        </div>
                    </form>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
