'use strict';

(() => {
    const projectBase = document.body.dataset.basePath || '';
    const apiEntry = new URL(`${projectBase}/backend/index.php`, window.location.origin);
    const state = { csrfToken: '' };
    const messageElement = document.querySelector('[data-billing-message]');

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
        if (state.csrfToken && options.method && options.method !== 'GET') {
            headers.set('X-CSRF-Token', state.csrfToken);
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

    function errorMessage(payload, fallback) {
        if (payload && payload.errors && typeof payload.errors === 'object') {
            const first = Object.values(payload.errors).find((value) => typeof value === 'string');
            if (first) return first;
        }
        return payload && typeof payload.message === 'string' ? payload.message : fallback;
    }

    function showMessage(message, type = 'error') {
        messageElement.textContent = message;
        messageElement.classList.toggle('error', type === 'error');
        messageElement.classList.toggle('success', type === 'success');
        messageElement.hidden = false;
    }

    function login() {
        window.location.replace(`${projectBase}/auth/login.php?message=session-expired`);
    }

    async function parseRequest(route, options = {}) {
        const { response, payload } = await request(route, options);
        if (response.status === 401) {
            login();
            throw new Error('Your session has expired. Sign in again.');
        }
        if (!response.ok || !payload.success) {
            const error = new Error(errorMessage(payload, 'The request could not be completed.'));
            error.status = response.status;
            throw error;
        }
        return payload.data;
    }

    async function verifySession() {
        const data = await parseRequest('/api/auth/me');
        const role = data.user.role.name;
        if (!['cashier', 'system_administrator'].includes(role)) {
            window.location.replace(`${projectBase}/frontend/dashboard.php`);
            return false;
        }
        state.csrfToken = data.csrf_token;
        return true;
    }

    function pretty(value) {
        return value ? String(value).replaceAll('_', ' ') : '—';
    }

    function dateTime(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }).format(date);
    }

    function appendCell(row, value, className = '') {
        const cell = document.createElement('td');
        if (className) cell.className = className;
        cell.textContent = value === null || value === '' ? '—' : String(value);
        row.appendChild(cell);
        return cell;
    }

    function emptyQueue(message) {
        const body = document.querySelector('[data-billing-rows]');
        body.replaceChildren();
        const row = document.createElement('tr');
        appendCell(row, message, 'table-message').colSpan = 7;
        body.appendChild(row);
    }

    async function loadQueue(search = '') {
        emptyQueue('Loading ticket-payment queue…');
        try {
            const data = await parseRequest('/api/billing/queue', {
                query: search ? { search } : {},
            });
            const body = document.querySelector('[data-billing-rows]');
            body.replaceChildren();
            if (data.visits.length === 0) {
                emptyQueue('No eligible visits are awaiting ticket payment.');
                return;
            }
            data.visits.forEach((visit) => {
                const row = document.createElement('tr');
                const patientCell = appendCell(row, '');
                const patientName = document.createElement('strong');
                patientName.textContent = visit.patient_name || 'Temporary patient';
                const patientNumber = document.createElement('small');
                patientNumber.textContent = visit.patient_number || 'No patient number';
                patientCell.append(patientName, patientNumber);
                appendCell(row, `#${visit.visit_id}`);
                appendCell(row, pretty(visit.visit_source));
                appendCell(row, visit.source_organization || '—');
                appendCell(row, dateTime(visit.arrived_at));
                appendCell(row, visit.ticket_amount);

                const paymentCell = document.createElement('td');
                const form = document.createElement('form');
                form.className = 'billing-payment-form';
                const method = document.createElement('select');
                method.setAttribute('aria-label', `Payment method for visit ${visit.visit_id}`);
                [
                    ['cash', 'Cash'],
                    ['card', 'Card (simulated)'],
                    ['medical_aid', 'Medical aid (simulated)'],
                ].forEach(([value, label]) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = label;
                    method.appendChild(option);
                });
                const submit = document.createElement('button');
                submit.type = 'submit';
                submit.className = 'button button-primary button-small';
                submit.textContent = 'Take payment';
                form.append(method, submit);
                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    recordPayment(visit, method.value, submit);
                });
                paymentCell.appendChild(form);
                row.appendChild(paymentCell);
                body.appendChild(row);
            });
        } catch (error) {
            emptyQueue('Unable to load the ticket-payment queue.');
            showMessage(error.message);
        }
    }

    async function recordPayment(visit, paymentMethod, button) {
        if (!window.confirm(`Record the ticket fee for visit #${visit.visit_id}?`)) return;
        button.disabled = true;
        button.textContent = 'Recording…';
        try {
            const data = await parseRequest(
                `/api/billing/visits/${encodeURIComponent(visit.visit_id)}/ticket-payment`,
                {
                    method: 'POST',
                    body: JSON.stringify({ payment_method: paymentMethod }),
                }
            );
            messageElement.replaceChildren();
            messageElement.classList.remove('error');
            messageElement.classList.add('success');
            messageElement.hidden = false;
            messageElement.append(
                document.createTextNode(`Payment recorded. Visit #${data.visit_id} is awaiting nursing. `)
            );
            const receipt = document.createElement('a');
            receipt.href = `receipt.php?payment_id=${encodeURIComponent(data.payment_id)}`;
            receipt.textContent = `Print receipt ${data.receipt_number}`;
            messageElement.appendChild(receipt);
            await loadQueue(document.querySelector('#billing-search').value.trim());
        } catch (error) {
            showMessage(error.message);
            button.disabled = false;
            button.textContent = 'Take payment';
        }
    }

    function setReceiptValue(selector, value) {
        const element = document.querySelector(selector);
        if (element) element.textContent = value === null || value === '' ? '—' : String(value);
    }

    async function loadReceipt() {
        const paymentId = new URLSearchParams(window.location.search).get('payment_id');
        if (!paymentId) {
            showMessage('A payment ID is required to view a receipt.');
            return;
        }
        try {
            const data = await parseRequest(
                `/api/billing/payments/${encodeURIComponent(paymentId)}/receipt`
            );
            const { payment, bill, visit, patient, items } = data;
            setReceiptValue('[data-receipt-number]', payment.receipt_number);
            setReceiptValue('[data-bill-number]', bill.bill_number);
            setReceiptValue('[data-patient]', patient.name || 'Temporary patient');
            setReceiptValue('[data-patient-number]', patient.patient_number);
            setReceiptValue('[data-visit]', visit.visit_id);
            setReceiptValue('[data-source]', pretty(visit.visit_source));
            setReceiptValue('[data-paid-at]', dateTime(payment.paid_at));
            setReceiptValue(
                '[data-method]',
                `${pretty(payment.payment_method)}${payment.is_simulated ? ' (simulated)' : ''}`
            );
            setReceiptValue('[data-cashier]', payment.cashier_name);
            setReceiptValue('[data-paid-amount]', payment.amount);
            document.querySelector('[data-simulated-badge]').hidden = !payment.is_simulated;

            const body = document.querySelector('[data-receipt-items]');
            body.replaceChildren();
            items.forEach((item) => {
                const row = document.createElement('tr');
                appendCell(row, item.description || pretty(item.charge_type));
                appendCell(row, item.quantity);
                appendCell(row, item.unit_amount);
                appendCell(row, item.line_total);
                body.appendChild(row);
            });
            document.querySelector('[data-receipt]').hidden = false;
        } catch (error) {
            showMessage(error.message);
        }
    }

    document.addEventListener('DOMContentLoaded', async () => {
        document.querySelector('[data-sign-out]')?.addEventListener('click', async () => {
            try {
                await parseRequest('/api/auth/logout', { method: 'POST' });
                window.location.replace(`${projectBase}/auth/login.php`);
            } catch (error) {
                showMessage(error.message);
            }
        });
        document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
        try {
            if (!await verifySession()) return;
            if (document.body.hasAttribute('data-receipt-page')) {
                await loadReceipt();
                return;
            }
            document.querySelector('[data-search-button]').addEventListener('click', () => {
                loadQueue(document.querySelector('#billing-search').value.trim());
            });
            document.querySelector('#billing-search').addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    loadQueue(event.currentTarget.value.trim());
                }
            });
            await loadQueue();
        } catch (error) {
            showMessage(error.message);
        }
    });
})();
