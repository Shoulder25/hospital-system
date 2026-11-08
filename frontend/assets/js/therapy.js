'use strict';

(() => {
    const body = document.body;
    const projectBase = body.dataset.basePath || '';
    const page = body.dataset.therapyPage || 'index';
    const apiEntry = new URL(`${projectBase}/backend/index.php`, window.location.origin);
    const state = {
        csrfToken: '',
        referrals: [],
        selected: null,
        pendingSessionRequest: null,
        searchTimer: null,
    };
    const workflowPages = {
        assessments: ['[data-assessment-form]'],
        sessions: ['[data-session-form]', '[data-completion-section]'],
        'treatment-plans': ['[data-plan-form]'],
        progress: ['[data-progress-form]'],
        referrals: ['[data-review-section]'],
        pending: ['[data-review-section]'],
        'referral-details': [
            '[data-assessment-form]', '[data-plan-form]', '[data-session-form]',
            '[data-progress-form]', '[data-upload-form]', '[data-review-section]',
            '[data-completion-section]',
        ],
    };
    const messageElement = document.querySelector('[data-therapy-message]');

    async function request(route, options = {}) {
        const { query, ...fetchOptions } = options;
        const headers = new Headers(options.headers || {});
        const url = new URL(apiEntry.href);
        url.searchParams.set('route', route);
        if (query && typeof query === 'object') {
            Object.entries(query).forEach(([key, value]) => {
                if (value !== null && value !== undefined && value !== '') {
                    url.searchParams.set(key, String(value));
                }
            });
        }
        const isFormData = options.body instanceof FormData;
        if (options.body && !isFormData && !headers.has('Content-Type')) {
            headers.set('Content-Type', 'application/json');
        }
        if (state.csrfToken && options.method && options.method !== 'GET') {
            headers.set('X-CSRF-Token', state.csrfToken);
        }
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
        if (!['therapist', 'system_administrator'].includes(role)) {
            window.location.replace(`${projectBase}/frontend/dashboard.php`);
            return false;
        }
        state.csrfToken = data.csrf_token;
        return true;
    }

    function addText(parent, tagName, text, className = '') {
        const element = document.createElement(tagName);
        if (className) element.className = className;
        element.textContent = text === null || text === '' ? '—' : String(text);
        parent.appendChild(element);
        return element;
    }

    function formattedDate(value, dateOnly = false) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat(undefined, dateOnly
            ? { year: 'numeric', month: 'short', day: 'numeric' }
            : { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }
        ).format(date);
    }

    function createSessionRequestId() {
        if (window.crypto.randomUUID) return window.crypto.randomUUID();
        const bytes = window.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const value = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
        return `${value.slice(0, 8)}-${value.slice(8, 12)}-${value.slice(12, 16)}-${value.slice(16, 20)}-${value.slice(20)}`;
    }

    function currentUserId() {
        return Number(body.dataset.userId || 0);
    }

    function renderQueue() {
        const list = document.querySelector('[data-therapy-list]');
        if (!list) return;
        list.replaceChildren();
        const count = document.querySelector('[data-therapy-count]');
        if (count) {
            count.textContent = `${state.referrals.length} case${state.referrals.length === 1 ? '' : 's'}`;
        }
        if (state.referrals.length === 0) {
            addText(list, 'p', 'No therapy referrals match this queue.', 'therapy-empty');
            return;
        }

        state.referrals.forEach((referral) => {
            const card = document.createElement('article');
            card.className = 'therapy-referral-card';
            const name = addText(
                card,
                'strong',
                referral.patient_name || 'Temporary patient',
                'therapy-card-patient'
            );
            name.title = `Patient ID ${referral.patient_id} · ${referral.patient_number || 'No patient number'}`;
            addText(card, 'span', `Patient #${referral.patient_id} · Visit #${referral.visit_id}`);
            addText(card, 'span', `${referral.therapy_type} · ${String(referral.status).replaceAll('_', ' ')}`);
            addText(card, 'span', `${referral.referrer.name} · ${String(referral.referrer.role).replaceAll('_', ' ')}`);
            addText(card, 'span', `Therapist: ${referral.assigned_therapist || 'Awaiting acceptance'}`);
            addText(card, 'p', referral.referral_reason, 'therapy-card-reason');
            const progress = addText(
                card,
                'small',
                `${referral.session_count || 0} sessions · ${referral.has_assessment ? 'assessed' : 'assessment pending'} · ${referral.has_treatment_plan ? 'plan saved' : 'plan pending'}`
            );
            progress.className = 'therapy-card-progress';
            const footer = document.createElement('div');
            footer.className = 'therapy-card-footer';
            addText(footer, 'small', `Referred ${formattedDate(referral.created_at)}`);
            const actions = document.createElement('div');
            actions.className = 'therapy-card-actions';
            const select = document.createElement('button');
            select.type = 'button';
            select.className = 'button button-outline button-small';
            select.textContent = 'View case';
            select.addEventListener('click', () => selectReferral(referral.therapy_referral_id));
            const details = document.createElement('a');
            details.className = 'text-link';
            details.href = `referral-details.php?id=${encodeURIComponent(referral.therapy_referral_id)}`;
            details.textContent = 'Open full record';
            actions.append(select, details);
            footer.appendChild(actions);
            card.appendChild(footer);
            list.appendChild(card);
        });
    }

    function setField(selector, value) {
        const element = document.querySelector(selector);
        if (element) element.textContent = value === null || value === '' ? '—' : String(value);
    }

    function fillForm(formSelector, values) {
        const form = document.querySelector(formSelector);
        if (!form) return;
        Object.entries(values).forEach(([name, value]) => {
            if (form.elements[name]) form.elements[name].value = value || '';
        });
    }

    function renderSessions(referral) {
        const container = document.querySelector('[data-sessions]');
        if (!container) return;
        container.replaceChildren();
        if (!referral.sessions || referral.sessions.length === 0) {
            addText(container, 'p', 'No sessions recorded yet.', 'therapy-record-empty');
        } else {
            referral.sessions.forEach((session, index) => {
                const card = document.createElement('article');
                card.className = 'therapy-session-card';
                addText(card, 'strong', `Session ${index + 1} · ${formattedDate(session.session_at)}`);
                addText(card, 'small', `Recorded by ${session.therapist_name}`);
                if (session.activities_performed) addText(card, 'p', `Activities: ${session.activities_performed}`);
                if (session.observations) addText(card, 'p', `Observations: ${session.observations}`);
                addText(card, 'p', session.session_notes || 'No notes recorded.');
                if (session.patient_response) addText(card, 'p', `Patient response: ${session.patient_response}`);
                if (session.progress_notes) addText(card, 'p', `Progress: ${session.progress_notes}`);
                if (session.challenges) addText(card, 'p', `Challenges: ${session.challenges}`);
                if (session.follow_up_recommendations) {
                    addText(card, 'p', `Follow-up: ${session.follow_up_recommendations}`);
                }
                container.appendChild(card);
            });
        }

        const select = document.querySelector('[data-session-select]');
        if (select) {
            const selectedValue = select.value;
            select.replaceChildren(new Option('Not linked to a session', ''));
            (referral.sessions || []).forEach((session, index) => {
                select.add(new Option(
                    `Session ${index + 1} · ${formattedDate(session.session_at)}`,
                    String(session.therapy_session_id)
                ));
            });
            select.value = selectedValue;
        }
    }

    function renderProgress(referral) {
        const container = document.querySelector('[data-progress-list]');
        if (!container) return;
        container.replaceChildren();
        if (!referral.progress || referral.progress.length === 0) {
            addText(container, 'p', 'No progress notes recorded.', 'therapy-record-empty');
            return;
        }
        referral.progress.forEach((entry) => {
            const card = document.createElement('article');
            card.className = 'therapy-session-card';
            addText(card, 'strong', `Progress · ${formattedDate(entry.logged_at)}`);
            addText(card, 'small', `Recorded by ${entry.therapist_name}`);
            addText(card, 'p', `Patient response: ${entry.patient_response}`);
            addText(card, 'p', `Progress: ${entry.progress_notes}`);
            if (entry.challenges) addText(card, 'p', `Challenges: ${entry.challenges}`);
            if (entry.follow_up_recommendations) {
                addText(card, 'p', `Follow-up: ${entry.follow_up_recommendations}`);
            }
            container.appendChild(card);
        });
    }

    function renderFiles(referral) {
        const container = document.querySelector('[data-files]');
        if (!container) return;
        container.replaceChildren();
        if (!referral.files || referral.files.length === 0) {
            addText(container, 'p', 'No supporting documents uploaded.', 'therapy-record-empty');
            return;
        }
        referral.files.forEach((file) => {
            const link = document.createElement('a');
            link.className = 'therapy-file-link';
            link.href = new URL(
                `${apiEntry.href}?route=${encodeURIComponent(`/api/therapy/files/${file.therapy_file_id}`)}`,
                window.location.origin
            ).href;
            link.textContent = `${file.name} · ${formattedDate(file.uploaded_at)}`;
            link.rel = 'noopener';
            container.appendChild(link);
        });
    }

    function renderSelected(referral) {
        state.selected = referral;
        const panel = document.querySelector('[data-record-panel]');
        if (!panel) return;
        panel.hidden = false;
        setField('[data-record-heading]', `${referral.patient_name || 'Patient'} · Visit #${referral.visit_id}`);
        setField('[data-record-subheading]', `Patient ID ${referral.patient_id} · ${referral.patient_number || 'No patient number'} · Referred ${formattedDate(referral.created_at)}`);
        const status = document.querySelector('[data-record-status]');
        status.textContent = String(referral.status).replaceAll('_', ' ');
        status.className = `therapy-status therapy-status-${referral.status}`;
        setField('[data-patient-id]', `${referral.patient_id} · ${referral.patient_number || 'No patient number'}`);
        setField('[data-visit-id]', referral.visit_id);
        setField('[data-patient-demographics]', `${referral.date_of_birth || 'DOB unknown'} · ${referral.gender || 'Gender unknown'}${referral.is_temporary ? ' · temporary record' : ''}`);
        setField('[data-therapist]', referral.assigned_therapist || 'Awaiting acceptance');
        setField('[data-therapy-type]', referral.therapy_type);
        setField('[data-referral-reason]', referral.referral_reason);
        setField('[data-referrer]', `${referral.referrer.name} (${String(referral.referrer.role).replaceAll('_', ' ')})`);
        setField('[data-review-status]', referral.review_status);
        setField('[data-flagged-reason]', referral.flagged_reason);
        setField('[data-diagnosis]', referral.diagnosis.description || 'No diagnosis text recorded.');
        setField('[data-findings]', referral.diagnosis.clinical_findings);
        setField('[data-doctor-plan]', referral.diagnosis.treatment_plan);
        setField('[data-presenting-complaint]', referral.consultation.presenting_complaint);
        setField('[data-clinical-observations]', referral.consultation.clinical_observations);
        setField('[data-examination-findings]', referral.consultation.examination_findings);
        setField('[data-consultation-notes]', referral.consultation.notes);
        setField('[data-patient-history]', referral.patient_history
            ? referral.patient_history.map((item) => `${formattedDate(item.arrived_at, true)} · Visit #${item.visit_id} · ${item.diagnosis || item.status}`).join('\n')
            : 'No previous visit history available.');

        const assessment = referral.assessment;
        setField('[data-assessment-function]', assessment && assessment.functional_status);
        setField('[data-assessment-findings]', assessment && assessment.assessment_findings);
        setField('[data-assessment-goals]', assessment && assessment.initial_goals);
        fillForm('[data-assessment-form]', assessment || {});
        const plan = referral.treatment_plan_record;
        setField('[data-plan-goals]', plan && plan.goals);
        setField('[data-plan-interventions]', plan && plan.interventions);
        setField('[data-plan-schedule]', plan ? `${plan.frequency || 'Frequency not set'} · ${plan.duration || 'Duration not set'}` : null);
        setField('[data-plan-review]', plan && plan.review_date ? formattedDate(plan.review_date, true) : null);
        fillForm('[data-plan-form]', plan || {});
        setField('[data-outcome]', referral.outcome ? `Outcome: ${referral.outcome}` : '');
        setField('[data-discharge-notes]', referral.discharge_notes ? `Discharge notes: ${referral.discharge_notes}` : '');

        renderSessions(referral);
        renderProgress(referral);
        renderFiles(referral);

        const mine = !referral.assigned_therapist_user_id
            || referral.assigned_therapist_user_id === currentUserId();
        const active = referral.visit_status === 'awaiting_therapy'
            && ['referred', 'in_progress'].includes(referral.status);
        const canReview = active && referral.status === 'referred' && mine
            && ['pending', 'flagged'].includes(referral.review_status);
        const canWork = active && mine && referral.review_status === 'accepted';
        const canComplete = canWork && referral.sessions.length > 0
            && Boolean(assessment) && Boolean(plan);
        const pageForms = new Set(workflowPages[page] || []);
        const forms = {
            '[data-assessment-form]': canWork,
            '[data-plan-form]': canWork,
            '[data-session-form]': canWork,
            '[data-progress-form]': canWork,
            '[data-upload-form]': canWork,
            '[data-review-section]': canReview,
            '[data-completion-form]': canComplete
                && pageForms.has('[data-completion-section]'),
        };
        Object.entries(forms).forEach(([selector, visible]) => {
            const element = document.querySelector(selector);
            if (element) element.hidden = !visible || !pageForms.has(selector);
        });
        const completionNote = document.querySelector('[data-completion-note]');
        if (completionNote) completionNote.hidden = !canWork || canComplete;
        const completionSection = document.querySelector('[data-completion-section]');
        if (completionSection) {
            completionSection.hidden = referral.status !== 'completed'
                && (!pageForms.has('[data-completion-section]') || !canWork);
        }
    }

    async function selectReferral(referralId) {
        try {
            const data = await parseRequest(`/api/therapy/referrals/${referralId}`);
            renderSelected(data.referral);
            const url = new URL(window.location.href);
            if (page === 'referral-details') url.searchParams.set('id', String(referralId));
            else url.searchParams.delete('id');
            window.history.replaceState({}, '', url.href);
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function loadQueue(search = '') {
        const list = document.querySelector('[data-therapy-list]');
        if (list) addText(list, 'p', 'Loading therapy referrals…', 'therapy-empty');
        const scope = body.dataset.therapyScope || 'all';
        try {
            const data = await parseRequest('/api/therapy/queue', {
                query: { scope, search: search.trim() },
            });
            state.referrals = data.referrals;
            renderQueue();
            const selectedId = body.dataset.referralId;
            if (selectedId) {
                await selectReferral(selectedId);
            } else if (document.querySelector('[data-record-panel]') && page !== 'referral-details'
                && state.selected && state.referrals.some((item) => item.therapy_referral_id === state.selected.therapy_referral_id)) {
                await selectReferral(state.selected.therapy_referral_id);
            }
        } catch (error) {
            if (list) {
                list.replaceChildren();
                addText(list, 'p', error.message, 'therapy-empty');
            }
            showMessage(error.message);
        }
    }

    async function loadDashboard() {
        try {
            const data = await parseRequest('/api/therapy/dashboard');
            Object.entries(data.counts).forEach(([name, value]) => {
                const element = document.querySelector(`[data-stat="${name}"]`);
                if (element) element.textContent = String(value);
            });
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function loadReports() {
        try {
            const data = await parseRequest('/api/therapy/reports');
            Object.entries(data.counts).forEach(([name, value]) => {
                const element = document.querySelector(`[data-stat="${name}"]`);
                if (element) element.textContent = String(value);
            });
            const rows = document.querySelector('[data-report-rows]');
            if (!rows) return;
            rows.replaceChildren();
            if (data.completed_cases.length === 0) {
                const row = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = 6;
                cell.className = 'table-message';
                cell.textContent = 'No completed therapy cases are available.';
                row.appendChild(cell);
                rows.appendChild(row);
                return;
            }
            data.completed_cases.forEach((item) => {
                const row = document.createElement('tr');
                const patientCell = document.createElement('td');
                const patientLink = document.createElement('a');
                patientLink.href = `referral-details.php?id=${encodeURIComponent(item.therapy_referral_id)}`;
                patientLink.textContent = `${item.patient_name} · ${item.patient_number}`;
                patientCell.appendChild(patientLink);
                row.append(
                    patientCell,
                    Object.assign(document.createElement('td'), { textContent: `#${item.visit_id}` }),
                    Object.assign(document.createElement('td'), { textContent: item.therapy_type }),
                    Object.assign(document.createElement('td'), { textContent: `${item.referrer_name} (${String(item.referrer_role).replaceAll('_', ' ')})` }),
                    Object.assign(document.createElement('td'), { textContent: item.outcome || item.discharge_notes || 'Outcome not recorded' }),
                    Object.assign(document.createElement('td'), { textContent: formattedDate(item.completed_at, true) })
                );
                rows.appendChild(row);
            });
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function submitJson(form, route, successMessage, beforeReload = null) {
        if (!form.reportValidity() || !state.selected) return;
        const payload = Object.fromEntries(new FormData(form).entries());
        Object.keys(payload).forEach((key) => {
            if (payload[key] === '') payload[key] = null;
        });
        const button = form.querySelector('button[type="submit"]');
        if (button) button.disabled = true;
        try {
            const result = await parseRequest(route, {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            showMessage(successMessage(result), 'success');
            if (beforeReload) beforeReload();
            await loadQueue(document.querySelector('[data-therapy-search]')?.value || '');
            if (state.selected) await selectReferral(state.selected.therapy_referral_id);
        } catch (error) {
            showMessage(error.message);
        } finally {
            if (button) button.disabled = false;
        }
    }

    async function acceptReferral() {
        if (!state.selected) return;
        const button = document.querySelector('[data-accept]');
        button.disabled = true;
        try {
            await parseRequest(`/api/therapy/referrals/${state.selected.therapy_referral_id}/accept`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            showMessage('Referral accepted and assigned to you.', 'success');
            await loadQueue();
            await selectReferral(state.selected.therapy_referral_id);
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    async function flagReferral(event) {
        event.preventDefault();
        const form = event.currentTarget;
        await submitJson(
            form,
            `/api/therapy/referrals/${state.selected.therapy_referral_id}/flag`,
            () => 'Referral flagged for review.',
            () => form.reset()
        );
    }

    async function recordSession(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!state.selected) return;
        const payload = Object.fromEntries(new FormData(form).entries());
        Object.keys(payload).forEach((key) => {
            if (payload[key] === '') payload[key] = null;
        });
        if (!state.pendingSessionRequest
            || state.pendingSessionRequest.referralId !== state.selected.therapy_referral_id) {
            state.pendingSessionRequest = {
                referralId: state.selected.therapy_referral_id,
                requestId: createSessionRequestId(),
            };
        }
        payload.client_request_id = state.pendingSessionRequest.requestId;
        const button = form.querySelector('[data-record-session]');
        button.disabled = true;
        try {
            const result = await parseRequest(`/api/therapy/referrals/${state.selected.therapy_referral_id}/sessions`, {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            state.pendingSessionRequest = null;
            form.reset();
            showMessage(result.created
                ? 'Session recorded and added to the visit bill.'
                : 'This session had already been recorded; no duplicate charge was added.', 'success');
            await loadQueue();
            await selectReferral(state.selected.therapy_referral_id);
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    async function completeTherapy(event) {
        event.preventDefault();
        if (!state.selected) return;
        const form = event.currentTarget;
        if (!window.confirm('Complete this therapy referral and route the visit onward?')) return;
        const payload = Object.fromEntries(new FormData(form).entries());
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            const data = await parseRequest(`/api/therapy/referrals/${state.selected.therapy_referral_id}/complete`, {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            showMessage(`Therapy completed. Visit status: ${data.visit_status.replaceAll('_', ' ')}.`, 'success');
            await loadQueue();
            await selectReferral(state.selected.therapy_referral_id);
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    async function uploadFile(event) {
        event.preventDefault();
        if (!state.selected) return;
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            await parseRequest(`/api/therapy/referrals/${state.selected.therapy_referral_id}/files`, {
                method: 'POST',
                body: new FormData(form),
            });
            form.reset();
            showMessage('Therapy document uploaded.', 'success');
            await selectReferral(state.selected.therapy_referral_id);
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    function setWorkflowForms() {
        const visible = new Set(workflowPages[page] || []);
        document.querySelectorAll(
            '[data-assessment-form], [data-plan-form], [data-session-form], [data-progress-form], [data-upload-form], [data-review-section], [data-completion-section]'
        ).forEach((element) => {
            if (!visible.has(`[${element.getAttributeNames().find((name) => name.startsWith('data-'))}]`)) {
                element.hidden = true;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', async () => {
        setWorkflowForms();
        document.querySelector('[data-sign-out]')?.addEventListener('click', async () => {
            try {
                await parseRequest('/api/auth/logout', { method: 'POST' });
                window.location.replace(`${projectBase}/auth/login.php`);
            } catch (error) {
                showMessage(error.message);
            }
        });
        document.querySelectorAll('[data-refresh]').forEach((button) => {
            button.addEventListener('click', () => {
                loadQueue(document.querySelector('[data-therapy-search]')?.value || '');
                if (page === 'index') loadDashboard();
                if (page === 'reports') loadReports();
            });
        });
        document.querySelector('[data-accept]')?.addEventListener('click', acceptReferral);
        document.querySelector('[data-flag-form]')?.addEventListener('submit', flagReferral);
        document.querySelector('[data-assessment-form]')?.addEventListener('submit', (event) => {
            event.preventDefault();
            submitJson(
                event.currentTarget,
                `/api/therapy/referrals/${state.selected.therapy_referral_id}/assessment`,
                () => 'Initial assessment saved.'
            );
        });
        document.querySelector('[data-plan-form]')?.addEventListener('submit', (event) => {
            event.preventDefault();
            submitJson(
                event.currentTarget,
                `/api/therapy/referrals/${state.selected.therapy_referral_id}/treatment-plan`,
                () => 'Treatment plan saved.'
            );
        });
        document.querySelector('[data-progress-form]')?.addEventListener('submit', (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            submitJson(
                form,
                `/api/therapy/referrals/${state.selected.therapy_referral_id}/progress`,
                () => 'Progress saved.',
                () => form.reset()
            );
        });
        document.querySelector('[data-session-form]')?.addEventListener('submit', recordSession);
        document.querySelector('[data-completion-form]')?.addEventListener('submit', completeTherapy);
        document.querySelector('[data-upload-form]')?.addEventListener('submit', uploadFile);
        const search = document.querySelector('[data-therapy-search]');
        search?.addEventListener('input', () => {
            window.clearTimeout(state.searchTimer);
            state.searchTimer = window.setTimeout(() => loadQueue(search.value), 250);
        });

        try {
            if (!await verifySession()) return;
            await loadQueue();
            if (page === 'index') await loadDashboard();
            if (page === 'reports') await loadReports();
            if (page === 'referral-details' && !body.dataset.referralId) {
                showMessage('Open a referral from the referrals list to view its details.');
            }
        } catch (error) {
            showMessage(error.message);
        }
    });
})();
