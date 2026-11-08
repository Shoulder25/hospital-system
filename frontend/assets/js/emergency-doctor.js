'use strict';

(() => {
    const root = document.body;
    const basePath = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${basePath}backend/index.php`, window.location.origin);
    const message = document.querySelector('[data-emergency-doctor-message]');
    const state = {
        user: null,
        csrfToken: '',
        visitId: new URLSearchParams(window.location.search).get('visit_id'),
        consultationId: null,
        detail: null,
        search: '',
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
            window.location.replace(`${basePath}auth/login.php?message=session-expired`);
            throw new Error('Your session has expired. Sign in again.');
        }
        if (!response.ok || !payload.success) {
            const details = payload && payload.errors
                ? Object.values(payload.errors).find((value) => typeof value === 'string')
                : null;
            throw new Error(details || payload?.message || 'The request could not be completed.');
        }
        return payload.data;
    }

    function showMessage(text, type = 'error') {
        if (!message) return;
        message.textContent = text;
        message.classList.toggle('error', type === 'error');
        message.classList.toggle('success', type === 'success');
        message.hidden = false;
        message.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function clearMessage() {
        if (!message) return;
        message.hidden = true;
        message.textContent = '';
        message.classList.remove('error', 'success');
    }

    function display(value) {
        return value === null || value === undefined || value === ''
            ? '—'
            : String(value);
    }

    function pretty(value) {
        return value ? String(value).replaceAll('_', ' ') : '—';
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime())
            ? String(value)
            : new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            }).format(date);
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

    function emptyTable(target, text, count) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.className = 'table-message';
        cell.colSpan = count;
        cell.textContent = text;
        row.replaceChildren(cell);
        target.replaceChildren(row);
    }

    async function checkSession() {
        const data = await request('/api/auth/me');
        state.user = data.user;
        state.csrfToken = data.csrf_token;
        if (!['emergency_doctor', 'system_administrator'].includes(state.user.role.name)) {
            window.location.replace(`${basePath}frontend/dashboard.php`);
            return false;
        }
        return true;
    }

    function makeAction(label, handler) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button button-outline button-small';
        button.textContent = label;
        button.addEventListener('click', handler);
        return button;
    }

    function renderQueue(visits, section) {
        const target = document.querySelector('[data-doctor-rows]');
        target.replaceChildren();
        if (!visits.length) {
            emptyTable(target, 'No emergency cases match this activity or search.', 6);
            return;
        }
        visits.forEach((visit) => {
            const row = document.createElement('tr');
            const patient = document.createElement('td');
            const patientName = document.createElement('strong');
            patientName.textContent = visit.patient_name || 'Temporary patient';
            const patientNumber = document.createElement('small');
            patientNumber.textContent = `${display(visit.patient_number)}${visit.is_temporary ? ' · temporary' : ''}`;
            patient.append(patientName, patientNumber);
            row.appendChild(patient);

            addCell(row, `Visit ${visit.visit_id} · ${formatDate(visit.arrived_at)}`);
            addCell(row, visit.encounter?.triage_level);
            const handover = document.createElement('td');
            const handoverStatus = document.createElement('span');
            handoverStatus.className = `emergency-status emergency-status-${visit.encounter?.handover_status || 'not_started'}`;
            handoverStatus.textContent = pretty(visit.encounter?.handover_status);
            handover.appendChild(handoverStatus);
            if (visit.encounter?.emergency_doctor_name) {
                const doctor = document.createElement('small');
                doctor.textContent = visit.encounter.emergency_doctor_name;
                handover.appendChild(doctor);
            }
            row.appendChild(handover);
            addCell(row, `${pretty(visit.status)}${visit.encounter?.outcome ? ` · ${pretty(visit.encounter.outcome)}` : ''}`);

            const action = document.createElement('td');
            if (section === 'handovers' && visit.encounter?.handover_status === 'pending'
                && state.user.role.name === 'emergency_doctor') {
                action.appendChild(makeAction('Accept handover', () => acceptHandover(visit.visit_id)));
            } else {
                const link = document.createElement('a');
                link.className = 'button button-outline button-small';
                link.href = section === 'history'
                    ? `doctor-history.php?visit_id=${encodeURIComponent(visit.visit_id)}`
                    : `doctor-assessment.php?visit_id=${encodeURIComponent(visit.visit_id)}`;
                link.textContent = section === 'history'
                    ? 'View record'
                    : section === 'handovers' ? 'Open accepted case' : 'Review handover';
                action.appendChild(link);
            }
            row.appendChild(action);
            target.appendChild(row);
        });
    }

    async function loadList() {
        const sectionElement = document.querySelector('[data-doctor-list-page]');
        if (!sectionElement) return;
        const section = sectionElement.dataset.section;
        const searchElement = document.querySelector('[data-doctor-search]');
        const data = await request('/api/emergency/doctor/queue', {
            query: { section, search: searchElement?.value.trim() || state.search },
        });
        renderQueue(data.visits || [], section);
    }

    async function loadDashboard() {
        const data = await request('/api/emergency/doctor/dashboard');
        Object.entries(data.stats || {}).forEach(([key, value]) => {
            document.querySelectorAll(`[data-stat="${key}"]`).forEach((counter) => {
                counter.textContent = String(value);
            });
        });
    }

    function renderNurseAssessment(assessment) {
        const target = document.querySelector('[data-nurse-assessment]');
        target.replaceChildren();
        if (!assessment) {
            const empty = document.createElement('p');
            empty.className = 'emergency-nurse-muted';
            empty.textContent = 'No nursing assessment has been saved yet.';
            target.appendChild(empty);
            return;
        }

        [
            ['Assessment status', pretty(assessment.status)],
            ['Nurse', assessment.nurse_name],
            ['Symptoms', assessment.symptoms],
            ['Blood pressure', assessment.blood_pressure],
            ['Pulse', assessment.pulse],
            ['Respiratory rate', assessment.respiratory_rate],
            ['Temperature', assessment.temperature],
            ['Oxygen saturation', assessment.oxygen_saturation],
            ['Consciousness level', assessment.consciousness_level],
            ['Pain score', assessment.pain_score],
            ['Allergies', assessment.allergies],
            ['Visible injuries', assessment.visible_injuries],
            ['Triage priority', assessment.triage_priority],
            ['Nursing notes', assessment.nursing_notes],
            ['Immediate care', assessment.immediate_care],
        ].forEach(([label, value]) => {
            const wrapper = document.createElement('div');
            const term = document.createElement('dt');
            const detail = document.createElement('dd');
            term.textContent = label;
            detail.textContent = display(value);
            wrapper.append(term, detail);
            target.appendChild(wrapper);
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

    function renderNurseObservations(observations) {
        const target = document.querySelector('[data-nurse-observations]');
        target.replaceChildren();
        if (!observations.length) {
            emptyTable(target, 'No separate nursing observations recorded.', 4);
            return;
        }
        observations.forEach((item) => {
            const row = document.createElement('tr');
            addCell(row, formatDate(item.recorded_at));
            addCell(row, [
                item.blood_pressure ? `BP ${item.blood_pressure}` : '',
                item.pulse ? `Pulse ${item.pulse}` : '',
                item.respiratory_rate ? `RR ${item.respiratory_rate}` : '',
                item.temperature ? `Temp ${item.temperature}` : '',
                item.oxygen_saturation ? `O₂ ${item.oxygen_saturation}` : '',
                item.consciousness_level,
                item.pain_score ? `Pain ${item.pain_score}` : '',
            ].filter(Boolean).join(' · '));
            addCell(row, [item.nursing_notes, item.immediate_care].filter(Boolean).join(' · '));
            addCell(row, item.nurse_name);
            target.appendChild(row);
        });
    }

    function renderClinicalRecords(records) {
        const target = document.querySelector('[data-doctor-clinical-records]');
        if (!target) return;
        target.replaceChildren();
        if (!records.length) {
            emptyTable(target, 'No doctor assessment recorded.', 4);
            return;
        }
        records.forEach((record) => {
            const row = document.createElement('tr');
            addCell(row, formatDate(record.consulted_at));
            addCell(row, record.clinician_name);
            addCell(row, [
                record.presenting_complaint,
                record.clinical_observations,
                record.examination_findings,
                record.diagnosis_stage
                    ? `${pretty(record.diagnosis_stage)} diagnosis: ${record.diagnosis_description || 'not recorded'}`
                    : record.diagnosis_text,
            ].filter(Boolean).join(' · '));
            addCell(row, [record.treatment_plan, record.notes, record.diagnosis_notes]
                .filter(Boolean).join(' · '));
            target.appendChild(row);
        });
    }

    function renderDoctorNotes(notes) {
        const target = document.querySelector('[data-doctor-notes]');
        if (!target) return;
        target.replaceChildren();
        if (!notes.length) {
            emptyTable(target, 'No treatment instructions recorded.', 4);
            return;
        }
        notes.forEach((note) => {
            const row = document.createElement('tr');
            addCell(row, formatDate(note.created_at));
            addCell(row, pretty(note.note_type));
            addCell(row, note.note_text);
            addCell(row, note.doctor_name);
            target.appendChild(row);
        });
    }

    function populateSpecialists(specialists) {
        const select = document.querySelector('[data-specialist-options]');
        if (!select) return;
        select.replaceChildren();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = specialists.length
            ? 'Choose an active specialist'
            : 'No active specialist accounts available';
        select.appendChild(placeholder);
        specialists.forEach((specialist) => {
            const option = document.createElement('option');
            option.value = String(specialist.user_id);
            option.textContent = `${specialist.display_name} · ${pretty(specialist.role_name)}`;
            select.appendChild(option);
        });
    }

    function renderOrders(data) {
        const ordersTarget = document.querySelector('[data-test-orders]');
        if (ordersTarget) {
            ordersTarget.replaceChildren();
            const orders = data.test_orders || [];
            if (!orders.length) {
                emptyTable(ordersTarget, 'No investigation orders recorded.', 4);
            } else {
                orders.forEach((order) => {
                    const row = document.createElement('tr');
                    addCell(row, `${pretty(order.test_type)} · #${order.test_order_id}`);
                    addCell(row, pretty(order.department));
                    addCell(row, `${pretty(order.status)} · ${formatDate(order.ordered_at)}`);
                    addCell(row, (order.results || []).map((result) =>
                        `${pretty(result.status)}: ${result.text}${result.notes ? ` (${result.notes})` : ''} · ${formatDate(result.recorded_at)}`
                    ).join('\n') || 'No results recorded yet.');
                    ordersTarget.appendChild(row);
                });
            }
        }
        const prescriptionsTarget = document.querySelector('[data-prescriptions]');
        if (prescriptionsTarget) {
            prescriptionsTarget.replaceChildren();
            const prescriptions = data.prescriptions || [];
            if (!prescriptions.length) {
                emptyTable(prescriptionsTarget, 'No prescriptions recorded.', 4);
            } else {
                prescriptions.forEach((prescription) => {
                    const row = document.createElement('tr');
                    addCell(row, `#${prescription.prescription_id}`);
                    addCell(row, pretty(prescription.status));
                    addCell(row, formatDate(prescription.created_at));
                    addCell(row, prescription.prescribed_by);
                    prescriptionsTarget.appendChild(row);
                });
            }
        }
    }

    function renderReferrals(referrals) {
        const target = document.querySelector('[data-referral-list]');
        if (!target) return;
        target.replaceChildren();
        if (!referrals.length) {
            emptyTable(target, 'No referrals recorded.', 5);
            return;
        }
        referrals.forEach((referral) => {
            const row = document.createElement('tr');
            addCell(row, referral.destination);
            addCell(row, pretty(referral.referral_type));
            addCell(row, pretty(referral.status));
            addCell(row, [referral.reason, referral.clinical_summary].filter(Boolean).join(' · '));
            addCell(row, formatDate(referral.created_at));
            target.appendChild(row);
        });
    }

    function fillForm(form, record, fields) {
        if (!form || !record) return;
        fields.forEach((name) => {
            if (form.elements.namedItem(name)) {
                form.elements.namedItem(name).value = record[name] || '';
            }
        });
    }

    function configureCaseLinks(visitId) {
        document.querySelectorAll('[data-case-link]').forEach((link) => {
            const url = new URL(link.dataset.caseLink, window.location.href);
            url.searchParams.set('visit_id', String(visitId));
            link.href = url.href;
        });
    }

    async function loadVisit() {
        if (!document.querySelector('[data-doctor-case]')) return;
        if (!state.visitId || !/^[1-9][0-9]*$/.test(state.visitId)) {
            showMessage('Open an emergency case from the Doctor queue or handover list.');
            return;
        }
        const data = await request(`/api/emergency/visits/${state.visitId}`);
        state.detail = data;
        const visit = data.visit;
        const encounter = visit.encounter || {};
        const doctorOwnsCase = state.user.role.name === 'emergency_doctor'
            && Number(encounter.emergency_doctor_id) === Number(state.user.user_id)
            && visit.encounter_status === 'in_progress';
        setText('[data-case-name]', visit.patient_name || 'Temporary patient');
        setText('[data-case-summary]', `Visit ${visit.visit_id} · ${visit.patient_number}${visit.is_temporary ? ' · temporary record' : ''}`);
        setText('[data-case-patient-number]', visit.patient_number);
        setText('[data-case-visit-id]', visit.visit_id);
        setText('[data-case-arrival]', formatDate(visit.arrived_at));
        setText('[data-case-status]', pretty(visit.status));
        setText('[data-case-doctor]', visit.encounter?.emergency_doctor_name);
        setText('[data-case-outcome]', encounter.outcome
            ? `${pretty(encounter.outcome)}${encounter.outcome_recorded_at ? ` · ${formatDate(encounter.outcome_recorded_at)}` : ''}`
            : 'Not recorded');
        setText('[data-case-complaint]', visit.reason_for_visit);
        setText('[data-case-reception-notes]', visit.reception_notes);
        setText('[data-handover-status]', encounter.handover_status === 'pending'
            ? `Pending handover requested ${formatDate(encounter.handover_requested_at)}. Accept to take responsibility.`
            : encounter.handover_status === 'accepted'
                ? `Accepted by ${encounter.emergency_doctor_name || 'Emergency Doctor'} at ${formatDate(encounter.handover_accepted_at)}.`
                : 'No nurse handover has been requested.');
        renderNurseAssessment(data.nursing_assessment);
        renderStaffAttendance(data);
        renderNurseObservations(data.nursing_observations || []);
        renderClinicalRecords(data.clinical_records || []);
        renderDoctorNotes(data.doctor_notes || []);
        renderOrders(data);
        renderReferrals(data.referrals || []);
        populateSpecialists(data.specialists || []);
        configureCaseLinks(visit.visit_id);
        const accept = document.querySelector('[data-accept-doctor-handover]');
        if (accept) {
            accept.hidden = !(
                state.user.role.name === 'emergency_doctor'
                && encounter.handover_status === 'pending'
                && !encounter.emergency_doctor_id
            );
        }

        const latestConsultation = (data.clinical_records || []).find((record) =>
            record.next_step === 'undecided'
            && Number(record.clinician_user_id) === Number(state.user.user_id)
        ) || (data.clinical_records || []).find((record) => record.next_step === 'undecided');
        state.consultationId = latestConsultation
            ? Number(latestConsultation.consultation_id)
            : null;
        const assessmentForm = document.querySelector('[data-doctor-form="assessment"]');
        if (!state.loadedVisitId || state.loadedVisitId !== String(visit.visit_id)) {
            fillForm(assessmentForm, latestConsultation, [
                'presenting_complaint', 'clinical_observations',
                'examination_findings', 'notes',
            ]);
        }
        const diagnosisForm = document.querySelector('[data-doctor-form="diagnosis"]');
        if (diagnosisForm) {
            diagnosisForm.elements.namedItem('consultation_id').value =
                state.consultationId ? String(state.consultationId) : '';
        }
        const referralForm = document.querySelector('[data-doctor-form="referral"]');
        if (referralForm) {
            referralForm.elements.namedItem('consultation_id').value =
                state.consultationId ? String(state.consultationId) : '';
        }
        const outcomeForm = document.querySelector('[data-doctor-form="outcome"]');
        if (outcomeForm && encounter.outcome) {
            outcomeForm.elements.namedItem('outcome').value = encounter.outcome;
            outcomeForm.elements.namedItem('outcome_notes').value = encounter.outcome_notes || '';
        }
        const workspace = document.querySelector('[data-doctor-workspace]');
        if (workspace) {
            workspace.querySelectorAll('input, textarea, select, button').forEach((field) => {
                if (field.hasAttribute('data-add-medicine')
                    || field.hasAttribute('data-remove-medicine')) return;
                field.disabled = !doctorOwnsCase;
            });
            if (!doctorOwnsCase) {
                const note = document.createElement('p');
                note.className = 'emergency-nurse-muted';
                note.dataset.caseEditNote = '';
                note.textContent = state.user.role.name === 'system_administrator'
                    ? 'Administrator access is read-only.'
                    : encounter.handover_status === 'pending'
                        ? 'Accept the nurse handover before recording clinical care.'
                        : 'This encounter is not assigned to your doctor account.';
                if (!workspace.querySelector('[data-case-edit-note]')) workspace.prepend(note);
            } else {
                workspace.querySelector('[data-case-edit-note]')?.remove();
            }
        }
        document.querySelector('[data-doctor-case]').hidden = false;
        state.loadedVisitId = String(visit.visit_id);
    }

    async function acceptHandover(visitId) {
        clearMessage();
        try {
            await request(`/api/emergency/visits/${visitId}/accept`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            showMessage('Nurse handover accepted. This doctor is now responsible for the emergency encounter.', 'success');
            if (state.visitId === String(visitId)) await loadVisit();
            await loadList();
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function submitForm(form, route, body, success) {
        clearMessage();
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
            const result = await request(route, {
                method: 'POST',
                body: JSON.stringify(body),
            });
            if (result.consultation_id) state.consultationId = Number(result.consultation_id);
            showMessage(success, 'success');
            await loadVisit();
            await loadList();
        } catch (error) {
            showMessage(error.message);
        } finally {
            submit.disabled = false;
        }
    }

    function bindForms() {
        document.querySelectorAll('[data-doctor-form]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                if (!state.visitId) return;
                const values = Object.fromEntries(new FormData(form).entries());
                const action = form.dataset.doctorForm;
                if (action === 'assessment') {
                    submitForm(
                        form,
                        `/api/emergency/doctor/visits/${state.visitId}/assessment`,
                        values,
                        'Clinical assessment saved.'
                    );
                } else if (action === 'diagnosis') {
                    submitForm(
                        form,
                        `/api/emergency/doctor/visits/${state.visitId}/diagnosis`,
                        {
                            ...values,
                            consultation_id: state.consultationId,
                            diagnosis_made: values.diagnosis_made === 'true',
                        },
                        'Diagnosis recorded.'
                    );
                } else if (action === 'treatment') {
                    submitForm(
                        form,
                        `/api/emergency/doctor/visits/${state.visitId}/treatment`,
                        values,
                        'Treatment and nurse instructions saved.'
                    );
                } else if (action === 'orders') {
                    const testTypes = Array.from(form.querySelectorAll('[name="test_types[]"]:checked'))
                        .map((input) => input.value);
                    submitForm(
                        form,
                        `/api/emergency/doctor/visits/${state.visitId}/orders`,
                        { test_types: testTypes },
                        'Investigation orders sent to the existing testing queues.'
                    );
                } else if (action === 'prescription') {
                    const items = Array.from(form.querySelectorAll('[data-medicine-item]')).map((item) => ({
                        medicine_id: item.querySelector('[name="medicine_id"]').value,
                        quantity: item.querySelector('[name="quantity"]').value,
                        dosage: item.querySelector('[name="dosage"]').value,
                        frequency: item.querySelector('[name="frequency"]').value,
                        duration: item.querySelector('[name="duration"]').value,
                        instructions: item.querySelector('[name="instructions"]').value,
                    }));
                    submitForm(
                        form,
                        `/api/emergency/visits/${state.visitId}/prescription`,
                        { items },
                        'Prescription saved for Pharmacy. It has not been dispensed.'
                    );
                } else if (action === 'referral') {
                    submitForm(
                        form,
                        `/api/emergency/doctor/visits/${state.visitId}/referrals`,
                        {
                            ...values,
                            consultation_id: state.consultationId,
                            outside_tests_payable: form.elements.namedItem('outside_tests_payable').checked,
                        },
                        'Referral recorded.'
                    );
                } else if (action === 'outcome') {
                    submitForm(
                        form,
                        `/api/emergency/doctor/visits/${state.visitId}/outcome`,
                        values,
                        'Patient outcome recorded.'
                    );
                }
            });
        });
    }

    function bindMedicineControls() {
        const container = document.querySelector('[data-medicine-items]');
        if (!container) return;
        document.querySelector('[data-add-medicine]').addEventListener('click', () => {
            const first = container.querySelector('[data-medicine-item]');
            const copy = first.cloneNode(true);
            copy.querySelectorAll('input').forEach((input) => {
                input.value = '';
            });
            const select = copy.querySelector('select');
            select.selectedIndex = 0;
            container.appendChild(copy);
        });
        container.addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove-medicine]');
            if (!button) return;
            const items = container.querySelectorAll('[data-medicine-item]');
            if (items.length <= 1) {
                items[0].querySelectorAll('input').forEach((input) => {
                    input.value = '';
                });
                items[0].querySelector('select').selectedIndex = 0;
                return;
            }
            button.closest('[data-medicine-item]').remove();
        });
    }

    function populateMedicines(medicines) {
        document.querySelectorAll('[data-medicine-select]').forEach((select) => {
            select.replaceChildren();
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = medicines.length
                ? 'Select an active medicine'
                : 'No active medicines available';
            select.appendChild(placeholder);
            medicines.forEach((medicine) => {
                const option = document.createElement('option');
                option.value = String(medicine.medicine_id);
                option.textContent = `${medicine.medicine_name}${medicine.is_demo ? ' · demo' : ''}`;
                select.appendChild(option);
            });
        });
    }

    function bindReferralOptions() {
        const type = document.querySelector('[data-referral-type]');
        if (!type) return;
        const toggle = () => {
            const specialist = type.value === 'specialist';
            document.querySelector('[data-specialist-field]').hidden = !specialist;
            document.querySelector('[data-reason-field]').hidden = !specialist;
            document.querySelector('[data-provider-field]').hidden = specialist;
            document.querySelector('[data-outside-tests-field]').hidden = specialist;
            document.querySelector('[data-specialist-options]').required = specialist;
            document.querySelector('[data-provider-field] input').required = false;
            document.querySelector('[data-reason-field] textarea').required = specialist;
            document.querySelector('[data-outside-tests-field] input').disabled = specialist;
        };
        type.addEventListener('change', toggle);
        toggle();
    }

    function bindListControls() {
        const searchButton = document.querySelector('[data-doctor-search-button]');
        const search = document.querySelector('[data-doctor-search]');
        if (!searchButton || !search) return;
        searchButton.addEventListener('click', () => {
            state.search = search.value.trim();
            loadList().catch((error) => showMessage(error.message));
        });
        search.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchButton.click();
            }
        });
    }

    async function init() {
        try {
            if (!(await checkSession())) return;
            bindForms();
            bindMedicineControls();
            bindReferralOptions();
            bindListControls();
            document.querySelector('[data-accept-doctor-handover]')?.addEventListener('click', () => {
                if (state.visitId) acceptHandover(Number(state.visitId));
            });
            if (document.querySelector('[data-doctor-list-page]')) {
                await loadList();
                window.setInterval(() => loadList().catch((error) => showMessage(error.message)), 15000);
            }
            if (root.dataset.emergencyDoctorPage === 'dashboard') {
                await loadDashboard();
                window.setInterval(() => loadDashboard().catch((error) => showMessage(error.message)), 15000);
            }
            if (document.querySelector('[data-doctor-case]') && state.visitId) {
                await loadVisit();
                window.setInterval(() => loadVisit().catch((error) => showMessage(error.message)), 15000);
            }
            if (state.detail) populateMedicines(state.detail.medicines || []);
            document.querySelector('[data-sign-out]').addEventListener('click', async () => {
                try {
                    await request('/api/auth/logout', {
                        method: 'POST',
                        body: JSON.stringify({}),
                    });
                } finally {
                    window.location.replace(`${basePath}auth/login.php`);
                }
            });
        } catch (error) {
            showMessage(error.message || 'The Emergency Doctor module could not be loaded.');
        }
    }

    init();
})();
