'use strict';

(() => {
    const projectBase = document.body.dataset.basePath || '';
    const apiEntry = new URL(`${projectBase}/backend/index.php`, window.location.origin);
    const state = {
        csrfToken: '',
        visits: [],
        selectedVisitId: null,
        visit: null,
    };
    const messageElement = document.querySelector('[data-pharmacy-message]');

    async function request(route, options = {}) {
        const headers = new Headers(options.headers || {});
        const url = new URL(apiEntry.href);
        url.searchParams.set('route', route);
        if (options.body && !headers.has('Content-Type')) {
            headers.set('Content-Type', 'application/json');
        }
        if (state.csrfToken && options.method && options.method !== 'GET') {
            headers.set('X-CSRF-Token', state.csrfToken);
        }
        const response = await fetch(url.href, {
            ...options,
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
        messageElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    async function parseRequest(route, options = {}) {
        const { response, payload } = await request(route, options);
        if (response.status === 401) {
            window.location.replace(`${projectBase}/auth/login.php?message=session-expired`);
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
        if (!['pharmacist', 'system_administrator'].includes(data.user.role.name)) {
            window.location.replace(`${projectBase}/frontend/dashboard.php`);
            return false;
        }
        state.csrfToken = data.csrf_token;
        return true;
    }

    function addText(parent, tagName, text, className = '') {
        const element = document.createElement(tagName);
        if (className) element.className = className;
        element.textContent = text === null || text === '' ? '—' : String(text);
        parent.appendChild(element);
        return element;
    }

    function formattedDate(value) {
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

    function money(value) {
        const amount = Number(value);
        return Number.isFinite(amount) ? amount.toFixed(2) : '0.00';
    }

    function createUuid() {
        if (window.crypto.randomUUID) return window.crypto.randomUUID();
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    function paymentRequestKey(visitId) {
        return `hospital.pharmacy.payment.${visitId}`;
    }

    function getPaymentRequestId(visitId) {
        const key = paymentRequestKey(visitId);
        const existing = window.sessionStorage.getItem(key);
        if (existing && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(existing)) {
            return existing;
        }
        const requestId = createUuid();
        window.sessionStorage.setItem(key, requestId);
        return requestId;
    }

    function renderQueue() {
        const list = document.querySelector('[data-pharmacy-queue]');
        list.replaceChildren();
        if (state.visits.length === 0) {
            addText(list, 'p', 'No visits are awaiting pharmacy service.', 'pharmacy-empty');
            return;
        }
        state.visits.forEach((visit) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pharmacy-visit-card';
            button.classList.toggle('selected', visit.visit_id === state.selectedVisitId);
            button.addEventListener('click', () => openVisit(visit.visit_id));
            const patient = addText(
                button,
                'strong',
                visit.patient_name || 'Temporary patient',
                'pharmacy-card-patient'
            );
            patient.title = visit.patient_number || '';
            addText(button, 'span', `Visit #${visit.visit_id} · ${visit.status.replaceAll('_', ' ')}`);
            addText(
                button,
                'small',
                `Bill ${visit.bill_status.replaceAll('_', ' ')} · Due ${money(visit.balance)} · ${visit.prescription_count} active prescription${visit.prescription_count === 1 ? '' : 's'}`
            );
            if (visit.is_emergency) addText(button, 'span', 'Emergency · deferred charges', 'pharmacy-card-tag');
            if (visit.medication_collection_only) addText(button, 'span', 'Medication collection only', 'pharmacy-card-tag');
            addText(button, 'small', formattedDate(visit.arrived_at));
            list.appendChild(button);
        });
    }

    async function loadQueue(search = '', preferredId = state.selectedVisitId) {
        const list = document.querySelector('[data-pharmacy-queue]');
        list.replaceChildren();
        addText(list, 'p', 'Loading pharmacy queue…', 'pharmacy-empty');
        try {
            const data = await parseRequest(`/api/pharmacy/queue${search ? `?search=${encodeURIComponent(search)}` : ''}`);
            state.visits = data.visits;
            renderQueue();
            if (preferredId && state.visits.some((visit) => visit.visit_id === preferredId)) {
                await openVisit(preferredId, false);
            } else if (preferredId) {
                state.selectedVisitId = null;
                state.visit = null;
                document.querySelector('[data-visit-panel]').hidden = true;
            }
        } catch (error) {
            list.replaceChildren();
            addText(list, 'p', 'Unable to load pharmacy queue.', 'pharmacy-empty');
            showMessage(error.message);
        }
    }

    function renderPrescriptions(prescriptions) {
        const container = document.querySelector('[data-prescriptions]');
        container.replaceChildren();
        const count = prescriptions.length;
        document.querySelector('[data-prescription-summary]').textContent =
            `${count} prescription${count === 1 ? '' : 's'}`;
        if (count === 0) {
            addText(container, 'p', 'No prescription is linked to this visit.', 'pharmacy-record-empty');
            return;
        }
        prescriptions.forEach((prescription) => {
            const card = document.createElement('article');
            card.className = 'pharmacy-prescription-card';
            const heading = document.createElement('div');
            heading.className = 'pharmacy-prescription-heading';
            addText(heading, 'strong', `Prescription #${prescription.prescription_id}`);
            addText(heading, 'span', prescription.is_dispensed
                ? `Dispensed ${formattedDate(prescription.dispensed_at)}`
                : `${prescription.status.replaceAll('_', ' ')} · Visit #${prescription.prescription_visit_id}`);
            card.appendChild(heading);
            addText(card, 'small', `Prescribed by ${prescription.prescribed_by}`);
            const tableWrap = document.createElement('div');
            tableWrap.className = 'pharmacy-table-wrap';
            const table = document.createElement('table');
            table.className = 'admin-table pharmacy-items-table';
            const thead = document.createElement('thead');
            const header = document.createElement('tr');
            ['Medicine', 'Directions', 'Qty', 'Price', 'Stock', 'State'].forEach((label) => {
                addText(header, 'th', label);
            });
            thead.appendChild(header);
            table.appendChild(thead);
            const tbody = document.createElement('tbody');
            prescription.items.forEach((item) => {
                const row = document.createElement('tr');
                addText(row, 'td', item.medicine_name);
                addText(row, 'td', [item.dosage, item.frequency, item.duration, item.instructions]
                    .filter(Boolean).join(' · ') || '—');
                addText(row, 'td', item.quantity);
                addText(row, 'td', money(item.unit_price));
                addText(row, 'td', item.stock_available);
                addText(row, 'td', item.is_dispensed ? 'Dispensed' : item.is_billed ? 'Billed' : 'Not billed');
                tbody.appendChild(row);
            });
            table.appendChild(tbody);
            tableWrap.appendChild(table);
            card.appendChild(tableWrap);
            container.appendChild(card);
        });
    }

    function renderBill(bill) {
        const groups = document.querySelector('[data-bill-groups]');
        groups.replaceChildren();
        if (bill.groups.length === 0) {
            addText(groups, 'p', 'No charges recorded for this visit.', 'pharmacy-record-empty');
        } else {
            bill.groups.forEach((group) => {
                const section = document.createElement('section');
                section.className = 'pharmacy-bill-group';
                const heading = document.createElement('div');
                heading.className = 'pharmacy-bill-group-heading';
                addText(heading, 'h4', group.charge_type.replaceAll('_', ' '));
                addText(heading, 'strong', money(group.total));
                section.appendChild(heading);
                group.items.forEach((item) => {
                    const row = document.createElement('div');
                    row.className = 'pharmacy-bill-item';
                    addText(row, 'span', `${item.description} · ${item.quantity} × ${money(item.unit_amount)}`);
                    addText(row, 'strong', money(item.line_total));
                    section.appendChild(row);
                });
                groups.appendChild(section);
            });
        }
        document.querySelector('[data-bill-number]').textContent =
            `${bill.bill_number} · ${bill.status.replaceAll('_', ' ')}`;
        document.querySelector('[data-bill-total]').textContent = money(bill.total);
        document.querySelector('[data-approved-payments]').textContent = money(bill.approved_payments);
        document.querySelector('[data-balance]').textContent = money(bill.balance);
        document.querySelector('[data-payment-form]').elements.amount.value =
            Number(bill.balance) > 0 ? money(bill.balance) : '';
        document.querySelector('[data-payment-form] [data-take-payment]').disabled =
            Number(bill.balance) <= 0;
    }

    function renderPayments(payments) {
        const container = document.querySelector('[data-payments]');
        container.replaceChildren();
        if (payments.length === 0) {
            addText(container, 'p', 'No payments recorded.', 'pharmacy-record-empty');
            return;
        }
        payments.forEach((payment) => {
            const row = document.createElement('div');
            row.className = 'pharmacy-payment-row';
            const details = document.createElement('div');
            addText(details, 'strong', `${payment.payment_method.replaceAll('_', ' ')} · ${money(payment.amount)}`);
            addText(details, 'small', `${payment.receipt_number || 'No receipt'} · ${formattedDate(payment.paid_at)}${payment.is_simulated ? ' · simulated' : ''}`);
            row.appendChild(details);
            if (payment.payment_id && payment.receipt_number) {
                const receipt = document.createElement('a');
                receipt.className = 'button button-outline button-small';
                receipt.href = `receipt.php?payment_id=${encodeURIComponent(payment.payment_id)}`;
                receipt.textContent = 'Receipt';
                row.appendChild(receipt);
            }
            container.appendChild(row);
        });
    }

    function renderDispensings(dispensings) {
        const container = document.querySelector('[data-dispensings]');
        container.replaceChildren();
        if (dispensings.length === 0) {
            addText(container, 'p', 'No dispensing records for this visit.', 'pharmacy-record-empty');
            return;
        }
        dispensings.forEach((dispensing) => {
            const record = document.createElement('article');
            record.className = 'pharmacy-dispensing-record';
            addText(
                record,
                'strong',
                `Dispensing #${dispensing.dispensing_id} · ${formattedDate(dispensing.dispensed_at)}`
            );
            addText(record, 'small', `Pharmacist: ${dispensing.pharmacist_name}`);
            dispensing.items.forEach((item) => {
                addText(
                    record,
                    'span',
                    `${item.medicine_name} · ${item.quantity_dispensed} dispensed`
                );
            });
            container.appendChild(record);
        });
    }

    function renderAvailablePrescriptions(prescriptions) {
        const section = document.querySelector('[data-available-section]');
        const select = document.querySelector('[data-prescription-select]');
        section.hidden = !state.visit.visit.medication_collection_only;
        select.replaceChildren();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = prescriptions.length
            ? 'Select an earlier active prescription'
            : 'No eligible earlier prescriptions';
        select.appendChild(placeholder);
        prescriptions.forEach((prescription) => {
            const option = document.createElement('option');
            option.value = String(prescription.prescription_id);
            const medicines = prescription.items.map((item) => item.medicine_name).join(', ');
            option.textContent = `Visit #${prescription.prescription_visit_id}: ${medicines}`;
            select.appendChild(option);
        });
        document.querySelector('[data-select-prescription]').disabled =
            prescriptions.length === 0;
    }

    function populateSchemes(schemes) {
        const select = document.querySelector('[data-scheme-select]');
        select.replaceChildren();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = schemes.length ? 'Select a scheme' : 'No active schemes';
        select.appendChild(placeholder);
        schemes.forEach((scheme) => {
            const option = document.createElement('option');
            option.value = String(scheme.medical_aid_scheme_id);
            option.textContent = scheme.scheme_name;
            select.appendChild(option);
        });
        select.selectedIndex = 0;
    }

    function populateMemberships(memberships) {
        const select = document.querySelector('[data-membership-select]');
        select.replaceChildren();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = memberships.length
            ? 'Enter a different membership'
            : 'No saved memberships';
        select.appendChild(placeholder);
        memberships.forEach((membership) => {
            const option = document.createElement('option');
            option.value = String(membership.membership_id);
            option.dataset.schemeId = String(membership.medical_aid_scheme_id);
            option.dataset.memberNumber = membership.member_number;
            option.dataset.memberName = membership.member_name;
            option.textContent =
                `${membership.scheme_name} · ${membership.member_number} (${membership.member_name})`;
            select.appendChild(option);
        });
        select.selectedIndex = 0;
        const form = document.querySelector('[data-payment-form]');
        form.elements.medical_aid_scheme_id.selectedIndex = 0;
        form.elements.member_number.value = '';
        form.elements.member_name.value = '';
    }

    async function openVisit(visitId, setSelection = true) {
        if (setSelection) {
            state.selectedVisitId = visitId;
            renderQueue();
        }
        try {
            state.visit = await parseRequest(`/api/pharmacy/visits/${visitId}`);
            const {
                visit,
                bill,
                prescriptions,
                available_prescriptions,
                payments,
                dispensings,
                medical_aid_schemes,
                medical_aid_memberships,
            } = state.visit;
            document.querySelector('[data-visit-heading]').textContent =
                `${visit.patient_name || 'Patient'} · Visit #${visit.visit_id}`;
            document.querySelector('[data-visit-subheading]').textContent =
                `${visit.patient_number} · ${visit.is_emergency ? 'Emergency visit' : visit.medication_collection_only ? 'Medication collection only' : 'Hospital visit'}`;
            document.querySelector('[data-visit-status]').textContent = visit.status.replaceAll('_', ' ');
            document.querySelector('[data-visit-status]').className =
                `pharmacy-status pharmacy-status-${visit.status}`;
            renderPrescriptions(prescriptions);
            renderBill(bill);
            renderPayments(payments);
            renderDispensings(dispensings);
            renderAvailablePrescriptions(available_prescriptions);
            populateSchemes(medical_aid_schemes);
            populateMemberships(medical_aid_memberships);
            const activeUndispensed = prescriptions.some(
                (prescription) => prescription.status === 'active' && !prescription.is_dispensed
            );
            document.querySelector('[data-dispense]').disabled =
                !activeUndispensed || Number(bill.balance) > 0 || visit.status === 'completed';
            document.querySelector('[data-visit-panel]').hidden = false;
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function selectPrescription() {
        if (!state.selectedVisitId) return;
        const select = document.querySelector('[data-prescription-select]');
        if (!select.value) {
            showMessage('Select an active earlier prescription first.');
            return;
        }
        const button = document.querySelector('[data-select-prescription]');
        button.disabled = true;
        try {
            await parseRequest(`/api/pharmacy/visits/${state.selectedVisitId}/prescription`, {
                method: 'POST',
                body: JSON.stringify({ prescription_id: Number(select.value) }),
            });
            showMessage('Prescription selected and medication charges added to this visit.', 'success');
            await openVisit(state.selectedVisitId, false);
            await loadQueue(document.querySelector('#pharmacy-search').value, state.selectedVisitId);
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    function updateMedicalAidFields() {
        const form = document.querySelector('[data-payment-form]');
        const medicalAid = form.elements.payment_method.value === 'medical_aid';
        document.querySelector('[data-medical-aid-fields]').hidden = !medicalAid;
        form.elements.medical_aid_scheme_id.required = medicalAid;
        form.elements.member_number.required = medicalAid;
    }

    async function takePayment(event) {
        event.preventDefault();
        if (!state.selectedVisitId) return;
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const requestVisitId = state.selectedVisitId;
        const button = form.querySelector('[data-take-payment]');
        button.disabled = true;
        try {
            const body = {
                payment_method: form.elements.payment_method.value,
                amount: form.elements.amount.value,
                client_request_id: getPaymentRequestId(requestVisitId),
            };
            if (body.payment_method === 'medical_aid') {
                body.medical_aid_scheme_id = Number(form.elements.medical_aid_scheme_id.value);
                body.member_number = form.elements.member_number.value.trim();
                body.member_name = form.elements.member_name.value.trim();
            }
            const data = await parseRequest(`/api/pharmacy/visits/${requestVisitId}/payments`, {
                method: 'POST',
                body: JSON.stringify(body),
            });
            window.sessionStorage.removeItem(paymentRequestKey(requestVisitId));
            showMessage(
                `Payment recorded${data.is_simulated ? ' (simulated approval)' : ''}. Balance due: ${money(data.balance)}.`,
                'success'
            );
            await loadQueue(document.querySelector('#pharmacy-search').value, requestVisitId);
            window.open(`receipt.php?payment_id=${encodeURIComponent(data.payment_id)}`, '_blank', 'noopener');
        } catch (error) {
            if (error.status === 409) {
                window.sessionStorage.removeItem(paymentRequestKey(requestVisitId));
            }
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    async function dispenseMedication() {
        if (!state.selectedVisitId) return;
        if (!window.confirm('Confirm that the listed medicines are being dispensed?')) return;
        const button = document.querySelector('[data-dispense]');
        button.disabled = true;
        try {
            await parseRequest(`/api/pharmacy/visits/${state.selectedVisitId}/dispense`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            showMessage('Medication dispensed, stock updated, and visit completed.', 'success');
            await loadQueue(document.querySelector('#pharmacy-search').value, null);
        } catch (error) {
            showMessage(error.message);
            button.disabled = false;
        }
    }

    function appendReceiptCell(row, value) {
        const cell = document.createElement('td');
        cell.textContent = value === null || value === '' ? '—' : String(value);
        row.appendChild(cell);
    }

    async function loadReceipt() {
        const paymentId = new URLSearchParams(window.location.search).get('payment_id');
        if (!paymentId || !/^[1-9][0-9]*$/.test(paymentId)) {
            showMessage('A valid payment ID is required.');
            return;
        }
        const data = await parseRequest(`/api/pharmacy/payments/${paymentId}/receipt`);
        document.querySelector('[data-receipt-number]').textContent = data.payment.receipt_number;
        document.querySelector('[data-receipt-bill]').textContent = data.bill.bill_number;
        document.querySelector('[data-receipt-patient]').textContent = data.visit.patient_name;
        document.querySelector('[data-receipt-patient-number]').textContent = data.visit.patient_number;
        document.querySelector('[data-receipt-visit]').textContent = String(data.visit.visit_id);
        document.querySelector('[data-receipt-date]').textContent = formattedDate(data.payment.paid_at);
        document.querySelector('[data-receipt-method]').textContent =
            `${data.payment.payment_method.replaceAll('_', ' ')}${data.payment.is_simulated ? ' (simulated)' : ''}`;
        document.querySelector('[data-receipt-status]').textContent = data.payment.status;
        document.querySelector('[data-receipt-amount]').textContent = money(data.payment.amount);
        document.querySelector('[data-simulated-badge]').hidden = !data.payment.is_simulated;
        const tbody = document.querySelector('[data-receipt-items]');
        tbody.replaceChildren();
        data.bill.items.forEach((item) => {
            const row = document.createElement('tr');
            appendReceiptCell(row, item.charge_type.replaceAll('_', ' '));
            appendReceiptCell(row, item.description);
            appendReceiptCell(row, item.quantity);
            appendReceiptCell(row, money(item.unit_amount));
            appendReceiptCell(row, money(item.line_total));
            tbody.appendChild(row);
        });
        const priorPayments = document.querySelector('[data-receipt-prior-payments]');
        priorPayments.replaceChildren();
        data.prior_payments.forEach((payment) => {
            const row = document.createElement('tr');
            appendReceiptCell(row, payment.receipt_number || `Payment #${payment.payment_id}`);
            appendReceiptCell(row, payment.payment_method.replaceAll('_', ' '));
            appendReceiptCell(row, money(payment.amount));
            appendReceiptCell(row, payment.status);
            appendReceiptCell(row, formattedDate(payment.paid_at));
            priorPayments.appendChild(row);
        });
        document.querySelector('[data-prior-payments-section]').hidden =
            data.prior_payments.length === 0;
        document.querySelector('[data-receipt-total]').textContent = money(data.bill.total_amount);
        document.querySelector('[data-receipt-approved]').textContent =
            money(data.bill.approved_payments);
        document.querySelector('[data-receipt-balance]').textContent =
            money(data.bill.remaining_balance);
        document.querySelector('[data-receipt]').hidden = false;
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
        document.querySelector('[data-search]')?.addEventListener('click', () => {
            loadQueue(document.querySelector('#pharmacy-search').value.trim(), null);
        });
        document.querySelector('#pharmacy-search')?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                loadQueue(event.currentTarget.value.trim(), null);
            }
        });
        document.querySelector('[data-select-prescription]')?.addEventListener('click', selectPrescription);
        document.querySelector('[data-payment-form]')?.addEventListener('submit', takePayment);
        document.querySelector('[data-payment-form]')?.elements.payment_method.addEventListener(
            'change',
            updateMedicalAidFields
        );
        document.querySelector('[data-membership-select]')?.addEventListener('change', (event) => {
            const option = event.currentTarget.selectedOptions[0];
            if (!option.value) return;
            const form = document.querySelector('[data-payment-form]');
            form.elements.medical_aid_scheme_id.value = option.dataset.schemeId;
            form.elements.member_number.value = option.dataset.memberNumber;
            form.elements.member_name.value = option.dataset.memberName;
        });
        document.querySelector('[data-dispense]')?.addEventListener('click', dispenseMedication);
        document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
        try {
            if (!await verifySession()) return;
            if (document.body.hasAttribute('data-receipt-page')) {
                await loadReceipt();
                return;
            }
            updateMedicalAidFields();
            await loadQueue();
        } catch (error) {
            showMessage(error.message);
        }
    });
})();
