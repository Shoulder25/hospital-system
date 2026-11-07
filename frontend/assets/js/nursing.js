'use strict';

(() => {
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    const messageElement = document.querySelector('[data-nursing-message]');
    let csrfToken = '';
    let medicineCatalogue = [];
    let activeVisitId = null;
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

    function errorMessage(payload, fallback) {
        if (payload && payload.errors && typeof payload.errors === 'object') {
            const first = Object.values(payload.errors).find((value) => typeof value === 'string');
            if (first) return first;
        }
        return payload && typeof payload.message === 'string' ? payload.message : fallback;
    }

    function showMessage(message, type = 'error') {
        if (!messageElement) return;
        messageElement.textContent = message;
        messageElement.classList.toggle('error', type === 'error');
        messageElement.classList.toggle('success', type === 'success');
        messageElement.hidden = false;
        messageElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideMessage() {
        if (!messageElement) return;
        messageElement.hidden = true;
        messageElement.textContent = '';
        messageElement.classList.remove('error', 'success');
    }

    function goToLogin() {
        window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
    }

    async function parseRequest(route, options = {}, fallback = 'The request could not be completed.') {
        const { response, payload } = await request(route, options);
        if (response.status === 401) {
            goToLogin();
            throw new Error('Your session has expired. Sign in again.');
        }
        if (!response.ok || !payload.success) {
            const error = new Error(errorMessage(payload, fallback));
            error.payload = payload;
            error.status = response.status;
            throw error;
        }
        return payload.data;
    }

    async function checkSession() {
        const { response, payload } = await request('/api/auth/me');
        if (response.status === 401) {
            goToLogin();
            return false;
        }
        if (!response.ok || !payload.success) {
            throw new Error(errorMessage(payload, 'Could not verify the current session.'));
        }
        const role = payload.data.user.role.name;
        if (!['nurse', 'system_administrator'].includes(role)) {
            window.location.replace(`${projectBase}frontend/dashboard.php`);
            return false;
        }
        csrfToken = payload.data.csrf_token;
        return true;
    }

    function setBusy(button, busy, busyText) {
        if (!button) return;
        if (busy) {
            button.dataset.originalText = button.textContent;
            button.textContent = busyText;
            button.disabled = true;
        } else {
            button.textContent = button.dataset.originalText || button.textContent;
            button.disabled = false;
        }
    }

    function formatDateTime(value) {
        if (!value) return '—';
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

    function appendCell(row, value, className = '') {
        const cell = document.createElement('td');
        if (className) cell.className = className;
        cell.textContent = value === null || value === '' ? '—' : String(value);
        row.appendChild(cell);
        return cell;
    }

    function emptyRow(body, columns, message) {
        body.replaceChildren();
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = columns;
        cell.className = 'table-message';
        cell.textContent = message;
        row.appendChild(cell);
        body.appendChild(row);
    }

    async function loadDashboard() {
        const data = await parseRequest(
            '/api/nursing/dashboard',
            {},
            'Could not load the nursing dashboard.'
        );
        Object.entries(data.stats).forEach(([key, value]) => {
            const element = document.querySelector(`[data-stat="${key}"]`);
            if (element) element.textContent = String(value);
        });
        medicineCatalogue = data.minor_treatment_medicines;
        await loadQueue();
    }

    async function loadQueue(search = '') {
        const body = document.querySelector('[data-queue-rows]');
        emptyRow(body, 7, 'Loading nursing queue…');
        try {
            const data = await parseRequest(
                '/api/nursing/queue',
                { query: search ? { search } : {} },
                'Could not load the nursing queue.'
            );
            body.replaceChildren();
            if (data.visits.length === 0) {
                emptyRow(body, 7, 'No eligible patients are currently waiting for nursing.');
                return;
            }
            data.visits.forEach((visit) => {
                const row = document.createElement('tr');
                appendCell(row, visit.patient_number);
                appendCell(row, visit.patient_name || 'Unknown patient');
                appendCell(row, formatDateTime(visit.arrived_at));
                const emergencyCell = appendCell(row, visit.is_emergency ? 'Emergency' : 'No');
                emergencyCell.className = visit.is_emergency ? 'nursing-emergency' : '';
                appendCell(row, visit.visit_source.replaceAll('_', ' '));

                const statusCell = appendCell(row, '');
                const status = document.createElement('span');
                status.className = 'status-badge';
                status.textContent = visit.assessment_id
                    ? (visit.decision === 'minor_illness' ? 'Minor treatment pending' : 'Decision pending')
                    : visit.status.replaceAll('_', ' ');
                statusCell.appendChild(status);

                const actionCell = appendCell(row, '', 'nursing-action-cell');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'status-toggle';
                button.textContent = visit.assessment_id
                    ? (visit.decision === 'minor_illness' ? 'Record treatment' : 'Continue decision')
                    : 'Open assessment';
                button.addEventListener('click', () => openVisit(visit.visit_id));
                actionCell.appendChild(button);
                body.appendChild(row);
            });
        } catch (error) {
            emptyRow(body, 7, error.message);
        }
    }

    function setPatientSummary(patient, visit) {
        const summary = document.querySelector('[data-patient-summary]');
        summary.replaceChildren();
        [
            ['Patient number', patient.patient_number],
            ['Full name', patient.full_name || 'Unknown emergency patient'],
            ['Date of birth', patient.date_of_birth || '—'],
            ['Gender', patient.gender],
            ['Visit date', formatDateTime(visit.arrived_at)],
            ['Visit source', visit.visit_source.replaceAll('_', ' ')],
            ['Current status', visit.status.replaceAll('_', ' ')],
        ].forEach(([label, value]) => {
            const item = document.createElement('div');
            item.className = 'nursing-summary-item';
            const term = document.createElement('span');
            term.textContent = label;
            const description = document.createElement('strong');
            description.textContent = value || '—';
            item.append(term, description);
            summary.appendChild(item);
        });
        document.querySelector('[data-assessment-visit-summary]').textContent =
            `Visit #${visit.visit_id} · ${formatDateTime(visit.arrived_at)}`;
    }

    function renderHistory(history) {
        const body = document.querySelector('[data-history-rows]');
        body.replaceChildren();
        if (history.length === 0) {
            emptyRow(body, 4, 'No previous visits are recorded.');
            return;
        }
        history.forEach((visit) => {
            const row = document.createElement('tr');
            appendCell(row, formatDateTime(visit.arrived_at));
            appendCell(row, visit.visit_source.replaceAll('_', ' '));
            appendCell(row, visit.status.replaceAll('_', ' '));
            const assessment = visit.assessment;
            const text = assessment
                ? [assessment.complaint, assessment.decision && assessment.decision.replaceAll('_', ' ')]
                    .filter(Boolean)
                    .join(' · ')
                : 'No nursing assessment';
            appendCell(row, text || '—');
            body.appendChild(row);
        });
    }

    function fillAssessment(assessment) {
        const form = document.querySelector('[data-assessment-form]');
        form.reset();
        if (!assessment) return;
        form.elements.complaint.value = assessment.complaint || '';
        form.elements.symptoms.value = assessment.symptoms || '';
        form.elements.symptom_duration.value = assessment.symptom_duration || '';
        form.elements.temperature.value = assessment.temperature || '';
        form.elements.pulse.value = assessment.pulse || '';
        form.elements.respiratory_rate.value = assessment.respiratory_rate || '';
        form.elements.oxygen_saturation.value = assessment.oxygen_saturation || '';
        form.elements.weight.value = assessment.weight || '';
        form.elements.height.value = assessment.height || '';
        form.elements.nursing_notes.value = assessment.notes || '';
        if (assessment.blood_pressure) {
            const [systolic, diastolic] = assessment.blood_pressure.split('/');
            form.elements.systolic.value = systolic || '';
            form.elements.diastolic.value = diastolic || '';
        }
    }

    function toggleAssessmentReadOnly(readOnly) {
        const form = document.querySelector('[data-assessment-form]');
        form.querySelectorAll('input, textarea, select').forEach((input) => {
            input.disabled = readOnly;
        });
        document.querySelector('[data-save-assessment]').hidden = readOnly;
        document.querySelector('[data-save-hint]').textContent = readOnly
            ? 'Assessment saved. Clinical entries are not editable after saving.'
            : '';
    }

    function renderTreatmentItems() {
        const container = document.querySelector('[data-treatment-items]');
        container.replaceChildren();
        addTreatmentItem();
    }

    function addTreatmentItem() {
        const container = document.querySelector('[data-treatment-items]');
        const row = document.createElement('div');
        row.className = 'treatment-item-row';

        const medicineLabel = document.createElement('label');
        medicineLabel.textContent = 'Approved low-schedule medicine';
        const medicineSelect = document.createElement('select');
        medicineSelect.name = 'medicine_id';
        medicineSelect.required = true;
        medicineSelect.add(new Option('Choose a medicine', ''));
        medicineCatalogue.forEach((medicine) => {
            const stock = Number(medicine.quantity_in_stock);
            const option = new Option(
                `${medicine.medicine_name} · stock ${medicine.quantity_in_stock} · ${medicine.unit_price} per unit${medicine.is_demo ? ' · DEMO' : ''}`,
                String(medicine.medicine_id)
            );
            option.disabled = stock <= 0;
            medicineSelect.add(option);
        });
        medicineLabel.appendChild(medicineSelect);

        const quantityLabel = document.createElement('label');
        quantityLabel.textContent = 'Quantity';
        const quantityInput = document.createElement('input');
        quantityInput.name = 'quantity';
        quantityInput.type = 'number';
        quantityInput.min = '0.01';
        quantityInput.max = '10000';
        quantityInput.step = '0.01';
        quantityInput.required = true;
        quantityLabel.appendChild(quantityInput);

        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'status-toggle';
        removeButton.textContent = 'Remove';
        removeButton.setAttribute('aria-label', 'Remove treatment medicine');
        removeButton.addEventListener('click', () => {
            row.remove();
            if (container.children.length === 0) addTreatmentItem();
        });
        row.append(medicineLabel, quantityLabel, removeButton);
        container.appendChild(row);
    }

    function displayAssessmentState(data) {
        const assessment = data.assessment;
        const decisionSection = document.querySelector('[data-decision-section]');
        const treatmentSection = document.querySelector('[data-treatment-section]');
        decisionSection.hidden = true;
        treatmentSection.hidden = true;
        document.querySelector('[data-decision-form]').reset();
        document.querySelector('[data-treatment-form]').reset();
        document.querySelector('[data-test-options]').hidden = true;
        renderTreatmentItems();

        if (!assessment) {
            toggleAssessmentReadOnly(false);
            return;
        }
        fillAssessment(assessment);
        toggleAssessmentReadOnly(true);
        if (assessment.decision === 'minor_illness') {
            treatmentSection.hidden = false;
            return;
        }
        decisionSection.hidden = false;
    }

    async function openVisit(visitId) {
        hideMessage();
        try {
            const data = await parseRequest(
                `/api/nursing/visits/${encodeURIComponent(visitId)}`,
                {},
                'Could not open this nursing visit.'
            );
            activeVisitId = Number(visitId);
            setPatientSummary(data.patient, data.visit);
            renderHistory(data.history);
            displayAssessmentState(data);
            const panel = document.querySelector('[data-assessment-panel]');
            panel.hidden = false;
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            showMessage(error.message);
            await refreshDashboard();
        }
    }

    function numericOrNull(value) {
        return value === '' ? null : Number(value);
    }

    async function saveAssessment(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const button = document.querySelector('[data-save-assessment]');
        if (!form.reportValidity() || activeVisitId === null) return;
        hideMessage();
        const values = new FormData(form);
        const payload = {
            complaint: values.get('complaint').trim(),
            symptoms: values.get('symptoms').trim(),
            symptom_duration: values.get('symptom_duration').trim(),
            nursing_notes: values.get('nursing_notes').trim(),
            systolic: numericOrNull(values.get('systolic')),
            diastolic: numericOrNull(values.get('diastolic')),
            temperature: numericOrNull(values.get('temperature')),
            pulse: numericOrNull(values.get('pulse')),
            respiratory_rate: numericOrNull(values.get('respiratory_rate')),
            oxygen_saturation: numericOrNull(values.get('oxygen_saturation')),
            weight: numericOrNull(values.get('weight')),
            height: numericOrNull(values.get('height')),
        };

        setBusy(button, true, 'Saving assessment…');
        try {
            await parseRequest(
                `/api/nursing/visits/${activeVisitId}/assessment`,
                { method: 'POST', body: JSON.stringify(payload) },
                'Could not save the nursing assessment.'
            );
            await openVisit(activeVisitId);
            await refreshDashboard(false);
            showMessage('Assessment saved. Select the nursing decision below.', 'success');
        } catch (error) {
            showMessage(error.message);
        } finally {
            setBusy(button, false);
        }
    }

    function selectedTestTypes() {
        return [...document.querySelectorAll('[data-test-options] input[name="test_types"]:checked')]
            .map((input) => input.value);
    }

    async function submitDecision(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const button = document.querySelector('[data-submit-decision]');
        if (!form.reportValidity() || activeVisitId === null) return;
        const values = new FormData(form);
        const decision = values.get('decision');
        const testTypes = decision === 'tests' ? selectedTestTypes() : [];
        if (decision === 'tests' && testTypes.length === 0) {
            showMessage('Select at least one approved in-house test.');
            return;
        }
        const labels = {
            minor_illness: 'minor illness treatment, using only the approved low-schedule medicine list',
            tests: `the testing centre (${testTypes.map((type) => type.replaceAll('_', ' ')).join(', ')})`,
            general_doctor: 'the general doctor queue',
        };
        if (!window.confirm(`Submit this assessment outcome and route the patient to ${labels[decision]}?`)) {
            return;
        }

        setBusy(button, true, 'Submitting decision…');
        hideMessage();
        try {
            const data = await parseRequest(
                `/api/nursing/visits/${activeVisitId}/decision`,
                { method: 'POST', body: JSON.stringify({ decision, test_types: testTypes }) },
                'Could not submit the nursing decision.'
            );
            if (decision === 'minor_illness') {
                await openVisit(activeVisitId);
                showMessage('Minor illness outcome recorded. Add the treatment details.', 'success');
            } else {
                document.querySelector('[data-assessment-panel]').hidden = true;
                activeVisitId = null;
                showMessage(
                    decision === 'tests'
                        ? `Patient routed to testing. ${testTypes.length} approved test order(s) created.`
                        : 'Patient routed to the general doctor queue.',
                    'success'
                );
            }
            await refreshDashboard(false);
            if (data.status === 'awaiting_minor_treatment') {
                document.querySelector('[data-treatment-section]').hidden = false;
            }
        } catch (error) {
            showMessage(error.message);
        } finally {
            setBusy(button, false);
        }
    }

    async function saveMinorTreatment(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const button = document.querySelector('[data-save-treatment]');
        if (!form.reportValidity() || activeVisitId === null) return;
        const rows = [...document.querySelectorAll('.treatment-item-row')];
        const items = rows.map((row) => ({
            medicine_id: Number(row.querySelector('[name="medicine_id"]').value),
            quantity: Number(row.querySelector('[name="quantity"]').value),
        }));
        if (items.some((item) => !item.medicine_id || !Number.isFinite(item.quantity))) {
            showMessage('Select an approved medicine and enter a valid quantity for each item.');
            return;
        }
        if (!window.confirm('Record the low-schedule treatment, update the medicine stock and add the charges to the visit bill for pharmacy payment?')) {
            return;
        }

        const values = new FormData(form);
        setBusy(button, true, 'Recording treatment…');
        hideMessage();
        try {
            const result = await parseRequest(
                `/api/nursing/visits/${activeVisitId}/minor-treatment`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        items,
                        treatment_notes: values.get('treatment_notes').trim(),
                    }),
                },
                'Could not record minor illness treatment.'
            );
            document.querySelector('[data-assessment-panel]').hidden = true;
            activeVisitId = null;
            showMessage(
                `Treatment recorded. Visit bill total: ${result.total_amount}. Routed to pharmacy payment.`,
                'success'
            );
            await refreshDashboard(false);
        } catch (error) {
            showMessage(error.message);
        } finally {
            setBusy(button, false);
        }
    }

    async function refreshDashboard(showErrors = true) {
        try {
            await loadDashboard();
        } catch (error) {
            if (showErrors) showMessage(error.message);
        }
    }

    function bindQueueSearch() {
        const input = document.querySelector('[data-queue-search]');
        input.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(
                () => loadQueue(input.value.trim()),
                250
            );
        });
    }

    function bindDecisionVisibility() {
        document.querySelectorAll('[data-decision-form] input[name="decision"]').forEach((input) => {
            input.addEventListener('change', () => {
                const testOptions = document.querySelector('[data-test-options]');
                testOptions.hidden = input.value !== 'tests' || !input.checked;
            });
        });
    }

    function bindLogout() {
        document.querySelector('[data-sign-out]').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            setBusy(button, true, 'Signing out…');
            try {
                await parseRequest('/api/auth/logout', {
                    method: 'POST',
                    body: JSON.stringify({}),
                }, 'Could not sign out.');
            } finally {
                window.location.replace(`${projectBase}auth/login.php`);
            }
        });
    }

    async function init() {
        document.querySelector('[data-current-date]').textContent =
            new Intl.DateTimeFormat(undefined, {
                weekday: 'long',
                month: 'long',
                day: 'numeric',
            }).format(new Date());
        document.querySelector('[data-close-assessment]').addEventListener('click', () => {
            document.querySelector('[data-assessment-panel]').hidden = true;
            activeVisitId = null;
        });
        document.querySelector('[data-assessment-form]').addEventListener('submit', saveAssessment);
        document.querySelector('[data-decision-form]').addEventListener('submit', submitDecision);
        document.querySelector('[data-treatment-form]').addEventListener('submit', saveMinorTreatment);
        document.querySelector('[data-add-treatment-item]').addEventListener('click', addTreatmentItem);
        bindQueueSearch();
        bindDecisionVisibility();
        bindLogout();

        try {
            if (!await checkSession()) return;
            await loadDashboard();
        } catch (error) {
            showMessage(error.message);
        }
    }

    init();
})();
