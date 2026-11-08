'use strict';

(() => {
    const root = document.body;
    const page = root.dataset.receptionPage;
    const projectBase = root.dataset.basePath || '';
    const apiEntry = new URL(`${projectBase}/backend/index.php`, window.location.origin);
    const messageElement = document.querySelector('[data-reception-message]');
    let csrfToken = '';
    let selectedAppointmentPatient = null;
    let selectedArrivalPatient = null;
    let organizations = [];
    let activeTicketVisitId = null;

    function apiUrl(route, query = {}) {
        const url = new URL(apiEntry.href);
        url.searchParams.set('route', route);
        Object.entries(query).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                url.searchParams.set(key, String(value));
            }
        });
        return url.href;
    }

    async function request(route, options = {}) {
        const headers = new Headers(options.headers || {});
        if (options.body && !headers.has('Content-Type')) {
            headers.set('Content-Type', 'application/json');
        }
        if (csrfToken && options.method && options.method !== 'GET') {
            headers.set('X-CSRF-Token', csrfToken);
        }
        const response = await fetch(apiUrl(route, options.query), {
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

    async function parseRequest(route, options = {}, fallback = 'The request could not be completed.') {
        const { response, payload } = await request(route, options);
        if (response.status === 401) {
            window.location.replace(`${projectBase}/auth/login.php?message=session-expired`);
            throw new Error('Your session has expired. Sign in again.');
        }
        if (!response.ok || !payload.success) {
            const error = new Error(errorMessage(payload, fallback));
            error.status = response.status;
            throw error;
        }
        return payload.data;
    }

    function showMessage(message, type = 'error') {
        if (!messageElement) return;
        messageElement.textContent = message;
        messageElement.classList.toggle('error', type === 'error');
        messageElement.classList.toggle('success', type === 'success');
        messageElement.hidden = false;
        messageElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function showEmpty(body, columns, message) {
        body.replaceChildren();
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = columns;
        cell.className = 'table-message';
        cell.textContent = message;
        row.appendChild(cell);
        body.appendChild(row);
    }

    function addCell(row, value) {
        const cell = document.createElement('td');
        cell.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
        row.appendChild(cell);
        return cell;
    }

    function dateTime(value) {
        if (!value) return '—';
        const date = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return value;
        return new Intl.DateTimeFormat(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(date);
    }

    function patientName(patient) {
        return [patient.first_name, patient.last_name].filter(Boolean).join(' ') || 'Unknown patient';
    }

    function statusLabel(value) {
        return String(value || '').replaceAll('_', ' ');
    }

    function makeButton(label, callback, className = 'status-toggle') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = label;
        button.addEventListener('click', callback);
        return button;
    }

    async function checkSession() {
        const { response, payload } = await request('/api/auth/me');
        if (response.status === 401) {
            window.location.replace(`${projectBase}/auth/login.php?message=session-expired`);
            return false;
        }
        if (!response.ok || !payload.success) {
            throw new Error(errorMessage(payload, 'Could not verify the current session.'));
        }
        const role = payload.data.user.role.name;
        if (!['receptionist', 'system_administrator'].includes(role)) {
            window.location.replace(`${projectBase}/frontend/dashboard.php`);
            return false;
        }
        csrfToken = payload.data.csrf_token;
        return true;
    }

    async function signOut() {
        try {
            await parseRequest('/api/auth/logout', {
                method: 'POST',
                body: JSON.stringify({}),
            }, 'Could not sign out.');
        } finally {
            window.location.replace(`${projectBase}/auth/login.php`);
        }
    }

    function renderPatients(patients, target) {
        target.replaceChildren();
        if (!patients.length) {
            showEmpty(target, 5, 'No matching patient records.');
            return;
        }
        patients.forEach((patient) => {
            const row = document.createElement('tr');
            addCell(row, patientName(patient));
            addCell(row, patient.patient_number);
            addCell(row, patient.date_of_birth || '—');
            addCell(row, patient.phone);
            const actions = document.createElement('td');
            const profile = document.createElement('a');
            profile.className = 'status-toggle';
            profile.href = `patient-records.php?patient_id=${encodeURIComponent(patient.patient_id)}`;
            profile.textContent = 'Open profile';
            actions.appendChild(profile);
            row.appendChild(actions);
            target.appendChild(row);
        });
    }

    async function searchPatients(search, target) {
        if (!search.trim()) return;
        showEmpty(target, 5, 'Searching patient records…');
        try {
            const data = await parseRequest('/api/reception/patients', {
                query: { search: search.trim() },
            }, 'Could not search patient records.');
            renderPatients(data.patients, target);
        } catch (error) {
            showEmpty(target, 5, error.message);
        }
    }

    function bindPatientSearch() {
        const form = document.querySelector('[data-patient-search-form]');
        if (!form) return;
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            await searchPatients(
                new FormData(form).get('search'),
                document.querySelector('[data-patient-results]')
            );
        });
    }

    function bindRegistration() {
        const form = document.querySelector('[data-register-form]');
        if (!form) return;
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const values = new FormData(form);
            const body = Object.fromEntries(values.entries());
            try {
                const data = await parseRequest('/api/reception/patients', {
                    method: 'POST',
                    body: JSON.stringify(body),
                }, 'Could not register the patient.');
                showMessage(`Patient registered. Patient number: ${data.patient.patient_number}`, 'success');
                window.setTimeout(() => {
                    window.location.href = `patient-records.php?patient_id=${encodeURIComponent(data.patient.patient_id)}`;
                }, 500);
            } catch (error) {
                showMessage(error.message);
            }
        });
    }

    async function loadDashboard() {
        const data = await parseRequest('/api/reception/dashboard', {}, 'Could not load reception overview.');
        Object.entries(data.stats).forEach(([key, value]) => {
            const target = document.querySelector(`[data-stat="${key}"]`);
            if (target) target.textContent = String(value);
        });

        const renderOverviewRows = (selector, rows, columns, render) => {
            const body = document.querySelector(selector);
            if (!body) return;
            if (!rows.length) {
                showEmpty(body, columns, 'No records to display.');
                return;
            }
            body.replaceChildren();
            rows.forEach((item) => {
                const row = document.createElement('tr');
                render(row, item);
                body.appendChild(row);
            });
        };
        renderOverviewRows('[data-overview-recent]', data.recent_patients, 3, (row, patient) => {
            addCell(row, patientName(patient));
            addCell(row, patient.patient_number);
            addCell(row, dateTime(patient.created_at));
        });
        renderOverviewRows('[data-overview-appointments]', data.upcoming_appointments, 3, (row, item) => {
            addCell(row, `${item.first_name} ${item.last_name}`);
            addCell(row, dateTime(item.scheduled_at));
            addCell(row, statusLabel(item.status));
        });
        renderOverviewRows('[data-overview-arrivals]', data.todays_arrivals_list, 3, (row, item) => {
            addCell(row, `${item.first_name} ${item.last_name}`);
            addCell(row, `#${item.visit_id}`);
            addCell(row, statusLabel(item.status));
        });
        renderOverviewRows('[data-overview-processed]', data.recently_processed, 3, (row, item) => {
            addCell(row, `${item.first_name} ${item.last_name}`);
            addCell(row, `#${item.visit_id}`);
            addCell(row, statusLabel(item.status));
        });
        renderOverviewRows('[data-overview-attention]', data.requiring_attention, 3, (row, item) => {
            addCell(row, `${item.first_name} ${item.last_name}`);
            addCell(row, item.is_emergency ? 'Emergency' : 'Standard');
            addCell(row, statusLabel(item.status));
        });
    }

    async function loadOrganizations() {
        const data = await parseRequest('/api/reception/organizations');
        organizations = data.organizations;
        updateOrganizationOptions();
    }

    function updateOrganizationOptions() {
        const source = document.querySelector('#arrival-source');
        if (!source) return;
        const select = document.querySelector('#arrival-organization');
        const field = document.querySelector('[data-arrival-organization-field]');
        const type = source.value === 'school_appointment'
            ? 'school'
            : (source.value === 'referral' ? 'referring_facility' : '');
        field.hidden = !type;
        select.required = Boolean(type);
        select.replaceChildren(new Option(type === 'school' ? 'Choose a school' : 'Choose a facility', ''));
        organizations.filter((organization) => organization.organization_type === type)
            .forEach((organization) => select.add(new Option(organization.name, organization.organization_id)));
    }

    function renderPatientPickers(patients, target, onSelect) {
        target.replaceChildren();
        if (!patients.length) {
            showEmpty(target, 3, 'No matching patient records.');
            return;
        }
        patients.forEach((patient) => {
            const row = document.createElement('tr');
            addCell(row, patientName(patient));
            addCell(row, patient.patient_number);
            const action = document.createElement('td');
            action.appendChild(makeButton('Select', () => onSelect(patient), 'status-toggle reception-select-button'));
            row.appendChild(action);
            target.appendChild(row);
        });
    }

    function bindAppointmentSearch() {
        const form = document.querySelector('[data-appointment-search]');
        if (!form) return;
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const search = new FormData(form).get('search');
            const target = document.querySelector('[data-appointment-patient-results]');
            showEmpty(target, 3, 'Searching patients…');
            try {
                const data = await parseRequest('/api/reception/patients', { query: { search } });
                renderPatientPickers(data.patients, target, (patient) => {
                    selectedAppointmentPatient = patient;
                    document.querySelector('[data-appointment-form] [name="patient_id"]').value = patient.patient_id;
                    document.querySelector('[data-appointment-selected-label]').textContent =
                        `${patientName(patient)} · ${patient.patient_number}`;
                    document.querySelector('[data-appointment-selected]').classList.add('has-patient');
                    document.querySelector('[data-appointment-submit]').disabled = false;
                });
            } catch (error) {
                showEmpty(target, 3, error.message);
            }
        });
    }

    function renderAppointments(appointments) {
        const body = document.querySelector('[data-appointment-rows]');
        if (!body) return;
        if (!appointments.length) {
            showEmpty(body, 6, 'No appointments found.');
            return;
        }
        body.replaceChildren();
        appointments.forEach((appointment) => {
            const row = document.createElement('tr');
            addCell(row, `${appointment.first_name} ${appointment.last_name}`);
            addCell(row, appointment.patient_number);
            addCell(row, dateTime(appointment.scheduled_at));
            addCell(row, appointment.reason);
            const status = addCell(row, '');
            const badge = document.createElement('span');
            badge.className = `status-badge${appointment.status === 'cancelled' ? ' inactive' : ''}`;
            badge.textContent = statusLabel(appointment.status);
            status.appendChild(badge);
            const actions = document.createElement('td');
            if (appointment.status === 'scheduled') {
                actions.append(
                    makeButton('Check in', () => markAppointmentArrived(appointment), 'status-toggle activate'),
                    document.createTextNode(' '),
                    makeButton('Reschedule', () => rescheduleAppointment(appointment)),
                    document.createTextNode(' '),
                    makeButton('Cancel', () => cancelAppointment(appointment))
                );
            } else if (appointment.visit_id) {
                actions.textContent = `Visit #${appointment.visit_id}`;
            } else {
                actions.textContent = '—';
            }
            row.appendChild(actions);
            body.appendChild(row);
        });
    }

    async function loadAppointments() {
        const data = await parseRequest('/api/reception/appointments', {}, 'Could not load appointments.');
        renderAppointments(data.appointments);
    }

    async function submitAppointment(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        if (!selectedAppointmentPatient) {
            showMessage('Find and select an existing patient first.');
            return;
        }
        const values = new FormData(form);
        try {
            await parseRequest('/api/reception/appointments', {
                method: 'POST',
                body: JSON.stringify({
                    patient_id: Number(values.get('patient_id')),
                    scheduled_at: values.get('scheduled_at'),
                    reason: values.get('reason').trim(),
                }),
            }, 'Could not schedule the appointment.');
            form.reset();
            selectedAppointmentPatient = null;
            form.elements.patient_id.value = '';
            document.querySelector('[data-appointment-selected-label]').textContent = 'No patient selected';
            document.querySelector('[data-appointment-selected]').classList.remove('has-patient');
            document.querySelector('[data-appointment-submit]').disabled = true;
            await loadAppointments();
            showMessage('Appointment scheduled.', 'success');
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function updateAppointment(appointmentId, body, message) {
        await parseRequest(`/api/reception/appointments/${appointmentId}`, {
            method: 'PATCH',
            body: JSON.stringify(body),
        }, message);
        await loadAppointments();
        showMessage(message, 'success');
    }

    async function rescheduleAppointment(appointment) {
        const value = window.prompt('Enter the new date and time (YYYY-MM-DDTHH:MM):', appointment.scheduled_at.replace(' ', 'T').slice(0, 16));
        if (value === null) return;
        try {
            await updateAppointment(appointment.appointment_id, {
                action: 'reschedule',
                scheduled_at: value,
            }, 'Appointment rescheduled.');
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function cancelAppointment(appointment) {
        if (!window.confirm(`Cancel the appointment for ${appointment.first_name} ${appointment.last_name}?`)) return;
        try {
            await updateAppointment(appointment.appointment_id, { action: 'cancel' }, 'Appointment cancelled.');
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function markAppointmentArrived(appointment) {
        if (!window.confirm(`Check in ${appointment.first_name} ${appointment.last_name}? Ticket payment will be required before nursing.`)) return;
        try {
            const data = await parseRequest(`/api/reception/appointments/${appointment.appointment_id}/arrive`, {
                method: 'POST',
                body: JSON.stringify({}),
            }, 'Could not check in the appointment.');
            await loadAppointments();
            await openTicketPayment(data.visit_id);
        } catch (error) {
            showMessage(error.message);
        }
    }

    function bindArrivals() {
        const searchForm = document.querySelector('[data-arrival-search]');
        const arrivalForm = document.querySelector('[data-arrival-form]');
        if (!arrivalForm) return;
        searchForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const search = new FormData(searchForm).get('search');
            const target = document.querySelector('[data-arrival-patient-results]');
            showEmpty(target, 4, 'Searching patients…');
            try {
                const data = await parseRequest('/api/reception/patients', { query: { search } });
                renderPatientPickers(data.patients, target, (patient) => {
                    selectedArrivalPatient = patient;
                    arrivalForm.elements.patient_id.value = patient.patient_id;
                    document.querySelector('[data-arrival-selected-label]').textContent =
                        `${patientName(patient)} · ${patient.patient_number}`;
                    document.querySelector('[data-arrival-selected]').classList.add('has-patient');
                    document.querySelector('[data-arrival-submit]').disabled = false;
                });
            } catch (error) {
                showEmpty(target, 4, error.message);
            }
        });
        document.querySelector('#arrival-source').addEventListener('change', updateOrganizationOptions);
        arrivalForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!arrivalForm.reportValidity()) return;
            if (!selectedArrivalPatient) {
                showMessage('Find and select a patient first.');
                return;
            }
            const values = new FormData(arrivalForm);
            const type = values.get('arrival_type');
            const body = {
                patient_id: Number(values.get('patient_id')),
                is_emergency: type === 'emergency',
                medication_collection_only: type === 'medication_collection_only',
                visit_source: values.get('visit_source'),
                source_organization_id: values.get('source_organization_id')
                    ? Number(values.get('source_organization_id'))
                    : null,
                reason_for_visit: values.get('reason_for_visit').trim(),
                reception_notes: values.get('reception_notes').trim(),
            };
            const nextStep = body.is_emergency
                ? 'immediate emergency processing'
                : (body.medication_collection_only ? 'pharmacy' : 'ticket payment, then nursing');
            if (!window.confirm(`Check in this patient and route to ${nextStep}?`)) return;
            try {
                const data = await parseRequest('/api/reception/visits', {
                    method: 'POST',
                    body: JSON.stringify(body),
                }, 'Could not check in the patient.');
                arrivalForm.reset();
                selectedArrivalPatient = null;
                document.querySelector('[data-arrival-selected-label]').textContent = 'No patient selected';
                document.querySelector('[data-arrival-selected]').classList.remove('has-patient');
                document.querySelector('[data-arrival-submit]').disabled = true;
                updateOrganizationOptions();
                await loadArrivals().catch((error) => showMessage(error.message));
                if (data.visit.is_emergency) {
                    showMessage(
                        `Emergency visit #${data.visit.visit_id} — no initial ticket payment required. Continue to Emergency Department.`,
                        'success'
                    );
                } else if (data.visit.medication_collection_only) {
                    showMessage(
                        `Medication-collection visit #${data.visit.visit_id} — no initial ticket payment required. Continue to Pharmacy.`,
                        'success'
                    );
                } else {
                    await openTicketPayment(data.visit.visit_id);
                }
            } catch (error) {
                showMessage(error.message);
            }
        });
    }

    async function openTicketPayment(visitId) {
        const panel = document.querySelector('[data-ticket-payment-panel]');
        if (!panel) return;

        activeTicketVisitId = Number(visitId);
        panel.hidden = false;
        document.querySelector('[data-ticket-payment-summary]').textContent =
            'Loading the patient and active ticket-fee details…';
        document.querySelector('[data-ticket-patient]').textContent = '—';
        document.querySelector('[data-ticket-patient-number]').textContent = '—';
        document.querySelector('[data-ticket-visit-id]').textContent = `#${activeTicketVisitId}`;
        document.querySelector('[data-ticket-fee]').textContent = 'Loading…';
        document.querySelector('[data-ticket-payment-status]').textContent = '';
        document.querySelector('[data-ticket-payment-form]').hidden = true;
        document.querySelector('[data-ticket-receipt]').hidden = true;
        document.querySelector('[data-ticket-payment-retry]').hidden = true;
        const arrivalSubmit = document.querySelector('[data-arrival-submit]');
        if (arrivalSubmit) arrivalSubmit.disabled = true;
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

        try {
            const data = await parseRequest(`/api/billing/visits/${activeTicketVisitId}`);
            document.querySelector('[data-ticket-patient]').textContent =
                data.visit.patient_name || 'Unknown patient';
            document.querySelector('[data-ticket-patient-number]').textContent =
                data.visit.patient_number || '—';
            document.querySelector('[data-ticket-visit-id]').textContent =
                `#${data.visit.visit_id}`;
            document.querySelector('[data-ticket-fee]').textContent =
                `${data.ticket_fee.description} · E${data.ticket_fee.amount}`;
            document.querySelector('[data-ticket-payment-summary]').textContent =
                `Visit #${data.visit.visit_id} requires ticket payment before Nursing.`;
            document.querySelector('[data-ticket-payment-form]').hidden = false;
            document.querySelector('[data-ticket-payment-status]').textContent =
                'Payment not yet recorded.';
        } catch (error) {
            document.querySelector('[data-ticket-payment-summary]').textContent =
                `Visit #${activeTicketVisitId} was created. Do not create the visit again to collect payment.`;
            document.querySelector('[data-ticket-payment-status]').textContent =
                `Could not load ticket payment details: ${error.message}`;
            document.querySelector('[data-ticket-payment-retry]').hidden = false;
            showMessage(
                `Visit #${activeTicketVisitId} was created, but its ticket details could not be loaded. Retry here; do not create the visit again.`
            );
        }
    }

    function bindTicketPayment() {
        const form = document.querySelector('[data-ticket-payment-form]');
        if (!form) return;

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (activeTicketVisitId === null || !form.reportValidity()) return;
            const button = form.querySelector('[data-ticket-payment-submit]');
            if (!window.confirm(`Confirm the ticket payment for visit #${activeTicketVisitId}?`)) return;

            button.disabled = true;
            document.querySelector('[data-ticket-payment-status]').textContent =
                'Recording payment…';
            try {
                const data = await parseRequest(
                    `/api/billing/visits/${activeTicketVisitId}/ticket-payment`,
                    {
                        method: 'POST',
                        body: JSON.stringify({
                            payment_method: form.elements.payment_method.value,
                        }),
                    }
                );
                document.querySelector('[data-ticket-payment-form]').hidden = true;
                document.querySelector('[data-ticket-payment-status]').textContent =
                    `Payment successful${data.is_simulated ? ' — simulated approval' : ''}. Receipt ${data.receipt_number} generated. The patient may proceed to Nursing.`;
                const receipt = document.querySelector('[data-ticket-receipt]');
                receipt.href = `${projectBase}/frontend/billing/receipt.php?payment_id=${encodeURIComponent(data.payment_id)}`;
                receipt.hidden = false;
                const arrivalSubmit = document.querySelector('[data-arrival-submit]');
                if (arrivalSubmit) arrivalSubmit.disabled = !selectedArrivalPatient;
                showMessage(
                    `Ticket payment successful for visit #${activeTicketVisitId}. Patient may proceed to Nursing.`,
                    'success'
                );
                await loadArrivals().catch((error) => showMessage(error.message));
            } catch (error) {
                const message = error.status === 409
                    ? 'Visit already paid or no longer awaiting ticket payment. No additional payment was recorded.'
                    : `Payment unsuccessful: ${error.message}`;
                document.querySelector('[data-ticket-payment-status]').textContent = message;
                showMessage(message);
            } finally {
                button.disabled = false;
            }
        });

        document.querySelector('[data-ticket-payment-retry]').addEventListener('click', () => {
            if (activeTicketVisitId !== null) {
                openTicketPayment(activeTicketVisitId);
            }
        });
    }

    function renderVisits(visits, target, columns, referral = false) {
        if (!visits.length) {
            showEmpty(target, columns, 'No visits found.');
            return;
        }
        target.replaceChildren();
        visits.forEach((visit) => {
            const row = document.createElement('tr');
            addCell(row, `#${visit.visit_id}`);
            addCell(row, `${visit.patient_name} · ${visit.patient_number}`);
            if (referral) addCell(row, visit.organization_name || 'Referring facility');
            addCell(row, dateTime(visit.arrived_at));
            if (referral) {
                addCell(row, [visit.reason_for_visit, visit.source_notes].filter(Boolean).join(' · '));
                addCell(row, statusLabel(visit.status));
            } else if (page === 'arrivals') {
                addCell(row, visit.reason_for_visit);
                addCell(row, visit.is_emergency ? 'Emergency' : (visit.medication_collection_only ? 'Pharmacy' : 'Standard'));
                addCell(row, statusLabel(visit.status));
            } else {
                addCell(row, visit.age === null ? '—' : `${visit.age} · ${visit.gender}`);
                addCell(row, visit.reason_for_visit);
                addCell(row, visit.is_emergency ? 'Emergency' : (visit.medication_collection_only ? 'Medication only' : statusLabel(visit.visit_source)));
                addCell(row, statusLabel(visit.status));
            }
            target.appendChild(row);
        });
    }

    async function loadArrivals() {
        const body = document.querySelector('[data-arrival-rows]');
        if (!body) return;
        const data = await parseRequest('/api/reception/arrivals');
        renderVisits(data.visits, body, 6);
    }

    async function loadQueue() {
        const body = document.querySelector('[data-queue-rows]');
        if (!body) return;
        const status = document.querySelector('[data-queue-status]').value;
        const data = await parseRequest('/api/reception/queue', { query: { status } });
        renderVisits(data.visits, body, 7);
    }

    async function loadReferrals() {
        const body = document.querySelector('[data-referral-rows]');
        if (!body) return;
        const data = await parseRequest('/api/reception/referrals');
        renderVisits(data.visits, body, 6, true);
    }

    async function loadPatientRecord(patientId) {
        const data = await parseRequest(`/api/reception/patients/${encodeURIComponent(patientId)}`);
        const patient = data.patient;
        document.querySelector('[data-record-panel]').hidden = false;
        document.querySelector('[data-record-title]').textContent =
            `${patientName(patient)} · ${patient.patient_number}`;
        document.querySelector('[data-create-visit]').href =
            `arrivals.php?patient_id=${encodeURIComponent(patient.patient_id)}`;
        const details = document.querySelector('[data-record-details]');
        details.replaceChildren();
        [
            ['National ID', patient.national_id],
            ['Date of birth', patient.date_of_birth],
            ['Gender', patient.gender],
            ['Phone', patient.phone],
            ['Address', patient.address],
            ['Registered', dateTime(patient.created_at)],
        ].forEach(([label, value]) => {
            const term = document.createElement('dt');
            term.textContent = label;
            const description = document.createElement('dd');
            description.textContent = value || '—';
            details.append(term, description);
        });
        const body = document.querySelector('[data-record-visits]');
        if (!data.visits.length) {
            showEmpty(body, 5, 'No visits recorded for this patient.');
            return;
        }
        body.replaceChildren();
        data.visits.forEach((visit) => {
            const row = document.createElement('tr');
            addCell(row, `#${visit.visit_id}`);
            addCell(row, dateTime(visit.arrived_at));
            addCell(row, visit.reason_for_visit || statusLabel(visit.visit_source));
            addCell(row, visit.is_emergency ? 'Emergency' : 'No');
            addCell(row, statusLabel(visit.status));
            body.appendChild(row);
        });
    }

    async function init() {
        const date = document.querySelector('[data-current-date]');
        if (date) {
            date.textContent = new Intl.DateTimeFormat(undefined, {
                weekday: 'long',
                month: 'long',
                day: 'numeric',
            }).format(new Date());
        }
        document.querySelector('[data-sign-out]').addEventListener('click', signOut);
        bindPatientSearch();
        bindRegistration();
        bindTicketPayment();
        try {
            if (!await checkSession()) return;
            if (page === 'overview') await loadDashboard();
            if (page === 'appointments') {
                bindAppointmentSearch();
                document.querySelector('[data-appointment-form]').addEventListener('submit', submitAppointment);
                document.querySelector('[data-refresh-appointments]').addEventListener('click', () => loadAppointments().catch((error) => showMessage(error.message)));
                const minDate = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                document.querySelector('#appointment-date').min = minDate;
                await loadAppointments();
            }
            if (page === 'arrivals') {
                bindArrivals();
                await Promise.all([loadOrganizations(), loadArrivals()]);
                const patientId = new URLSearchParams(window.location.search).get('patient_id');
                if (patientId) {
                    const patientData = await parseRequest(`/api/reception/patients/${encodeURIComponent(patientId)}`);
                    selectedArrivalPatient = patientData.patient;
                    document.querySelector('[data-arrival-form]').elements.patient_id.value = patientData.patient.patient_id;
                    document.querySelector('[data-arrival-selected-label]').textContent =
                        `${patientName(patientData.patient)} · ${patientData.patient.patient_number}`;
                    document.querySelector('[data-arrival-selected]').classList.add('has-patient');
                    document.querySelector('[data-arrival-submit]').disabled = false;
                }
                document.querySelector('[data-refresh-arrivals]').addEventListener('click', () => loadArrivals().catch((error) => showMessage(error.message)));
            }
            if (page === 'queue') {
                document.querySelector('[data-queue-status]').addEventListener('change', () => loadQueue().catch((error) => showMessage(error.message)));
                await loadQueue();
            }
            if (page === 'referrals') await loadReferrals();
            if (page === 'patient-records') {
                const patientId = new URLSearchParams(window.location.search).get('patient_id');
                if (patientId) await loadPatientRecord(patientId);
            }
        } catch (error) {
            showMessage(error.message);
        }
    }

    init();
})();
