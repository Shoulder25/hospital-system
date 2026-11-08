'use strict';

(() => {
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    const allowedRoles = [
        'system_administrator',
        'emergency_doctor',
        'emergency_nurse',
        'nurse',
        'general_doctor',
        'resident_specialist',
        'locum_specialist',
        'testing_desk_clerk',
        'laboratory_technician',
        'radiographer',
        'cardiac_technician',
        'therapist',
        'pharmacist',
    ];
    let csrfToken = '';

    async function request(route, options = {}) {
        const url = new URL(apiEntry.href);
        url.searchParams.set('route', route);
        const { query, ...fetchOptions } = options;
        Object.entries(query || {}).forEach(([key, value]) => {
            if (value) url.searchParams.set(key, value);
        });
        const headers = new Headers(fetchOptions.headers || {});
        if (csrfToken && fetchOptions.method && fetchOptions.method !== 'GET') {
            headers.set('X-CSRF-Token', csrfToken);
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

    function showMessage(message, type = 'error') {
        const element = document.querySelector('[data-status-message]');
        element.textContent = message;
        element.classList.toggle('error', type === 'error');
        element.classList.toggle('success', type === 'success');
        element.hidden = false;
    }

    function messageText(payload, fallback) {
        if (payload && payload.errors && typeof payload.errors === 'object') {
            const first = Object.values(payload.errors).find((value) => typeof value === 'string');
            if (first) return first;
        }
        return payload && typeof payload.message === 'string' ? payload.message : fallback;
    }

    function formatDateTime(value) {
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

    async function checkSession() {
        const { response, payload } = await request('/api/auth/me');
        if (response.status === 401) {
            window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
            return false;
        }
        if (!response.ok || !payload.success) {
            throw new Error(messageText(payload, 'Could not verify your session.'));
        }
        const user = payload.data.user;
        if (!allowedRoles.includes(user.role.name)) {
            window.location.replace(`${projectBase}frontend/dashboard.php`);
            return false;
        }
        csrfToken = payload.data.csrf_token;
        document.querySelector('[data-status-user]').textContent = user.display_name;
        document.querySelector('[data-status-avatar]').textContent =
            Array.from(user.display_name)[0] || '—';
        document.querySelector('[data-status-role]').textContent =
            user.role.name.replaceAll('_', ' ');
        return true;
    }

    function renderVisit(visit) {
        document.querySelector('[data-visit-patient]').textContent =
            visit.patient_name || 'Temporary / unnamed patient';
        document.querySelector('[data-visit-number]').textContent = visit.patient_number;
        document.querySelector('[data-visit-id]').textContent = String(visit.visit_id);
        document.querySelector('[data-visit-arrival]').textContent = formatDateTime(visit.arrived_at);
        document.querySelector('[data-visit-arrival]').dateTime =
            visit.arrived_at.replace(' ', 'T');
        document.querySelector('[data-visit-status]').textContent =
            visit.status.replaceAll('_', ' ');

        const timeline = document.querySelector('[data-visit-timeline]');
        timeline.replaceChildren();
        if (visit.timeline.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'timeline-empty';
            empty.textContent = 'No workflow records have been recorded yet.';
            timeline.appendChild(empty);
        } else {
            visit.timeline.forEach((event) => {
                const item = document.createElement('li');
                item.append(document.createTextNode(event.summary));
                const time = document.createElement('time');
                time.dateTime = event.event_at.replace(' ', 'T');
                time.textContent = formatDateTime(event.event_at);
                item.appendChild(time);
                timeline.appendChild(item);
            });
        }
        document.querySelector('[data-visit-result]').hidden = false;
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const form = document.querySelector('[data-status-search]');
        const submit = document.querySelector('[data-status-submit]');
        const result = document.querySelector('[data-visit-result]');
        try {
            const allowed = await checkSession();
            if (!allowed) return;
        } catch (error) {
            showMessage(error.message);
            return;
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            result.hidden = true;
            submit.disabled = true;
            showMessage('Searching visit…', 'success');
            try {
                const search = new FormData(form).get('search').trim();
                const { response, payload } = await request('/api/visits/status', {
                    query: { search },
                });
                if (response.status === 401) {
                    window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
                    return;
                }
                if (!response.ok || !payload.success) {
                    throw new Error(messageText(payload, 'Could not load visit status.'));
                }
                renderVisit(payload.data.visit);
                showMessage('Visit information loaded.', 'success');
            } catch (error) {
                showMessage(error.message);
            } finally {
                submit.disabled = false;
            }
        });

        document.querySelector('[data-status-logout]').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
                const { response, payload } = await request('/api/auth/logout', {
                    method: 'POST',
                });
                if (!response.ok || !payload.success) {
                    throw new Error(messageText(payload, 'Could not sign out.'));
                }
                window.location.replace(`${projectBase}auth/login.php`);
            } catch (error) {
                showMessage(error.message);
                button.disabled = false;
            }
        });
    });
})();
