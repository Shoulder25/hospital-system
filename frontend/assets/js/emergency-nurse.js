'use strict';

(() => {
    const root = document.body;
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    const message = document.querySelector('[data-emergency-nurse-message]');
    const state = {
        csrfToken: '',
        user: null,
        visitId: new URLSearchParams(window.location.search).get('visit_id'),
    };

    async function request(route, options = {}) {
        const url = new URL(apiEntry.href);
        url.searchParams.set('route', route);
        Object.entries(options.query || {}).forEach(([key, value]) => {
            url.searchParams.set(key, value);
        });
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
        if (response.status === 401) {
            window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
            throw new Error('Your session has expired. Sign in again.');
        }
        if (!response.ok || !payload.success) {
            const details = payload && payload.errors && Object.values(payload.errors)
                .find((value) => typeof value === 'string');
            throw new Error(details || payload?.message || 'The request could not be completed.');
        }
        return payload.data;
    }

    function showMessage(text, type = 'error') {
        message.textContent = text;
        message.classList.toggle('error', type === 'error');
        message.classList.toggle('success', type === 'success');
        message.hidden = false;
        message.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function clearMessage() {
        message.hidden = true;
        message.textContent = '';
        message.classList.remove('error', 'success');
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime())
            ? String(value)
            : new Intl.DateTimeFormat(undefined, {
                year: 'numeric', month: 'short', day: 'numeric',
                hour: 'numeric', minute: '2-digit',
            }).format(date);
    }

    function display(value) {
        return value === null || value === undefined || value === ''
            ? '—'
            : String(value);
    }

    function setText(selector, value) {
        const element = document.querySelector(selector);
        if (element) element.textContent = display(value);
    }

    function addCell(row, value, strong = false) {
        const cell = document.createElement('td');
        if (strong) {
            const text = document.createElement('strong');
            text.textContent = display(value);
            cell.appendChild(text);
        } else {
            cell.textContent = display(value);
        }
        row.appendChild(cell);
        return cell;
    }

    function emptyTable(target, text, colSpan) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.className = 'table-message';
        cell.colSpan = colSpan;
        cell.textContent = text;
        row.appendChild(cell);
        target.replaceChildren(row);
    }

    function visitLink(visitId, label = 'Open assessment') {
        const link = document.createElement('a');
        link.className = 'button button-outline button-small';
        link.href = `assessment.php?visit_id=${encodeURIComponent(visitId)}`;
        link.textContent = label;
        return link;
    }

    function renderQueue(visits, section) {
        const target = document.querySelector('[data-nurse-rows]');
        target.replaceChildren();
        if (!visits.length) {
            emptyTable(target, 'No emergency visits are available in this activity.', 6);
            return;
        }
        visits.forEach((visit) => {
            const row = document.createElement('tr');
            const patient = document.createElement('td');
            const name = document.createElement('strong');
            name.textContent = visit.patient_name || 'Temporary patient';
            const number = document.createElement('small');
            number.textContent = `${display(visit.patient_number)}${visit.is_temporary ? ' · temporary' : ''}`;
            patient.append(name, number);
            row.appendChild(patient);
            addCell(row, visit.visit_id);
            addCell(row, formatDate(visit.arrived_at));

            const statusCell = document.createElement('td');
            const badge = document.createElement('span');
            const assessmentStatus = visit.encounter?.assessment_status || visit.encounter_status;
            badge.className = `emergency-status emergency-status-${assessmentStatus || 'not_started'}`;
            badge.textContent = visit.encounter?.handover_status
                ? visit.encounter.handover_status.replaceAll('_', ' ')
                : assessmentStatus.replaceAll('_', ' ');
            statusCell.appendChild(badge);
            if (visit.encounter?.emergency_doctor_name) {
                const doctor = document.createElement('small');
                doctor.textContent = `Emergency Doctor: ${visit.encounter.emergency_doctor_name}`;
                if (visit.encounter.handover_accepted_at) {
                    doctor.textContent += ` · accepted ${formatDate(visit.encounter.handover_accepted_at)}`;
                }
                statusCell.appendChild(doctor);
            }
            row.appendChild(statusCell);

            addCell(row, visit.status.replaceAll('_', ' '));
            const action = document.createElement('td');
            action.appendChild(visitLink(
                visit.visit_id,
                section === 'history' ? 'View record' : 'Open patient'
            ));
            row.appendChild(action);
            target.appendChild(row);
        });
    }

    async function loadQueue() {
        const table = document.querySelector('[data-nurse-list-page]');
        if (!table) return;
        const section = table.dataset.section;
        const search = document.querySelector('[data-nurse-search]').value.trim();
        const data = await request('/api/emergency/nurse/queue', {
            query: { section, search },
        });
        renderQueue(data.visits || [], section);
    }

    async function loadDashboard() {
        if (root.dataset.emergencyNursePage !== 'index') return;
        const data = await request('/api/emergency/nurse/dashboard');
        Object.entries(data.stats || {}).forEach(([key, value]) => {
            const counter = document.querySelector(`[data-stat="${key}"]`);
            if (counter) counter.textContent = String(value);
        });
    }

    function renderHistory(data) {
        const target = document.querySelector('[data-patient-history]');
        target.replaceChildren();
        const previousVisits = data.previous_visits || [];
        const nursing = data.previous_emergency_assessments || [];
        const generalNursing = data.previous_nursing_assessments || [];
        const tests = data.previous_test_results || [];
        const prescriptions = data.previous_prescriptions || [];
        if (!previousVisits.length && !nursing.length
            && !generalNursing.length && !tests.length && !prescriptions.length) {
            emptyTable(target, 'No previous medical history is available in this system.', 3);
            return;
        }

        previousVisits.forEach((record) => {
            const row = document.createElement('tr');
            addCell(row, `Visit ${record.visit_id} · ${formatDate(record.arrived_at)}`);
            addCell(row, record.consultation_type
                ? `${record.consultation_type} consultation`
                : 'Visit');
            addCell(row, [
                record.reason_for_visit,
                record.presenting_complaint,
                record.diagnosis_text,
                record.treatment_plan,
                record.consultation_notes,
            ].filter(Boolean).join(' · ') || record.status.replaceAll('_', ' '));
            target.appendChild(row);
        });
        nursing.forEach((record) => {
            const row = document.createElement('tr');
            addCell(row, `Visit ${record.visit_id} · ${formatDate(record.updated_at)}`);
            addCell(row, 'Previous emergency nursing assessment');
            addCell(row, [
                record.triage_priority,
                record.symptoms,
                record.allergies ? `Allergies: ${record.allergies}` : '',
                record.visible_injuries ? `Visible injuries: ${record.visible_injuries}` : '',
                record.nursing_notes,
            ].filter(Boolean).join(' · '));
            target.appendChild(row);
        });
        generalNursing.forEach((record) => {
            const row = document.createElement('tr');
            addCell(row, `Visit ${record.visit_id} · ${formatDate(record.assessed_at)}`);
            addCell(row, 'Previous nursing assessment');
            addCell(row, [
                record.complaint,
                record.symptoms,
                record.blood_pressure ? `BP ${record.blood_pressure}` : '',
                record.pulse ? `Pulse ${record.pulse}` : '',
                record.temperature ? `Temp ${record.temperature}` : '',
                record.decision,
                record.notes,
            ].filter(Boolean).join(' · '));
            target.appendChild(row);
        });
        tests.forEach((record) => {
            const row = document.createElement('tr');
            addCell(row, `Visit ${record.visit_id} · ${formatDate(record.recorded_at)}`);
            addCell(row, `${record.test_type} test · ${record.status}`);
            addCell(row, [record.result_text, record.result_notes]
                .filter(Boolean).join(' · '));
            target.appendChild(row);
        });
        prescriptions.forEach((record) => {
            const row = document.createElement('tr');
            addCell(row, `Visit ${record.visit_id} · ${formatDate(record.created_at)}`);
            addCell(row, `Previous prescription · ${record.status}`);
            addCell(row, [
                record.medicine_name,
                record.dosage,
                record.frequency,
                record.duration,
            ].filter(Boolean).join(' · '));
            target.appendChild(row);
        });
    }

    function renderStaffAttendance(data) {
        const target = document.querySelector('[data-staff-attendance]');
        if (!target) return;
        target.replaceChildren();
        const nurses = data.attending_nurses || [];
        const encounter = data.visit.encounter || {};
        nurses.forEach((nurse) => {
            const row = document.createElement('tr');
            addCell(row, 'Emergency Nurse');
            addCell(row, nurse.display_name);
            addCell(row, formatDate(nurse.assigned_at));
            target.appendChild(row);
        });
        if (encounter.emergency_doctor_name) {
            const row = document.createElement('tr');
            addCell(row, 'Emergency Doctor');
            addCell(row, encounter.emergency_doctor_name);
            addCell(row, formatDate(encounter.handover_accepted_at || encounter.started_at));
            target.appendChild(row);
        } else if (encounter.handover_status === 'pending') {
            const row = document.createElement('tr');
            addCell(row, 'Emergency Doctor');
            addCell(row, 'Handover pending; no doctor assigned');
            addCell(row, formatDate(encounter.handover_requested_at));
            target.appendChild(row);
        }
        if (!target.children.length) {
            emptyTable(target, 'No emergency staff assignment recorded.', 3);
        }
    }

    function renderObservations(observations) {
        const target = document.querySelector('[data-observation-history]');
        target.replaceChildren();
        if (!observations.length) {
            emptyTable(target, 'No observations have been recorded for this encounter.', 4);
            return;
        }
        observations.forEach((observation) => {
            const row = document.createElement('tr');
            addCell(row, formatDate(observation.recorded_at));
            addCell(row, [
                observation.blood_pressure ? `BP ${observation.blood_pressure}` : '',
                observation.pulse ? `Pulse ${observation.pulse}` : '',
                observation.respiratory_rate ? `RR ${observation.respiratory_rate}` : '',
                observation.temperature ? `Temp ${observation.temperature}` : '',
                observation.oxygen_saturation ? `O₂ ${observation.oxygen_saturation}` : '',
                observation.consciousness_level,
                observation.pain_score ? `Pain ${observation.pain_score}` : '',
            ].filter(Boolean).join(' · '));
            addCell(row, [
                observation.nursing_notes,
                observation.immediate_care,
            ].filter(Boolean).join(' · '));
            addCell(row, observation.nurse_name);
            target.appendChild(row);
        });
    }

    function populateAssessment(assessment) {
        const form = document.querySelector('[data-nurse-assessment-form]');
        if (!form || !assessment) return;
        [
            'symptoms', 'blood_pressure', 'pulse', 'respiratory_rate',
            'temperature', 'oxygen_saturation', 'consciousness_level',
            'pain_score', 'allergies', 'visible_injuries', 'triage_priority',
            'nursing_notes', 'immediate_care',
        ].forEach((field) => {
            form.elements.namedItem(field).value = assessment[field] || '';
        });
        const status = assessment.status === 'complete'
            ? 'Assessment marked complete'
            : 'Assessment draft saved';
        document.querySelector('[data-acknowledgement-state]').textContent += ` ${status}.`;
    }

    function renderDoctorStatus(data, preserveAssessment) {
        const encounter = data.visit.encounter;
        const status = encounter?.handover_status || 'not_requested';
        const doctor = encounter?.emergency_doctor_name;
        const handoverMessage = status === 'pending'
            ? 'Handover requested. Waiting for an Emergency Doctor to accept.'
            : status === 'accepted'
                ? `Handover received and accepted by ${doctor || 'an Emergency Doctor'}${encounter.handover_accepted_at ? ` at ${formatDate(encounter.handover_accepted_at)}` : ''}. Current emergency status: ${data.visit.status.replaceAll('_', ' ')}.`
                : 'Doctor handover has not been requested.';
        const outcomeMessage = encounter?.outcome
            ? ` Latest recorded outcome: ${encounter.outcome.replaceAll('_', ' ')}${encounter.outcome_recorded_by ? ` by ${encounter.outcome_recorded_by}` : ''}${encounter.outcome_recorded_at ? ` at ${formatDate(encounter.outcome_recorded_at)}` : ''}.`
            : '';
        setText('[data-doctor-status]', handoverMessage + outcomeMessage);
        const button = document.querySelector('[data-request-handover]');
        const assigned = (data.attending_nurses || []).some(
            (nurse) => Number(nurse.user_id) === Number(state.user.user_id)
        );
        button.hidden = root.dataset.canEdit !== 'true'
            || !assigned
            || status !== 'not_requested';
        const visitActive = data.visit.status === 'emergency_in_progress';
        button.disabled = !visitActive;

        const hasAcknowledged = assigned && Boolean(encounter);
        document.querySelector('[data-acknowledgement-state]').textContent = hasAcknowledged
            ? 'You acknowledged this emergency patient. Assessment and observation entries are enabled.'
            : 'Review the patient, then acknowledge to begin the nursing record.';
        document.querySelector('[data-acknowledge]').hidden = hasAcknowledged || !visitActive;
        const canEdit = root.dataset.canEdit === 'true';
        document.querySelector('[data-assessment-fields]').disabled =
            !canEdit || !hasAcknowledged || !visitActive;
        document.querySelector('[data-observation-fields]').disabled =
            !canEdit || !hasAcknowledged || !visitActive;
        if (!canEdit) {
            document.querySelector('[data-acknowledge]').hidden = true;
        }
        if (!preserveAssessment) populateAssessment(data.assessment);
    }

    function renderDoctorProgress(data) {
        const target = document.querySelector('[data-doctor-progress]');
        if (!target) return;
        const records = data.doctor_consultations || [];
        const notes = data.doctor_notes || [];
        const progress = [
            ...records.map((record) => ({
                date: record.consulted_at,
                doctor: record.doctor_name,
                assessment: [
                    record.presenting_complaint,
                    record.clinical_observations,
                    record.examination_findings,
                    record.diagnosis_text,
                ].filter(Boolean).join(' · '),
                plan: [record.treatment_plan, record.notes]
                    .filter(Boolean).join(' · '),
            })),
            ...notes.map((note) => ({
                date: note.created_at,
                doctor: note.doctor_name,
                assessment: note.note_type === 'instruction'
                    ? 'Instructions for nursing'
                    : 'Treatment / stabilization',
                plan: note.note_text,
            })),
        ].sort((first, second) => String(second.date).localeCompare(String(first.date)));
        target.replaceChildren();
        if (!progress.length) {
            emptyTable(target, 'No Emergency Doctor assessment or instruction has been recorded.', 4);
            setText('[data-doctor-progress-status]', 'No Emergency Doctor assessment or instruction has been recorded.');
            return;
        }
        progress.forEach((record) => {
            const row = document.createElement('tr');
            addCell(row, formatDate(record.date));
            addCell(row, record.doctor);
            addCell(row, record.assessment);
            addCell(row, record.plan);
            target.appendChild(row);
        });
        setText('[data-doctor-progress-status]', 'Latest Emergency Doctor notes and instructions are shown below.');
    }

    async function loadAssessment(preserveAssessment = false) {
        const panel = document.querySelector('[data-nurse-assessment]');
        if (!panel) return;
        if (!state.visitId || !/^[1-9][0-9]*$/.test(state.visitId)) {
            showMessage('Open an assessment from an emergency patient list using a valid visit ID.');
            return;
        }
        const data = await request(`/api/emergency/nurse/visits/${state.visitId}`);
        const patient = data.patient;
        setText('[data-patient-name]', patient.patient_name || 'Temporary patient');
        setText('[data-patient-summary]', `Visit ${data.visit.visit_id} · ${data.visit.status.replaceAll('_', ' ')}`);
        setText('[data-patient-number]', patient.patient_number);
        setText('[data-patient-demographics]', [
            patient.date_of_birth,
            patient.gender,
        ].filter(Boolean).join(' · '));
        setText('[data-patient-phone]', patient.phone);
        setText('[data-patient-address]', patient.address);
        setText('[data-visit-id]', data.visit.visit_id);
        setText('[data-arrival]', formatDate(data.visit.arrived_at));
        setText('[data-complaint]', data.reason_for_visit);
        setText('[data-reception-notes]', data.reception_notes);
        renderHistory(data);
        renderObservations(data.observations || []);
        renderStaffAttendance(data);
        renderDoctorProgress(data);
        renderDoctorStatus(data, preserveAssessment);
        panel.hidden = false;
    }

    async function submitJson(route, body, successText) {
        clearMessage();
        await request(route, {
            method: 'POST',
            body: JSON.stringify(body),
        });
        showMessage(successText, 'success');
    }

    function bindAssessment() {
        const panel = document.querySelector('[data-nurse-assessment]');
        if (!panel) return;
        document.querySelector('[data-acknowledge]').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
                await submitJson(
                    `/api/emergency/nurse/visits/${state.visitId}/acknowledge`,
                    {},
                    'Patient acknowledged. You can now save or continue the assessment.'
                );
                await loadAssessment();
            } catch (error) {
                showMessage(error.message);
            } finally {
                button.disabled = false;
            }
        });

        document.querySelector('[data-nurse-assessment-form]').addEventListener('submit', async (event) => {
            event.preventDefault();
            const submitter = event.submitter;
            const status = submitter?.dataset.saveAssessment || 'draft';
            const body = Object.fromEntries(new FormData(event.currentTarget).entries());
            body.status = status;
            submitter.disabled = true;
            try {
                await submitJson(
                    `/api/emergency/nurse/visits/${state.visitId}/assessment`,
                    body,
                    status === 'complete' ? 'Assessment marked complete.' : 'Assessment draft saved.'
                );
                await loadAssessment();
            } catch (error) {
                showMessage(error.message);
            } finally {
                submitter.disabled = false;
            }
        });

        document.querySelector('[data-observation-form]').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            try {
                await submitJson(
                    `/api/emergency/nurse/visits/${state.visitId}/observations`,
                    Object.fromEntries(new FormData(form).entries()),
                    'Observation recorded.'
                );
                form.reset();
                await loadAssessment();
            } catch (error) {
                showMessage(error.message);
            } finally {
                button.disabled = false;
            }
        });

        document.querySelector('[data-request-handover]').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
                await submitJson(
                    `/api/emergency/nurse/visits/${state.visitId}/handover`,
                    {},
                    'Emergency Doctor handover requested. The assessment can still be continued.'
                );
                await loadAssessment();
            } catch (error) {
                showMessage(error.message);
            } finally {
                button.disabled = false;
            }
        });
    }

    async function checkSession() {
        const data = await request('/api/auth/me');
        state.user = data.user;
        state.csrfToken = data.csrf_token;
        if (!['emergency_nurse', 'system_administrator'].includes(state.user.role.name)) {
            window.location.replace(`${projectBase}frontend/dashboard.php`);
            return false;
        }
        return true;
    }

    async function init() {
        try {
            if (!(await checkSession())) return;
            const page = root.dataset.emergencyNursePage;
            if (page === 'index') await loadDashboard();
            if (document.querySelector('[data-nurse-list-page]')) {
                await loadQueue();
                document.querySelector('[data-nurse-search-button]').addEventListener('click', () => {
                    loadQueue().catch((error) => showMessage(error.message));
                });
                document.querySelector('[data-nurse-search]').addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        document.querySelector('[data-nurse-search-button]').click();
                    }
                });
                window.setInterval(() => loadQueue().catch((error) => showMessage(error.message)), 15000);
            }
            if (document.querySelector('[data-nurse-assessment]')) {
                bindAssessment();
                await loadAssessment();
                window.setInterval(
                    () => loadAssessment(true).catch((error) => showMessage(error.message)),
                    15000
                );
            }
            if (page === 'index') {
                window.setInterval(() => loadDashboard().catch((error) => showMessage(error.message)), 15000);
            }
            const logout = document.querySelector('[data-sign-out]');
            logout.addEventListener('click', async () => {
                try {
                    await request('/api/auth/logout', {
                        method: 'POST',
                        body: JSON.stringify({}),
                    });
                } finally {
                    window.location.replace(`${projectBase}auth/login.php`);
                }
            });
        } catch (error) {
            showMessage(error.message || 'The Emergency Nurse module could not be loaded.');
        }
    }

    init();
})();
