<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Dashboard | Hospital Patient Processing</title>
    <link rel="stylesheet" href="assets/css/auth.css">
    <script src="assets/js/auth.js" defer></script>
</head>
<body class="dashboard-page" data-page="dashboard">
    <div class="dashboard-layout">
        <aside class="sidebar">
            <a class="brand-lockup sidebar-brand" href="dashboard.php">
                <span class="brand-mark" aria-hidden="true">+</span>
                <span>Care<span class="brand-light">Bridge</span></span>
            </a>
            <p class="sidebar-caption">WORKSPACE</p>
            <nav class="side-nav" aria-label="Main navigation">
                <a class="side-link active" href="dashboard.php"><span aria-hidden="true">⌂</span> Overview</a>
                <a class="side-link" href="#activity"><span aria-hidden="true">◷</span> Recent activity</a>
                <a class="side-link reception-only" href="reception/index.php" hidden><span aria-hidden="true">⌕</span> Reception desk</a>
                <a class="side-link nursing-only" href="nursing/index.php" hidden><span aria-hidden="true">＋</span> Nursing</a>
                <a class="side-link doctor-only" href="doctor/index.php" hidden><span aria-hidden="true">＋</span> General doctor</a>
                <a class="side-link" href="testing-desk/index.php" hidden data-module-link data-roles="testing_desk_clerk,system_administrator"><span aria-hidden="true">◷</span> Testing centre desk</a>
                <a class="side-link" href="laboratory/index.php" hidden data-module-link data-roles="laboratory_technician,system_administrator"><span aria-hidden="true">＋</span> Laboratory</a>
                <a class="side-link" href="radiology/index.php" hidden data-module-link data-roles="radiographer,system_administrator"><span aria-hidden="true">＋</span> Radiology</a>
                <a class="side-link" href="cardiac/index.php" hidden data-module-link data-roles="cardiac_technician,system_administrator"><span aria-hidden="true">＋</span> Cardiac</a>
                <a class="side-link admin-only" href="../auth/signup.php" hidden><span aria-hidden="true">＋</span> Staff accounts</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help">
                    <span class="help-icon" aria-hidden="true">i</span>
                    <div><strong>Need access?</strong><small>Contact your system administrator.</small></div>
                </div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>

        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title">
                    <span class="mobile-brand-mark" aria-hidden="true">+</span>
                    <span>Staff workspace</span>
                </div>
                <div class="topbar-actions">
                    <span class="user-avatar" id="user-avatar" aria-hidden="true">—</span>
                    <div class="user-summary">
                        <strong id="header-user-name">Loading…</strong>
                        <span id="header-user-role">Checking session</span>
                    </div>
                    <button class="button button-outline button-logout" id="logout-button" type="button">Sign out</button>
                </div>
            </header>

            <section class="dashboard-content" aria-labelledby="welcome-heading">
                <div class="welcome-row">
                    <div>
                        <p class="eyebrow">HOSPITAL OPERATIONS</p>
                        <h1 id="welcome-heading">Welcome, <span id="welcome-name">there</span></h1>
                        <p class="dashboard-subtitle">Your workspace is ready. Department tools will appear here as they are added.</p>
                    </div>
                    <div class="date-pill"><span class="date-dot" aria-hidden="true"></span> Staff portal</div>
                </div>

                <div class="notice" id="dashboard-message" role="status" aria-live="polite" hidden></div>

                <div class="section-heading">
                    <div>
                        <h2>Hospital at a glance</h2>
                        <p>Statistics will be connected to live hospital data in a later milestone.</p>
                    </div>
                    <span class="demo-badge">PLACEHOLDERS</span>
                </div>

                <div class="stat-grid" aria-label="Placeholder hospital statistics">
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-teal" aria-hidden="true">＋</div>
                        <p>Patient visits today</p>
                        <strong>—</strong>
                        <span>Not connected yet</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-blue" aria-hidden="true">⌕</div>
                        <p>Awaiting assessment</p>
                        <strong>—</strong>
                        <span>Not connected yet</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-amber" aria-hidden="true">◷</div>
                        <p>Tests in progress</p>
                        <strong>—</strong>
                        <span>Not connected yet</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-violet" aria-hidden="true">✓</div>
                        <p>Visits completed</p>
                        <strong>—</strong>
                        <span>Not connected yet</span>
                    </article>
                </div>

                <section class="activity-card" id="activity" aria-labelledby="activity-heading">
                    <div class="activity-heading">
                        <div>
                            <h2 id="activity-heading">Recent activity</h2>
                            <p>Updates from your hospital workspace</p>
                        </div>
                        <span class="activity-live"><i aria-hidden="true"></i> Workspace</span>
                    </div>
                    <div class="empty-activity">
                        <span class="empty-icon" aria-hidden="true">◷</span>
                        <strong>No activity to show yet</strong>
                        <p>Activity will appear here when hospital workflows are connected.</p>
                    </div>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
