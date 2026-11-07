'use strict';

(() => {
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    let csrfToken = '';
    let searchTimer = null;

    async function request(route, options = {}) {
        const headers = new Headers(options.headers || {});
        const url = new URL(apiEntry.href);
        url.searchParams.set('route', route);
        if (options.query) {
            Object.entries(options.query).forEach(([key, value]) => {
                url.searchParams.set(key, value);
            });
        }
        if (options.body && !headers.has('Content-Type')) {
            headers.set('Content-Type', 'application/json');
        }
        if (csrfToken && options.method && options.method !== 'GET') {
            headers.set('X-CSRF-Token', csrfToken);
        }
        const { query, ...fetchOptions } = options;
        const response = await fetch(url.href, {
            ...fetchOptions,
            headers,
            credentials: 'same-origin',
            cache: 'no-store',
        });
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an unreadable response.');
        }
        return { response, payload };
    }

    function messageText(payload, fallback) {
        if (payload && payload.errors && typeof payload.errors === 'object') {
            const first = Object.values(payload.errors).find((value) => typeof value === 'string');
            if (first) return first;
        }
        return payload && typeof payload.message === 'string' ? payload.message : fallback;
    }

    function showMessage(message, type = 'error') {
        const element = document.querySelector('[data-admin-message]');
        if (!element) return;
        element.textContent = message;
        element.classList.toggle('error', type === 'error');
        element.classList.toggle('success', type === 'success');
        element.hidden = false;
    }

    function hideMessage() {
        const element = document.querySelector('[data-admin-message]');
        if (!element) return;
        element.hidden = true;
        element.textContent = '';
        element.classList.remove('error', 'success');
    }

    async function requireAdminSession() {
        const { response, payload } = await request('/api/auth/me');
        if (response.status === 401) {
            window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
            return false;
        }
        if (!response.ok || !payload.success) {
            throw new Error(messageText(payload, 'Could not verify administrator access.'));
        }
        csrfToken = payload.data.csrf_token;
        if (payload.data.user.role.name !== 'system_administrator') {
            window.location.replace(`${projectBase}frontend/dashboard.php`);
            return false;
        }
        return true;
    }

    function appendCell(row, value, className = '') {
        const cell = document.createElement('td');
        if (className) cell.className = className;
        cell.textContent = value;
        row.appendChild(cell);
        return cell;
    }

    function formatDate(value) {
        const date = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return value;
        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        }).format(date);
    }

    async function loadUsers(search = '') {
        const rows = document.querySelector('[data-user-rows]');
        if (!rows) return;
        rows.replaceChildren();

        const loadingRow = document.createElement('tr');
        const loadingCell = document.createElement('td');
        loadingCell.colSpan = 6;
        loadingCell.className = 'table-message';
        loadingCell.textContent = 'Loading staff accounts…';
        loadingRow.appendChild(loadingCell);
        rows.appendChild(loadingRow);

        try {
            const { response, payload } = await request('/api/admin/users', {
                query: search ? { search } : {},
            });
            if (response.status === 401) {
                window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
                return;
            }
            if (response.status === 403) {
                window.location.replace(`${projectBase}frontend/dashboard.php`);
                return;
            }
            if (!response.ok || !payload.success) {
                throw new Error(messageText(payload, 'Could not load staff accounts.'));
            }

            rows.replaceChildren();
            if (payload.data.users.length === 0) {
                const row = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = 6;
                cell.className = 'table-message';
                cell.textContent = 'No matching staff accounts.';
                row.appendChild(cell);
                rows.appendChild(row);
                return;
            }

            payload.data.users.forEach((user) => {
                const row = document.createElement('tr');
                const nameCell = appendCell(row, user.display_name);
                const name = document.createElement('strong');
                name.textContent = user.display_name;
                nameCell.replaceChildren(name);
                appendCell(row, user.username);

                const roleCell = appendCell(row, '');
                const role = document.createElement('span');
                role.className = 'role-badge';
                role.textContent = user.role.role_name.replaceAll('_', ' ');
                roleCell.appendChild(role);

                const statusCell = appendCell(row, '');
                const status = document.createElement('span');
                status.className = `status-badge${user.is_active ? '' : ' inactive'}`;
                status.textContent = user.is_active ? 'Active' : 'Inactive';
                statusCell.appendChild(status);

                appendCell(row, formatDate(user.created_at));
                const actions = appendCell(row, '');
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = `status-toggle${user.is_active ? '' : ' activate'}`;
                toggle.textContent = user.is_active ? 'Deactivate' : 'Activate';
                toggle.setAttribute('aria-label', `${toggle.textContent} ${user.display_name}`);
                toggle.addEventListener('click', () => updateUserStatus(user, toggle));
                actions.appendChild(toggle);
                rows.appendChild(row);
            });
        } catch (error) {
            rows.replaceChildren();
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 6;
            cell.className = 'table-message';
            cell.textContent = error.message;
            row.appendChild(cell);
            rows.appendChild(row);
        }
    }

    async function loadRoles() {
        const select = document.querySelector('#staff-role');
        const submit = document.querySelector('[data-create-user-submit]');
        if (!select || !submit) return;

        const { response, payload } = await request('/api/admin/roles');
        if (response.status === 401) {
            window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
            return;
        }
        if (response.status === 403) {
            window.location.replace(`${projectBase}frontend/dashboard.php`);
            return;
        }
        if (!response.ok || !payload.success) {
            throw new Error(messageText(payload, 'Could not load account roles.'));
        }

        select.replaceChildren(new Option('Choose a role', ''));
        payload.data.roles.forEach((role) => {
            const option = new Option(role.role_name.replaceAll('_', ' '), role.role_id);
            select.add(option);
        });
        select.disabled = false;
        submit.disabled = false;
    }

    async function updateUserStatus(user, button) {
        hideMessage();
        button.disabled = true;
        try {
            const { response, payload } = await request(
                `/api/admin/users/${user.user_id}/status`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({ is_active: !user.is_active }),
                }
            );
            if (response.status === 401) {
                window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
                return;
            }
            if (!response.ok || !payload.success) {
                throw new Error(messageText(payload, 'Could not update account status.'));
            }
            showMessage(payload.message, 'success');
            await loadUsers(document.querySelector('[data-user-search]')?.value.trim() || '');
        } catch (error) {
            showMessage(error.message);
            button.disabled = false;
        }
    }

    function setupSearch() {
        const input = document.querySelector('[data-user-search]');
        if (!input) return;
        input.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => loadUsers(input.value.trim()), 250);
        });
    }

    function setupCreateUserForm() {
        const form = document.querySelector('[data-create-user-form]');
        const submit = document.querySelector('[data-create-user-submit]');
        if (!form || !submit) return;

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage();
            if (!form.reportValidity()) return;

            submit.disabled = true;
            const formData = new FormData(form);
            try {
                const { response, payload } = await request('/api/admin/users', {
                    method: 'POST',
                    body: JSON.stringify({
                        display_name: formData.get('display_name'),
                        username: formData.get('username'),
                        password: formData.get('password'),
                        role_id: Number(formData.get('role_id')),
                    }),
                });
                if (response.status === 401) {
                    window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
                    return;
                }
                if (!response.ok || !payload.success) {
                    throw new Error(messageText(payload, 'Could not create the account.'));
                }
                form.reset();
                showMessage('Staff account created successfully.', 'success');
                await loadUsers(document.querySelector('[data-user-search]')?.value.trim() || '');
            } catch (error) {
                showMessage(error.message);
            } finally {
                submit.disabled = false;
            }
        });
    }

    function setupLogout() {
        document.querySelectorAll('[data-admin-logout]').forEach((button) => {
            button.addEventListener('click', async () => {
                button.disabled = true;
                try {
                    const { response, payload } = await request('/api/auth/logout', {
                        method: 'POST',
                    });
                    if (!response.ok || !payload.success) {
                        throw new Error(messageText(payload, 'Could not sign out.'));
                    }
                    window.location.replace(`${projectBase}auth/login.php`);
                } catch (error) {
                    showMessage(error.message);
                    button.disabled = false;
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', async () => {
        setupLogout();
        try {
            const allowed = await requireAdminSession();
            if (!allowed) return;
            if (document.body.dataset.page === 'admin-users') {
                await loadRoles();
                setupSearch();
                setupCreateUserForm();
                await loadUsers();
            }
        } catch (error) {
            showMessage(error.message);
        }
    });
})();
