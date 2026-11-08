'use strict';

(() => {
    const projectBase = document.body.dataset.basePath || '';
    const apiEntry = new URL(`${projectBase}/backend/index.php`, window.location.origin);
    const state = { csrfToken: '', referrals: [], selectedId: null, medicines: [] };
    const messageElement = document.querySelector('[data-specialist-message]');

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

    function showMessage(message, type = 'error', link = null) {
        messageElement.replaceChildren();
        messageElement.textContent = message;
        messageElement.classList.toggle('error', type === 'error');
        messageElement.classList.toggle('success', type === 'success');
        if (link) {
            messageElement.append(document.createTextNode(' '));
            const anchor = document.createElement('a');
            anchor.href = link.href;
            anchor.textContent = link.label;
            messageElement.appendChild(anchor);
        }
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
        const role = data.user.role.name;
        if (!['resident_specialist', 'locum_specialist', 'system_administrator'].includes(role)) {
            window.location.replace(`${projectBase}/frontend/dashboard.php`);
            return false;
        }
        state.csrfToken = data.csrf_token;
        return true;
    }

    function pretty(value) {
        return value ? String(value).replaceAll('_', ' ') : '—';
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

    function addText(parent, tagName, text, className = '') {
        const element = document.createElement(tagName);
        if (className) element.className = className;
        element.textContent = text === null || text === '' ? '—' : String(text);
        parent.appendChild(element);
        return element;
    }

    function showEmptyList(text) {
        const list = document.querySelector('[data-referral-list]');
        list.replaceChildren();
        addText(list, 'p', text, 'specialist-empty');
    }

    function renderReferralList() {
        const list = document.querySelector('[data-referral-list]');
        list.replaceChildren();
        document.querySelector('[data-referral-count]').textContent =
            `${state.referrals.length} referral${state.referrals.length === 1 ? '' : 's'}`;
        if (state.referrals.length === 0) {
            showEmptyList('No pending or accepted referrals.');
            return;
        }
        state.referrals.forEach((referral) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'specialist-referral-card';
            button.classList.toggle('selected', referral.specialist_referral_id === state.selectedId);
            button.addEventListener('click', () => selectReferral(referral.specialist_referral_id));
            const patient = addText(
                button,
                'strong',
                referral.patient_name || 'Temporary patient',
                'specialist-card-patient'
            );
            patient.title = referral.patient_number || '';
            addText(button, 'span', `Visit #${referral.visit_id} · ${pretty(referral.details.referral.status)}`);
            addText(button, 'span', referral.details.referral.reason, 'specialist-card-reason');
            addText(button, 'small', formattedDate(referral.created_at));
            list.appendChild(button);
        });
    }

    function addRecordSection(container, title, entries) {
        const section = document.createElement('section');
        section.className = 'specialist-record-section';
        addText(section, 'h3', title);
        if (!entries || entries.length === 0) {
            addText(section, 'p', 'No record available.', 'specialist-record-empty');
        } else {
            entries.forEach((entry) => {
                const card = document.createElement('article');
                card.className = 'specialist-history-card';
                Object.entries(entry).forEach(([key, value]) => {
                    if (value === null || value === '') return;
                    addText(card, 'strong', pretty(key));
                    addText(card, 'p', value);
                });
                section.appendChild(card);
            });
        }
        container.appendChild(section);
    }

    function renderRecord(referral) {
        const panel = document.querySelector('[data-record-panel]');
        const details = referral.details;
        document.querySelector('[data-record-heading]').textContent =
            `${referral.patient_name || 'Patient'} · Visit #${referral.visit_id}`;
        document.querySelector('[data-record-subheading]').textContent =
            `${referral.patient_number || 'No patient number'} · Referred ${formattedDate(referral.created_at)}`;
        const status = document.querySelector('[data-record-status]');
        status.textContent = pretty(details.referral.status);
        status.className = `specialist-status specialist-status-${details.referral.status}`;

        const content = document.querySelector('[data-record]');
        content.replaceChildren();
        const patientSection = document.createElement('section');
        patientSection.className = 'specialist-record-section';
        addText(patientSection, 'h3', 'Patient details');
        addText(patientSection, 'strong', 'Name');
        addText(patientSection, 'p', details.patient.name);
        addText(patientSection, 'strong', 'Patient number');
        addText(patientSection, 'p', details.patient.patient_number);
        addText(patientSection, 'strong', 'Date of birth');
        addText(patientSection, 'p', details.patient.date_of_birth || '—');
        addText(patientSection, 'strong', 'Gender');
        addText(patientSection, 'p', details.patient.gender || '—');
        if (details.patient.is_temporary) {
            addText(patientSection, 'p', 'Temporary patient record.');
        }
        content.appendChild(patientSection);
        const reasonSection = document.createElement('section');
        reasonSection.className = 'specialist-record-section';
        addText(reasonSection, 'h3', 'Referral');
        addText(reasonSection, 'strong', 'Reason');
        addText(reasonSection, 'p', details.referral.reason);
        if (details.referral.clinical_summary) {
            addText(reasonSection, 'strong', 'Referring clinical summary');
            addText(reasonSection, 'p', details.referral.clinical_summary);
        }
        content.appendChild(reasonSection);
        addRecordSection(content, 'Referring doctor consultation', details.referring_consultation
            ? [details.referring_consultation]
            : []);
        addRecordSection(content, 'Nursing assessments', details.nursing_assessments);
        addRecordSection(content, 'Test orders and results', details.tests);
        addRecordSection(content, 'Doctor consultations and diagnoses', [
            ...details.consultations,
            ...details.diagnoses,
        ]);
        const therapyReports = (details.therapy_reports || []).flatMap((report) => [
            {
                therapy_type: report.therapy_type,
                status: report.status,
                review_status: report.review_status,
                assigned_therapist: report.therapist_name,
                outcome: report.outcome,
                discharge_notes: report.discharge_notes,
                completed_at: report.completed_at,
            },
            ...report.sessions.map((session) => ({
                session_at: session.session_at,
                session_notes: session.session_notes,
                patient_response: session.patient_response,
                progress_notes: session.progress_notes,
                follow_up_recommendations: session.follow_up_recommendations,
            })),
            ...report.progress.map((progress) => ({
                progress_recorded_at: progress.logged_at,
                patient_response: progress.patient_response,
                progress_notes: progress.progress_notes,
                challenges: progress.challenges,
                follow_up_recommendations: progress.follow_up_recommendations,
            })),
        ]);
        addRecordSection(content, 'Therapy reports and progress', therapyReports);
        panel.hidden = false;
        const isPending = details.referral.status === 'pending';
        document.querySelector('[data-accept-row]').hidden = !isPending;
        document.querySelector('[data-outcome-panel]').hidden = details.referral.status !== 'accepted';
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function selectReferral(referralId) {
        state.selectedId = referralId;
        const referral = state.referrals.find(
            (item) => item.specialist_referral_id === referralId
        );
        if (!referral) return;
        renderReferralList();
        renderRecord(referral);
    }

    function populateMedicines(medicines) {
        state.medicines = medicines;
        const select = document.querySelector('[data-medicine-select]');
        select.replaceChildren();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = medicines.length ? 'Select medicine' : 'No active medicines';
        select.appendChild(placeholder);
        medicines.forEach((medicine) => {
            const option = document.createElement('option');
            option.value = String(medicine.medicine_id);
            option.textContent = `${medicine.medicine_name}${medicine.is_demo ? ' (demo)' : ''}`;
            select.appendChild(option);
        });
    }

    async function loadReferrals(preferredId = state.selectedId) {
        showEmptyList('Loading assigned referrals…');
        try {
            const data = await parseRequest('/api/specialists/referrals');
            state.referrals = data.referrals;
            populateMedicines(data.medicines);
            renderReferralList();
            if (preferredId && state.referrals.some(
                (referral) => referral.specialist_referral_id === preferredId
            )) {
                selectReferral(preferredId);
            } else {
                state.selectedId = null;
                document.querySelector('[data-record-panel]').hidden = true;
                document.querySelector('[data-outcome-panel]').hidden = true;
            }
        } catch (error) {
            showEmptyList('Unable to load referrals.');
            showMessage(error.message);
        }
    }

    async function acceptReferral() {
        const referralId = state.selectedId;
        if (!referralId || !window.confirm('Accept this assigned specialist referral?')) return;
        const button = document.querySelector('[data-accept]');
        button.disabled = true;
        try {
            await parseRequest(`/api/specialists/referrals/${referralId}/accept`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            showMessage('Referral accepted. Review the record and submit the specialist outcome.', 'success');
            await loadReferrals(referralId);
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    function updateOutcomeFields() {
        const form = document.querySelector('[data-outcome-form]');
        const diagnosed = form.elements.outcome.value === 'diagnosed';
        document.querySelector('[data-diagnosed-fields]').hidden = !diagnosed;
        document.querySelector('[data-outside-fields]').hidden = diagnosed;
        const diagnosisField = form.elements.diagnosis_description;
        diagnosisField.required = diagnosed;
        form.elements.provider_name.required = !diagnosed;
        form.elements.clinical_summary.required = !diagnosed;
        form.elements.outside_tests_amount.required = !diagnosed;
    }

    function collectConsultation(form) {
        return {
            presenting_complaint: form.elements.presenting_complaint.value.trim(),
            clinical_observations: form.elements.clinical_observations.value.trim(),
            examination_findings: form.elements.examination_findings.value.trim(),
            notes: form.elements.notes.value.trim(),
        };
    }

    function collectPrescription(form) {
        if (!form.elements.create_prescription.checked) return [];
        return [{
            medicine_id: Number(form.elements.medicine_id.value),
            quantity: form.elements.quantity.value,
            dosage: form.elements.dosage.value.trim(),
            frequency: form.elements.frequency.value.trim(),
            duration: form.elements.duration.value.trim(),
            instructions: form.elements.instructions.value.trim(),
        }];
    }

    async function submitOutcome(event) {
        event.preventDefault();
        const referralId = state.selectedId;
        const form = event.currentTarget;
        if (!referralId || !form.reportValidity()) return;
        const body = {
            outcome: form.elements.outcome.value,
            ...collectConsultation(form),
        };
        if (body.outcome === 'diagnosed') {
            body.diagnosis_description = form.elements.diagnosis_description.value.trim();
            body.clinical_findings = form.elements.clinical_findings.value.trim();
            body.treatment_plan = form.elements.treatment_plan.value.trim();
            body.refer_for_therapy = form.elements.refer_for_therapy.checked;
            body.therapy_type = form.elements.therapy_type.value.trim();
            body.therapy_reason = form.elements.therapy_reason.value.trim();
            body.prescription_items = collectPrescription(form);
        } else {
            body.provider_name = form.elements.provider_name.value.trim();
            body.clinical_summary = form.elements.clinical_summary.value.trim();
            body.outside_tests_amount = form.elements.outside_tests_amount.value;
        }
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        button.textContent = 'Saving…';
        try {
            const data = await parseRequest(
                `/api/specialists/referrals/${referralId}/outcome`,
                { method: 'POST', body: JSON.stringify(body) }
            );
            if (data.outside_referral_id) {
                showMessage(
                    'Outside referral issued. Visit is awaiting payment.',
                    'success',
                    {
                        href: `outside-referral.php?outside_referral_id=${encodeURIComponent(data.outside_referral_id)}`,
                        label: 'Open printable referral',
                    }
                );
            } else {
                showMessage(`Specialist diagnosis saved. Visit status: ${pretty(data.visit_status)}.`, 'success');
            }
            form.reset();
            updateOutcomeFields();
            await loadReferrals(null);
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
            button.textContent = 'Save outcome';
        }
    }

    async function loadOutsideReferral() {
        const referralId = new URLSearchParams(window.location.search)
            .get('outside_referral_id');
        if (!referralId) {
            showMessage('An outside referral ID is required.');
            return;
        }
        try {
            const data = await parseRequest(
                `/api/specialists/outside-referrals/${encodeURIComponent(referralId)}`
            );
            const { outside_referral: referral, patient, visit } = data;
            document.querySelector('[data-letter-patient]').textContent = patient.name || 'Temporary patient';
            document.querySelector('[data-letter-patient-number]').textContent = patient.patient_number || '—';
            document.querySelector('[data-letter-visit]').textContent = String(visit.visit_id);
            document.querySelector('[data-letter-provider]').textContent = referral.provider_name;
            document.querySelector('[data-letter-date]').textContent = formattedDate(referral.created_at);
            document.querySelector('[data-letter-issuer]').textContent = referral.issued_by;
            document.querySelector('[data-letter-summary]').textContent = referral.clinical_summary;
            const reasonSection = document.querySelector('[data-letter-reason-section]');
            const diagnosisSection = document.querySelector('[data-letter-diagnosis-section]');
            reasonSection.hidden = !referral.referral_reason;
            diagnosisSection.hidden = !referral.diagnosis_description;
            document.querySelector('[data-letter-reason]').textContent =
                referral.referral_reason || '';
            document.querySelector('[data-letter-diagnosis]').textContent =
                referral.diagnosis_description || '';
            document.querySelector('[data-outside-letter]').hidden = false;
        } catch (error) {
            showMessage(error.message);
        }
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
        document.querySelector('[data-refresh]')?.addEventListener('click', () => loadReferrals());
        document.querySelector('[data-accept]')?.addEventListener('click', acceptReferral);
        document.querySelector('[data-outcome-form]')?.addEventListener('submit', submitOutcome);
        document.querySelector('[data-outcome-form]')?.elements.outcome.addEventListener(
            'change',
            updateOutcomeFields
        );
        document.querySelector('[data-outcome-form]')?.elements.refer_for_therapy
            .addEventListener('change', (event) => {
                document.querySelector('[data-therapy-fields]').hidden = !event.currentTarget.checked;
            });
        document.querySelector('[data-outcome-form]')?.elements.create_prescription
            .addEventListener('change', (event) => {
                document.querySelector('[data-prescription-fields]').hidden = !event.currentTarget.checked;
            });
        document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
        try {
            if (!await verifySession()) return;
            if (document.body.hasAttribute('data-outside-referral-page')) {
                await loadOutsideReferral();
                return;
            }
            updateOutcomeFields();
            await loadReferrals(null);
        } catch (error) {
            showMessage(error.message);
        }
    });
})();
