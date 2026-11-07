<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#eff7f7">
    <title>Sign in | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../frontend/assets/css/auth.css">
    <script src="../frontend/assets/js/auth.js" defer></script>
</head>
<body data-page="login">
    <main class="auth-shell">
        <section class="auth-brand" aria-label="Hospital Patient Processing">
            <a class="brand-lockup" href="login.php" aria-label="Hospital Patient Processing home">
                <span class="brand-mark" aria-hidden="true">+</span>
                <span>Care<span class="brand-light">Bridge</span></span>
            </a>
            <div class="brand-copy">
                <p class="eyebrow">HOSPITAL PATIENT PROCESSING</p>
                <h1>Better care starts with a connected team.</h1>
                <p>One secure workspace for the people who make every patient visit count.</p>
            </div>
            <div class="brand-note">
                <span class="status-dot" aria-hidden="true"></span>
                <span>Staff access · Academic prototype</span>
            </div>
            <div class="brand-decoration" aria-hidden="true">
                <span></span><span></span><span></span><span></span><span></span>
                <b>+</b>
            </div>
        </section>

        <section class="auth-panel">
            <div class="auth-card">
                <div class="mobile-brand brand-lockup" aria-hidden="true">
                    <span class="brand-mark">+</span>
                    <span>Care<span class="brand-light">Bridge</span></span>
                </div>
                <p class="eyebrow">WELCOME BACK</p>
                <h2>Sign in to your account</h2>
                <p class="form-intro">Use the staff account provided by your administrator.</p>

                <div class="notice" id="login-message" role="status" aria-live="polite" hidden></div>

                <form id="login-form" novalidate>
                    <label class="field-label" for="login-username">Username</label>
                    <input
                        id="login-username"
                        name="username"
                        type="text"
                        autocomplete="username"
                        maxlength="80"
                        required
                        autofocus
                        placeholder="Enter your username">

                    <div class="label-row">
                        <label class="field-label" for="login-password">Password</label>
                    </div>
                    <div class="password-wrap">
                        <input
                            id="login-password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            required
                            placeholder="Enter your password">
                        <button class="password-toggle" type="button" data-password-toggle="login-password" aria-pressed="false">
                            Show
                        </button>
                    </div>

                    <button class="button button-primary button-full" id="login-submit" type="submit">
                        <span>Sign in</span>
                        <span class="button-arrow" aria-hidden="true">→</span>
                    </button>
                </form>

                <p class="auth-footnote">Need an account? Contact your system administrator.</p>
                <p class="security-note"><span aria-hidden="true">▣</span> Your staff session is protected.</p>
            </div>
            <footer class="panel-footer">CareBridge Hospital System <span>·</span> Academic prototype</footer>
        </section>
    </main>
</body>
</html>
