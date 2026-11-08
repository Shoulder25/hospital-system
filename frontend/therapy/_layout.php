<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/therapy_page.php';
$context = requireTherapyPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$pages = [
    'index' => ['Therapy dashboard', 'A live view of incoming, active and completed therapy cases.', 'all'],
    'referrals' => ['Therapy referrals', 'Review genuine referrals from general doctors and specialists.', 'all'],
    'pending' => ['Pending referrals', 'Accept incoming referrals or flag them with a reason.', 'pending'],
    'patients' => ['Assigned patients', 'Work with accepted therapy patients assigned to you.', 'patients'],
    'assessments' => ['Initial assessments', 'Document functional status, clinical findings and goals.', 'assessments'],
    'sessions' => ['Therapy sessions', 'Record each session, activities, observations and response.', 'sessions'],
    'treatment-plans' => ['Treatment plans', 'Create or update goals, interventions and review dates.', 'patients'],
    'progress' => ['Patient progress', 'Record responses, progress, challenges and follow-up.', 'patients'],
    'reports' => ['Therapy reports', 'Review completion outcomes and case summaries.', 'completed'],
    'completed' => ['Completed cases', 'Review completed therapy cases and discharge notes.', 'completed'],
    'history' => ['Patient history', 'Search assigned referrals, previous sessions and progress.', 'history'],
    'referral-details' => ['Referral details', 'Review the complete clinical record and manage the case.', 'all'],
];
if (!isset($pages[$therapyPage])) {
    http_response_code(404);
    exit('Therapy page not found.');
}
[$pageTitle, $pageDescription, $queueScope] = $pages[$therapyPage];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'T';
$isDetailsPage = $therapyPage === 'referral-details';
$referralId = $_GET['id'] ?? '';
if (!is_string($referralId) || ($referralId !== '' && !ctype_digit($referralId))) {
    http_response_code(400);
    exit('A valid therapy referral ID is required.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title><?= $escape($pageTitle) ?> | CareBridge</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/therapy.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/therapy.css') ?>">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/therapy.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/therapy.js') ?>" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page therapy-page"
      data-base-path="<?= $escape($basePath) ?>"
      data-therapy-page="<?= $escape($therapyPage) ?>"
      data-therapy-scope="<?= $escape($queueScope) ?>"
      data-user-id="<?= (int) $staff['user_id'] ?>"
      data-referral-id="<?= $escape($referralId) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar" aria-label="CareBridge navigation"></aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span><?= $escape($pageTitle) ?></span></div>
                <div class="topbar-actions">
                    <span class="user-avatar"><?= $escape($initial) ?></span>
                    <div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span>Therapy · <?= $escape(str_replace('_', ' ', $staff['role_name'])) ?></span></div>
                    <button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button>
                </div>
            </header>
            <section class="dashboard-content admin-content therapy-content" aria-labelledby="therapy-heading">
                <div class="admin-welcome therapy-welcome">
                    <div>
                        <p class="eyebrow">CAREBRIDGE · IN-HOUSE CARE</p>
                        <h1 id="therapy-heading"><?= $escape($pageTitle) ?></h1>
                        <p class="dashboard-subtitle"><?= $escape($pageDescription) ?></p>
                    </div>
                    <span class="therapy-count" data-therapy-count>Loading…</span>
                </div>
                <div class="admin-notice" data-therapy-message role="status" aria-live="polite" hidden></div>

                <?php if (in_array($therapyPage, ['index', 'reports'], true)): ?>
                    <section class="therapy-stats" aria-label="Therapy case totals">
                        <a href="pending.php"><span>Awaiting review</span><strong data-stat="pending">—</strong></a>
                        <a href="patients.php"><span>Active cases</span><strong data-stat="active">—</strong></a>
                        <a href="completed.php"><span>Completed</span><strong data-stat="completed">—</strong></a>
                        <a href="pending.php"><span>Flagged</span><strong data-stat="flagged">—</strong></a>
                    </section>
                <?php endif; ?>

                <?php if ($therapyPage === 'reports'): ?>
                    <section class="admin-panel therapy-report-panel" aria-labelledby="therapy-report-heading">
                        <div class="admin-panel-heading">
                            <div><h2 id="therapy-report-heading">Completed outcomes</h2><p>Outcome and discharge summaries recorded by the assigned therapist.</p></div>
                            <button class="button button-outline button-small" type="button" data-refresh>Refresh</button>
                        </div>
                        <div class="table-wrap">
                            <table class="admin-table therapy-report-table">
                                <thead><tr><th>Patient / ID</th><th>Visit</th><th>Therapy</th><th>Referrer</th><th>Outcome</th><th>Completed</th></tr></thead>
                                <tbody data-report-rows><tr><td class="table-message" colspan="6">Loading report…</td></tr></tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!$isDetailsPage): ?>
                    <section class="admin-panel therapy-queue-panel" aria-labelledby="queue-heading">
                        <div class="admin-panel-heading">
                            <div><h2 id="queue-heading"><?= $escape($pageTitle) ?></h2><p>Patient ID, visit, diagnosis, referral source and assignment are loaded from the clinical record.</p></div>
                            <div class="therapy-list-tools">
                                <label><span class="visually-hidden">Search therapy patients and referrals</span><input type="search" maxlength="100" placeholder="Patient, patient ID, visit or referral" data-therapy-search></label>
                                <button class="button button-outline button-small" type="button" data-refresh>Refresh</button>
                            </div>
                        </div>
                        <div class="therapy-queue" data-therapy-list><p class="therapy-empty">Loading therapy referrals…</p></div>
                    </section>
                <?php endif; ?>

                <?php if ($isDetailsPage): ?>
                    <p class="therapy-back-link"><a class="text-link" href="referrals.php">← Back to referrals</a></p>
                <?php endif; ?>
                <section class="admin-panel therapy-record-panel" data-record-panel hidden aria-labelledby="record-heading">
                    <div class="admin-panel-heading">
                        <div><h2 id="record-heading" data-record-heading>Therapy case</h2><p data-record-subheading></p></div>
                        <span class="therapy-status" data-record-status></span>
                    </div>
                    <div class="therapy-record">
                        <section class="therapy-record-section">
                            <h3>Patient and referral</h3>
                            <dl class="therapy-details">
                                <div><dt>Patient ID</dt><dd data-patient-id>—</dd></div>
                                <div><dt>Visit ID</dt><dd data-visit-id>—</dd></div>
                                <div><dt>Date of birth / gender</dt><dd data-patient-demographics>—</dd></div>
                                <div><dt>Assigned therapist</dt><dd data-therapist>—</dd></div>
                                <div><dt>Therapy type</dt><dd data-therapy-type>—</dd></div>
                                <div><dt>Referral instructions</dt><dd data-referral-reason>—</dd></div>
                                <div><dt>Referred by</dt><dd data-referrer>—</dd></div>
                                <div><dt>Referral review</dt><dd data-review-status>—</dd></div>
                                <div><dt>Flag reason</dt><dd data-flagged-reason>—</dd></div>
                            </dl>
                        </section>
                        <section class="therapy-record-section">
                            <h3>Clinical context</h3>
                            <dl class="therapy-details">
                                <div><dt>Diagnosis</dt><dd data-diagnosis>—</dd></div>
                                <div><dt>Clinical findings</dt><dd data-findings>—</dd></div>
                                <div><dt>Doctor treatment plan</dt><dd data-doctor-plan>—</dd></div>
                                <div><dt>Presenting complaint</dt><dd data-presenting-complaint>—</dd></div>
                                <div><dt>Clinical observations</dt><dd data-clinical-observations>—</dd></div>
                                <div><dt>Examination findings</dt><dd data-examination-findings>—</dd></div>
                                <div><dt>Consultation notes</dt><dd data-consultation-notes>—</dd></div>
                                <div><dt>Relevant patient history</dt><dd data-patient-history>—</dd></div>
                            </dl>
                        </section>
                        <section class="therapy-record-section">
                            <h3>Initial therapy assessment</h3>
                            <dl class="therapy-details">
                                <div><dt>Functional status</dt><dd data-assessment-function>—</dd></div>
                                <div><dt>Assessment findings</dt><dd data-assessment-findings>—</dd></div>
                                <div><dt>Initial goals</dt><dd data-assessment-goals>—</dd></div>
                            </dl>
                            <form class="therapy-form" data-assessment-form hidden>
                                <label class="therapy-field"><span>Relevant history</span><textarea name="relevant_history" rows="2" maxlength="5000"></textarea></label>
                                <label class="therapy-field"><span>Functional status</span><textarea name="functional_status" rows="3" maxlength="5000" required></textarea></label>
                                <label class="therapy-field"><span>Assessment findings</span><textarea name="assessment_findings" rows="3" maxlength="10000" required></textarea></label>
                                <label class="therapy-field"><span>Initial goals</span><textarea name="initial_goals" rows="3" maxlength="5000" required></textarea></label>
                                <button class="button button-primary" type="submit">Save assessment</button>
                            </form>
                        </section>
                        <section class="therapy-record-section">
                            <h3>Current treatment plan</h3>
                            <dl class="therapy-details">
                                <div><dt>Goals</dt><dd data-plan-goals>—</dd></div>
                                <div><dt>Interventions</dt><dd data-plan-interventions>—</dd></div>
                                <div><dt>Frequency / duration</dt><dd data-plan-schedule>—</dd></div>
                                <div><dt>Review date</dt><dd data-plan-review>—</dd></div>
                            </dl>
                            <form class="therapy-form" data-plan-form hidden>
                                <label class="therapy-field"><span>Treatment goals</span><textarea name="goals" rows="3" maxlength="10000" required></textarea></label>
                                <label class="therapy-field"><span>Planned interventions</span><textarea name="interventions" rows="3" maxlength="10000" required></textarea></label>
                                <div class="therapy-form-row">
                                    <label class="therapy-field"><span>Session frequency</span><input name="frequency" maxlength="150"></label>
                                    <label class="therapy-field"><span>Duration</span><input name="duration" maxlength="150"></label>
                                    <label class="therapy-field"><span>Review date</span><input name="review_date" type="date"></label>
                                </div>
                                <button class="button button-primary" type="submit">Save treatment plan</button>
                            </form>
                        </section>
                        <section class="therapy-record-section">
                            <h3>Therapy sessions</h3>
                            <div class="therapy-sessions" data-sessions><p class="therapy-record-empty">No sessions recorded yet.</p></div>
                            <form class="therapy-form" data-session-form hidden>
                                <label class="therapy-field"><span>Session date and time</span><input name="session_at" type="datetime-local"></label>
                                <label class="therapy-field"><span>Activities performed</span><textarea name="activities_performed" rows="2" maxlength="5000"></textarea></label>
                                <label class="therapy-field"><span>Observations</span><textarea name="observations" rows="2" maxlength="5000"></textarea></label>
                                <label class="therapy-field"><span>Session notes</span><textarea name="session_notes" rows="3" maxlength="5000" required></textarea></label>
                                <label class="therapy-field"><span>Patient response</span><textarea name="patient_response" rows="2" maxlength="5000"></textarea></label>
                                <label class="therapy-field"><span>Progress notes</span><textarea name="progress_notes" rows="2" maxlength="5000"></textarea></label>
                                <label class="therapy-field"><span>Challenges</span><textarea name="challenges" rows="2" maxlength="5000"></textarea></label>
                                <label class="therapy-field"><span>Follow-up recommendations</span><textarea name="follow_up_recommendations" rows="2" maxlength="5000"></textarea></label>
                                <button class="button button-primary" type="submit" data-record-session>Save session</button>
                            </form>
                        </section>
                        <section class="therapy-record-section">
                            <h3>Progress history</h3>
                            <div class="therapy-sessions" data-progress-list><p class="therapy-record-empty">No progress notes recorded.</p></div>
                            <form class="therapy-form" data-progress-form hidden>
                                <label class="therapy-field"><span>Session (optional)</span><select name="therapy_session_id" data-session-select><option value="">Not linked to a session</option></select></label>
                                <label class="therapy-field"><span>Patient response</span><textarea name="patient_response" rows="2" maxlength="5000" required></textarea></label>
                                <label class="therapy-field"><span>Progress notes</span><textarea name="progress_notes" rows="3" maxlength="10000" required></textarea></label>
                                <label class="therapy-field"><span>Challenges</span><textarea name="challenges" rows="2" maxlength="5000"></textarea></label>
                                <label class="therapy-field"><span>Follow-up recommendations</span><textarea name="follow_up_recommendations" rows="2" maxlength="5000"></textarea></label>
                                <button class="button button-primary" type="submit">Save progress</button>
                            </form>
                        </section>
                        <section class="therapy-record-section">
                            <h3>Supporting documents</h3>
                            <div class="therapy-files" data-files><p class="therapy-record-empty">No supporting documents uploaded.</p></div>
                            <form class="therapy-form therapy-upload-form" data-upload-form hidden>
                                <label class="therapy-field"><span>Upload PDF, JPG, PNG or TXT (10 MB max)</span><input name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,.txt" required></label>
                                <button class="button button-outline" type="submit">Upload document</button>
                            </form>
                        </section>
                        <section class="therapy-record-section" data-review-section>
                            <h3>Referral decision</h3>
                            <p class="therapy-help">Accept to assign this case to your therapy queue. Flag it with a reason if the referral needs clarification.</p>
                            <div class="therapy-review-actions">
                                <button class="button button-primary" type="button" data-accept>Accept referral</button>
                                <form class="therapy-form" data-flag-form>
                                    <label class="therapy-field"><span>Reason for flagging</span><textarea name="reason" rows="2" maxlength="2000" required></textarea></label>
                                    <button class="button button-outline" type="submit">Flag for review</button>
                                </form>
                            </div>
                        </section>
                        <section class="therapy-record-section" data-completion-section>
                            <h3>Complete therapy case</h3>
                            <form class="therapy-form therapy-completion-form" data-completion-form hidden>
                                <label class="therapy-field"><span>Outcome</span><textarea name="outcome" rows="2" maxlength="5000" required></textarea></label>
                                <label class="therapy-field"><span>Discharge notes</span><textarea name="discharge_notes" rows="3" maxlength="10000" required></textarea></label>
                                <button class="button button-primary" type="submit">Complete therapy</button>
                            </form>
                            <p data-completion-note class="therapy-help">Record an assessment, plan and at least one session before completing the case.</p>
                            <p class="therapy-outcome" data-outcome></p>
                            <p class="therapy-outcome" data-discharge-notes></p>
                        </section>
                    </div>
                </section>

                <?php if ($therapyPage === 'index'): ?>
                    <section class="admin-panel therapy-shortcuts" aria-label="Therapy activities">
                        <a href="referrals.php"><strong>Review referrals</strong><span>Open doctor and specialist referrals</span></a>
                        <a href="assessments.php"><strong>Initial assessments</strong><span>Document function and therapy goals</span></a>
                        <a href="sessions.php"><strong>Record sessions</strong><span>Record activities and observations</span></a>
                        <a href="reports.php"><strong>Reports and outcomes</strong><span>Review discharge outcomes</span></a>
                    </section>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
