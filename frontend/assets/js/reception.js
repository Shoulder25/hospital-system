'use strict';

(() => {
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    const messageElement = document.querySelector('[data-reception-message]');
    let csrfToken = '';
    let organizations = [];
    let detailsPatient = null;
    let completionPatientId = null;

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

    function redirectToLogin() {
        window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
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
        const role = payload.data.user.role.name;
        if (!['receptionist', 'system_administrator'].includes(role)) {
            window.location.replace(`${projectBase}frontend/dashboard.php`);
            return false;
        }
        csrfToken = payload.data.csrf_token;
        return true;
    }

    async function parseRequest(route, options, fallback) {
        const { response, payload } = await request(route, options);
        if (response.status === 401) {
            redirectToLogin();
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

    function appendTextCell(row, value, className = '') {
        const cell = document.createElement('td');
        if (className) cell.className = className;
        cell.textContent = value === null || value === '' ? '—' : String(value);
        row.appendChild(cell);
        return cell;
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return value;
        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        }).format(date);
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

    function setEmptyRow(body, columns, message) {
        body.replaceChildren();
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = columns;
        cell.className = 'table-message';
        cell.textContent = message;
        row.appendChild(cell);
        body.appendChild(row);
    }

    function setSelectedPatient(patient) {
        const selected = document.querySelector('[data-selected-patient]');
        const label = document.querySelector('[data-selected-patient-label]');
        const patientId = document.querySelector('[data-visit-form] [name="patient_id"]');
        const submit = document.querySelector('[data-visit-submit]');
        if (!patient) {
            patientId.value = '';
            label.textContent = 'No patient selected';
            selected.classList.remove('has-patient');
            submit.disabled = true;
            document.querySelectorAll('[data-visit-form] [name="arrival_type"]').forEach((input) => {
                input.disabled = false;
            });
            return;
        }

        patientId.value = String(patient.patient_id);
        label.textContent = patient.is_temporary
            ? `Temporary emergency record · ${patient.patient_number}`
            : `${patient.first_name} ${patient.last_name} · ${patient.patient_number}`;
        selected.classList.add('has-patient');
        submit.disabled = false;
        if (patient.is_temporary) {
            document.querySelector('[data-visit-form] [value="emergency"]').checked = true;
        }
        document.querySelectorAll('[data-visit-form] [name="arrival_type"]').forEach((input) => {
            input.disabled = Boolean(patient.is_temporary) && input.value !== 'emergency';
        });
    }

    function renderPatientRows(patients, target, includeDob) {
        target.replaceChildren();
        if (patients.length === 0) {
            setEmptyRow(target, includeDob ? 5 : 4, 'No matching patient records.');
            return;
        }
        patients.forEach((patient) => {
            const row = document.createElement('tr');
            appendTextCell(
                row,
                patient.is_temporary && !patient.first_name && !patient.last_name
                    ? 'Unknown emergency patient'
                    : `${patient.first_name || ''} ${patient.last_name || ''}`.trim()
            );
            appendTextCell(row, patient.patient_number);
            if (includeDob) {
                appendTextCell(row, formatDate(patient.date_of_birth));
                appendTextCell(row, patient.national_id);
            } else {
                appendTextCell(row, formatDateTime(patient.created_at));
            }
            const actions = appendTextCell(row, '', 'reception-actions');
            const viewButton = document.createElement('button');
            viewButton.type = 'button';
            viewButton.className = 'status-toggle';
            viewButton.textContent = 'View details';
            viewButton.addEventListener('click', () => loadPatientDetails(patient.patient_id));
            actions.appendChild(viewButton);

            const useButton = document.createElement('button');
            useButton.type = 'button';
            useButton.className = 'status-toggle reception-select-button';
            useButton.textContent = 'Select';
            useButton.addEventListener('click', () => {
                setSelectedPatient(patient);
                document.querySelector('[data-visit-form]').scrollIntoView({
                    behavior: 'smooth',
                    block: 'center',
                });
            });
            actions.appendChild(useButton);
            target.appendChild(row);
        });
    }

    async function loadDashboard() {
        const data = await parseRequest(
            '/api/reception/dashboard',
            {},
            'Could not load reception statistics.'
        );
        Object.entries(data.stats).forEach(([key, value]) => {
            const element = document.querySelector(`[data-stat="${key}"]`);
            if (element) element.textContent = String(value);
        });
        renderPatientRows(
            data.recent_patients,
            document.querySelector('[data-recent-patients]'),
            false
        );
    }

    async function loadOrganizations() {
        const data = await parseRequest(
            '/api/reception/organizations',
            {},
            'Could not load visit source organizations.'
        );
        organizations = data.organizations;
        updateOrganizationOptions();
    }

    function updateOrganizationOptions() {
        const source = document.querySelector('#visit-source').value;
        const field = document.querySelector('[data-organization-field]');
        const select = document.querySelector('#visit-organization');
        const expectedType = source === 'school_appointment'
            ? 'school'
            : (source === 'referral' ? 'referring_facility' : null);
        field.hidden = expectedType === null;
        select.required = expectedType !== null;
        select.replaceChildren(new Option(
            expectedType === 'school'
                ? 'Choose a school'
                : 'Choose a referring facility',
            ''
        ));
        organizations
            .filter((organization) => organization.organization_type === expectedType)
            .forEach((organization) => {
                select.add(new Option(organization.name, String(organization.organization_id)));
            });
    }

    async function searchPatients(search) {
        hideMessage();
        const body = document.querySelector('[data-search-results]');
        setEmptyRow(body, 5, 'Searching patient records…');
        try {
            const data = await parseRequest(
                '/api/reception/patients',
                { query: { search } },
                'Could not search patient records.'
            );
            renderPatientRows(data.patients, body, true);
        } catch (error) {
            setEmptyRow(body, 5, error.message);
        }
    }

    async function loadPatientDetails(patientId) {
        hideMessage();
        try {
            const data = await parseRequest(
                `/api/reception/patients/${encodeURIComponent(patientId)}`,
                {},
                'Could not load patient details.'
            );
            const patient = data.patient;
            detailsPatient = patient;
            const panel = document.querySelector('[data-patient-details]');
            const summary = document.querySelector('[data-patient-details-summary]');
            const fields = document.querySelector('[data-patient-detail-fields]');
            const visits = document.querySelector('[data-patient-visits]');
            const patientName = [patient.first_name, patient.last_name].filter(Boolean).join(' ')
                || 'Unknown patient';
            summary.textContent = `${patient.is_temporary ? 'Temporary record · ' : ''}${patientName} · ${patient.patient_number}`;
            document.querySelector('[data-complete-record]').hidden = !patient.is_temporary;
            fields.replaceChildren();
            [
                ['National ID', patient.national_id],
                ['Date of birth', formatDate(patient.date_of_birth)],
                ['Gender', patient.gender],
                ['Phone', patient.phone],
                ['Address', patient.address],
                ['Registered', formatDateTime(patient.created_at)],
            ].forEach(([label, value]) => {
                const term = document.createElement('dt');
                term.textContent = label;
                const description = document.createElement('dd');
                description.textContent = value || '—';
                fields.append(term, description);
            });
            visits.replaceChildren();
            if (data.visits.length === 0) {
                setEmptyRow(visits, 5, 'No visits recorded for this patient.');
            } else {
                data.visits.forEach((visit) => {
                    const row = document.createElement('tr');
                    appendTextCell(row, `#${visit.visit_id}`);
                    appendTextCell(row, formatDateTime(visit.arrived_at));
                    appendTextCell(row, visit.visit_source.replaceAll('_', ' '));
                    appendTextCell(
                        row,
                        visit.is_emergency
                            ? 'Emergency'
                            : (visit.medication_collection_only ? 'Medication collection' : 'Standard')
                    );
                    appendTextCell(row, visit.status.replaceAll('_', ' '));
                    visits.appendChild(row);
                });
            }
            panel.hidden = false;
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            showMessage(error.message);
        }
    }

    function registrationBody(form, allowPossibleDuplicate = false) {
        const values = new FormData(form);
        return {
            first_name: values.get('first_name').trim(),
            last_name: values.get('last_name').trim(),
            national_id: values.get('national_id').trim(),
            date_of_birth: values.get('date_of_birth'),
            gender: values.get('gender'),
            phone: values.get('phone').trim(),
            address: values.get('address').trim(),
            is_temporary: values.get('is_temporary') === 'true',
            allow_possible_duplicate: allowPossibleDuplicate,
        };
    }

    async function createPatient(form, button, allowPossibleDuplicate = false) {
        const requestBody = registrationBody(form, allowPossibleDuplicate);
        const data = await parseRequest(
            '/api/reception/patients',
            {
                method: 'POST',
                body: JSON.stringify(requestBody),
            },
            'Could not register the patient.'
        );
        form.reset();
        completionPatientId = null;
        toggleTemporaryPatientFields();
        button.textContent = 'Register patient';
        setSelectedPatient(data.patient);
        await Promise.all([loadDashboard(), searchPatients(data.patient.patient_number)]);
        showMessage(
            `${data.patient.is_temporary ? 'Temporary emergency record created.' : 'Patient registered.'} Patient number: ${data.patient.patient_number}`,
            'success'
        );
        return data.patient;
    }

    async function completePatient(form, button) {
        const requestBody = registrationBody(form);
        delete requestBody.is_temporary;
        delete requestBody.allow_possible_duplicate;
        const data = await parseRequest(
            `/api/reception/patients/${encodeURIComponent(completionPatientId)}`,
            { method: 'PATCH', body: JSON.stringify(requestBody) },
            'Could not complete the temporary patient record.'
        );
        const patientId = completionPatientId;
        completionPatientId = null;
        form.reset();
        toggleTemporaryPatientFields();
        button.dataset.originalText = 'Register patient';
        button.textContent = 'Register patient';
        await Promise.all([loadDashboard(), loadPatientDetails(patientId)]);
        showMessage(`Patient details completed for ${data.patient.patient_number}.`, 'success');
    }

    function possibleDuplicatePrompt(error) {
        const duplicates = error.payload
            && error.payload.errors
            && Array.isArray(error.payload.errors.possible_duplicates)
            ? error.payload.errors.possible_duplicates
            : [];
        if (error.status !== 409 || duplicates.length === 0) return false;
        const list = duplicates.map((patient) =>
            `${patient.first_name} ${patient.last_name} — ${patient.patient_number}`
        ).join('\n');
        return window.confirm(
            `${error.payload.message}\n\n${list}\n\nRegister a separate record anyway?`
        );
    }

    function bindPatientRegistration() {
        const form = document.querySelector('[data-patient-form]');
        const button = document.querySelector('[data-register-submit]');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage();
            if (!form.reportValidity()) return;

            setBusy(button, true, 'Registering…');
            try {
                if (completionPatientId !== null) {
                    await completePatient(form, button);
                    return;
                }
                try {
                    await createPatient(form, button);
                } catch (error) {
                    if (possibleDuplicatePrompt(error)) {
                        await createPatient(form, button, true);
                    } else {
                        throw error;
                    }
                }
            } catch (error) {
                showMessage(error.message);
            } finally {
                setBusy(button, false);
            }
        });
    }

    function bindPatientSearch() {
        const form = document.querySelector('[data-patient-search-form]');
        const button = document.querySelector('[data-search-submit]');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const search = new FormData(form).get('search').trim();
            setBusy(button, true, 'Searching…');
            try {
                await searchPatients(search);
            } finally {
                setBusy(button, false);
            }
        });
    }

    function visitConfirmation(arrivalType, source) {
        if (arrivalType === 'emergency') {
            return 'This visit will go directly to emergency care. Payment is deferred.';
        }
        if (arrivalType === 'medication_collection_only') {
            return 'This visit will go to pharmacy processing and skip the ticket fee and nursing assessment.';
        }
        const sourceLabel = source === 'walk_in'
            ? 'walk-in'
            : source.replaceAll('_', ' ');
        return `This ${sourceLabel} visit will require ticket-fee payment before nursing assessment.`;
    }

    function bindVisitCreation() {
        const form = document.querySelector('[data-visit-form]');
        const button = document.querySelector('[data-visit-submit]');
        document.querySelector('#visit-source').addEventListener('change', updateOrganizationOptions);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage();
            if (!form.reportValidity()) return;
            const values = new FormData(form);
            const patientId = values.get('patient_id');
            if (!patientId) {
                showMessage('Select an existing or newly registered patient first.');
                return;
            }
            const arrivalType = values.get('arrival_type');
            const source = values.get('visit_source');
            const organizationId = values.get('source_organization_id');
            if (!window.confirm(visitConfirmation(arrivalType, source))) return;

            const payload = {
                patient_id: Number(patientId),
                is_emergency: arrivalType === 'emergency',
                medication_collection_only: arrivalType === 'medication_collection_only',
                visit_source: source,
                source_organization_id: organizationId ? Number(organizationId) : null,
                source_notes: values.get('source_notes').trim(),
            };
            setBusy(button, true, 'Starting visit…');
            try {
                const data = await parseRequest(
                    '/api/reception/visits',
                    { method: 'POST', body: JSON.stringify(payload) },
                    'Could not start the visit.'
                );
                showMessage(
                    `Visit #${data.visit.visit_id} started. Status: ${data.visit.status.replaceAll('_', ' ')}.`,
                    'success'
                );
                form.reset();
                updateOrganizationOptions();
                setSelectedPatient(null);
                await loadDashboard();
            } catch (error) {
                showMessage(error.message);
            } finally {
                setBusy(button, false);
            }
        });
    }

    function toggleTemporaryPatientFields() {
        const isTemporary = document.querySelector('[data-patient-form] [name="is_temporary"]').checked;
        ['#patient-first-name', '#patient-last-name', '#patient-dob'].forEach((selector) => {
            const field = document.querySelector(selector);
            field.required = !isTemporary;
        });
        document.querySelectorAll('[data-temporary-optional]').forEach((marker) => {
            marker.hidden = isTemporary;
        });
        if (isTemporary) {
            document.querySelector('#patient-gender').value = 'unknown';
        }
    }

    function startPatientCompletion(patient) {
        completionPatientId = patient.patient_id;
        const form = document.querySelector('[data-patient-form]');
        form.elements.first_name.value = patient.first_name || '';
        form.elements.last_name.value = patient.last_name || '';
        form.elements.national_id.value = patient.national_id || '';
        form.elements.date_of_birth.value = patient.date_of_birth || '';
        form.elements.gender.value = patient.gender || 'unknown';
        form.elements.phone.value = patient.phone || '';
        form.elements.address.value = patient.address || '';
        form.elements.is_temporary.checked = false;
        toggleTemporaryPatientFields();
        document.querySelector('[data-register-submit]').textContent = 'Save completed details';
        showMessage('Complete the emergency patient details, then save the record.', 'success');
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        document.querySelector('#patient-first-name').focus();
    }

    function bindSignOut() {
        document.querySelector('[data-sign-out]').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            setBusy(button, true, 'Signing out…');
            try {
                await parseRequest(
                    '/api/auth/logout',
                    { method: 'POST', body: JSON.stringify({}) },
                    'Could not sign out.'
                );
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
        document.querySelector('[data-close-details]').addEventListener('click', () => {
            document.querySelector('[data-patient-details]').hidden = true;
        });
        document.querySelector('[data-complete-record]').addEventListener('click', () => {
            if (detailsPatient && detailsPatient.is_temporary) {
                startPatientCompletion(detailsPatient);
            }
        });
        document.querySelector('[data-patient-form] [name="is_temporary"]').addEventListener(
            'change',
            toggleTemporaryPatientFields
        );
        toggleTemporaryPatientFields();
        bindPatientSearch();
        bindPatientRegistration();
        bindVisitCreation();
        bindSignOut();

        try {
            if (!await checkSession()) return;
            await Promise.all([loadDashboard(), loadOrganizations()]);
        } catch (error) {
            showMessage(error.message);
        }
    }

    init();
})();
