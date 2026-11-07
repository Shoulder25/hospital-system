'use strict';

(() => {
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    let csrfToken = '';

    function apiUrl(route) {
        const url = new URL(apiEntry.href);
        url.searchParams.set('route', route);
        return url.href;
    }

    async function request(route, options = {}) {
        const headers = new Headers(options.headers || {});
        if (options.body && !headers.has('Content-Type')) {
            headers.set('Content-Type', 'application/json');
        }
        if (csrfToken && options.method && options.method !== 'GET') {
            headers.set('X-CSRF-Token', csrfToken);
        }

        const response = await fetch(apiUrl(route), {
            ...options,
            headers,
            credentials: 'same-origin',
            cache: 'no-store',
        });

        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an unreadable response. Please try again.');
        }

        return { response, payload };
    }

    function showMessage(element, message, type = 'error') {
        if (!element) return;
        element.textContent = message;
        element.classList.toggle('error', type === 'error');
        element.classList.toggle('success', type === 'success');
        element.hidden = false;
    }

    function hideMessage(element) {
        if (!element) return;
        element.hidden = true;
        element.textContent = '';
        element.classList.remove('error', 'success');
    }

    function errorMessage(payload, fallback) {
        if (payload && payload.errors && typeof payload.errors === 'object') {
            const first = Object.values(payload.errors).find((value) => typeof value === 'string');
            if (first) return first;
        }
        return payload && typeof payload.message === 'string' ? payload.message : fallback;
    }

    async function loadCsrfToken() {
        const { response, payload } = await request('/api/auth/csrf');
        if (!response.ok || !payload.success || typeof payload.data.csrf_token !== 'string') {
            throw new Error(errorMessage(payload, 'Could not start a secure session.'));
        }
        csrfToken = payload.data.csrf_token;
    }

    async function currentSession() {
        const { response, payload } = await request('/api/auth/me');
        if (response.ok && payload.success) {
            csrfToken = payload.data.csrf_token;
            return payload.data.user;
        }
        if (response.status === 401) return null;
        throw new Error(errorMessage(payload, 'Could not check your session.'));
    }

    function setBusy(button, busy, label) {
        if (!button) return;
        button.disabled = busy;
        const text = button.querySelector('span:first-child');
        if (text) text.textContent = busy ? label : button.dataset.originalLabel;
    }

    function setupPasswordToggles() {
        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.passwordToggle);
                if (!input) return;
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.textContent = show ? 'Hide' : 'Show';
                button.setAttribute('aria-pressed', show ? 'true' : 'false');
            });
        });
    }

    async function initLogin() {
        const form = document.getElementById('login-form');
        const message = document.getElementById('login-message');
        const submit = document.getElementById('login-submit');
        submit.dataset.originalLabel = 'Sign in';
        const query = new URLSearchParams(window.location.search);
        if (query.get('message') === 'session-expired') {
            showMessage(message, 'Your session has ended. Please sign in again.');
        }

        try {
            const user = await currentSession();
            if (user) {
                const destination = dashboardForRole(user.role.name);
                window.location.replace(`${projectBase}${destination}`);
                return;
            }
            await loadCsrfToken();
        } catch (error) {
            showMessage(message, error.message);
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(message);
            if (!form.reportValidity()) return;

            const data = new FormData(form);
            setBusy(submit, true, 'Signing in…');

            try {
                if (!csrfToken) await loadCsrfToken();
                const { response, payload } = await request('/api/auth/login', {
                    method: 'POST',
                    body: JSON.stringify({
                        username: data.get('username'),
                        password: data.get('password'),
                    }),
                });

                if (!response.ok || !payload.success) {
                    if (response.status === 403) await loadCsrfToken();
                    throw new Error(errorMessage(payload, 'Sign in failed. Please try again.'));
                }

                csrfToken = payload.data.csrf_token;
                const destination = dashboardForRole(payload.data.user.role.name);
                window.location.replace(`${projectBase}${destination}`);
            } catch (error) {
                showMessage(message, error.message);
                setBusy(submit, false);
            }
        });
    }

    async function initSignup() {
        const form = document.getElementById('signup-form');
        const message = document.getElementById('signup-message');
        const roleSelect = document.getElementById('signup-role');
        const submit = document.getElementById('signup-submit');
        submit.dataset.originalLabel = 'Create staff account';

        let user;
        try {
            user = await currentSession();
        } catch (error) {
            showMessage(message, error.message);
            return;
        }

        if (!user) {
            window.location.replace(`${projectBase}auth/login.php`);
            return;
        }
        if (user.role.name !== 'system_administrator') {
            showMessage(message, 'Administrator access is required to create staff accounts.');
            window.setTimeout(() => window.location.replace(`${projectBase}frontend/dashboard.php`), 1600);
            return;
        }

        try {
            const { response, payload } = await request('/api/auth/roles');
            if (!response.ok || !payload.success) {
                if (response.status === 401) {
                    window.location.replace(`${projectBase}auth/login.php`);
                    return;
                }
                if (response.status === 403) {
                    window.location.replace(`${projectBase}frontend/dashboard.php`);
                    return;
                }
                throw new Error(errorMessage(payload, 'Could not load staff roles.'));
            }
            roleSelect.replaceChildren(new Option('Choose a staff role', ''));
            payload.data.roles.forEach((role) => {
                roleSelect.add(new Option(role.name.replaceAll('_', ' '), role.name));
            });
            roleSelect.disabled = false;
            submit.disabled = false;
        } catch (error) {
            showMessage(message, error.message);
            return;
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(message);
            if (!form.reportValidity()) return;

            const data = new FormData(form);
            if (data.get('password') !== data.get('confirm_password')) {
                showMessage(message, 'The passwords do not match.');
                document.getElementById('signup-confirm-password').focus();
                return;
            }

            setBusy(submit, true, 'Creating account…');
            try {
                const { response, payload } = await request('/api/auth/register', {
                    method: 'POST',
                    body: JSON.stringify({
                        display_name: data.get('display_name'),
                        username: data.get('username'),
                        password: data.get('password'),
                        confirm_password: data.get('confirm_password'),
                        role: data.get('role'),
                    }),
                });

                if (!response.ok || !payload.success) {
                    if (response.status === 403) {
                        const activeUser = await currentSession();
                        if (!activeUser) {
                            window.location.replace(`${projectBase}auth/login.php`);
                            return;
                        }
                    }
                    throw new Error(errorMessage(payload, 'Could not create the account.'));
                }

                form.reset();
                showMessage(message, 'Staff account created successfully.', 'success');
            } catch (error) {
                showMessage(message, error.message);
            } finally {
                setBusy(submit, false);
            }
        });
    }

    function initials(name) {
        return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'U';
    }

    function dashboardForRole(role) {
        if (role === 'system_administrator') return 'frontend/admin/dashboard.php';
        if (role === 'receptionist') return 'frontend/reception/index.php';
        if (role === 'nurse') return 'frontend/nursing/index.php';
        if (role === 'general_doctor') return 'frontend/doctor/index.php';
        if (role === 'testing_desk_clerk') return 'frontend/testing-desk/index.php';
        if (role === 'laboratory_technician') return 'frontend/laboratory/index.php';
        if (role === 'radiographer') return 'frontend/radiology/index.php';
        if (role === 'cardiac_technician') return 'frontend/cardiac/index.php';
        return 'frontend/dashboard.php';
    }

    async function initDashboard() {
        const message = document.getElementById('dashboard-message');
        try {
            const user = await currentSession();
            if (!user) {
                window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
                return;
            }

            document.getElementById('welcome-name').textContent = user.display_name;
            document.getElementById('header-user-name').textContent = user.display_name;
            document.getElementById('header-user-role').textContent = user.role.name.replaceAll('_', ' ');
            document.getElementById('user-avatar').textContent = initials(user.display_name);
            document.querySelectorAll('.admin-only').forEach((link) => {
                link.hidden = user.role.name !== 'system_administrator';
            });
            document.querySelectorAll('.reception-only').forEach((link) => {
                link.hidden = !['receptionist', 'system_administrator'].includes(user.role.name);
            });
            document.querySelectorAll('.nursing-only').forEach((link) => {
                link.hidden = !['nurse', 'system_administrator'].includes(user.role.name);
            });
            document.querySelectorAll('.doctor-only').forEach((link) => {
                link.hidden = !['general_doctor', 'system_administrator'].includes(user.role.name);
            });
            document.querySelectorAll('[data-module-link]').forEach((link) => {
                const roles = (link.dataset.roles || '').split(',').filter(Boolean);
                link.hidden = !roles.includes(user.role.name);
            });
        } catch (error) {
            window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
            return;
        }

        document.getElementById('logout-button').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
                if (!csrfToken) {
                    const user = await currentSession();
                    if (!user) {
                        window.location.replace(`${projectBase}auth/login.php`);
                        return;
                    }
                }

                const { response, payload } = await request('/api/auth/logout', { method: 'POST' });
                if (!response.ok || !payload.success) {
                    throw new Error(errorMessage(payload, 'Could not sign out. Please try again.'));
                }
                window.location.replace(`${projectBase}auth/login.php`);
            } catch (error) {
                showMessage(message, error.message);
                button.disabled = false;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        setupPasswordToggles();
        const page = document.body.dataset.page;
        if (page === 'login') initLogin();
        if (page === 'signup') initSignup();
        if (page === 'dashboard') initDashboard();
    });
})();
