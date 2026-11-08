'use strict';

(() => {
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    const nursingPage = document.body.dataset.nursingPage || 'overview';
    const messageElement = document.querySelector('[data-nursing-message]');
    let csrfToken = '';
    let currentUserId = null;
    let currentUserRole = '';
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
        currentUserId = Number(payload.data.user.user_id);
        currentUserRole = role;
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

    function pretty(value) {
        return value ? String(value).replaceAll('_', ' ') : '—';
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
        if (document.querySelector('[data-queue-rows]')) await loadQueue();
    }

    async function loadQueue(
        search = document.querySelector('[data-queue-search]').value.trim(),
        filter = document.querySelector('[data-queue-filter]').value
    ) {
        const body = document.querySelector('[data-queue-rows]');
        emptyRow(body, 10, 'Loading nursing queue…');
        try {
            const data = await parseRequest(
                '/api/nursing/queue',
                { query: { search, filter } },
                'Could not load the nursing queue.'
            );
            const drafts = data.visits.filter(
                (visit) => visit.assessment_id !== null && visit.decision === null
            );
            const incomingVisits = data.visits.filter(
                (visit) => visit.assessment_id === null
                    || (visit.decision === 'minor_illness' && !visit.has_treatment)
            );
            body.replaceChildren();
            if (incomingVisits.length === 0) {
                emptyRow(body, 10, 'No eligible patients are currently waiting for nursing.');
            }
            incomingVisits.forEach((visit) => {
                const row = document.createElement('tr');
                appendCell(row, `#${visit.visit_id}`);
                appendCell(row, visit.patient_number);
                appendCell(row, visit.patient_name || 'Unknown patient');
                appendCell(
                    row,
                    visit.age === null ? visit.gender : `${visit.age} · ${visit.gender}`
                );
                appendCell(row, visit.visit_source.replaceAll('_', ' '));
                appendCell(row, formatDateTime(visit.arrived_at));
                const statusCell = appendCell(row, '');
                const status = document.createElement('span');
                status.className = 'status-badge';
                status.textContent = visit.assessment_id
                    ? (visit.decision === 'minor_illness' ? 'Minor treatment pending' : 'Decision pending')
                    : visit.status.replaceAll('_', ' ');
                statusCell.appendChild(status);

                const paymentCell = appendCell(row, '');
                const paymentBadge = document.createElement('span');
                paymentBadge.className = `status-badge${visit.ticket_payment_confirmed ? '' : ' inactive'}`;
                paymentBadge.textContent = visit.ticket_payment_confirmed
                    ? 'Confirmed'
                    : 'Not found';
                paymentCell.appendChild(paymentBadge);

                appendCell(row, visit.assigned_nurse_name || 'Unassigned');
                const actionCell = appendCell(row, '', 'nursing-action-cell');
                if (currentUserRole === 'nurse') {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'status-toggle';
                    const assignedToMe = Number(visit.assigned_nurse_user_id) === currentUserId;
                    button.textContent = assignedToMe
                        ? (visit.assessment_id
                            ? (visit.decision === 'minor_illness' ? 'Record treatment' : 'Continue assessment')
                            : 'Start assessment')
                        : (visit.assigned_nurse_user_id ? 'View record' : 'Start assessment');
                    button.addEventListener('click', () => {
                        if (!visit.assigned_nurse_user_id || assignedToMe) {
                            startAssessment(visit, button);
                        } else {
                            window.location.href = `assessment.php?visit_id=${encodeURIComponent(visit.visit_id)}`;
                        }
                    });
                    actionCell.appendChild(button);
                } else {
                    const viewButton = document.createElement('button');
                    viewButton.type = 'button';
                    viewButton.className = 'status-toggle';
                    viewButton.textContent = 'View record';
                    viewButton.addEventListener('click', () => {
                        window.location.href = `assessment.php?visit_id=${encodeURIComponent(visit.visit_id)}`;
                    });
                    actionCell.appendChild(viewButton);
                    if (!visit.assessment_id) {
                        const reassignButton = document.createElement('button');
                        reassignButton.type = 'button';
                        reassignButton.className = 'status-toggle activate';
                        reassignButton.textContent = visit.assigned_nurse_user_id
                            ? 'Reassign'
                            : 'Assign nurse';
                        reassignButton.addEventListener('click', () => reassignVisit(visit));
                        actionCell.append(document.createTextNode(' '), reassignButton);
                    }
                }
                body.appendChild(row);
            });

            const draftBody = document.querySelector('[data-draft-rows]');
            if (draftBody) {
                draftBody.replaceChildren();
                if (drafts.length === 0) {
                    emptyRow(draftBody, 7, 'No saved drafts are waiting to be resumed.');
                } else {
                    drafts.forEach((visit) => {
                        const row = document.createElement('tr');
                        appendCell(row, `#${visit.visit_id}`);
                        appendCell(row, `${visit.patient_name || 'Unknown patient'} · ${visit.patient_number}`);
                        appendCell(row, visit.visit_source.replaceAll('_', ' '));
                        appendCell(row, formatDateTime(visit.arrived_at));
                        appendCell(row, formatDateTime(visit.nursing_started_at));
                        appendCell(row, visit.assigned_nurse_name || 'Unassigned');
                        const action = appendCell(row, '', 'nursing-action-cell');
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'status-toggle';
                        const assignedToMe = Number(visit.assigned_nurse_user_id) === currentUserId;
                        button.textContent = currentUserRole === 'nurse' && assignedToMe
                            ? 'Resume draft'
                            : 'View draft';
                        button.addEventListener('click', () => {
                            window.location.href = `assessment.php?visit_id=${encodeURIComponent(visit.visit_id)}`;
                        });
                        action.appendChild(button);
                        draftBody.appendChild(row);
                    });
                }
            }
        } catch (error) {
            emptyRow(body, 10, error.message);
            const draftBody = document.querySelector('[data-draft-rows]');
            if (draftBody) emptyRow(draftBody, 7, error.message);
        }
    }

    function setPatientSummary(patient, visit, assessment) {
        const summary = document.querySelector('[data-patient-summary]');
        summary.replaceChildren();
        [
            ['Patient number', patient.patient_number],
            ['Full name', patient.full_name || 'Unknown emergency patient'],
            ['Date of birth', patient.date_of_birth || '—'],
            ['Gender', patient.gender],
            ['Phone', patient.phone],
            ['Address', patient.address],
            ['Visit ID', `#${visit.visit_id}`],
            ['Arrival time', formatDateTime(visit.arrived_at)],
            ['Visit source', visit.visit_source.replaceAll('_', ' ')],
            ['Visit reason', visit.reason_for_visit],
            ['Reception notes', visit.reception_notes],
            ['Source notes', visit.source_notes],
            ['Appointment reason', visit.appointment_reason],
            ['Assigned nurse', visit.assigned_nurse_name || 'Unassigned'],
            ['Assessment nurse', assessment ? visit.assigned_nurse_name : 'Not recorded'],
            ['Assessment date', assessment ? formatDateTime(assessment.assessed_at) : 'Not recorded'],
            ['Assessment started', formatDateTime(visit.nursing_started_at)],
            ['Ticket payment', visit.ticket_payment_confirmed ? 'Confirmed' : 'Not found'],
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
                ? [
                    `Complaint: ${assessment.complaint || '—'}`,
                    assessment.symptoms && `Symptoms: ${assessment.symptoms}`,
                    assessment.symptom_duration && `Duration: ${assessment.symptom_duration}`,
                    assessment.decision && `Outcome: ${assessment.decision.replaceAll('_', ' ')}`,
                    assessment.blood_pressure && `BP: ${assessment.blood_pressure}`,
                    assessment.pulse !== null && assessment.pulse !== undefined && `Pulse: ${assessment.pulse}`,
                    assessment.temperature !== null && assessment.temperature !== undefined
                        && `Temperature: ${assessment.temperature}`,
                    assessment.respiratory_rate !== null && assessment.respiratory_rate !== undefined
                        && `Respiratory rate: ${assessment.respiratory_rate}`,
                    assessment.oxygen_saturation !== null && assessment.oxygen_saturation !== undefined
                        && `Oxygen saturation: ${assessment.oxygen_saturation}`,
                    assessment.weight !== null && assessment.weight !== undefined
                        && `Weight: ${assessment.weight}`,
                    assessment.height !== null && assessment.height !== undefined
                        && `Height: ${assessment.height}`,
                    assessment.notes && `Notes: ${assessment.notes}`,
                    assessment.assessed_by && `Nurse: ${assessment.assessed_by}`,
                    assessment.assessed_at && `Recorded: ${formatDateTime(assessment.assessed_at)}`,
                ].filter(Boolean).join('\n')
                : 'No nursing assessment';
            appendCell(row, text || '—');
            body.appendChild(row);
        });
    }

    function renderDoctorNotes(notes) {
        const body = document.querySelector('[data-doctor-history-rows]');
        body.replaceChildren();
        if (!notes.length) {
            emptyRow(body, 4, 'No previous doctor notes are recorded.');
            return;
        }
        notes.forEach((note) => {
            const row = document.createElement('tr');
            appendCell(row, `#${note.visit_id} · ${formatDateTime(note.consulted_at || note.arrived_at)}`);
            appendCell(row, note.consultation_type.replaceAll('_', ' '));
            appendCell(
                row,
                [
                    note.presenting_complaint && `Complaint: ${note.presenting_complaint}`,
                    note.clinical_observations && `Observations: ${note.clinical_observations}`,
                    note.examination_findings && `Examination: ${note.examination_findings}`,
                ].filter(Boolean).join('\n') || '—'
            );
            appendCell(
                row,
                [
                    note.diagnosis_text && `Diagnosis: ${note.diagnosis_text}`,
                    note.treatment_plan && `Plan: ${note.treatment_plan}`,
                    note.notes && `Notes: ${note.notes}`,
                ].filter(Boolean).join('\n') || '—'
            );
            body.appendChild(row);
        });
    }

    function renderTestResults(results) {
        const body = document.querySelector('[data-test-history-rows]');
        body.replaceChildren();
        if (!results.length) {
            emptyRow(body, 4, 'No previous test results are recorded.');
            return;
        }
        results.forEach((result) => {
            const row = document.createElement('tr');
            appendCell(row, `#${result.visit_id} · ${formatDateTime(result.recorded_at)}`);
            appendCell(row, `${result.test_type.replaceAll('_', ' ')} · ${result.department}`);
            appendCell(row, `${result.result_status} · order ${result.order_status}`);
            appendCell(
                row,
                [result.result_text, result.result_notes].filter(Boolean).join('\n') || '—'
            );
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
        const assignedToMe = currentUserRole === 'nurse'
            && Number(data.visit.assigned_nurse_user_id) === currentUserId;
        decisionSection.hidden = true;
        treatmentSection.hidden = true;
        document.querySelector('[data-decision-form]').reset();
        document.querySelector('[data-treatment-form]').reset();
        document.querySelector('[data-test-options]').hidden = true;
        renderTreatmentItems();

        if (!assessment) {
            toggleAssessmentReadOnly(!assignedToMe);
            document.querySelector('[data-save-hint]').textContent = assignedToMe
                ? ''
                : 'This record is read-only. Start the assessment as the assigned nurse before recording clinical information.';
            return;
        }
        fillAssessment(assessment);
        toggleAssessmentReadOnly(true);
        if (assessment.decision === 'minor_illness') {
            treatmentSection.hidden = !assignedToMe;
            return;
        }
        decisionSection.hidden = !assignedToMe;
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
            if (currentUserRole === 'nurse'
                && data.visit.status === 'awaiting_nurse'
                && !data.visit.assigned_nurse_user_id) {
                await parseRequest(
                    `/api/nursing/visits/${encodeURIComponent(visitId)}/start`,
                    { method: 'POST', body: JSON.stringify({}) },
                    'Could not take responsibility for this visit.'
                );
                return openVisit(visitId);
            }
            setPatientSummary(data.patient, data.visit, data.assessment);
            renderHistory(data.history);
            renderDoctorNotes(data.doctor_notes);
            renderTestResults(data.test_results);
            document.querySelector('[data-allergies]').textContent =
                data.allergies_supported
                    ? (data.allergies.length ? data.allergies.join(', ') : 'No allergies recorded.')
                    : 'Allergy records are not supported by the current database schema.';
            displayAssessmentState(data);
            const panel = document.querySelector('[data-assessment-panel]');
            panel.hidden = false;
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            showMessage(error.message);
            await refreshDashboard();
        }
    }

    async function startAssessment(visit, button) {
        setBusy(button, true, 'Starting…');
        try {
            await parseRequest(
                `/api/nursing/visits/${visit.visit_id}/start`,
                { method: 'POST', body: JSON.stringify({}) },
                'Could not start this assessment.'
            );
            window.location.href = `assessment.php?visit_id=${encodeURIComponent(visit.visit_id)}`;
        } catch (error) {
            if (error.status === 409) {
                showMessage('Another nurse has already taken this visit. The queue has been refreshed.');
                await loadQueue();
            } else {
                showMessage(error.message);
            }
        } finally {
            setBusy(button, false);
        }
    }

    async function reassignVisit(visit) {
        try {
            const data = await parseRequest(
                '/api/nursing/nurses',
                {},
                'Could not load active nurses for reassignment.'
            );
            if (!data.nurses.length) {
                showMessage('No active nurse accounts are available for assignment.');
                return;
            }
            const choices = data.nurses
                .map((nurse) => `${nurse.user_id}: ${nurse.display_name}`)
                .join('\n');
            const answer = window.prompt(
                `Enter the active nurse ID for visit #${visit.visit_id}:\n${choices}`,
                visit.assigned_nurse_user_id ? String(visit.assigned_nurse_user_id) : ''
            );
            if (answer === null) return;
            const nurseId = Number(answer.trim());
            if (!data.nurses.some((nurse) => nurse.user_id === nurseId)) {
                showMessage('Choose one of the active nurse IDs shown in the list.');
                return;
            }
            await parseRequest(
                `/api/nursing/visits/${visit.visit_id}/reassign`,
                {
                    method: 'POST',
                    body: JSON.stringify({ nurse_user_id: nurseId }),
                },
                'Could not reassign this visit.'
            );
            await refreshDashboard(false);
            showMessage(`Visit #${visit.visit_id} was reassigned to nurse ${nurseId}.`, 'success');
        } catch (error) {
            showMessage(error.message);
            await loadQueue();
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

    function renderCompletedAssessments(assessments) {
        const body = document.querySelector('[data-completed-rows]');
        if (!body) return;
        body.replaceChildren();
        if (!assessments.length) {
            emptyRow(body, 7, 'No completed nursing assessments match this search.');
            return;
        }
        assessments.forEach((assessment) => {
            const row = document.createElement('tr');
            appendCell(row, `#${assessment.visit_id} · ${assessment.patient_name}`);
            appendCell(row, `${formatDateTime(assessment.arrived_at)} · ${assessment.visit_source.replaceAll('_', ' ')}`);
            appendCell(row, formatDateTime(assessment.assessed_at));
            appendCell(row, assessment.nurse_name);
            appendCell(row, [
                assessment.complaint && `Complaint: ${assessment.complaint}`,
                assessment.symptoms && `Symptoms: ${assessment.symptoms}`,
                assessment.symptom_duration && `Duration: ${assessment.symptom_duration}`,
                assessment.blood_pressure && `BP: ${assessment.blood_pressure}`,
                assessment.pulse !== null && `Pulse: ${assessment.pulse}`,
                assessment.temperature !== null && `Temperature: ${assessment.temperature}`,
                assessment.respiratory_rate !== null && `Respiratory rate: ${assessment.respiratory_rate}`,
                assessment.oxygen_saturation !== null && `Oxygen saturation: ${assessment.oxygen_saturation}`,
                assessment.weight !== null && `Weight: ${assessment.weight}`,
                assessment.height !== null && `Height: ${assessment.height}`,
                assessment.notes && `Observations: ${assessment.notes}`,
            ].filter(Boolean).join('\n') || '—');
            appendCell(row, [
                pretty(assessment.decision),
                assessment.minor_treatment_notes,
                assessment.treatment_summary && `Treatment: ${assessment.treatment_summary}`,
            ].filter(Boolean).join('\n'));
            appendCell(row, pretty(assessment.visit_status));
            body.appendChild(row);
        });
    }

    async function loadCompletedAssessments(search = '') {
        const body = document.querySelector('[data-completed-rows]');
        if (!body) return;
        emptyRow(body, 7, 'Loading completed assessments…');
        try {
            const data = await parseRequest(
                '/api/nursing/completed',
                { query: { search } },
                'Could not load completed nursing assessments.'
            );
            renderCompletedAssessments(data.assessments);
        } catch (error) {
            emptyRow(body, 7, error.message);
        }
    }

    function renderDoctorReferrals(referrals) {
        const body = document.querySelector('[data-referral-rows]');
        if (!body) return;
        body.replaceChildren();
        if (!referrals.length) {
            emptyRow(body, 6, 'No nursing referrals to the Doctor queue match this search.');
            return;
        }
        referrals.forEach((referral) => {
            const row = document.createElement('tr');
            appendCell(row, `#${referral.visit_id} · ${referral.patient_name} · ${referral.patient_number}`);
            appendCell(row, referral.visit_source.replaceAll('_', ' '));
            appendCell(row, formatDateTime(referral.referral_at));
            appendCell(row, referral.nurse_name);
            appendCell(row, [
                referral.complaint && `Reason: ${referral.complaint}`,
                referral.symptoms && `Symptoms: ${referral.symptoms}`,
                referral.assessment_findings && `Findings: ${referral.assessment_findings}`,
            ].filter(Boolean).join('\n') || '—');
            appendCell(row, pretty(referral.visit_status));
            body.appendChild(row);
        });
    }

    async function loadDoctorReferrals(search = '') {
        const body = document.querySelector('[data-referral-rows]');
        if (!body) return;
        emptyRow(body, 6, 'Loading doctor referrals…');
        try {
            const data = await parseRequest(
                '/api/nursing/referrals',
                { query: { search } },
                'Could not load nursing doctor referrals.'
            );
            renderDoctorReferrals(data.referrals);
        } catch (error) {
            emptyRow(body, 6, error.message);
        }
    }

    function bindListSearch(selector, loader) {
        const input = document.querySelector(selector);
        if (!input) return;
        input.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => loader(input.value.trim()), 250);
        });
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
        if (!input) return;
        input.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(
                () => loadQueue(input.value.trim()),
                250
            );
        });
        document.querySelector('[data-queue-filter]').addEventListener('change', () => loadQueue());
        document.querySelector('[data-refresh-queue]').addEventListener('click', () => {
            refreshDashboard(false);
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
        const button = document.querySelector('[data-sign-out]');
        if (!button) return;
        button.addEventListener('click', async (event) => {
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
        const currentDate = document.querySelector('[data-current-date]');
        if (currentDate) {
            currentDate.textContent = new Intl.DateTimeFormat(undefined, {
                weekday: 'long',
                month: 'long',
                day: 'numeric',
            }).format(new Date());
        }
        bindLogout();

        try {
            if (!await checkSession()) return;
            if (nursingPage === 'overview') {
                await loadDashboard();
                window.setInterval(() => refreshDashboard(false), 15000);
                return;
            }
            if (nursingPage === 'queue') {
                bindQueueSearch();
                await loadDashboard();
                window.setInterval(() => refreshDashboard(false), 15000);
                return;
            }
            if (nursingPage === 'completed') {
                bindListSearch('[data-completed-search]', loadCompletedAssessments);
                document.querySelector('[data-refresh-completed]')?.addEventListener(
                    'click',
                    () => loadCompletedAssessments(
                        document.querySelector('[data-completed-search]').value.trim()
                    )
                );
                await loadCompletedAssessments();
                return;
            }
            if (nursingPage === 'referrals') {
                bindListSearch('[data-referral-search]', loadDoctorReferrals);
                document.querySelector('[data-refresh-referrals]')?.addEventListener(
                    'click',
                    () => loadDoctorReferrals(
                        document.querySelector('[data-referral-search]').value.trim()
                    )
                );
                await loadDoctorReferrals();
                return;
            }

            document.querySelector('[data-close-assessment]')?.addEventListener('click', () => {
                window.location.href = 'queue.php';
            });
            document.querySelector('[data-assessment-form]')?.addEventListener('submit', saveAssessment);
            document.querySelector('[data-decision-form]')?.addEventListener('submit', submitDecision);
            document.querySelector('[data-treatment-form]')?.addEventListener('submit', saveMinorTreatment);
            document.querySelector('[data-add-treatment-item]')?.addEventListener('click', addTreatmentItem);
            bindDecisionVisibility();
            await loadDashboard();
            const visitId = new URLSearchParams(window.location.search).get('visit_id');
            if (!visitId || !/^\d+$/.test(visitId)) {
                showMessage('Open an eligible visit from the nursing queue.');
                return;
            }
            await openVisit(visitId);
        } catch (error) {
            showMessage(error.message);
        }
    }

    init();
})();
