<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/reception_page.php';

$context = requireReceptionPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'R';
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
    <title>Reception | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/reception.css">
    <script src="../assets/js/reception.js" defer></script>
</head>
<body class="dashboard-page admin-page reception-page" data-base-path="<?= $escape($basePath) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php">
                <span class="brand-mark" aria-hidden="true">+</span>
                <span>Care<span class="brand-light">Bridge</span></span>
            </a>
            <p class="sidebar-caption">RECEPTION / OPD</p>
            <nav class="side-nav" aria-label="Reception navigation">
                <a class="side-link active" href="index.php"><span aria-hidden="true">⌂</span> Reception desk</a>
                <a class="side-link" href="#patient-search"><span aria-hidden="true">⌕</span> Find a patient</a>
                <a class="side-link" href="#patient-registration"><span aria-hidden="true">＋</span> Register patient</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help">
                    <span class="help-icon" aria-hidden="true">i</span>
                    <div><strong>Patient arrivals</strong><small>Search first to avoid duplicate records.</small></div>
                </div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>

        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title">
                    <span class="mobile-brand-mark" aria-hidden="true">+</span>
                    <span>Reception / Patient registration</span>
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

            <section class="dashboard-content admin-content reception-content" aria-labelledby="reception-heading">
                <div class="admin-welcome reception-welcome">
                    <div>
                        <p class="eyebrow">ARRIVAL / REGISTRATION</p>
                        <h1 id="reception-heading">Reception desk</h1>
                        <p class="dashboard-subtitle">Find or register a patient, then route their arrival to the correct next step.</p>
                    </div>
                    <span class="reception-date" data-current-date></span>
                </div>

                <div class="admin-notice" data-reception-message role="status" aria-live="polite" hidden></div>

                <section class="admin-stat-grid reception-stat-grid" aria-label="Reception statistics">
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-teal" aria-hidden="true">♙</div>
                        <p>Total patients registered</p>
                        <strong data-stat="total_patients">—</strong>
                        <span>All patient records</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-blue" aria-hidden="true">＋</div>
                        <p>Today's arrivals</p>
                        <strong data-stat="todays_arrivals">—</strong>
                        <span>Visits recorded today</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-amber" aria-hidden="true">!</div>
                        <p>Emergency arrivals</p>
                        <strong data-stat="emergency_arrivals">—</strong>
                        <span>Arrived today</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-violet" aria-hidden="true">◷</div>
                        <p>Awaiting ticket payment</p>
                        <strong data-stat="awaiting_ticket_payment">—</strong>
                        <span>Current visit queue</span>
                    </article>
                </section>

                <div class="reception-workspace">
                    <section class="admin-panel reception-panel" id="patient-search" aria-labelledby="patient-search-heading">
                        <div class="admin-panel-heading">
                            <div>
                                <h2 id="patient-search-heading">Find an existing patient</h2>
                                <p>Search by patient number, national ID or name before registering.</p>
                            </div>
                        </div>
                        <form class="reception-search-form" data-patient-search-form>
                            <label class="search-field reception-search-field">
                                <span aria-hidden="true">⌕</span>
                                <span class="visually-hidden">Patient search</span>
                                <input name="search" type="search" maxlength="100" placeholder="Patient number, national ID or name" required>
                            </label>
                            <button class="button button-primary" type="submit" data-search-submit>Search</button>
                        </form>
                        <div class="table-wrap">
                            <table class="admin-table reception-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Patient</th>
                                        <th scope="col">Patient number</th>
                                        <th scope="col">Date of birth</th>
                                        <th scope="col">National ID</th>
                                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody data-search-results>
                                    <tr><td class="table-message" colspan="5">Search for a patient to see matching records.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="admin-panel reception-panel" id="patient-registration" aria-labelledby="patient-registration-heading">
                        <div class="admin-panel-heading">
                            <div>
                                <h2 id="patient-registration-heading">Register a new patient</h2>
                                <p>Patient numbers are generated automatically. Fields marked required must be completed.</p>
                            </div>
                        </div>
                        <form class="reception-form" data-patient-form>
                            <label class="temporary-patient-option reception-field-wide">
                                <input name="is_temporary" type="checkbox" value="true">
                                <span>
                                    <strong>Emergency temporary record</strong>
                                    <small>Use when an emergency patient cannot provide full details. Complete the record after stabilization.</small>
                                </span>
                            </label>
                            <div class="reception-field">
                                <label for="patient-first-name">First name <span data-required-marker data-temporary-optional aria-hidden="true">*</span></label>
                                <input id="patient-first-name" name="first_name" type="text" maxlength="100" autocomplete="given-name" required>
                            </div>
                            <div class="reception-field">
                                <label for="patient-last-name">Last name <span data-required-marker data-temporary-optional aria-hidden="true">*</span></label>
                                <input id="patient-last-name" name="last_name" type="text" maxlength="100" autocomplete="family-name" required>
                            </div>
                            <div class="reception-field">
                                <label for="patient-national-id">National ID</label>
                                <input id="patient-national-id" name="national_id" type="text" maxlength="50" autocomplete="off">
                            </div>
                            <div class="reception-field">
                                <label for="patient-dob">Date of birth <span data-required-marker data-temporary-optional aria-hidden="true">*</span></label>
                                <input id="patient-dob" name="date_of_birth" type="date" required>
                            </div>
                            <div class="reception-field">
                                <label for="patient-gender">Gender <span data-required-marker aria-hidden="true">*</span></label>
                                <select id="patient-gender" name="gender" required>
                                    <option value="">Select</option>
                                    <option value="female">Female</option>
                                    <option value="male">Male</option>
                                    <option value="other">Other</option>
                                    <option value="unknown">Unknown</option>
                                </select>
                            </div>
                            <div class="reception-field">
                                <label for="patient-phone">Phone number</label>
                                <input id="patient-phone" name="phone" type="tel" maxlength="40" autocomplete="tel">
                            </div>
                            <div class="reception-field reception-field-wide">
                                <label for="patient-address">Address</label>
                                <input id="patient-address" name="address" type="text" maxlength="255" autocomplete="street-address">
                            </div>
                            <div class="reception-field reception-field-wide">
                                <button class="button button-primary" type="submit" data-register-submit>Register patient</button>
                            </div>
                        </form>
                    </section>
                </div>

                <section class="admin-panel reception-panel visit-panel" aria-labelledby="visit-heading">
                    <div class="admin-panel-heading">
                        <div>
                            <h2 id="visit-heading">Start a visit</h2>
                            <p>Select a patient above or search for an existing record. Confirm the visit route before saving.</p>
                        </div>
                    </div>
                    <div class="selected-patient" data-selected-patient>
                        <span class="selected-patient-icon" aria-hidden="true">♙</span>
                        <span data-selected-patient-label>No patient selected</span>
                    </div>
                    <form class="reception-form visit-form" data-visit-form>
                        <input type="hidden" name="patient_id">
                        <fieldset class="visit-type-fieldset">
                            <legend>Arrival type</legend>
                            <label><input type="radio" name="arrival_type" value="standard" checked> Standard arrival</label>
                            <label><input type="radio" name="arrival_type" value="emergency"> Emergency</label>
                            <label><input type="radio" name="arrival_type" value="medication_collection_only"> Medication collection only</label>
                        </fieldset>
                        <div class="reception-field">
                            <label for="visit-source">Visit source</label>
                            <select id="visit-source" name="visit_source" required>
                                <option value="walk_in">Walk-in</option>
                                <option value="school_appointment">School appointment</option>
                                <option value="referral">Referral</option>
                            </select>
                        </div>
                        <div class="reception-field" data-organization-field hidden>
                            <label for="visit-organization">School / referring organization</label>
                            <select id="visit-organization" name="source_organization_id">
                                <option value="">Choose an organization</option>
                            </select>
                        </div>
                        <div class="reception-field reception-field-wide">
                            <label for="visit-source-notes">Source notes</label>
                            <textarea id="visit-source-notes" name="source_notes" rows="2" maxlength="2000" placeholder="Optional appointment or referral notes"></textarea>
                        </div>
                        <div class="reception-field reception-field-wide">
                            <button class="button button-primary" type="submit" data-visit-submit disabled>Confirm and start visit</button>
                        </div>
                    </form>
                </section>

                <section class="admin-panel reception-panel recent-panel" aria-labelledby="recent-heading">
                    <div class="admin-panel-heading">
                        <div>
                            <h2 id="recent-heading">Recent patient registrations</h2>
                            <p>Most recently added patient records.</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="admin-table reception-table">
                            <thead>
                                <tr>
                                    <th scope="col">Patient</th>
                                    <th scope="col">Patient number</th>
                                    <th scope="col">Registered</th>
                                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody data-recent-patients>
                                <tr><td class="table-message" colspan="4">Loading recent patients…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="admin-panel patient-details-panel" data-patient-details hidden aria-labelledby="patient-details-heading">
                    <div class="admin-panel-heading">
                        <div>
                            <h2 id="patient-details-heading">Patient details</h2>
                            <p data-patient-details-summary></p>
                        </div>
                    <div class="patient-detail-actions">
                        <button class="button button-primary" type="button" data-complete-record hidden>Complete details</button>
                        <button class="button button-outline" type="button" data-close-details>Close</button>
                    </div>
                    </div>
                    <dl class="patient-detail-grid" data-patient-detail-fields></dl>
                    <div class="table-wrap">
                        <table class="admin-table reception-table">
                            <thead>
                                <tr><th scope="col">Visit</th><th scope="col">Arrived</th><th scope="col">Source</th><th scope="col">Route</th><th scope="col">Status</th></tr>
                            </thead>
                            <tbody data-patient-visits></tbody>
                        </table>
                    </div>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
