'use strict';

(() => {
    const projectBase = document.body.dataset.basePath || '';
    const apiEntry = new URL(`${projectBase}/backend/index.php`, window.location.origin);
    const state = {
        csrfToken: '',
        activeVisit: null,
        activeBill: null,
        activeTicketVisit: null,
        pendingRequestId: null,
        currencyCode: null,
    };
    const messageElement = document.querySelector('[data-billing-message]');

    async function request(route, options = {}) {
        const headers = new Headers(options.headers || {});
        const url = new URL(apiEntry.href);
        url.searchParams.set('route', route);
        if (options.query) {
            Object.entries(options.query).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined) {
                    url.searchParams.set(key, String(value));
                }
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

    function showMessage(message, type = 'error', link = null) {
        messageElement.replaceChildren();
        messageElement.classList.toggle('error', type === 'error');
        messageElement.classList.toggle('success', type === 'success');
        messageElement.hidden = false;
        messageElement.append(document.createTextNode(message));
        if (link) {
            messageElement.append(document.createTextNode(' '));
            const anchor = document.createElement('a');
            anchor.href = link.href;
            anchor.textContent = link.label;
            messageElement.appendChild(anchor);
        }
    }

    function hideMessage() {
        messageElement.hidden = true;
        messageElement.replaceChildren();
        messageElement.classList.remove('error', 'success');
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
        const allowedRoles = document.body.hasAttribute('data-receipt-page')
            ? ['cashier', 'system_administrator', 'receptionist']
            : ['cashier', 'system_administrator'];
        if (!allowedRoles.includes(role)) {
            window.location.replace(`${projectBase}/frontend/dashboard.php`);
            return false;
        }
        state.csrfToken = data.csrf_token;
        return true;
    }

    function pretty(value) {
        return value ? String(value).replaceAll('_', ' ') : '—';
    }

    function money(value, currency = state.currencyCode) {
        const amount = Number(value);
        if (!Number.isFinite(amount)) return '—';
        if (!currency) return amount.toFixed(2);
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            currencyDisplay: 'narrowSymbol',
        }).format(amount);
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

    function dateInputValue(date) {
        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0'),
        ].join('-');
    }

    function newRequestId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
            const random = Math.random() * 16 | 0;
            return (character === 'x' ? random : (random & 3 | 8)).toString(16);
        });
    }

    function appendCell(row, value, className = '') {
        const cell = document.createElement('td');
        if (className) cell.className = className;
        cell.textContent = value === null || value === undefined || value === ''
            ? '—'
            : String(value);
        row.appendChild(cell);
        return cell;
    }

    function appendReceiptLink(cell, paymentId, label = 'Receipt') {
        const link = document.createElement('a');
        link.className = 'text-link';
        link.href = `receipt.php?payment_id=${encodeURIComponent(paymentId)}`;
        link.textContent = label;
        cell.appendChild(link);
    }

    function emptyTable(selector, columns, message) {
        const body = document.querySelector(selector);
        if (!body) return;
        body.replaceChildren();
        const row = document.createElement('tr');
        const cell = appendCell(row, message, 'table-message');
        cell.colSpan = columns;
        body.appendChild(row);
    }

    async function loadDashboard() {
        const form = document.querySelector('[data-date-range]');
        const values = form ? new FormData(form) : null;
        const data = await parseRequest('/api/billing/dashboard', {
            query: values ? { from: values.get('from'), to: values.get('to') } : {},
        });
        if (typeof data.currency === 'string') state.currencyCode = data.currency;
        Object.entries(data.stats).forEach(([key, value]) => {
            const element = document.querySelector(`[data-billing-stat="${key}"]`);
            if (element) {
                element.textContent = key === 'payments_received'
                    ? money(value)
                    : String(value);
            }
        });
        renderRecentPayments(data.recent_payments);
        renderConfiguredFees(data.configured_fees);
        const missingFees = document.querySelector('[data-missing-fees]');
        if (missingFees) {
            missingFees.hidden = data.missing_fee_codes.length === 0;
            missingFees.textContent = data.missing_fee_codes.length
                ? `Required fee configuration is missing or has no positive amount: ${data.missing_fee_codes.join(', ')}. The related workflow must not charge an invented amount.`
                : '';
        }
    }

    function renderRecentPayments(payments) {
        const body = document.querySelector('[data-recent-payments]');
        if (!body) return;
        body.replaceChildren();
        if (!payments.length) {
            emptyTable('[data-recent-payments]', 8, 'No payments were recorded in this date range.');
            return;
        }
        payments.forEach((payment) => {
            const row = document.createElement('tr');
            appendCell(row, dateTime(payment.paid_at));
            const patient = appendCell(row, '');
            const name = document.createElement('strong');
            name.textContent = payment.patient_name || 'Temporary patient';
            const number = document.createElement('small');
            number.textContent = payment.patient_number || 'No patient number';
            patient.append(name, number);
            appendCell(row, `#${payment.visit_id}`);
            appendCell(row, payment.cashier_name || 'Staff unavailable');
            appendCell(row, `${pretty(payment.payment_method)}${payment.is_simulated ? ' (simulated)' : ''}`);
            appendCell(row, pretty(payment.status));
            appendCell(row, money(payment.amount));
            const receipt = appendCell(row, '');
            if (payment.status === 'approved' && payment.payment_id) {
                appendReceiptLink(receipt, payment.payment_id);
            }
            body.appendChild(row);
        });
    }

    function renderConfiguredFees(fees) {
        const container = document.querySelector('[data-configured-fees]');
        if (!container) return;
        container.replaceChildren();
        if (!fees.length) {
            container.textContent = 'No active positive-value fees are configured.';
            return;
        }
        fees.forEach((fee) => {
            const item = document.createElement('div');
            item.className = 'billing-fee';
            const description = document.createElement('span');
            description.textContent = `${fee.description} · ${fee.fee_code}${fee.is_demo ? ' · DEMO' : ''}`;
            const amount = document.createElement('strong');
            amount.textContent = fee.requires_manual_amount
                ? 'Manual amount at referral workflow'
                : (fee.amount === null ? 'Amount not configured' : money(fee.amount, fee.currency));
            item.append(description, amount);
            container.appendChild(item);
        });
    }

    function renderPatientVisits(visits) {
        const body = document.querySelector('[data-patient-visits]');
        body.replaceChildren();
        if (!visits.length) {
            emptyTable('[data-patient-visits]', 9, 'No matching patient visits were found.');
            return;
        }
        visits.forEach((visit) => {
            const row = document.createElement('tr');
            const patient = appendCell(row, '');
            const name = document.createElement('strong');
            name.textContent = visit.patient_name || 'Temporary patient';
            const number = document.createElement('small');
            number.textContent = visit.patient_number || 'No patient number';
            patient.append(name, number);
            appendCell(row, `#${visit.visit_id}`);
            appendCell(row, dateTime(visit.arrived_at));
            appendCell(row, pretty(visit.visit_status));
            appendCell(row, pretty(visit.bill_status));
            appendCell(row, money(visit.billed_amount));
            appendCell(row, money(visit.paid_amount));
            appendCell(row, money(visit.balance));
            const action = appendCell(row, '');
            if (visit.bill_id !== null) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button button-outline button-small';
                button.textContent = 'View bill';
                button.addEventListener('click', () => loadBill(visit.visit_id));
                action.appendChild(button);
            } else if (visit.visit_status === 'awaiting_ticket_payment') {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button button-outline button-small';
                button.textContent = 'Collect ticket';
                button.addEventListener('click', () => {
                    window.location.href = `ticket-payments.php?visit_id=${encodeURIComponent(visit.visit_id)}`;
                });
                action.appendChild(button);
            } else {
                action.textContent = 'No bill';
            }
            body.appendChild(row);
        });
    }

    async function searchPatients(search) {
        hideMessage();
        emptyTable('[data-patient-visits]', 9, 'Searching patient and visit records…');
        try {
            const data = await parseRequest('/api/billing/patients', {
                query: { search: search.trim() },
            });
            renderPatientVisits(data.visits);
        } catch (error) {
            emptyTable('[data-patient-visits]', 9, error.message);
            showMessage(error.message);
        }
    }

    function renderBillItems(items) {
        const body = document.querySelector('[data-bill-items]');
        body.replaceChildren();
        if (!items.length) {
            emptyTable('[data-bill-items]', 6, 'No charges have been added to this bill.');
            return;
        }
        items.forEach((item) => {
            const row = document.createElement('tr');
            if (item.is_voided) row.classList.add('billing-item-voided');
            appendCell(row, pretty(item.charge_type));
            appendCell(row, item.is_voided ? `${item.description} (removed)` : item.description);
            appendCell(row, item.quantity);
            appendCell(row, money(item.unit_amount));
            appendCell(row, item.is_voided ? '—' : money(item.line_total));
            const action = appendCell(row, '');
            if (item.can_remove) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'status-toggle';
                button.textContent = 'Remove';
                button.addEventListener('click', () => removeBillService(item, button));
                action.appendChild(button);
            }
            body.appendChild(row);
        });
    }

    function renderAvailableServices(services, canAdd) {
        const panel = document.querySelector('[data-service-adder]');
        const form = document.querySelector('[data-add-service-form]');
        const select = form.elements.fee_id;
        const submit = form.querySelector('[data-add-service]');
        select.replaceChildren(new Option('Choose a configured service', ''));
        services.forEach((service) => {
            select.add(new Option(
                `${service.description} · ${service.fee_code} · ${money(service.amount, service.currency)}${service.is_demo ? ' · DEMO' : ''}`,
                String(service.fee_id)
            ));
        });
        const enabled = canAdd && services.length > 0;
        panel.hidden = !enabled;
        submit.disabled = !enabled;
        document.querySelector('[data-selected-service-price]').textContent =
            'Select a service to see its configured price.';
        select.onchange = () => {
            const selected = services.find((service) => String(service.fee_id) === select.value);
            document.querySelector('[data-selected-service-price]').textContent = selected
                ? `Configured unit price: ${money(selected.amount, selected.currency)}`
                : 'Select a service to see its configured price.';
        };
    }

    async function addBillService(form) {
        if (!state.activeBill || !form.reportValidity()) return;
        const formData = new FormData(form);
        const feeId = String(formData.get('fee_id'));
        const quantity = String(formData.get('quantity'));
        const requestKey = `carebridge-service:${state.activeBill.bill_id}:${feeId}:${quantity}`;
        let requestId;
        try {
            requestId = window.sessionStorage.getItem(requestKey);
            if (!requestId) {
                requestId = newRequestId();
                window.sessionStorage.setItem(requestKey, requestId);
            }
        } catch (error) {
            state.pendingServiceRequestId = state.pendingServiceRequestId || newRequestId();
            requestId = state.pendingServiceRequestId;
        }
        const button = form.querySelector('[data-add-service]');
        button.disabled = true;
        button.textContent = 'Adding…';
        try {
            const responseData = await parseRequest(
                `/api/billing/bills/${encodeURIComponent(state.activeBill.bill_id)}/items`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        fee_id: feeId,
                        quantity,
                        client_request_id: requestId,
                    }),
                }
            );
            try {
                window.sessionStorage.removeItem(requestKey);
            } catch (error) {
                state.pendingServiceRequestId = null;
            }
            form.reset();
            await loadBill(state.activeVisit);
            showMessage(
                responseData.duplicate
                    ? 'This service was already added; the existing charge is shown below.'
                    : 'Service added using the current configured price.',
                'success'
            );
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
            button.textContent = 'Add';
        }
    }

    async function removeBillService(item, button) {
        if (!state.activeBill || !item.can_remove) return;
        button.disabled = true;
        try {
            await parseRequest(
                `/api/billing/bills/${encodeURIComponent(state.activeBill.bill_id)}/items/${encodeURIComponent(item.bill_item_id)}`,
                { method: 'DELETE' }
            );
            await loadBill(state.activeVisit);
            showMessage('Service removed from the current bill. The audit history is retained.', 'success');
        } catch (error) {
            showMessage(error.message);
            button.disabled = false;
        }
    }

    function renderBillPayments(payments) {
        const body = document.querySelector('[data-bill-payments]');
        body.replaceChildren();
        if (!payments.length) {
            emptyTable('[data-bill-payments]', 6, 'No payments have been recorded for this visit.');
            return;
        }
        payments.forEach((payment) => {
            const row = document.createElement('tr');
            const receipt = appendCell(row, '');
            if (payment.receipt_number && payment.status === 'approved') {
                appendReceiptLink(receipt, payment.payment_id, payment.receipt_number);
            } else {
                receipt.textContent = payment.receipt_number || '—';
            }
            appendCell(row, dateTime(payment.paid_at));
            appendCell(row, `${pretty(payment.payment_method)}${payment.is_simulated ? ' (simulated)' : ''}`);
            appendCell(row, pretty(payment.status));
            appendCell(row, money(payment.amount));
            const action = appendCell(row, '');
            if (payment.status === 'approved') appendReceiptLink(action, payment.payment_id, 'View');
            body.appendChild(row);
        });
    }

    function renderMedicalAidOptions(data) {
        const membershipSelect = document.querySelector('[data-saved-membership]');
        const schemeSelect = document.querySelector('[data-medical-aid-scheme]');
        membershipSelect.replaceChildren(new Option('Enter a membership', ''));
        data.medical_aid_memberships.forEach((membership) => {
            membershipSelect.add(new Option(
                `${membership.scheme_name} · ${membership.member_number} · ${membership.member_name}`,
                String(membership.membership_id)
            ));
        });
        schemeSelect.replaceChildren(new Option('Select a scheme', ''));
        data.medical_aid_schemes.forEach((scheme) => {
            schemeSelect.add(new Option(scheme.scheme_name, String(scheme.scheme_id)));
        });
        updateMedicalAidFields();
    }

    function updateMedicalAidFields() {
        const form = document.querySelector('[data-cashier-payment]');
        if (!form) return;
        const method = form.elements.payment_method.value;
        const aidFields = form.querySelector('[data-medical-aid-fields]');
        const savedMembership = form.elements.membership_id;
        const simulatedOutcome = form.elements.medical_aid_outcome;
        const hasSavedMembership = Boolean(savedMembership.value);
        aidFields.hidden = method !== 'medical_aid';
        simulatedOutcome.disabled = method !== 'medical_aid';
        form.elements.medical_aid_scheme_id.required =
            method === 'medical_aid' && !hasSavedMembership;
        form.elements.member_number.required =
            method === 'medical_aid' && !hasSavedMembership;
        form.elements.medical_aid_scheme_id.disabled = hasSavedMembership;
        form.elements.member_number.disabled = hasSavedMembership;
        form.elements.member_name.disabled = hasSavedMembership;
    }

    async function loadBill(visitId) {
        hideMessage();
        try {
            const data = await parseRequest(
                `/api/billing/visits/${encodeURIComponent(visitId)}/bill`
            );
            if (typeof data.currency === 'string') state.currencyCode = data.currency;
            state.activeVisit = Number(visitId);
            state.activeBill = data.bill;
            document.querySelector('[data-bill-summary]').textContent =
                `${data.patient.name || 'Temporary patient'} · Visit #${data.visit.visit_id}`;
            document.querySelector('[data-bill-patient-number]').textContent =
                data.patient.patient_number || 'No patient number';
            document.querySelector('[data-bill-patient-name]').textContent =
                data.patient.name || 'Temporary patient';
            document.querySelector('[data-bill-patient-phone]').textContent =
                data.patient.phone || '—';
            document.querySelector('[data-bill-patient-address]').textContent =
                data.patient.address || '—';
            document.querySelector('[data-bill-visit-date]').textContent =
                dateTime(data.visit.arrived_at);
            document.querySelector('[data-bill-visit-source]').textContent =
                pretty(data.visit.visit_source);
            document.querySelector('[data-bill-visit-status]').textContent =
                pretty(data.visit.status);
            document.querySelector('[data-bill-reference]').textContent =
                data.bill ? data.bill.bill_number : 'No bill created';
            document.querySelector('[data-bill-status]').textContent =
                data.bill ? pretty(data.bill.status) : 'No bill';
            document.querySelector('[data-bill-total]').textContent = money(data.totals.billed_amount);
            document.querySelector('[data-bill-paid]').textContent = money(data.totals.approved_amount);
            document.querySelector('[data-bill-balance]').textContent = money(data.totals.balance);
            renderBillItems(data.items);
            renderAvailableServices(data.available_services, Boolean(data.bill && data.bill.can_add_services));
            renderBillPayments(data.payments);
            renderMedicalAidOptions(data);
            const hasBalance = data.bill && Number(data.totals.balance) > 0;
            const paymentForm = document.querySelector('[data-cashier-payment]');
            paymentForm.hidden = !hasBalance;
            document.querySelector('[data-no-bill]').hidden = Boolean(data.bill);
            if (hasBalance) {
                const amountInput = paymentForm.elements.amount;
                amountInput.max = Number(data.totals.balance).toFixed(2);
                amountInput.value = Number(data.totals.balance).toFixed(2);
                state.pendingRequestId = newRequestId();
            } else {
                state.pendingRequestId = null;
            }
            const panel = document.querySelector('[data-bill-panel]');
            panel.hidden = false;
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function recordCashierPayment(form) {
        if (!state.activeBill || !state.pendingRequestId || !form.reportValidity()) return;
        const button = form.querySelector('[data-record-payment]');
        const input = new FormData(form);
        const amount = String(input.get('amount'));
        const method = String(input.get('payment_method'));
        button.disabled = true;
        button.textContent = 'Recording…';
        try {
            const data = await parseRequest(
                `/api/billing/bills/${encodeURIComponent(state.activeBill.bill_id)}/payments`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        amount,
                        payment_method: method,
                        medical_aid_outcome: method === 'medical_aid'
                            ? String(input.get('medical_aid_outcome'))
                            : undefined,
                        client_request_id: state.pendingRequestId,
                        membership_id: input.get('membership_id') || null,
                        medical_aid_scheme_id: input.get('medical_aid_scheme_id') || null,
                        member_number: input.get('member_number') || null,
                        member_name: input.get('member_name') || null,
                    }),
                }
            );
            state.pendingRequestId = null;
            await loadBill(state.activeVisit);
            const refreshes = [];
            if (document.querySelector('[data-billing-stat], [data-recent-payments]')) {
                refreshes.push(loadDashboard());
            }
            const searchInput = document.querySelector('#patient-billing-search');
            if (searchInput) refreshes.push(searchPatients(searchInput.value));
            await Promise.all(refreshes);
            if (data.payment_status === 'rejected') {
                showMessage(
                    `Simulated medical-aid payment rejected. No amount was applied; balance remains ${money(data.balance)}. Reference ${data.receipt_number}.`
                );
            } else {
                showMessage(
                    data.duplicate
                        ? `This payment was already recorded. Receipt ${data.receipt_number}.`
                        : `Payment recorded for visit #${data.visit_id}.`,
                    'success',
                    { href: `receipt.php?payment_id=${encodeURIComponent(data.payment_id)}`, label: 'View receipt' }
                );
            }
        } catch (error) {
            showMessage(error.message);
            button.disabled = false;
            button.textContent = 'Record payment';
        }
    }

    function emptyTicketQueue(message) {
        emptyTable('[data-billing-rows]', 7, message);
    }

    async function loadTicketQueue() {
        emptyTicketQueue('Loading ticket-payment queue…');
        try {
            const data = await parseRequest('/api/billing/ticket-queue');
            if (typeof data.currency === 'string') state.currencyCode = data.currency;
            const body = document.querySelector('[data-billing-rows]');
            body.replaceChildren();
            if (!data.visits.length) {
                emptyTicketQueue('No eligible visits are awaiting initial ticket payment.');
                return;
            }
            data.visits.forEach((visit) => {
                const row = document.createElement('tr');
                const patient = appendCell(row, '');
                const name = document.createElement('strong');
                name.textContent = visit.patient_name || 'Temporary patient';
                const number = document.createElement('small');
                number.textContent = visit.patient_number || 'No patient number';
                patient.append(name, number);
                appendCell(row, `#${visit.visit_id}`);
                appendCell(row, pretty(visit.visit_source));
                appendCell(row, visit.source_organization);
                appendCell(row, dateTime(visit.arrived_at));
                appendCell(row, `${money(visit.ticket_amount, visit.ticket_currency)}${visit.ticket_fee_is_demo ? ' (demo)' : ''}`);
                const action = appendCell(row, '');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button button-outline button-small';
                button.textContent = 'Open ticket payment';
                button.addEventListener('click', () => openTicket(visit.visit_id));
                action.appendChild(button);
                body.appendChild(row);
            });
        } catch (error) {
            emptyTicketQueue(error.message);
        }
    }

    async function openTicket(visitId) {
        hideMessage();
        try {
            const data = await parseRequest(`/api/billing/visits/${encodeURIComponent(visitId)}`);
            if (typeof data.ticket_fee.currency === 'string') {
                state.currencyCode = data.ticket_fee.currency;
            }
            state.activeTicketVisit = data.visit;
            document.querySelector('[data-ticket-summary]').textContent =
                `${data.visit.patient_name || 'Temporary patient'} · Visit #${data.visit.visit_id}`;
            document.querySelector('[data-ticket-patient-number]').textContent =
                data.visit.patient_number || 'No patient number';
            document.querySelector('[data-ticket-source]').textContent =
                pretty(data.visit.visit_source);
            document.querySelector('[data-ticket-arrived]').textContent =
                dateTime(data.visit.arrived_at);
            document.querySelector('[data-ticket-status]').textContent =
                pretty(data.visit.status);
            document.querySelector('[data-ticket-fee]').textContent =
                `${data.ticket_fee.description} · ${money(data.ticket_fee.amount, data.ticket_fee.currency)}${data.ticket_fee.is_demo ? ' (demo)' : ''}`;
            document.querySelector('[data-ticket-visit]').hidden = false;
            document.querySelector('[data-ticket-visit]').scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function recordTicketPayment(form) {
        if (!state.activeTicketVisit || !form.reportValidity()) return;
        const button = form.querySelector('[data-ticket-payment-submit]');
        const method = new FormData(form).get('payment_method');
        button.disabled = true;
        button.textContent = 'Recording…';
        try {
            const data = await parseRequest(
                `/api/billing/visits/${encodeURIComponent(state.activeTicketVisit.visit_id)}/ticket-payment`,
                { method: 'POST', body: JSON.stringify({ payment_method: method }) }
            );
            document.querySelector('[data-ticket-visit]').hidden = true;
            state.activeTicketVisit = null;
            const refreshes = [loadTicketQueue()];
            if (document.querySelector('[data-billing-stat], [data-recent-payments]')) {
                refreshes.push(loadDashboard());
            }
            await Promise.all(refreshes);
            showMessage(
                `Initial ticket payment recorded. Visit #${data.visit_id} is awaiting nursing.`,
                'success',
                { href: `receipt.php?payment_id=${encodeURIComponent(data.payment_id)}`, label: 'View receipt' }
            );
        } catch (error) {
            showMessage(error.message);
            button.disabled = false;
            button.textContent = 'Take ticket payment';
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
            setReceiptValue('[data-paid-amount]', money(payment.amount));
            setReceiptValue('[data-previous-payments]', money(payment.previous_payments));
            setReceiptValue('[data-remaining-balance]', money(payment.remaining_balance));
            setReceiptValue('[data-payment-status]', pretty(payment.status));
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

    function initBillingPage() {
        const dateRange = document.querySelector('[data-date-range]');
        const today = new Date();
        const cashierForm = document.querySelector('[data-cashier-payment]');
        if (dateRange) {
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            dateRange.elements.from.value = dateInputValue(firstDay);
            dateRange.elements.to.value = dateInputValue(today);
            dateRange.addEventListener('submit', (event) => {
                event.preventDefault();
                loadDashboard().catch((error) => showMessage(error.message));
            });
        }
        if (document.querySelector('[data-billing-stat], [data-recent-payments]')) {
            loadDashboard().catch((error) => showMessage(error.message));
        }

        const patientSearch = document.querySelector('[data-patient-search]');
        if (patientSearch) {
            patientSearch.addEventListener('submit', (event) => {
                event.preventDefault();
                searchPatients(new FormData(event.currentTarget).get('search'));
            });
        }

        const billPanel = document.querySelector('[data-bill-panel]');
        if (billPanel) {
            billPanel.hidden = true;
            cashierForm.addEventListener('submit', (event) => {
                event.preventDefault();
                recordCashierPayment(event.currentTarget);
            });
            document.querySelector('[data-add-service-form]').addEventListener('submit', (event) => {
                event.preventDefault();
                addBillService(event.currentTarget);
            });
            cashierForm.elements.payment_method.addEventListener('change', updateMedicalAidFields);
            cashierForm.elements.membership_id.addEventListener('change', updateMedicalAidFields);
            ['amount', 'payment_method', 'membership_id', 'medical_aid_scheme_id', 'member_number', 'member_name', 'medical_aid_outcome']
                .forEach((name) => {
                    cashierForm.elements[name].addEventListener('input', () => {
                        if (state.pendingRequestId) state.pendingRequestId = newRequestId();
                    });
                });
            document.querySelector('[data-close-bill]').addEventListener('click', () => {
                billPanel.hidden = true;
                state.activeVisit = null;
                state.activeBill = null;
                document.querySelector('[data-service-adder]').hidden = true;
            });
        }

        const ticketQueue = document.querySelector('[data-billing-rows]');
        if (ticketQueue) {
            document.querySelector('[data-ticket-payment-form]').addEventListener('submit', (event) => {
                event.preventDefault();
                recordTicketPayment(event.currentTarget);
            });
            document.querySelector('[data-close-ticket]').addEventListener('click', () => {
                document.querySelector('[data-ticket-visit]').hidden = true;
                state.activeTicketVisit = null;
            });
            document.querySelector('[data-refresh-ticket-queue]').addEventListener('click', () => {
                loadTicketQueue().catch((error) => showMessage(error.message));
            });
            loadTicketQueue().catch((error) => showMessage(error.message));
            const visitId = new URLSearchParams(window.location.search).get('visit_id');
            if (visitId) openTicket(visitId);
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
            initBillingPage();
        } catch (error) {
            showMessage(error.message);
        }
    });
})();
