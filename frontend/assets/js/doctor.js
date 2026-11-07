'use strict';

(() => {
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    const messageElement = document.querySelector('[data-doctor-message]');
    const queueBody = document.querySelector('[data-queue-rows]');
    const recordElement = document.querySelector('[data-doctor-record]');
    const consultationForm = document.querySelector('[data-consultation-form]');
    const diagnosisForm = document.querySelector('[data-diagnosis-form]');
    let csrfToken = '';
    let activeVisitId = null;
    let medicines = [];
    let specialists = [];
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
        if (!['general_doctor', 'system_administrator'].includes(payload.data.user.role.name)) {
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

    function label(value) {
        return value ? String(value).replaceAll('_', ' ') : '—';
    }

    function appendCell(row, value, className = '') {
        const cell = document.createElement('td');
        if (className) cell.className = className;
        cell.textContent = value === null || value === '' ? '—' : String(value);
        row.appendChild(cell);
        return cell;
    }

    function emptyRow(message) {
        queueBody.replaceChildren();
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 8;
        cell.className = 'table-message';
        cell.textContent = message;
        row.appendChild(cell);
        queueBody.appendChild(row);
    }

    function appendDefinitionList(target, pairs) {
        target.replaceChildren();
        pairs.forEach(([term, value]) => {
            const dt = document.createElement('dt');
            dt.textContent = term;
            const dd = document.createElement('dd');
            dd.textContent = value === null || value === '' ? '—' : String(value);
            target.append(dt, dd);
        });
    }

    function addTextCard(target, title, lines) {
        const card = document.createElement('article');
        card.className = 'doctor-history-item';
        const strong = document.createElement('strong');
        strong.textContent = title;
        card.appendChild(strong);
        lines.forEach((line) => {
            if (line === null || line === undefined || line === '') return;
            const p = document.createElement('p');
            p.textContent = String(line);
            card.appendChild(p);
        });
        target.appendChild(card);
    }

    async function loadDashboard() {
        const data = await parseRequest(
            '/api/doctor/dashboard',
            {},
            'Could not load the doctor dashboard.'
        );
        Object.entries(data.stats).forEach(([key, value]) => {
            const element = document.querySelector(`[data-stat="${key}"]`);
            if (element) element.textContent = String(value);
        });
        medicines = data.medicines;
        specialists = data.specialists;
        await loadQueue();
    }

    async function loadQueue(search = '') {
        emptyRow('Loading consultation queue…');
        try {
            const data = await parseRequest(
                '/api/doctor/queue',
                { query: search ? { search } : {} },
                'Could not load the consultation queue.'
            );
            queueBody.replaceChildren();
            if (data.visits.length === 0) {
                emptyRow('No eligible visits are currently waiting for doctor consultation.');
                return;
            }
            data.visits.forEach((visit) => {
                const row = document.createElement('tr');
                appendCell(row, visit.patient_number);
                appendCell(row, visit.patient_name);
                appendCell(row, `#${visit.visit_id}`);
                appendCell(row, formatDateTime(visit.arrived_at));
                appendCell(row, label(visit.visit_source));
                const status = document.createElement('span');
                status.className = 'doctor-status';
                status.textContent = label(visit.status);
                const statusCell = appendCell(row, '');
                statusCell.appendChild(status);
                const markers = [
                    visit.has_assessment ? 'Assessment available' : 'No nurse assessment',
                    visit.has_results ? 'Results available' : 'No results yet',
                ];
                appendCell(row, markers.join(' · '));
                const actionCell = appendCell(row, '', 'doctor-action-cell');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button button-primary button-small';
                button.textContent = 'Open visit';
                button.addEventListener('click', () => openVisit(visit.visit_id));
                actionCell.appendChild(button);
                if (visit.is_emergency) {
                    row.classList.add('doctor-visit-emergency');
                }
                queueBody.appendChild(row);
            });
        } catch (error) {
            emptyRow(error.message);
        }
    }

    function renderPatientDetails(patient, visit) {
        appendDefinitionList(document.querySelector('[data-patient-details]'), [
            ['Patient number', patient.patient_number],
            ['Name', patient.full_name],
            ['Date of birth', patient.date_of_birth],
            ['Gender', patient.gender],
            ['Phone', patient.phone],
            ['Address', patient.address],
            ['Visit ID', visit.visit_id],
            ['Visit date', formatDateTime(visit.arrived_at)],
            ['Source', label(visit.visit_source)],
            ['Visit status', label(visit.status)],
            ['Emergency', visit.is_emergency ? 'Yes' : 'No'],
        ]);
    }

    function renderAssessment(assessment) {
        const target = document.querySelector('[data-nursing-details]');
        if (!assessment) {
            appendDefinitionList(target, [['Nursing assessment', 'No assessment recorded']]);
            return;
        }
        appendDefinitionList(target, [
            ['Main complaint', assessment.complaint],
            ['Symptoms', assessment.symptoms],
            ['Duration', assessment.symptom_duration],
            ['Temperature', assessment.temperature],
            ['Blood pressure', assessment.blood_pressure],
            ['Pulse', assessment.pulse],
            ['Respiratory rate', assessment.respiratory_rate],
            ['Oxygen saturation', assessment.oxygen_saturation],
            ['Weight', assessment.weight],
            ['Height', assessment.height],
            ['Nursing observations', assessment.notes],
            ['Nurse', assessment.nurse_name],
            ['Assessment date', formatDateTime(assessment.assessed_at)],
        ]);
    }

    function renderResults(results) {
        const target = document.querySelector('[data-test-results]');
        target.replaceChildren();
        if (!results.length) {
            const empty = document.createElement('p');
            empty.className = 'doctor-empty';
            empty.textContent = 'No test orders or results are recorded for this visit.';
            target.appendChild(empty);
            return;
        }
        results.forEach((item) => {
            const title = `${label(item.test_type)} · ${label(item.department)} · ${label(item.order_status)}`;
            const result = item.result;
            addTextCard(target, title, [
                `Ordered: ${formatDateTime(item.ordered_at)}`,
                result ? `Result (${label(result.status)}): ${result.text}` : 'No result recorded.',
                result && result.notes ? `Result notes: ${result.notes}` : '',
                result ? `Recorded: ${formatDateTime(result.recorded_at)}${result.recorded_by ? ` by ${result.recorded_by}` : ''}` : '',
                item.retest_of_order_id ? `Retest of order #${item.retest_of_order_id}` : '',
            ]);
        });
    }

    function renderHistory(history) {
        const target = document.querySelector('[data-visit-history]');
        target.replaceChildren();
        if (!history.length) {
            const empty = document.createElement('p');
            empty.className = 'doctor-empty';
            empty.textContent = 'No previous visit history is available.';
            target.appendChild(empty);
            return;
        }
        history.forEach((item) => {
            addTextCard(target, `Visit #${item.visit_id} · ${formatDateTime(item.arrived_at)}`, [
                `Source: ${label(item.visit_source)} · Status: ${label(item.status)}`,
                item.consultation ? `Consultation: ${item.consultation.notes || 'Recorded'} (${formatDateTime(item.consultation.consulted_at)})` : '',
                item.diagnosis
                    ? (item.diagnosis.diagnosis_made
                        ? `Diagnosis: ${item.diagnosis.description || 'Recorded'}`
                        : 'No diagnosis was made.')
                    : '',
                item.diagnosis ? `Treatment plan: ${item.diagnosis.treatment_plan || '—'}` : '',
            ]);
        });
    }

    function renderClinicalRecords(consultations, diagnoses) {
        const target = document.querySelector('[data-current-clinical-records]');
        target.replaceChildren();
        if (!consultations.length && !diagnoses.length) {
            const empty = document.createElement('p');
            empty.className = 'doctor-empty';
            empty.textContent = 'No consultations or diagnoses have been recorded for this visit.';
            target.appendChild(empty);
            return;
        }
        consultations.forEach((item) => {
            addTextCard(
                target,
                `Consultation #${item.consultation_id} · ${formatDateTime(item.consulted_at)}`,
                [
                    `Doctor: ${item.clinician_name || '—'}`,
                    `Complaint: ${item.presenting_complaint || '—'}`,
                    `Observations: ${item.clinical_observations || '—'}`,
                    `Examination: ${item.examination_findings || '—'}`,
                    `Notes: ${item.notes || '—'}`,
                    `Next step: ${label(item.next_step)}`,
                ]
            );
        });
        diagnoses.forEach((item) => {
            addTextCard(
                target,
                `Diagnosis record #${item.diagnosis_id} · ${formatDateTime(item.diagnosed_at)}`,
                [
                    item.diagnosis_made ? `Diagnosis: ${item.description || '—'}` : 'No diagnosis was made.',
                    `Clinical findings: ${item.clinical_findings || '—'}`,
                    `Treatment plan: ${item.treatment_plan || '—'}`,
                    `Notes: ${item.notes || '—'}`,
                ]
            );
        });
    }

    function populateSpecialists() {
        const select = diagnosisForm.elements.specialist_user_id;
        const selected = select.value;
        select.replaceChildren(new Option('Choose an active specialist', ''));
        specialists.forEach((specialist) => {
            const option = new Option(
                `${specialist.display_name} (${label(specialist.role_name)})`,
                String(specialist.user_id)
            );
            select.add(option);
        });
        select.value = selected;
    }

    function createPrescriptionItem() {
        const container = document.querySelector('[data-prescription-items]');
        const item = document.createElement('div');
        item.className = 'doctor-prescription-item';

        const medicineLabel = document.createElement('label');
        medicineLabel.className = 'doctor-medicine-field';
        medicineLabel.appendChild(document.createTextNode('Medicine'));
        const medicineSelect = document.createElement('select');
        medicineSelect.name = 'medicine_id';
        medicineSelect.appendChild(new Option('Choose a medicine', ''));
        medicines.forEach((medicine) => {
            medicineSelect.add(new Option(medicine.medicine_name, String(medicine.medicine_id)));
        });
        medicineLabel.appendChild(medicineSelect);

        const fields = [
            ['Dosage', 'dosage', 'text'],
            ['Frequency', 'frequency', 'text'],
            ['Duration', 'duration', 'text'],
            ['Quantity', 'quantity', 'number'],
            ['Instructions', 'instructions', 'text'],
        ];
        item.appendChild(medicineLabel);
        fields.forEach(([caption, name, type]) => {
            const field = document.createElement('label');
            if (name === 'instructions') field.className = 'doctor-instructions-field';
            field.appendChild(document.createTextNode(caption));
            const input = document.createElement('input');
            input.name = name;
            input.type = type;
            input.maxLength = name === 'instructions' ? 2000 : 100;
            if (name === 'quantity') {
                input.min = '0.01';
                input.max = '10000';
                input.step = '0.01';
                input.value = '1';
            }
            field.appendChild(input);
            item.appendChild(field);
        });

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button button-outline button-small doctor-remove-item';
        remove.textContent = 'Remove';
        remove.addEventListener('click', () => item.remove());
        item.appendChild(remove);
        container.appendChild(item);
    }

    function prepareClinicalForms(data) {
        activeVisitId = data.visit.visit_id;
        consultationForm.reset();
        diagnosisForm.reset();
        document.querySelector('[data-prescription-items]').replaceChildren();
        renderPatientDetails(data.patient, data.visit);
        renderAssessment(data.nursing_assessment);
        renderResults(data.test_results);
        renderHistory(data.history);
        renderClinicalRecords(data.consultations, data.diagnoses);
        populateSpecialists();

        const recordSummary = document.querySelector('[data-record-summary]');
        recordSummary.textContent =
            `${data.patient.full_name} · ${data.patient.patient_number} · Visit #${data.visit.visit_id}`;
        const pending = data.consultations.find((item) => item.next_step === 'undecided');
        if (pending) {
            consultationForm.hidden = true;
            diagnosisForm.hidden = false;
            diagnosisForm.elements.consultation_id.value = String(pending.consultation_id);
            showMessage('This visit has a saved consultation awaiting its decision.', 'success');
        } else {
            consultationForm.hidden = false;
            diagnosisForm.hidden = true;
        }
        recordElement.hidden = false;
        recordElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async function openVisit(visitId) {
        hideMessage();
        try {
            const data = await parseRequest(
                `/api/doctor/visits/${visitId}`,
                {},
                'Could not load the patient clinical record.'
            );
            prepareClinicalForms(data);
        } catch (error) {
            showMessage(error.message);
        }
    }

    function updateOutcomeFields() {
        const made = diagnosisForm.elements.diagnosis_made.value === 'yes';
        const outcome = diagnosisForm.elements.outcome;
        const diagnosisOption = outcome.querySelector('option[value="diagnosis"]');
        const testsOption = outcome.querySelector('option[value="more_tests"]');
        const referralOption = outcome.querySelector('option[value="specialist_referral"]');
        diagnosisOption.disabled = !made;
        testsOption.disabled = made;
        referralOption.disabled = made;
        if (made && outcome.value !== 'diagnosis') outcome.value = 'diagnosis';
        if (!made && outcome.value === 'diagnosis') outcome.value = 'more_tests';

        document.querySelector('[data-diagnosis-fields]').hidden = !made;
        document.querySelector('[data-test-fields]').hidden = outcome.value !== 'more_tests';
        document.querySelector('[data-specialist-fields]').hidden =
            outcome.value !== 'specialist_referral';
        document.querySelector('[data-therapy-fields]').hidden =
            !made || !diagnosisForm.elements.refer_for_therapy.checked;
    }

    function prescriptionPayload() {
        const rows = [...document.querySelectorAll('.doctor-prescription-item')];
        const items = [];
        for (const row of rows) {
            const medicineId = row.querySelector('[name="medicine_id"]').value;
            if (!medicineId) continue;
            const quantity = row.querySelector('[name="quantity"]').value;
            if (!quantity || Number(quantity) <= 0) {
                throw new Error('Enter a quantity greater than zero for each selected medicine.');
            }
            items.push({
                medicine_id: Number(medicineId),
                dosage: row.querySelector('[name="dosage"]').value,
                frequency: row.querySelector('[name="frequency"]').value,
                duration: row.querySelector('[name="duration"]').value,
                quantity,
                instructions: row.querySelector('[name="instructions"]').value,
            });
        }
        return items;
    }

    async function submitConsultation(event) {
        event.preventDefault();
        hideMessage();
        if (!consultationForm.reportValidity()) return;
        if (!activeVisitId) return;
        const button = consultationForm.querySelector('[data-consultation-submit]');
        setBusy(button, true, 'Saving consultation…');
        const formData = new FormData(consultationForm);
        try {
            const data = await parseRequest(
                '/api/consultations',
                {
                    method: 'POST',
                    body: JSON.stringify({
                        visit_id: activeVisitId,
                        presenting_complaint: formData.get('presenting_complaint'),
                        clinical_observations: formData.get('clinical_observations'),
                        examination_findings: formData.get('examination_findings'),
                        notes: formData.get('notes'),
                    }),
                },
                'Could not save the consultation.'
            );
            diagnosisForm.reset();
            diagnosisForm.elements.consultation_id.value = String(data.consultation_id);
            consultationForm.hidden = true;
            diagnosisForm.hidden = false;
            updateOutcomeFields();
            showMessage('Consultation saved. Complete the diagnosis and choose the next step.', 'success');
        } catch (error) {
            showMessage(error.message);
        } finally {
            setBusy(button, false);
        }
    }

    async function submitDiagnosis(event) {
        event.preventDefault();
        hideMessage();
        if (!diagnosisForm.reportValidity()) return;
        if (!activeVisitId) return;

        const diagnosisMade = diagnosisForm.elements.diagnosis_made.value === 'yes';
        const outcome = diagnosisForm.elements.outcome.value;
        const items = prescriptionPayload();
        const therapyEnabled = diagnosisForm.elements.refer_for_therapy.checked;
        const tests = [...diagnosisForm.querySelectorAll('[name="test_types[]"]:checked')]
            .map((input) => input.value);
        if (!window.confirm('Submit this clinical decision and route the patient to the selected next step?')) {
            return;
        }
        const button = diagnosisForm.querySelector('[data-diagnosis-submit]');
        setBusy(button, true, 'Submitting decision…');
        const formData = new FormData(diagnosisForm);
        try {
            await parseRequest(
                '/api/diagnoses',
                {
                    method: 'POST',
                    body: JSON.stringify({
                        visit_id: activeVisitId,
                        consultation_id: Number(formData.get('consultation_id')),
                        diagnosis_made: diagnosisMade,
                        outcome,
                        diagnosis_description: formData.get('diagnosis_description'),
                        clinical_findings: formData.get('clinical_findings'),
                        treatment_plan: formData.get('treatment_plan'),
                        notes: formData.get('diagnosis_notes'),
                        test_types: tests,
                        specialist_user_id: formData.get('specialist_user_id') || null,
                        referral_reason: formData.get('referral_reason'),
                        clinical_summary: formData.get('clinical_summary'),
                        refer_for_therapy: therapyEnabled,
                        therapy_type: formData.get('therapy_type'),
                        therapy_reason: formData.get('therapy_reason'),
                        prescription_items: items,
                    }),
                },
                'Could not save the diagnosis and next step.'
            );
            showMessage('Clinical decision saved. The visit has been routed to the next workflow step.', 'success');
            diagnosisForm.hidden = true;
            consultationForm.hidden = true;
            await loadDashboard();
            window.setTimeout(() => {
                recordElement.hidden = true;
                activeVisitId = null;
            }, 1200);
        } catch (error) {
            showMessage(error.message);
        } finally {
            setBusy(button, false);
        }
    }

    function setupSearch() {
        const input = document.querySelector('[data-doctor-search]');
        input.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => loadQueue(input.value.trim()), 250);
        });
    }

    function setupLogout() {
        document.querySelector('[data-sign-out]').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
                await parseRequest('/api/auth/logout', { method: 'POST' }, 'Could not sign out.');
                window.location.replace(`${projectBase}auth/login.php`);
            } catch (error) {
                showMessage(error.message);
                button.disabled = false;
            }
        });
    }

    async function init() {
        const date = document.querySelector('[data-current-date]');
        date.textContent = new Intl.DateTimeFormat(undefined, {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        }).format(new Date());
        try {
            if (!await checkSession()) return;
            setupSearch();
            setupLogout();
            consultationForm.addEventListener('submit', submitConsultation);
            diagnosisForm.addEventListener('submit', submitDiagnosis);
            diagnosisForm.elements.diagnosis_made.addEventListener('change', updateOutcomeFields);
            diagnosisForm.elements.outcome.addEventListener('change', updateOutcomeFields);
            diagnosisForm.elements.refer_for_therapy.addEventListener('change', updateOutcomeFields);
            document.querySelector('[data-add-prescription-item]').addEventListener('click', createPrescriptionItem);
            document.querySelector('[data-close-record]').addEventListener('click', () => {
                recordElement.hidden = true;
                activeVisitId = null;
                hideMessage();
            });
            document.querySelector('[data-cancel-decision]').addEventListener('click', () => {
                recordElement.hidden = true;
                activeVisitId = null;
                hideMessage();
            });
            await loadDashboard();
        } catch (error) {
            showMessage(error.message);
        }
    }

    init();
})();
