'use strict';

(() => {
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    let csrfToken = '';
    let searchTimer = null;
    let currentAdminUserId = null;

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
        currentAdminUserId = Number(payload.data.user.user_id);
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

    function formatDateTime(value) {
        const date = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return value;
        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }).format(date);
    }

    function renderRows(target, records, columns, emptyMessage) {
        if (!target) return;
        target.replaceChildren();
        if (records.length === 0) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = columns.length;
            cell.className = 'table-message';
            cell.textContent = emptyMessage;
            row.appendChild(cell);
            target.appendChild(row);
            return;
        }
        records.forEach((record) => {
            const row = document.createElement('tr');
            columns.forEach((column) => {
                const value = column(record);
                appendCell(row, value === null || value === undefined || value === '' ? '—' : String(value));
            });
            target.appendChild(row);
        });
    }

    async function loadAuditLog(query = {}) {
        const rows = document.querySelector('[data-audit-rows]');
        if (!rows) return;
        try {
            const { response, payload } = await request('/api/admin/audit-log', { query });
            if (response.status === 401) {
                window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
                return;
            }
            if (response.status === 403) {
                window.location.replace(`${projectBase}frontend/dashboard.php`);
                return;
            }
            if (!response.ok || !payload.success) {
                throw new Error(messageText(payload, 'Could not load the audit log.'));
            }
            renderRows(rows, payload.data.entries, [
                (entry) => formatDateTime(entry.created_at),
                (entry) => entry.user_name
                    ? `${entry.user_name} (${entry.username || 'inactive account'})`
                    : 'Unknown user',
                (entry) => entry.action,
                (entry) => entry.patient_number
                    ? `${entry.patient_number}${entry.patient_name ? ` — ${entry.patient_name}` : ''}`
                    : '—',
                (entry) => entry.visit_id,
            ], 'No audit entries match these filters.');
        } catch (error) {
            renderRows(rows, [], Array(5).fill(() => ''), error.message);
        }
    }

    function localDateInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    async function loadReports(query = {}) {
        const targets = {
            patients: document.querySelector('[data-report-patients]'),
            tests: document.querySelector('[data-report-tests]'),
            revenueDate: document.querySelector('[data-report-revenue-date]'),
            revenueMethod: document.querySelector('[data-report-revenue-method]'),
        };
        if (!targets.patients) return;
        try {
            const { response, payload } = await request('/api/admin/reports', { query });
            if (response.status === 401) {
                window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
                return;
            }
            if (response.status === 403) {
                window.location.replace(`${projectBase}frontend/dashboard.php`);
                return;
            }
            if (!response.ok || !payload.success) {
                throw new Error(messageText(payload, 'Could not load reports.'));
            }
            const data = payload.data;
            renderRows(targets.patients, data.patients_per_day, [
                (row) => row.report_date,
                (row) => row.patient_count,
            ], 'No patient arrivals in this range.');
            renderRows(targets.tests, data.tests_by_type, [
                (row) => row.test_type.replaceAll('_', ' '),
                (row) => row.order_count,
            ], 'No test orders in this range.');
            renderRows(targets.revenueDate, data.revenue_by_date, [
                (row) => row.report_date,
                (row) => row.revenue,
            ], 'No approved payments in this range.');
            renderRows(targets.revenueMethod, data.revenue_by_method, [
                (row) => row.payment_method.replaceAll('_', ' '),
                (row) => row.revenue,
            ], 'No approved payments in this range.');
            document.querySelector('[data-specialist-referral-count]').textContent =
                String(data.specialist_referrals);
            document.querySelector('[data-outside-referral-count]').textContent =
                String(data.outside_referrals);
        } catch (error) {
            Object.values(targets).forEach((target) => {
                renderRows(target, [], [() => '', () => ''], error.message);
            });
        }
    }

    function setupMonitoringFilters() {
        const auditForm = document.querySelector('[data-audit-filters]');
        if (auditForm) {
            auditForm.addEventListener('submit', (event) => {
                event.preventDefault();
                const values = new FormData(auditForm);
                const query = {};
                values.forEach((value, key) => {
                    if (value) query[key] = value;
                });
                loadAuditLog(query);
            });
            loadAuditLog();
        }

        const reportForm = document.querySelector('[data-report-filters]');
        if (reportForm) {
            const to = reportForm.elements.namedItem('to');
            const from = reportForm.elements.namedItem('from');
            const today = new Date();
            if (to && from) {
                to.value = localDateInput(today);
                const start = new Date(today);
                start.setDate(start.getDate() - 29);
                from.value = localDateInput(start);
            }
            reportForm.addEventListener('submit', (event) => {
                event.preventDefault();
                const values = new FormData(reportForm);
                loadReports({
                    from: values.get('from'),
                    to: values.get('to'),
                });
            });
            loadReports({
                from: from ? from.value : '',
                to: to ? to.value : '',
            });
        }
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
                const reset = document.createElement('button');
                reset.type = 'button';
                reset.className = 'status-toggle';
                reset.textContent = 'Reset password';
                reset.disabled = Number(user.user_id) === currentAdminUserId;
                reset.setAttribute('aria-label', `Reset password for ${user.display_name}`);
                reset.addEventListener('click', () => resetUserPassword(user, reset));
                actions.appendChild(reset);
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
        const nextActive = !user.is_active;
        const action = nextActive ? 'activate' : 'deactivate';
        if (!window.confirm(`Are you sure you want to ${action} ${user.display_name}'s account?`)) {
            return;
        }
        button.disabled = true;
        try {
            const { response, payload } = await request(
                `/api/admin/users/${user.user_id}/status`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({ is_active: nextActive }),
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

    async function resetUserPassword(user, button) {
        hideMessage();
        if (!window.confirm(`Generate a temporary password for ${user.display_name}?`)) {
            return;
        }
        button.disabled = true;
        try {
            const { response, payload } = await request(
                `/api/admin/users/${user.user_id}/password-reset`,
                { method: 'POST' }
            );
            if (response.status === 401) {
                window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
                return;
            }
            if (!response.ok || !payload.success) {
                throw new Error(messageText(payload, 'Could not reset the password.'));
            }
            showMessage(
                `${payload.message} Temporary password: ${payload.data.temporary_password}`,
                'success'
            );
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
                showMessage('Staff account created. The staff member must change the temporary password at first sign-in.', 'success');
                await loadUsers(document.querySelector('[data-user-search]')?.value.trim() || '');
            } catch (error) {
                showMessage(error.message);
            } finally {
                submit.disabled = false;
            }
        });
    }

    function renderFees(fees) {
        const body = document.querySelector('[data-fee-rows]');
        body.replaceChildren();
        if (!fees.length) {
            const row = document.createElement('tr');
            const cell = appendCell(row, 'No configured services matched this search.', 'table-message');
            cell.colSpan = 8;
            body.appendChild(row);
            return;
        }
        fees.forEach((fee) => {
            const row = document.createElement('tr');
            const code = appendCell(row, fee.fee_code, 'fee-code-cell');
            if (fee.is_demo) code.append(document.createTextNode(' · DEMO'));
            appendCell(row, fee.description, 'fee-service-name');
            appendCell(row, new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: fee.currency,
                currencyDisplay: 'narrowSymbol',
            }).format(Number(fee.amount)));
            const status = appendCell(row, '');
            const statusBadge = document.createElement('span');
            statusBadge.className = `status-badge${fee.is_active ? '' : ' inactive'}`;
            statusBadge.textContent = fee.is_active ? 'Active' : 'Inactive';
            status.appendChild(statusBadge);
            appendCell(row, fee.cashier_addable ? 'Enabled' : 'No');
            appendCell(row, formatDateTime(fee.updated_at), 'fee-updated-cell');
            appendCell(row, fee.updated_by || 'Not recorded');
            const action = appendCell(row, '');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'status-toggle fee-edit-button';
            button.textContent = 'Edit';
            button.addEventListener('click', () => editFee(fee));
            action.appendChild(button);
            body.appendChild(row);
        });
    }

    async function loadFees(search = '') {
        const { response, payload } = await request('/api/admin/fees', {
            query: { search },
        });
        if (!response.ok || !payload.success) {
            throw new Error(messageText(payload, 'Could not load configured fees.'));
        }
        const fees = payload.data.fees;
        document.querySelector('[data-fee-currency]').textContent =
            fees.length ? fees[0].currency : 'No fee currency configured';
        renderFees(fees);
    }

    function resetFeeForm() {
        const form = document.querySelector('[data-fee-form]');
        form.reset();
        form.elements.fee_id.value = '';
        form.elements.fee_code.disabled = false;
        form.elements.is_active.checked = true;
        document.querySelector('[data-fee-editor-heading]').textContent = 'Add a service fee';
        document.querySelector('[data-fee-submit]').textContent = 'Add service';
        document.querySelector('[data-fee-cancel]').hidden = true;
    }

    function editFee(fee) {
        const form = document.querySelector('[data-fee-form]');
        form.elements.fee_id.value = String(fee.fee_id);
        form.elements.fee_code.value = fee.fee_code;
        form.elements.fee_code.disabled = true;
        form.elements.description.value = fee.description;
        form.elements.amount.value = fee.amount;
        form.elements.is_active.checked = fee.is_active;
        form.elements.cashier_addable.checked = fee.cashier_addable;
        document.querySelector('[data-fee-editor-heading]').textContent =
            `Edit ${fee.fee_code}`;
        document.querySelector('[data-fee-submit]').textContent = 'Save changes';
        document.querySelector('[data-fee-cancel]').hidden = false;
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        form.elements.description.focus();
    }

    function setupFees() {
        const form = document.querySelector('[data-fee-form]');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const formData = new FormData(form);
            const feeId = String(formData.get('fee_id') || '');
            const payload = {
                fee_code: String(formData.get('fee_code') || '').trim(),
                description: String(formData.get('description') || '').trim(),
                amount: String(formData.get('amount') || ''),
                is_active: form.elements.is_active.checked,
                cashier_addable: form.elements.cashier_addable.checked,
            };
            const submit = form.querySelector('[data-fee-submit]');
            submit.disabled = true;
            try {
                const { response, payload: result } = await request(
                    feeId ? `/api/admin/fees/${encodeURIComponent(feeId)}` : '/api/admin/fees',
                    {
                        method: feeId ? 'PATCH' : 'POST',
                        body: JSON.stringify(payload),
                    }
                );
                if (!response.ok || !result.success) {
                    throw new Error(messageText(result, 'Could not save this fee.'));
                }
                resetFeeForm();
                showMessage(feeId ? 'Fee configuration updated.' : 'Fee configuration created.', 'success');
                await loadFees(document.querySelector('[data-fee-search]').value.trim());
            } catch (error) {
                showMessage(error.message);
            } finally {
                submit.disabled = false;
            }
        });
        document.querySelector('[data-fee-cancel]').addEventListener('click', resetFeeForm);
        document.querySelector('[data-fee-search]').addEventListener('input', (event) => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => {
                loadFees(event.currentTarget.value.trim()).catch((error) => showMessage(error.message));
            }, 180);
        });
        loadFees().catch((error) => showMessage(error.message));
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
            if (document.body.dataset.page === 'admin-dashboard') {
                setupMonitoringFilters();
            }
            if (document.body.dataset.page === 'admin-users') {
                await loadRoles();
                setupSearch();
                setupCreateUserForm();
                await loadUsers();
            }
            if (document.body.dataset.page === 'admin-fees') {
                setupFees();
            }
        } catch (error) {
            showMessage(error.message);
        }
    });
})();
