'use strict';

(() => {
    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const apiEntry = new URL(`${projectBase}backend/index.php`, window.location.origin);
    const scope = document.body.dataset.testingScope;
    const messageElement = document.querySelector('[data-testing-message]');
    const queueBody = document.querySelector('[data-order-rows]');
    const detailPanel = document.querySelector('[data-order-detail]');
    const resultForm = document.querySelector('[data-result-form]');
    const page = document.body.dataset.testingPage || '';
    const requestedOrderId = new URLSearchParams(window.location.search).get('order_id');
    const state = {
        csrfToken: '',
        search: requestedOrderId || '',
        status: document.body.dataset.defaultStatus || '',
        department: '',
        activeOrder: null,
        orders: [],
        counts: null,
        requestedOrderHandled: false,
        timer: null,
    };
    const allowedRoles = {
        testing_desk: ['testing_desk_clerk', 'system_administrator'],
        laboratory: ['laboratory_technician', 'system_administrator'],
        radiology: ['radiographer', 'system_administrator'],
        cardiac: ['cardiac_technician', 'system_administrator'],
    };

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

    function hideMessage() {
        messageElement.hidden = true;
        messageElement.textContent = '';
        messageElement.classList.remove('error', 'success');
    }

    function login() {
        window.location.replace(`${projectBase}auth/login.php?message=session-expired`);
    }

    async function parseRequest(route, options = {}, fallback = 'The request could not be completed.') {
        const { response, payload } = await request(route, options);
        if (response.status === 401) {
            login();
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
            login();
            return false;
        }
        if (!response.ok || !payload.success) {
            throw new Error(errorMessage(payload, 'Could not verify the current session.'));
        }
        const role = payload.data.user.role.name;
        if (!allowedRoles[scope]?.includes(role)) {
            window.location.replace(`${projectBase}frontend/dashboard.php`);
            return false;
        }
        state.csrfToken = payload.data.csrf_token;
        document.querySelectorAll('[data-module-link]').forEach((link) => {
            const roles = (link.dataset.roles || '').split(',').filter(Boolean);
            link.hidden = !roles.includes(role);
        });
        return true;
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

    function pretty(value) {
        return value ? String(value).replaceAll('_', ' ') : '—';
    }

    function cell(row, text, className = '') {
        const td = document.createElement('td');
        if (className) td.className = className;
        td.textContent = text === null || text === '' ? '—' : String(text);
        row.appendChild(td);
        return td;
    }

    function emptyRow(message) {
        queueBody.replaceChildren();
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.className = 'table-message';
        td.colSpan = scope === 'testing_desk' ? 10 : 7;
        td.textContent = message;
        tr.appendChild(td);
        queueBody.appendChild(tr);
    }

    function updateCounts(orders) {
        const values = state.counts || ['ordered', 'in_progress', 'completed'].reduce((counts, status) => {
            counts[status] = orders.filter((order) => order.status === status).length;
            return counts;
        }, {});
        ['ordered', 'in_progress', 'completed'].forEach((status) => {
            const target = document.querySelector(`[data-count="${status}"]`);
            if (target) target.textContent = String(values[status] || 0);
        });
    }

    function makeButton(text, handler, className = 'button button-outline button-small') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = text;
        button.addEventListener('click', handler);
        return button;
    }

    function orderAction(order) {
        const action = document.createElement('td');
        if (scope === 'testing_desk') {
            if (page === 'queue' || page === 'route') {
                action.appendChild(orderLink(order, 'Review & route', 'route-order.php'));
            } else if (page === 'dashboard' && order.status === 'ordered' && !order.desk_routed_at) {
                action.appendChild(orderLink(order, 'Review & route', 'route-order.php'));
            } else if (order.desk_routed_at) {
                action.appendChild(orderLink(
                    order,
                    page === 'dashboard' ? 'View status' : `Routed ${formatDateTime(order.desk_routed_at)}`,
                    'order-details.php'
                ));
            } else {
                action.appendChild(orderLink(order, 'View details', 'order-details.php'));
            }
            return action;
        }

        if (order.status === 'ordered') {
            if (!order.desk_routed_at) {
                action.textContent = 'Awaiting testing desk';
            } else {
                action.appendChild(makeButton('Start test', () => startOrder(order)));
            }
        } else if (order.status === 'in_progress') {
            action.appendChild(makeButton('Record result', () => openOrder(order)));
        } else if (order.status === 'completed') {
            action.appendChild(makeButton('View result', () => openOrder(order)));
        } else {
            action.textContent = pretty(order.status);
        }
        return action;
    }

    function orderLink(order, label, pageName) {
        const link = document.createElement('a');
        link.className = 'button button-outline button-small';
        link.href = `${pageName}?order_id=${encodeURIComponent(order.test_order_id)}`;
        link.textContent = label;
        return link;
    }

    function renderOrders() {
        let selected = scope === 'testing_desk' && state.department
            ? state.orders.filter((order) => order.department === state.department)
            : state.orders;
        if (scope === 'testing_desk' && page === 'queue') {
            selected = selected.filter((order) => order.status === 'ordered' && !order.desk_routed_at);
        }
        updateCounts(selected);
        queueBody.replaceChildren();
        if (selected.length === 0) {
            emptyRow('No test orders match the selected filters.');
            return;
        }

        selected.forEach((order) => {
            const row = document.createElement('tr');
            const orderId = cell(row, `#${order.test_order_id}`);
            if (order.retest_of_order_id) {
                const tag = document.createElement('small');
                tag.className = 'testing-retest-tag';
                tag.textContent = `Retest of #${order.retest_of_order_id}`;
                orderId.appendChild(document.createElement('br'));
                orderId.appendChild(tag);
            }
            cell(row, `${order.patient_name} · ${order.patient_number}`);
            cell(row, `#${order.visit_id}`);
            cell(row, pretty(order.test_type));
            if (scope === 'testing_desk') {
                cell(row, pretty(order.department));
                cell(row, `${order.ordered_by || 'Staff'} · ${pretty(order.ordered_by_role)}`);
            }
            cell(row, formatDateTime(order.ordered_at));
            const statusCell = cell(row, '');
            const badge = document.createElement('span');
            badge.className = `testing-status testing-status-${order.status}`;
            badge.textContent = pretty(order.status);
            statusCell.appendChild(badge);
            if (scope === 'testing_desk') {
                const latest = order.results[order.results.length - 1];
                cell(row, latest ? `${pretty(latest.status)} · ${formatDateTime(latest.recorded_at)}` : 'No result yet');
            }
            row.appendChild(orderAction(order));
            queueBody.appendChild(row);
        });
    }

    async function loadOrders() {
        emptyRow('Loading orders…');
        const route = scope === 'testing_desk'
            ? '/api/testing-desk/orders'
            : `/api/${scope}/orders`;
        try {
            const data = await parseRequest(
                route,
                {
                    query: {
                        ...(state.search ? { search: state.search } : {}),
                        ...(state.status ? { status: state.status } : {}),
                    },
                },
                'Could not load test orders.'
            );
            state.orders = data.orders;
            state.counts = data.counts || null;
            renderOrders();
            if (state.activeOrder) {
                const refreshed = state.orders.find(
                    (order) => order.test_order_id === state.activeOrder.test_order_id
                );
                if (refreshed) renderOrderDetails(refreshed);
            }
            if (!state.requestedOrderHandled
                && requestedOrderId
                && /^[1-9][0-9]*$/.test(requestedOrderId)) {
                const requested = state.orders.find(
                    (order) => order.test_order_id === Number(requestedOrderId)
                );
                if (requested) {
                    openOrder(requested);
                } else if (page === 'route') {
                    showMessage('This order is not awaiting routing or is not available in the Testing Desk queue.');
                } else if (page === 'details') {
                    showMessage('Test order not found. Check the order ID or search again.');
                }
                state.requestedOrderHandled = true;
            }
        } catch (error) {
            emptyRow(error.message);
        }
    }

    async function routeOrder(order, button = null) {
        if (!window.confirm(`Route patient ${order.patient_name} to ${pretty(order.department)} for ${pretty(order.test_type)}?`)) {
            return;
        }
        if (button) button.disabled = true;
        hideMessage();
        try {
            await parseRequest(
                `/api/testing-desk/orders/${order.test_order_id}/route`,
                { method: 'POST', body: '{}' },
                'Could not route the patient.'
            );
            showMessage(`Patient routed to ${pretty(order.department)}.`, 'success');
            await loadOrders();
        } catch (error) {
            showMessage(error.message);
        } finally {
            if (button) button.disabled = false;
        }
    }

    async function startOrder(order) {
        if (!window.confirm(`Start ${pretty(order.test_type)} processing for ${order.patient_name}?`)) {
            return;
        }
        hideMessage();
        try {
            await parseRequest(
                `/api/${scope}/orders/${order.test_order_id}/start`,
                { method: 'POST', body: '{}' },
                'Could not start this test.'
            );
            showMessage('Test marked in progress.', 'success');
            await loadOrders();
            const updated = state.orders.find((item) => item.test_order_id === order.test_order_id);
            if (updated) openOrder(updated);
        } catch (error) {
            showMessage(error.message);
        }
    }

    function appendDetails(pairs) {
        const target = document.querySelector('[data-order-details]');
        target.replaceChildren();
        pairs.forEach(([term, value]) => {
            const dt = document.createElement('dt');
            dt.textContent = term;
            const dd = document.createElement('dd');
            dd.textContent = value === null || value === '' ? '—' : String(value);
            target.append(dt, dd);
        });
    }

    function renderOrderDetails(order) {
        state.activeOrder = order;
        document.querySelector('[data-order-summary]').textContent =
            `${order.patient_name} · ${order.patient_number} · Visit #${order.visit_id} · Order #${order.test_order_id}`;
        appendDetails([
            ['Patient number', order.patient_number],
            ['Patient', order.patient_name],
            ['Visit ID', order.visit_id],
            ['Visit date', formatDateTime(order.arrived_at)],
            ['Emergency', order.is_emergency ? 'Yes' : 'No'],
            ['Test', pretty(order.test_type)],
            ['Department', pretty(order.department)],
            ['Requested by', `${order.ordered_by || 'Staff'} · ${pretty(order.ordered_by_role)}`],
            ['Status', pretty(order.status)],
            ['Ordered', formatDateTime(order.ordered_at)],
            ['Desk routing', order.desk_routed_at ? `${formatDateTime(order.desk_routed_at)}${order.routed_by ? ` by ${order.routed_by}` : ''}` : 'Not routed yet'],
            ['Retest of order', order.retest_of_order_id ? `#${order.retest_of_order_id}` : 'No'],
        ]);
        const deskActions = document.querySelector('[data-desk-actions]');
        if (deskActions) {
            deskActions.replaceChildren();
            if (scope === 'testing_desk' && page === 'route') {
                if (order.status === 'ordered' && !order.desk_routed_at) {
                    deskActions.appendChild(makeButton(
                        `Route patient to ${pretty(order.department)}`,
                        (event) => routeOrder(order, event.currentTarget),
                        'button button-primary'
                    ));
                } else if (order.desk_routed_at) {
                    const note = document.createElement('p');
                    note.className = 'testing-empty';
                    note.textContent = `Already routed to ${pretty(order.department)}${order.routed_by ? ` by ${order.routed_by}` : ''}.`;
                    deskActions.appendChild(note);
                } else {
                    const note = document.createElement('p');
                    note.className = 'testing-empty';
                    note.textContent = 'This order is not eligible for routing.';
                    deskActions.appendChild(note);
                }
            }
        }
        renderResultHistory(order.results);
        if (resultForm) {
            resultForm.reset();
            resultForm.hidden = scope === 'testing_desk' || order.status !== 'in_progress';
        }
        detailPanel.hidden = false;
        detailPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function openOrder(order) {
        hideMessage();
        renderOrderDetails(order);
    }

    function renderResultHistory(results) {
        const target = document.querySelector('[data-result-history]');
        target.replaceChildren();
        if (results.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'testing-empty';
            empty.textContent = 'No result has been recorded for this test order.';
            target.appendChild(empty);
            return;
        }
        results.forEach((result) => {
            const article = document.createElement('article');
            article.className = 'testing-result-card';
            const heading = document.createElement('strong');
            heading.textContent = `${pretty(result.status)} · ${formatDateTime(result.recorded_at)}`;
            article.appendChild(heading);
            const text = document.createElement('p');
            text.textContent = result.text;
            article.appendChild(text);
            if (result.notes) {
                const notes = document.createElement('p');
                notes.className = 'testing-result-notes';
                notes.textContent = `Notes: ${result.notes}`;
                article.appendChild(notes);
            }
            if (result.recorded_by) {
                const author = document.createElement('small');
                author.textContent = `Recorded by ${result.recorded_by}`;
                article.appendChild(author);
            }
            target.appendChild(article);
        });
    }

    async function submitResult(event) {
        event.preventDefault();
        hideMessage();
        if (!resultForm.reportValidity() || !state.activeOrder) return;
        if (!window.confirm('Save this final test result? The order will be marked completed.')) return;
        const button = resultForm.querySelector('[data-save-result]');
        button.disabled = true;
        button.dataset.originalText = button.textContent;
        button.textContent = 'Saving result…';
        const formData = new FormData(resultForm);
        try {
            const route = `/api/${scope}/results`;
            await parseRequest(
                route,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        test_order_id: state.activeOrder.test_order_id,
                        result_text: formData.get('result_text'),
                        notes: formData.get('notes'),
                    }),
                },
                'Could not save this result.'
            );
            showMessage('Final result saved. The doctor can review it from the visit record.', 'success');
            await loadOrders();
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
            button.textContent = button.dataset.originalText || 'Save final result';
        }
    }

    function setupFilters() {
        const search = document.querySelector('[data-order-search]');
        const status = document.querySelector('[data-status-filter]');
        const departmentFilter = document.querySelector('[data-department-filter]');
        if (search) {
            search.value = state.search;
            search.addEventListener('input', (event) => {
            window.clearTimeout(state.timer);
            state.timer = window.setTimeout(() => {
                state.search = event.target.value.trim();
                loadOrders();
            }, 250);
            });
        }
        if (status) {
            status.value = state.status || 'all';
            status.addEventListener('change', (event) => {
                state.status = event.target.value;
                loadOrders();
            });
        }
        if (departmentFilter) {
            departmentFilter.addEventListener('change', (event) => {
                state.department = event.target.value;
                renderOrders();
            });
        }
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
            setupFilters();
            setupLogout();
            if (resultForm) resultForm.addEventListener('submit', submitResult);
            const close = document.querySelector('[data-close-detail]');
            if (close) close.addEventListener('click', () => {
                detailPanel.hidden = true;
                state.activeOrder = null;
            });
            await loadOrders();
            window.setInterval(() => loadOrders(), 20000);
        } catch (error) {
            showMessage(error.message);
        }
    }

    init();
})();
