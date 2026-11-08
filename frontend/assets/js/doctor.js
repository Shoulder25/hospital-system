'use strict';

(() => {
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    const doctorPage = document.body.dataset.doctorPage || 'dashboard';
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

    function radiologyFileUrl(file, download = false) {
        const url = new URL(`${projectBase}backend/index.php`, window.location.origin);
        url.searchParams.set('route', `/api/radiology/files/${file.radiology_file_id}`);
        if (download) url.searchParams.set('download', '1');
        return url.href;
    }

    function cardiacFileUrl(file, download = false) {
        const url = new URL(`${projectBase}backend/index.php`, window.location.origin);
        url.searchParams.set('route', `/api/cardiac/files/${file.cardiac_file_id}`);
        if (download) url.searchParams.set('download', '1');
        return url.href;
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
        if (!queueBody) return;
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
        renderRecentActivity(data.recent_activity || []);
        if (queueBody) await loadQueue();
    }

    function renderRecentActivity(activity) {
        const body = document.querySelector('[data-doctor-recent-activity]');
        if (!body) return;
        body.replaceChildren();
        if (!activity.length) {
            const row = document.createElement('tr');
            const cell = appendCell(row, 'No recent consultations are recorded.', 'table-message');
            cell.colSpan = 5;
            body.appendChild(row);
            return;
        }
        activity.forEach((item) => {
            const row = document.createElement('tr');
            appendCell(row, `${item.patient_name} · ${item.patient_number}`);
            appendCell(row, `#${item.visit_id}`);
            appendCell(row, formatDateTime(item.updated_at));
            appendCell(row, [item.presenting_complaint, item.diagnosis].filter(Boolean).join(' · ') || label(item.next_step));
            appendCell(row, label(item.visit_status));
            body.appendChild(row);
        });
    }

    async function loadQueue(search = '') {
        if (!queueBody) return;
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
                    visit.nursing_reason || (visit.has_assessment ? 'Nursing assessment available' : 'No nurse assessment'),
                    visit.final_test_summary || (visit.has_results ? 'Results available' : 'No results yet'),
                ];
                appendCell(row, markers.join(' · '));
                const actionCell = appendCell(row, '', 'doctor-action-cell');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button button-primary button-small';
                button.textContent = 'Clinical record';
                button.addEventListener('click', () => {
                    window.location.href = `clinical-record.php?visit_id=${encodeURIComponent(visit.visit_id)}`;
                });
                actionCell.appendChild(button);
                const consultLink = document.createElement('a');
                consultLink.className = 'button button-outline button-small';
                consultLink.href = `consultation.php?visit_id=${encodeURIComponent(visit.visit_id)}`;
                consultLink.textContent = 'Consult';
                actionCell.append(document.createTextNode(' '), consultLink);
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
            const isCardiac = item.department === 'cardiac';
            const files = isCardiac
                ? (item.cardiac_files || [])
                : (item.radiology_files || []);
            if (['radiology', 'cardiac'].includes(item.department) && files.length > 0) {
                const card = target.lastElementChild;
                const heading = document.createElement('h4');
                heading.className = 'doctor-radiology-files-heading';
                heading.textContent = isCardiac
                    ? 'ECG tracings and supporting files'
                    : 'Radiology images and supporting files';
                card.appendChild(heading);
                const fileList = document.createElement('div');
                fileList.className = 'doctor-radiology-files';
                files.forEach((file) => {
                    const fileCard = document.createElement('article');
                    fileCard.className = 'doctor-radiology-file';
                    const fileUrl = isCardiac ? cardiacFileUrl : radiologyFileUrl;
                    const url = fileUrl(file);
                    if (file.kind === 'image') {
                        const openImage = document.createElement('button');
                        openImage.type = 'button';
                        openImage.className = 'radiology-image-preview';
                        openImage.setAttribute('aria-label', `Preview and zoom ${isCardiac ? 'ECG tracing' : 'radiology image'} ${file.name}`);
                        const image = document.createElement('img');
                        image.src = url;
                        image.alt = `${file.name}, ${isCardiac ? 'ECG' : 'X-ray'} order ${item.test_order_id}`;
                        image.loading = 'lazy';
                        openImage.addEventListener('click', () => {
                            window.CareBridgeRadiologyImageViewer.open(
                                url,
                                image.alt,
                                file.name
                            );
                        });
                        openImage.appendChild(image);
                        fileCard.appendChild(openImage);
                    }
                    const name = document.createElement('strong');
                    name.textContent = file.name;
                    fileCard.appendChild(name);
                    const uploaded = document.createElement('small');
                    uploaded.textContent = `Uploaded ${formatDateTime(file.uploaded_at)} by ${file.uploaded_by || (isCardiac ? 'Cardiac technician' : 'Radiographer')}`;
                    fileCard.appendChild(uploaded);
                    const links = document.createElement('div');
                    links.className = 'doctor-radiology-file-links';
                    const open = document.createElement('a');
                    open.href = url;
                    open.target = '_blank';
                    open.rel = 'noopener';
                    open.textContent = file.kind === 'image' ? 'Open image' : 'Open document';
                    const download = document.createElement('a');
                    download.href = fileUrl(file, true);
                    download.textContent = 'Download';
                    links.append(open, download);
                    fileCard.appendChild(links);
                    fileList.appendChild(fileCard);
                });
                card.appendChild(fileList);
            }
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

    function renderPrescriptions(prescriptions, medicationHistory) {
        const target = document.querySelector('[data-prescriptions]');
        if (!target) return;
        target.replaceChildren();
        if (!prescriptions.length && !medicationHistory.length) {
            target.appendChild(Object.assign(document.createElement('p'), {
                className: 'doctor-empty',
                textContent: 'No prescriptions or medication history are recorded.',
            }));
            return;
        }
        prescriptions.forEach((prescription) => {
            addTextCard(
                target,
                `Prescription #${prescription.prescription_id} · ${label(prescription.status)}`,
                [
                    `Prescribed by: ${prescription.prescribed_by || '—'} · ${formatDateTime(prescription.created_at)}`,
                    prescription.dispensed_at
                        ? `Dispensed ${formatDateTime(prescription.dispensed_at)}`
                        : prescription.pharmacy_verified_at
                            ? `Verified by pharmacy: ${prescription.verified_by || '—'} · ${formatDateTime(prescription.pharmacy_verified_at)}`
                            : 'Not yet verified or dispensed by pharmacy',
                    prescription.dispensing_notes
                        ? `Pharmacy note: ${prescription.dispensing_notes}`
                        : '',
                    ...prescription.items.map((item) => [
                        `${item.medicine_name} · ${item.quantity}`,
                        item.dosage,
                        item.frequency,
                        item.duration,
                        item.instructions,
                    ].filter(Boolean).join(' · ')),
                ]
            );
        });
        medicationHistory.forEach((item) => {
            addTextCard(
                target,
                `Visit #${item.visit_id} · ${formatDateTime(item.arrived_at)} · ${label(item.status)}`,
                [`${item.medicine_name} · ${item.quantity}`, item.dosage, item.frequency, item.duration, item.instructions, item.dispensed_at ? `Dispensed ${formatDateTime(item.dispensed_at)}` : 'Not dispensed']
            );
        });
    }

    function renderReferrals(referrals) {
        const target = document.querySelector('[data-visit-referrals]');
        if (!target) return;
        target.replaceChildren();
        if (!referrals.length) {
            target.appendChild(Object.assign(document.createElement('p'), {
                className: 'doctor-empty',
                textContent: 'No specialist, therapy, or outside referrals are recorded for this visit.',
            }));
            return;
        }
        referrals.forEach((referral) => {
            addTextCard(
                target,
                `${label(referral.type)} referral #${referral.referral_id} · ${label(referral.status)}`,
                [`Destination: ${referral.destination || '—'}`, referral.reason, referral.details, `Created: ${formatDateTime(referral.created_at)}`]
            );
        });
    }

    function populateSpecialists() {
        if (!diagnosisForm) return;
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
        consultationForm?.reset();
        diagnosisForm?.reset();
        document.querySelector('[data-prescription-items]')?.replaceChildren();
        renderPatientDetails(data.patient, data.visit);
        renderAssessment(data.nursing_assessment);
        renderResults(data.test_results);
        renderHistory(data.history);
        renderClinicalRecords(data.consultations, data.diagnoses);
        renderPrescriptions(data.prescriptions || [], data.medication_history || []);
        renderReferrals(data.referrals || []);
        populateSpecialists();

        const recordSummary = document.querySelector('[data-record-summary]');
        if (recordSummary) {
            recordSummary.textContent =
                `${data.patient.full_name} · ${data.patient.patient_number} · Visit #${data.visit.visit_id}`;
        }
        document.querySelector('[data-record-consult]')?.setAttribute(
            'href',
            `consultation.php?visit_id=${encodeURIComponent(data.visit.visit_id)}`
        );
        document.querySelector('[data-clinical-record]')?.setAttribute(
            'href',
            `clinical-record.php?visit_id=${encodeURIComponent(data.visit.visit_id)}`
        );
        if (!consultationForm || !diagnosisForm) {
            recordElement.hidden = false;
            recordElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
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
            const result = await parseRequest(
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
            window.setTimeout(() => {
                window.location.href = result.status === 'completed'
                    ? 'history.php'
                    : 'queue.php';
            }, 900);
        } catch (error) {
            showMessage(error.message);
        } finally {
            setBusy(button, false);
        }
    }

    function emptyTable(target, columns, message) {
        target.replaceChildren();
        const row = document.createElement('tr');
        const cell = appendCell(row, message, 'table-message');
        cell.colSpan = columns;
        target.appendChild(row);
    }

    function renderConsultationHistory(records) {
        const body = document.querySelector('[data-history-rows]');
        if (!body) return;
        body.replaceChildren();
        if (!records.length) {
            emptyTable(body, 7, 'No completed consultations match this search.');
            return;
        }
        records.forEach((item) => {
            const row = document.createElement('tr');
            appendCell(row, `${item.patient_name} · ${item.patient_number}`);
            appendCell(row, `#${item.visit_id} · ${label(item.visit_source)}`);
            appendCell(row, formatDateTime(item.consulted_at));
            appendCell(row, [item.presenting_complaint, item.clinical_observations, item.examination_findings].filter(Boolean).join('\n'));
            appendCell(row, [item.diagnosis, item.treatment_plan].filter(Boolean).join('\n'));
            appendCell(row, label(item.visit_status));
            const action = appendCell(row, '');
            const link = document.createElement('a');
            link.className = 'button button-outline button-small';
            link.href = `clinical-record.php?visit_id=${encodeURIComponent(item.visit_id)}`;
            link.textContent = 'Open record';
            action.appendChild(link);
            body.appendChild(row);
        });
    }

    async function loadConsultationHistory(search = '') {
        const body = document.querySelector('[data-history-rows]');
        if (!body) return;
        emptyTable(body, 7, 'Loading consultation history…');
        try {
            const data = await parseRequest(
                '/api/doctor/history',
                { query: { search } },
                'Could not load consultation history.'
            );
            renderConsultationHistory(data.consultations);
        } catch (error) {
            emptyTable(body, 7, error.message);
        }
    }

    function renderDoctorReferrals(referrals) {
        const body = document.querySelector('[data-referral-rows]');
        if (!body) return;
        body.replaceChildren();
        if (!referrals.length) {
            emptyTable(body, 7, 'No referral activity matches this search.');
            return;
        }
        referrals.forEach((item) => {
            const row = document.createElement('tr');
            appendCell(row, label(item.referral_type));
            appendCell(row, `${item.patient_name} · ${item.patient_number}`);
            appendCell(row, `#${item.visit_id}`);
            appendCell(row, formatDateTime(item.created_at));
            appendCell(row, [item.destination, item.reason, item.details].filter(Boolean).join('\n'));
            appendCell(row, `${label(item.status)} · ${label(item.visit_status)}`);
            const action = appendCell(row, '');
            const link = document.createElement('a');
            link.className = 'button button-outline button-small';
            link.href = `clinical-record.php?visit_id=${encodeURIComponent(item.visit_id)}`;
            link.textContent = 'Open record';
            action.appendChild(link);
            body.appendChild(row);
        });
    }

    async function loadDoctorReferrals(search = '') {
        const body = document.querySelector('[data-referral-rows]');
        if (!body) return;
        emptyTable(body, 7, 'Loading referrals…');
        try {
            const data = await parseRequest(
                '/api/doctor/referrals',
                { query: { search } },
                'Could not load Doctor referrals.'
            );
            renderDoctorReferrals(data.referrals);
        } catch (error) {
            emptyTable(body, 7, error.message);
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

    function setupSearch() {
        const input = document.querySelector('[data-doctor-search]');
        if (!input) return;
        input.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => loadQueue(input.value.trim()), 250);
        });
    }

    function setupLogout() {
        const logoutButton = document.querySelector('[data-sign-out]');
        if (!logoutButton) return;
        logoutButton.addEventListener('click', async (event) => {
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
        if (date) {
            date.textContent = new Intl.DateTimeFormat(undefined, {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
            }).format(new Date());
        }
        try {
            if (!await checkSession()) return;
            setupLogout();
            if (doctorPage === 'dashboard') {
                await loadDashboard();
                return;
            }
            if (doctorPage === 'queue') {
                setupSearch();
                await loadQueue();
                return;
            }
            if (doctorPage === 'history') {
                bindListSearch('[data-history-search]', loadConsultationHistory);
                await loadConsultationHistory();
                return;
            }
            if (doctorPage === 'referrals') {
                bindListSearch('[data-referral-search]', loadDoctorReferrals);
                await loadDoctorReferrals();
                return;
            }

            if (!recordElement) return;
            recordElement.hidden = true;
            document.querySelector('[data-doctor-record-empty]')?.setAttribute('hidden', '');
            if (doctorPage === 'consultation') {
                await loadDashboard();
                consultationForm?.addEventListener('submit', submitConsultation);
                diagnosisForm?.addEventListener('submit', submitDiagnosis);
                diagnosisForm?.elements.diagnosis_made.addEventListener('change', updateOutcomeFields);
                diagnosisForm?.elements.outcome.addEventListener('change', updateOutcomeFields);
                diagnosisForm?.elements.refer_for_therapy.addEventListener('change', updateOutcomeFields);
                document.querySelector('[data-add-prescription-item]')?.addEventListener('click', createPrescriptionItem);
                document.querySelector('[data-cancel-decision]')?.addEventListener('click', () => {
                    window.location.href = 'queue.php';
                });
            }
            const visitId = new URLSearchParams(window.location.search).get('visit_id');
            if (!visitId || !/^\d+$/.test(visitId)) {
                document.querySelector('[data-doctor-record-empty]')?.removeAttribute('hidden');
                return;
            }
            await openVisit(visitId);
        } catch (error) {
            showMessage(error.message);
        }
    }

    init();
})();
