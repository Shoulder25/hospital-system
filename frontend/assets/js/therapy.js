'use strict';

(() => {
    const projectBase = document.body.dataset.basePath || '';
    const apiEntry = new URL(`${projectBase}/backend/index.php`, window.location.origin);
    const state = {
        csrfToken: '',
        referrals: [],
        selectedId: null,
        pendingSessionRequest: null,
    };
    const messageElement = document.querySelector('[data-therapy-message]');

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

    function showMessage(message, type = 'error') {
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

    function createSessionRequestId() {
        if (window.crypto.randomUUID) return window.crypto.randomUUID();

        const bytes = window.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const value = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
        return `${value.slice(0, 8)}-${value.slice(8, 12)}-${value.slice(12, 16)}-${value.slice(16, 20)}-${value.slice(20)}`;
    }

    function renderQueue() {
        const list = document.querySelector('[data-therapy-list]');
        list.replaceChildren();
        document.querySelector('[data-therapy-count]').textContent =
            `${state.referrals.length} referral${state.referrals.length === 1 ? '' : 's'}`;
        if (state.referrals.length === 0) {
            addText(list, 'p', 'No therapy referrals are available.', 'therapy-empty');
            return;
        }
        state.referrals.forEach((referral) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'therapy-referral-card';
            button.classList.toggle('selected', referral.therapy_referral_id === state.selectedId);
            button.addEventListener('click', () => selectReferral(referral.therapy_referral_id));
            const patient = addText(
                button,
                'strong',
                referral.patient_name || 'Temporary patient',
                'therapy-card-patient'
            );
            patient.title = referral.patient_number || '';
            addText(
                button,
                'span',
                `Visit #${referral.visit_id} · ${referral.therapy_type} · ${referral.status.replaceAll('_', ' ')}`
            );
            addText(button, 'span', referral.referral_reason, 'therapy-card-reason');
            addText(button, 'small', formattedDate(referral.created_at));
            list.appendChild(button);
        });
    }

    function renderSelected(referral) {
        document.querySelector('[data-record-heading]').textContent =
            `${referral.patient_name || 'Patient'} · Visit #${referral.visit_id}`;
        document.querySelector('[data-record-subheading]').textContent =
            `${referral.patient_number || 'No patient number'} · Referred ${formattedDate(referral.created_at)}`;
        const status = document.querySelector('[data-record-status]');
        status.textContent = referral.status.replaceAll('_', ' ');
        status.className = `therapy-status therapy-status-${referral.status}`;
        document.querySelector('[data-therapy-type]').textContent = referral.therapy_type || '—';
        document.querySelector('[data-referral-reason]').textContent = referral.referral_reason || '—';
        document.querySelector('[data-referrer]').textContent =
            `${referral.referrer.name} (${referral.referrer.role.replaceAll('_', ' ')})`;
        document.querySelector('[data-diagnosis]').textContent =
            referral.diagnosis.description || 'No diagnosis text recorded.';
        document.querySelector('[data-findings]').textContent =
            referral.diagnosis.clinical_findings || '—';
        document.querySelector('[data-treatment-plan]').textContent =
            referral.diagnosis.treatment_plan || '—';
        document.querySelector('[data-presenting-complaint]').textContent =
            referral.consultation.presenting_complaint || '—';
        document.querySelector('[data-clinical-observations]').textContent =
            referral.consultation.clinical_observations || '—';
        document.querySelector('[data-examination-findings]').textContent =
            referral.consultation.examination_findings || '—';
        document.querySelector('[data-consultation-notes]').textContent =
            referral.consultation.notes || '—';

        const sessions = document.querySelector('[data-sessions]');
        sessions.replaceChildren();
        if (referral.sessions.length === 0) {
            addText(sessions, 'p', 'No sessions recorded yet.', 'therapy-record-empty');
        } else {
            referral.sessions.forEach((session, index) => {
                const card = document.createElement('article');
                card.className = 'therapy-session-card';
                addText(card, 'strong', `Session ${index + 1} · ${formattedDate(session.session_at)}`);
                addText(card, 'small', `Recorded by ${session.therapist_name}`);
                addText(card, 'p', session.session_notes || 'No notes recorded.');
                sessions.appendChild(card);
            });
        }
        const canUpdate = referral.visit_status === 'awaiting_therapy'
            && ['referred', 'in_progress'].includes(referral.status);
        document.querySelector('[data-session-form]').hidden = !canUpdate;
        document.querySelector('[data-complete]').disabled =
            !canUpdate || referral.sessions.length === 0;
        document.querySelector('[data-record-panel]').hidden = false;
    }

    function selectReferral(referralId) {
        state.selectedId = referralId;
        renderQueue();
        const referral = state.referrals.find(
            (item) => item.therapy_referral_id === referralId
        );
        if (referral) renderSelected(referral);
    }

    async function loadQueue(preferredId = state.selectedId) {
        const list = document.querySelector('[data-therapy-list]');
        list.replaceChildren();
        addText(list, 'p', 'Loading therapy referrals…', 'therapy-empty');
        try {
            const data = await parseRequest('/api/therapy/queue');
            state.referrals = data.referrals;
            renderQueue();
            if (preferredId && state.referrals.some(
                (referral) => referral.therapy_referral_id === preferredId
            )) {
                selectReferral(preferredId);
            } else {
                state.selectedId = null;
                document.querySelector('[data-record-panel]').hidden = true;
            }
        } catch (error) {
            addText(list, 'p', 'Unable to load therapy referrals.', 'therapy-empty');
            showMessage(error.message);
        }
    }

    async function recordSession(event) {
        event.preventDefault();
        if (!state.selectedId) return;
        const form = event.currentTarget;
        if (!form.reportValidity()) return;
        const button = document.querySelector('[data-record-session]');
        button.disabled = true;
        if (!state.pendingSessionRequest
            || state.pendingSessionRequest.referralId !== state.selectedId) {
            state.pendingSessionRequest = {
                referralId: state.selectedId,
                requestId: createSessionRequestId(),
            };
        }
        try {
            const result = await parseRequest(`/api/therapy/referrals/${state.selectedId}/sessions`, {
                method: 'POST',
                body: JSON.stringify({
                    client_request_id: state.pendingSessionRequest.requestId,
                    session_notes: form.elements.session_notes.value.trim(),
                }),
            });
            state.pendingSessionRequest = null;
            form.reset();
            showMessage(result.created
                ? 'Session recorded and added to the visit bill.'
                : 'This session had already been recorded; no duplicate charge was added.', 'success');
            await loadQueue(state.selectedId);
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    async function completeTherapy() {
        if (!state.selectedId) return;
        const referralId = state.selectedId;
        if (!window.confirm('Complete this therapy referral and route the visit onward?')) return;
        const button = document.querySelector('[data-complete]');
        button.disabled = true;
        try {
            const data = await parseRequest(`/api/therapy/referrals/${referralId}/complete`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            showMessage(`Therapy completed. Visit status: ${data.visit_status.replaceAll('_', ' ')}.`, 'success');
            await loadQueue(null);
        } catch (error) {
            showMessage(error.message);
            button.disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', async () => {
        document.querySelector('[data-sign-out]').addEventListener('click', async () => {
            try {
                await parseRequest('/api/auth/logout', { method: 'POST' });
                window.location.replace(`${projectBase}/auth/login.php`);
            } catch (error) {
                showMessage(error.message);
            }
        });
        document.querySelector('[data-refresh]').addEventListener('click', () => loadQueue());
        document.querySelector('[data-session-form]').addEventListener('submit', recordSession);
        document.querySelector('[data-complete]').addEventListener('click', completeTherapy);
        try {
            if (await verifySession()) await loadQueue(null);
        } catch (error) {
            showMessage(error.message);
        }
    });
})();
