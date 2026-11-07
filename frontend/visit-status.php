<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Visit status | Hospital Patient Processing</title>
    <link rel="stylesheet" href="assets/css/auth.css">
    <link rel="stylesheet" href="assets/css/monitoring.css">
    <script src="assets/js/visit-status.js" defer></script>
</head>
<body class="dashboard-page monitoring-page" data-page="visit-status">
    <div class="dashboard-layout">
        <aside class="sidebar">
            <a class="brand-lockup sidebar-brand" href="dashboard.php">
                <span class="brand-mark" aria-hidden="true">+</span>
                <span>Care<span class="brand-light">Bridge</span></span>
            </a>
            <p class="sidebar-caption">WORKSPACE</p>
            <nav class="side-nav" aria-label="Main navigation">
                <a class="side-link" href="dashboard.php"><span aria-hidden="true">⌂</span> Dashboard</a>
                <a class="side-link active" href="visit-status.php"><span aria-hidden="true">◷</span> Visit status</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help">
                    <span class="help-icon" aria-hidden="true">i</span>
                    <div><strong>Visit status</strong><small>Current workflow status and recorded timeline.</small></div>
                </div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>

        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Visit status tracker</span></div>
                <div class="topbar-actions">
                    <div class="user-summary"><strong data-status-user>Loading…</strong><span data-status-role>Checking session</span></div>
                    <button class="button button-outline button-logout" type="button" data-status-logout>Sign out</button>
                </div>
            </header>
            <section class="dashboard-content tracking-content" aria-labelledby="tracking-heading">
                <div class="welcome-row">
                    <div>
                        <p class="eyebrow">PATIENT FLOW</p>
                        <h1 id="tracking-heading">Visit status tracker</h1>
                        <p class="dashboard-subtitle">Search by patient number or visit ID to view the latest visit and its recorded milestones.</p>
                    </div>
                    <span class="demo-badge">READ ONLY</span>
                </div>
                <div class="notice" data-status-message role="status" aria-live="polite" hidden></div>
                <section class="tracking-panel" aria-label="Find a visit">
                    <form class="tracking-search" data-status-search>
                        <label for="visit-search">Patient number or visit ID</label>
                        <div>
                            <input id="visit-search" name="search" type="search" maxlength="80" required autocomplete="off" placeholder="e.g. PAT-0001 or 42">
                            <button class="button button-primary" type="submit" data-status-submit>Search visit</button>
                        </div>
                    </form>
                </section>
                <section class="tracking-panel visit-result" data-visit-result aria-live="polite" hidden>
                    <div class="visit-summary">
                        <div>
                            <p class="eyebrow">CURRENT VISIT</p>
                            <h2 data-visit-patient></h2>
                            <p>Patient <strong data-visit-number></strong> · Visit <strong data-visit-id></strong></p>
                            <p>Arrived <time data-visit-arrival></time></p>
                        </div>
                        <div class="current-status">
                            <span>Current status</span>
                            <strong data-visit-status></strong>
                        </div>
                    </div>
                    <h3>Recorded timeline</h3>
                    <ol class="visit-timeline" data-visit-timeline></ol>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
