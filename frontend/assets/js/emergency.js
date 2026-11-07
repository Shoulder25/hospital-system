'use strict';

(() => {
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    const messageElement = document.querySelector('[data-emergency-message]');
    const rows = document.querySelector('[data-emergency-rows]');
    const detailPanel = document.querySelector('[data-emergency-detail]');
    const state = {
        csrfToken: '',
        role: document.body.dataset.role || '',
        search: '',
        visits: [],
        activeVisitId: null,
        detail: null,
    };

    async function request(route, options = {}) {
        const url = new URL(apiEntry.href);
        url.searchParams.set('route', route);
        if (options.query) {
            Object.entries(options.query).forEach(([key, value]) => {
                url.searchParams.set(key, value);
            });
        }
        const headers = new Headers(options.headers || {});
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
        messageElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideMessage() {
        messageElement.hidden = true;
        messageElement.textContent = '';
        messageElement.classList.remove('error', 'success');
    }

    function redirectToLogin() {
        window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
    }

    async function parseRequest(route, options = {}) {
        const { response, payload } = await request(route, options);
        if (response.status === 401) {
            redirectToLogin();
            throw new Error('Your session has expired. Sign in again.');
        }
        if (!response.ok || !payload.success) {
            const error = new Error(errorMessage(payload, 'The request could not be completed.'));
            error.status = response.status;
            throw error;
        }
        return payload.data;
    }

    async function checkSession() {
        const { response, payload } = await request('/api/auth/me');
        if (response.status === 401) {
            redirectToLogin();
            return false;
        }
        if (!response.ok || !payload.success) {
            throw new Error(errorMessage(payload, 'Could not verify the current session.'));
        }
        const user = payload.data.user;
        state.role = user.role.name;
        state.csrfToken = payload.data.csrf_token;
        if (!['emergency_doctor', 'emergency_nurse', 'system_administrator'].includes(state.role)) {
            window.location.replace(`${projectBase}frontend/dashboard.php`);
            return false;
        }
        return true;
    }

    function formatDateTime(value) {
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

    function pretty(value) {
        return value ? String(value).replaceAll('_', ' ') : '—';
    }

    function makeButton(text, handler) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button button-outline button-small';
        button.textContent = text;
        button.addEventListener('click', handler);
        return button;
    }

    function emptyRow(message) {
        rows.replaceChildren();
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.className = 'table-message';
        cell.colSpan = 6;
        cell.textContent = message;
        row.appendChild(cell);
        rows.appendChild(row);
    }

    function renderQueue() {
        rows.replaceChildren();
        if (state.visits.length === 0) {
            emptyRow('No emergency visits match the search.');
            return;
        }
        state.visits.forEach((visit) => {
            const row = document.createElement('tr');
            const patient = document.createElement('td');
            const name = document.createElement('strong');
            name.textContent = visit.patient_name || 'Temporary patient';
            const number = document.createElement('small');
            number.textContent = `${visit.patient_number}${visit.is_temporary ? ' · temporary' : ''}`;
            patient.append(name, number);
            row.appendChild(patient);

            const visitCell = document.createElement('td');
            visitCell.textContent = String(visit.visit_id);
            row.appendChild(visitCell);

            const arrived = document.createElement('td');
            arrived.textContent = formatDateTime(visit.arrived_at);
            row.appendChild(arrived);

            const encounter = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = `emergency-status emergency-status-${visit.encounter_status}`;
            badge.textContent = pretty(visit.encounter_status);
            encounter.appendChild(badge);
            row.appendChild(encounter);

            const status = document.createElement('td');
            status.textContent = pretty(visit.status);
            row.appendChild(status);

            const action = document.createElement('td');
            const button = makeButton('Open visit', () => openVisit(visit.visit_id));
            action.appendChild(button);
            row.appendChild(action);
            rows.appendChild(row);
        });
    }

    async function loadQueue() {
        const data = await parseRequest('/api/emergency/queue', {
            query: { search: state.search },
        });
        state.visits = data.visits || [];
        renderQueue();
    }

    function appendText(target, text) {
        target.textContent = text === null || text === undefined || text === ''
            ? '—'
            : String(text);
    }

    function renderNurses(nurses) {
        const list = document.querySelector('[data-nurse-list]');
        list.replaceChildren();
        if (!nurses.length) {
            const item = document.createElement('li');
            item.textContent = 'No attending nurses recorded.';
            list.appendChild(item);
        }
        nurses.forEach((nurse) => {
            const item = document.createElement('li');
            item.textContent = nurse.display_name;
            list.appendChild(item);
        });
    }

    function renderPrescriptions(prescriptions) {
        const list = document.querySelector('[data-prescription-list]');
        list.replaceChildren();
        if (!prescriptions.length) {
            const item = document.createElement('li');
            item.textContent = 'No prescription has been recorded.';
            list.appendChild(item);
        }
        prescriptions.forEach((prescription) => {
            const item = document.createElement('li');
            item.textContent = `#${prescription.prescription_id} · ${pretty(prescription.status)} · ${prescription.prescribed_by} · ${formatDateTime(prescription.created_at)}`;
            list.appendChild(item);
        });
    }

    function populateSelect(select, options, labelForOption) {
        select.replaceChildren();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = select.name === 'nurse_user_id'
            ? 'Select an active emergency nurse'
            : 'Select an active medicine';
        select.appendChild(placeholder);
        options.forEach((option) => {
            const item = document.createElement('option');
            item.value = String(option.user_id ?? option.medicine_id);
            item.textContent = labelForOption(option);
            select.appendChild(item);
        });
    }

    function setVisible(selector, visible) {
        document.querySelector(selector).hidden = !visible;
    }

    function renderDetail(data) {
        const visit = data.visit;
        state.detail = data;
        state.activeVisitId = visit.visit_id;
        detailPanel.hidden = false;
        appendText(document.querySelector('[data-detail-summary]'), `${visit.patient_name || 'Temporary patient'} · Visit ${visit.visit_id}`);
        appendText(document.querySelector('[data-patient-number]'), visit.patient_number);
        appendText(document.querySelector('[data-visit-id]'), visit.visit_id);
        appendText(document.querySelector('[data-arrived-at]'), formatDateTime(visit.arrived_at));
        appendText(document.querySelector('[data-visit-status]'), pretty(visit.status));
        appendText(document.querySelector('[data-encounter-status]'), pretty(visit.encounter_status));
        appendText(document.querySelector('[data-encounter-doctor]'), visit.encounter?.emergency_doctor_name);
        appendText(document.querySelector('[data-triage-level]'), visit.encounter?.triage_level);
        appendText(document.querySelector('[data-encounter-started]'), formatDateTime(visit.encounter?.started_at));
        const notes = document.querySelector('[data-stabilization-notes]');
        notes.textContent = visit.encounter?.stabilization_notes || '';
        notes.hidden = !visit.encounter?.stabilization_notes;
        document.querySelector('[data-temporary-note]').hidden = !visit.is_temporary;

        const canTreat = state.role === 'emergency_doctor';
        const encounterActive = visit.encounter_status === 'in_progress';
        setVisible('[data-encounter-create-section]', canTreat && visit.encounter_status === 'not_started');
        setVisible('[data-nurse-section]', canTreat && encounterActive);
        setVisible('[data-prescription-section]', canTreat && encounterActive
            && !data.prescriptions.some((prescription) => prescription.status === 'active'));
        setVisible('[data-prescriptions-section]', data.prescriptions.length > 0
            || canTreat && encounterActive);
        setVisible('[data-finish-section]', canTreat && encounterActive);

        renderNurses(data.attending_nurses || []);
        renderPrescriptions(data.prescriptions || []);
        populateSelect(
            document.querySelector('[data-nurse-form] select'),
            data.available_nurses || [],
            (nurse) => nurse.display_name
        );
        populateSelect(
            document.querySelector('[data-prescription-form] select'),
            data.medicines || [],
            (medicine) => `${medicine.medicine_name}${medicine.is_demo ? ' · demo' : ''}`
        );
    }

    async function openVisit(visitId) {
        hideMessage();
        try {
            const data = await parseRequest(`/api/emergency/visits/${visitId}`);
            renderDetail(data);
            detailPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function refreshVisit() {
        const visitId = state.activeVisitId;
        const [queueData, detailData] = await Promise.all([
            parseRequest('/api/emergency/queue', {
                query: { search: state.search },
            }),
            parseRequest(`/api/emergency/visits/${visitId}`),
        ]);
        state.visits = queueData.visits || [];
        renderQueue();
        renderDetail(detailData);
    }

    function formValues(form) {
        return Object.fromEntries(new FormData(form).entries());
    }

    function addFormHandler(selector, routePart, bodyForForm, successText) {
        const form = document.querySelector(selector);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage();
            const submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            try {
                await parseRequest(`/api/emergency/visits/${state.activeVisitId}/${routePart}`, {
                    method: 'POST',
                    body: JSON.stringify(bodyForForm(formValues(form))),
                });
                form.reset();
                showMessage(successText, 'success');
                if (state.activeVisitId !== null) await refreshVisit();
            } catch (error) {
                showMessage(error.message);
            } finally {
                submit.disabled = false;
            }
        });
    }

    function bindForms() {
        addFormHandler(
            '[data-encounter-form]',
            'encounter',
            (values) => values,
            'Emergency encounter started. Emergency-care charges are deferred to Pharmacy.'
        );
        addFormHandler(
            '[data-nurse-form]',
            'nurses',
            (values) => ({ nurse_user_id: values.nurse_user_id }),
            'Attending nurse assigned.'
        );
        addFormHandler(
            '[data-prescription-form]',
            'prescription',
            (values) => ({
                items: [{
                    medicine_id: values.medicine_id,
                    quantity: values.quantity,
                    dosage: values.dosage,
                    frequency: values.frequency,
                    duration: values.duration,
                    instructions: values.instructions,
                }],
            }),
            'Prescription saved for Pharmacy. No medication was dispensed.'
        );

        document.querySelector('[data-finish-encounter]').addEventListener('click', async (event) => {
            hideMessage();
            const button = event.currentTarget;
            button.disabled = true;
            try {
                const data = await parseRequest(
                    `/api/emergency/visits/${state.activeVisitId}/finish`,
                    { method: 'POST', body: JSON.stringify({}) }
                );
                showMessage(`Encounter finished. Visit status: ${pretty(data.visit_status)}. Charges remain unpaid until the pharmacy payment point.`, 'success');
                await loadQueue();
                detailPanel.hidden = true;
                state.activeVisitId = null;
            } catch (error) {
                showMessage(error.message);
            } finally {
                button.disabled = false;
            }
        });
    }

    async function init() {
        try {
            if (!(await checkSession())) return;
            bindForms();
            document.querySelector('[data-search-button]').addEventListener('click', async () => {
                state.search = document.querySelector('#emergency-search').value.trim();
                try {
                    await loadQueue();
                } catch (error) {
                    showMessage(error.message);
                }
            });
            document.querySelector('#emergency-search').addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    document.querySelector('[data-search-button]').click();
                }
            });
            document.querySelector('[data-close-detail]').addEventListener('click', () => {
                detailPanel.hidden = true;
                state.activeVisitId = null;
            });
            document.querySelector('[data-sign-out]').addEventListener('click', async () => {
                try {
                    await parseRequest('/api/auth/logout', {
                        method: 'POST',
                        body: JSON.stringify({}),
                    });
                } finally {
                    window.location.replace(`${projectBase}auth/login.php`);
                }
            });
            await loadQueue();
        } catch (error) {
            showMessage(error.message || 'The emergency module could not be loaded.');
        }
    }

    init();
})();
