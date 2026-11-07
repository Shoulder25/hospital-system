<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/admin_page.php';

$context = requireAdministratorPage();
$database = $context['database'];
$admin = $context['user'];
$initialMatch = [];
preg_match('/^./us', (string) $admin['display_name'], $initialMatch);
$adminInitial = $initialMatch[0] ?? 'A';

try {
    $totalStaff = (int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $activeStaff = (int) $database->query(
        'SELECT COUNT(*) FROM users WHERE is_active = 1'
    )->fetchColumn();
    $inactiveStaff = $totalStaff - $activeStaff;
    $recentStatement = $database->query(
        'SELECT u.display_name, u.username, u.created_at, r.role_name
         FROM users u
         INNER JOIN roles r ON r.role_id = u.role_id
         ORDER BY u.created_at DESC, u.user_id DESC
         LIMIT 6'
    );
    $recentUsers = $recentStatement->fetchAll();
} catch (PDOException $exception) {
    error_log('Unable to load administrator dashboard: ' . $exception->getMessage());
    http_response_code(503);
    exit('The administration dashboard is temporarily unavailable.');
}

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Administration dashboard | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="../assets/js/admin.js" defer></script>
</head>
<body class="dashboard-page admin-page" data-page="admin-dashboard">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="dashboard.php">
                <span class="brand-mark" aria-hidden="true">+</span>
                <span>Care<span class="brand-light">Bridge</span></span>
            </a>
            <p class="sidebar-caption">ADMINISTRATION</p>
            <nav class="side-nav" aria-label="Administration navigation">
                <a class="side-link active" href="dashboard.php"><span aria-hidden="true">⌂</span> Overview</a>
                <a class="side-link" href="users.php"><span aria-hidden="true">♙</span> Staff accounts</a>
                <a class="side-link" href="../reception/index.php"><span aria-hidden="true">⌕</span> Reception desk</a>
                <a class="side-link" href="../nursing/index.php"><span aria-hidden="true">＋</span> Nursing</a>
                <a class="side-link" href="../doctor/index.php"><span aria-hidden="true">＋</span> General doctor</a>
                <a class="side-link" href="../testing-desk/index.php"><span aria-hidden="true">◷</span> Testing centre desk</a>
                <a class="side-link" href="../laboratory/index.php"><span aria-hidden="true">＋</span> Laboratory</a>
                <a class="side-link" href="../radiology/index.php"><span aria-hidden="true">＋</span> Radiology</a>
                <a class="side-link" href="../cardiac/index.php"><span aria-hidden="true">＋</span> Cardiac</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help">
                    <span class="help-icon" aria-hidden="true">i</span>
                    <div><strong>Administration</strong><small>Manage staff access and account status.</small></div>
                </div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>

        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title">
                    <span class="mobile-brand-mark" aria-hidden="true">+</span>
                    <span>Administration / Overview</span>
                </div>
                <div class="topbar-actions">
                    <span class="user-avatar"><?= $escape($adminInitial) ?></span>
                    <div class="user-summary">
                        <strong><?= $escape($admin['display_name']) ?></strong>
                        <span>System administrator</span>
                    </div>
                    <button class="button button-outline button-logout" type="button" data-admin-logout>Sign out</button>
                </div>
            </header>

            <section class="dashboard-content admin-content" aria-labelledby="welcome-heading">
                <div class="admin-welcome">
                    <div>
                        <p class="eyebrow">STAFF & ACCESS</p>
                        <h1 id="welcome-heading">Welcome, <?= $escape($admin['display_name']) ?></h1>
                        <p class="dashboard-subtitle">Manage staff accounts and review access at a glance.</p>
                    </div>
                    <a class="button button-primary admin-action" href="users.php#new-staff">＋ Create staff account</a>
                </div>

                <div class="admin-notice" data-admin-message role="status" aria-live="polite" hidden></div>

                <div class="section-heading">
                    <div>
                        <h2>Staff overview</h2>
                        <p>Account totals from the hospital user directory.</p>
                    </div>
                    <span class="demo-badge">ADMINISTRATION</span>
                </div>

                <div class="stat-grid admin-stat-grid">
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-teal" aria-hidden="true">♙</div>
                        <p>Total staff accounts</p>
                        <strong><?= $totalStaff ?></strong>
                        <span>Accounts in the system</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-blue" aria-hidden="true">✓</div>
                        <p>Active accounts</p>
                        <strong><?= $activeStaff ?></strong>
                        <span>Currently enabled</span>
                    </article>
                    <article class="stat-card">
                        <div class="stat-icon stat-icon-amber" aria-hidden="true">Ⅱ</div>
                        <p>Inactive accounts</p>
                        <strong><?= $inactiveStaff ?></strong>
                        <span>Currently disabled</span>
                    </article>
                </div>

                <section class="admin-panel recent-staff-panel" aria-labelledby="recent-staff-heading">
                    <div class="admin-panel-heading">
                        <div>
                            <h2 id="recent-staff-heading">Recent staff accounts</h2>
                            <p>Latest accounts added to the system.</p>
                        </div>
                        <a class="text-link" href="users.php">Manage staff <span aria-hidden="true">→</span></a>
                    </div>
                    <?php if ($recentUsers === []): ?>
                        <div class="admin-empty">No staff accounts have been created yet.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="admin-table recent-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Staff member</th>
                                        <th scope="col">Username</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentUsers as $staff): ?>
                                        <tr>
                                            <td><strong><?= $escape($staff['display_name']) ?></strong></td>
                                            <td><?= $escape($staff['username']) ?></td>
                                            <td><span class="role-badge"><?= $escape(str_replace('_', ' ', $staff['role_name'])) ?></span></td>
                                            <td><?= $escape(date('M j, Y', strtotime($staff['created_at']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <p class="admin-footnote">Administration tools are for this academic prototype and its fictional demo data.</p>
            </section>
        </main>
    </div>
</body>
</html>
