<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/admin_page.php';

$context = requireAdministratorPage();
$admin = $context['user'];
$initialMatch = [];
preg_match('/^./us', (string) $admin['display_name'], $initialMatch);
$adminInitial = $initialMatch[0] ?? 'A';
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
    <title>Staff accounts | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="../assets/js/admin.js" defer></script>
</head>
<body class="dashboard-page admin-page" data-page="admin-users">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="dashboard.php">
                <span class="brand-mark" aria-hidden="true">+</span>
                <span>Care<span class="brand-light">Bridge</span></span>
            </a>
            <p class="sidebar-caption">ADMINISTRATION</p>
            <nav class="side-nav" aria-label="Administration navigation">
                <a class="side-link" href="dashboard.php"><span aria-hidden="true">⌂</span> Overview</a>
                <a class="side-link active" href="users.php"><span aria-hidden="true">♙</span> Staff accounts</a>
                <a class="side-link" href="../reception/index.php"><span aria-hidden="true">⌕</span> Reception desk</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help">
                    <span class="help-icon" aria-hidden="true">i</span>
                    <div><strong>Staff directory</strong><small>Create accounts and update their status.</small></div>
                </div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>

        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title">
                    <span class="mobile-brand-mark" aria-hidden="true">+</span>
                    <span>Administration / Staff accounts</span>
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

            <section class="dashboard-content admin-content" aria-labelledby="users-heading">
                <div class="admin-welcome">
                    <div>
                        <p class="eyebrow">ADMINISTRATION</p>
                        <h1 id="users-heading">Staff accounts</h1>
                        <p class="dashboard-subtitle">Create staff logins, search accounts and manage access status.</p>
                    </div>
                    <a class="button button-outline admin-action" href="dashboard.php">← Overview</a>
                </div>

                <div class="admin-notice" data-admin-message role="status" aria-live="polite" hidden></div>

                <section class="admin-panel create-staff-panel" id="new-staff" aria-labelledby="create-staff-heading">
                    <div class="admin-panel-heading">
                        <div>
                            <h2 id="create-staff-heading">Create staff account</h2>
                            <p>Passwords must be at least 12 characters. Administrator accounts are not created here.</p>
                        </div>
                    </div>
                    <form class="staff-form" data-create-user-form>
                        <div class="staff-form-field">
                            <label for="staff-name">Full name</label>
                            <input id="staff-name" name="display_name" type="text" maxlength="150" autocomplete="name" required>
                        </div>
                        <div class="staff-form-field">
                            <label for="staff-username">Username</label>
                            <input id="staff-username" name="username" type="text" minlength="3" maxlength="80" pattern="[A-Za-z0-9._\-]{3,80}" autocomplete="username" required>
                        </div>
                        <div class="staff-form-field">
                            <label for="staff-password">Temporary password</label>
                            <input id="staff-password" name="password" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                        </div>
                        <div class="staff-form-field">
                            <label for="staff-role">Role</label>
                            <select id="staff-role" name="role_id" required disabled>
                                <option value="">Loading roles…</option>
                            </select>
                        </div>
                        <div class="staff-form-submit">
                            <button class="button button-primary" type="submit" data-create-user-submit disabled>Create account</button>
                        </div>
                    </form>
                </section>

                <section class="admin-panel users-panel" aria-labelledby="directory-heading">
                    <div class="admin-panel-heading users-panel-heading">
                        <div>
                            <h2 id="directory-heading">Staff directory</h2>
                            <p>Search by name, username or role; activate or deactivate accounts.</p>
                        </div>
                        <label class="search-field">
                            <span class="visually-hidden">Search staff</span>
                            <span aria-hidden="true">⌕</span>
                            <input type="search" data-user-search placeholder="Search staff…" maxlength="100">
                        </label>
                    </div>
                    <div class="table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th scope="col">Staff member</th>
                                    <th scope="col">Username</th>
                                    <th scope="col">Role</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Created</th>
                                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody data-user-rows>
                                <tr><td class="table-message" colspan="6">Loading staff accounts…</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="table-footnote">Showing up to 200 matching accounts.</p>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
