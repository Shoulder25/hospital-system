<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#eff7f7">
    <title>Change password | Hospital Patient Processing</title>
    <link rel="stylesheet" href="assets/css/auth.css">
    <script src="assets/js/auth.js" defer></script>
</head>
<body data-page="password-change">
    <main class="auth-shell">
        <section class="auth-panel">
            <div class="auth-card">
                <p class="eyebrow">ACCOUNT SECURITY</p>
                <h2>Choose a new password</h2>
                <p class="form-intro">Your temporary password can no longer be used after you set a new one.</p>
                <div class="notice" id="password-change-message" role="status" aria-live="polite" hidden></div>
                <form id="password-change-form" novalidate>
                    <label class="field-label" for="new-password">New password</label>
                    <input id="new-password" name="password" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                    <label class="field-label" for="confirm-new-password">Confirm new password</label>
                    <input id="confirm-new-password" name="confirm_password" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required>
                    <button class="button button-primary button-full" id="password-change-submit" type="submit">
                        <span>Change password</span>
                        <span class="button-arrow" aria-hidden="true">→</span>
                    </button>
                </form>
                <p class="security-note">Use at least 12 characters. Avoid sharing your new password.</p>
            </div>
        </section>
    </main>
</body>
</html>
