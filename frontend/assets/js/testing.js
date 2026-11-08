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
        testType: '',
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
        if (options.body
            && !(options.body instanceof FormData)
            && !headers.has('Content-Type')) {
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
        td.colSpan = scope === 'testing_desk' || scope === 'laboratory'
            ? 10
            : (scope === 'cardiac' ? 9 : 7);
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

        if (scope === 'laboratory') {
            if (page === 'pending' && order.status === 'ordered' && order.desk_routed_at) {
                action.appendChild(makeButton('Receive for collection', () => startOrder(order)));
            } else {
                action.appendChild(orderLink(order, 'View details', 'order-details.php'));
            }
            return action;
        }

        if (scope === 'radiology') {
            if (page === 'imaging') {
                action.appendChild(makeButton('Manage images', () => openOrder(order)));
            } else if (order.status === 'ordered' && !order.desk_routed_at) {
                action.textContent = 'Awaiting Testing Centre';
            } else if (order.status === 'ordered') {
                action.appendChild(makeButton('Start examination', () => startOrder(order)));
            } else {
                action.appendChild(makeButton(
                    order.status === 'completed' ? 'View report and images' : 'Open examination',
                    () => openOrder(order)
                ));
            }
            return action;
        }

        if (scope === 'cardiac') {
            if (page === 'imaging') {
                action.appendChild(orderLink(order, 'Manage tracings', 'imaging.php'));
            } else if (order.status === 'ordered' && !order.desk_routed_at) {
                action.textContent = 'Awaiting Testing Centre';
            } else if (order.status === 'ordered') {
                action.appendChild(makeButton('Start examination', () => startOrder(order)));
            } else if (order.status === 'in_progress'
                && order.cardiac_attempts?.[order.cardiac_attempts.length - 1]?.status === 'unsuccessful') {
                action.appendChild(makeButton('Restart examination', () => startOrder(order)));
            } else if (order.status === 'cancelled') {
                action.appendChild(orderLink(order, 'View history', 'order-details.php'));
            } else {
                const targetPage = order.status === 'completed'
                    ? 'reports.php'
                    : 'record-results.php';
                action.appendChild(orderLink(
                    order,
                    order.status === 'completed' ? 'View report' : 'Open examination',
                    targetPage
                ));
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
        if (scope === 'laboratory') {
            if (page === 'pending') {
                selected = selected.filter((order) => order.status === 'ordered');
            } else if (page === 'specimens') {
                selected = selected.filter((order) => order.status === 'in_progress'
                    && order.specimen
                    && order.specimen.status !== 'processing'
                    && order.specimen.status !== 'completed');
            } else if (page === 'processing') {
                selected = selected.filter((order) => order.status === 'in_progress'
                    && order.specimen?.status === 'processing');
            } else if (page === 'completed') {
                selected = selected.filter((order) => order.status === 'completed');
            } else if (page === 'results') {
                selected = selected.filter((order) => ['in_progress', 'completed'].includes(order.status));
            }
        }
        if (scope === 'cardiac') {
            if (page === 'orders' && !state.status) {
                selected = selected.filter((order) => Boolean(order.desk_routed_at)
                    && order.status !== 'completed'
                    && order.status !== 'cancelled');
            } else if (page === 'pending') {
                selected = selected.filter((order) => order.status === 'ordered'
                    && order.desk_routed_at);
            } else if (page === 'processing' || page === 'record-results') {
                selected = selected.filter((order) => order.status === 'in_progress');
            } else if (page === 'imaging' || page === 'reports') {
                selected = selected.filter((order) => ['in_progress', 'completed'].includes(order.status));
            } else if (page === 'completed') {
                selected = selected.filter((order) => order.status === 'completed');
            }
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
            if (scope === 'cardiac') cell(row, order.patient_id);
            if (scope === 'laboratory') cell(row, order.patient_id);
            cell(row, `#${order.visit_id}`);
            cell(row, pretty(order.test_type));
            if (scope === 'cardiac') {
                cell(row, `${order.ordered_by || 'Staff'} · ${pretty(order.ordered_by_role)}`);
            }
            if (scope === 'testing_desk') {
                cell(row, pretty(order.department));
                cell(row, `${order.ordered_by || 'Staff'} · ${pretty(order.ordered_by_role)}`);
            }
            if (scope === 'laboratory') cell(row, order.ordered_by || 'Staff');
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
            if (scope === 'laboratory') {
                cell(row, order.specimen ? pretty(order.specimen.status) : 'Not received');
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
                        ...(state.testType ? { test_type: state.testType } : {}),
                    },
                },
                'Could not load test orders.'
            );
            state.orders = data.orders;
            state.counts = data.counts || null;
            renderOrders();
            if (scope === 'radiology' && page === 'imaging') {
                renderImagingGallery(state.orders);
            }
            if (scope === 'cardiac' && page === 'imaging') {
                renderCardiacGallery(state.orders);
            }
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
                } else if (page === 'details' || page === 'order-details') {
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
        const latestAttempt = order.cardiac_attempts?.[order.cardiac_attempts.length - 1];
        const isRestart = scope === 'cardiac' && latestAttempt?.status === 'unsuccessful';
        const prompt = isRestart
            ? `Start a new ECG examination attempt for ${order.patient_name}?`
            : `Start ${pretty(order.test_type)} processing for ${order.patient_name}?`;
        if (!window.confirm(prompt)) {
            return;
        }
        hideMessage();
        try {
            await parseRequest(
                `/api/${scope}/orders/${order.test_order_id}/start`,
                { method: 'POST', body: '{}' },
                'Could not start this test.'
            );
            showMessage(isRestart
                ? 'A new ECG examination attempt has started.'
                : 'Test marked in progress.', 'success');
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
            ['Patient ID', order.patient_id],
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
        const radiologyActions = document.querySelector('[data-radiology-actions]');
        if (radiologyActions) {
            radiologyActions.replaceChildren();
            if (scope === 'radiology') {
                if (order.status === 'ordered' && order.desk_routed_at) {
                    radiologyActions.appendChild(makeButton(
                        'Start examination',
                        () => startOrder(order),
                        'button button-primary'
                    ));
                } else if (order.status === 'ordered' && !order.desk_routed_at) {
                    const note = document.createElement('p');
                    note.className = 'testing-empty';
                    note.textContent = 'This order must be routed by the Testing Centre before starting examination.';
                    radiologyActions.appendChild(note);
                }
            }
        }
        renderSpecimenHistory(order.specimens || []);
        renderSpecimenActions(order);
        renderResultHistory(order.results, order);
        if (scope === 'cardiac') {
            renderCardiacAttempts(order);
            renderCardiacFiles(order);
            renderCardiacFailureForm(order);
        }
        if (scope === 'radiology') {
            renderRadiologyFiles(order);
        }
        if (resultForm) {
            resultForm.reset();
            const resultStatus = resultForm.querySelector('[data-result-status]');
            if (scope === 'laboratory') {
                const canAmend = order.status === 'completed';
                const canRecord = order.status === 'in_progress'
                    && order.specimen?.status === 'processing';
                resultForm.hidden = !canAmend && !canRecord;
                resultStatus.replaceChildren();
                const options = canAmend
                    ? [['amended', 'Additional result entry']]
                    : [
                        ['preliminary', 'Preliminary entry'],
                        ['final', 'Final result'],
                    ];
                options.forEach(([value, label]) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = label;
                    resultStatus.appendChild(option);
                });
            } else if (scope === 'radiology') {
                resultForm.hidden = order.status !== 'in_progress';
                resultStatus.replaceChildren();
                [
                    ['preliminary', 'Draft report'],
                    ['final', 'Submit report'],
                ].forEach(([value, label]) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = label;
                    resultStatus.appendChild(option);
                });
            } else if (scope === 'cardiac') {
                resultForm.hidden = order.status !== 'in_progress'
                    || order.cardiac_attempts?.at(-1)?.status === 'unsuccessful';
                resultStatus.replaceChildren();
                [
                    ['preliminary', 'Save draft'],
                    ['final', 'Submit report'],
                ].forEach(([value, label]) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = label;
                    resultStatus.appendChild(option);
                });
            } else {
                resultForm.hidden = scope === 'testing_desk' || order.status !== 'in_progress';
            }
        }
        detailPanel.hidden = false;
        detailPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function openOrder(order) {
        hideMessage();
        renderOrderDetails(order);
    }

    function radiologyFileUrl(file, download = false) {
        const url = new URL(apiEntry.href);
        url.searchParams.set('route', `/api/radiology/files/${file.radiology_file_id}`);
        if (download) url.searchParams.set('download', '1');
        return url.href;
    }

    function cardiacFileUrl(file, download = false) {
        const url = new URL(apiEntry.href);
        url.searchParams.set('route', `/api/cardiac/files/${file.cardiac_file_id}`);
        if (download) url.searchParams.set('download', '1');
        return url.href;
    }

    function createCardiacFileCard(file, order) {
        const article = document.createElement('article');
        article.className = 'radiology-file-card';
        const heading = document.createElement('strong');
        heading.textContent = file.name;
        article.appendChild(heading);
        const details = document.createElement('p');
        details.textContent = `${file.kind === 'image' ? 'ECG tracing' : 'Supporting document'} · ${formatDateTime(file.uploaded_at)} · ${file.uploaded_by || 'Cardiac technician'}`;
        article.appendChild(details);

        if (file.deleted_at) {
            const removed = document.createElement('p');
            removed.className = 'radiology-file-removed';
            removed.textContent = `Removed from active view ${formatDateTime(file.deleted_at)}${file.deleted_by ? ` by ${file.deleted_by}` : ''}. File history is retained.`;
            article.appendChild(removed);
            return article;
        }

        const url = cardiacFileUrl(file);
        if (file.kind === 'image') {
            const previewLink = document.createElement('button');
            previewLink.type = 'button';
            previewLink.className = 'radiology-image-preview';
            previewLink.setAttribute('aria-label', `Preview and zoom ECG tracing ${file.name}`);
            const image = document.createElement('img');
            image.src = url;
            image.alt = `${file.name} for ECG order ${order.test_order_id}`;
            image.loading = 'lazy';
            previewLink.addEventListener('click', () => {
                window.CareBridgeRadiologyImageViewer.open(url, image.alt, file.name);
            });
            previewLink.appendChild(image);
            article.appendChild(previewLink);
        }

        const actions = document.createElement('div');
        actions.className = 'radiology-file-actions';
        const open = document.createElement('a');
        open.href = url;
        open.target = '_blank';
        open.rel = 'noopener';
        open.textContent = file.kind === 'image' ? 'Open tracing' : 'Open document';
        actions.appendChild(open);
        const download = document.createElement('a');
        download.href = cardiacFileUrl(file, true);
        download.textContent = 'Download';
        actions.appendChild(download);
        if (scope === 'cardiac') {
            const remove = makeButton('Remove', () => removeCardiacFile(file));
            remove.classList.add('button-small');
            actions.appendChild(remove);
        }
        article.appendChild(actions);
        return article;
    }

    function renderCardiacFiles(order) {
        const target = document.querySelector('[data-cardiac-files]');
        if (target) {
            target.replaceChildren();
            const files = order.cardiac_files || [];
            if (files.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'testing-empty';
                empty.textContent = 'No ECG tracings or supporting documents have been uploaded.';
                target.appendChild(empty);
            } else {
                files.forEach((file) => target.appendChild(createCardiacFileCard(file, order)));
            }
        }

        const form = document.querySelector('[data-cardiac-upload]');
        if (form) {
            form.hidden = scope !== 'cardiac'
                || !['in_progress', 'completed'].includes(order.status);
            form.dataset.orderId = String(order.test_order_id);
            form.reset();
            clearCardiacUploadPreviews(form);
        }
    }

    function renderCardiacGallery(orders) {
        const target = document.querySelector('[data-cardiac-gallery]');
        if (!target) return;
        target.replaceChildren();
        const files = [];
        orders.forEach((order) => {
            (order.cardiac_files || []).forEach((file) => {
                if (!file.deleted_at) files.push({ order, file });
            });
        });
        if (files.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'testing-empty';
            empty.textContent = 'No active ECG files are available for the selected orders.';
            target.appendChild(empty);
            return;
        }
        files.forEach(({ order, file }) => {
            const article = createCardiacFileCard(file, order);
            const context = document.createElement('p');
            context.textContent = `${order.patient_name} · ${order.patient_number} · Patient ID ${order.patient_id} · Visit #${order.visit_id} · ECG order #${order.test_order_id}`;
            article.insertBefore(context, article.children[1] || null);
            target.appendChild(article);
        });
    }

    function renderCardiacAttempts(order) {
        const target = document.querySelector('[data-cardiac-attempt-history]');
        if (!target) return;
        target.replaceChildren();
        const attempts = order.cardiac_attempts || [];
        if (attempts.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'testing-empty';
            empty.textContent = 'No examination attempt has been recorded yet.';
            target.appendChild(empty);
            return;
        }
        attempts.forEach((attempt) => {
            const article = document.createElement('article');
            article.className = 'cardiac-attempt-card';
            const heading = document.createElement('strong');
            heading.textContent = `Attempt #${attempt.attempt_id} · ${pretty(attempt.status)}`;
            article.appendChild(heading);
            const timing = document.createElement('p');
            timing.textContent = `Started ${formatDateTime(attempt.started_at)}${attempt.completed_at ? ` · Ended ${formatDateTime(attempt.completed_at)}` : ''}`;
            article.appendChild(timing);
            if (attempt.failure_reason) {
                const reason = document.createElement('p');
                reason.textContent = `Reason: ${attempt.failure_reason}`;
                article.appendChild(reason);
            }
            const technician = document.createElement('small');
            technician.textContent = `Started by ${attempt.technician || 'staff'}${attempt.completed_by ? ` · Ended by ${attempt.completed_by}` : ''}`;
            article.appendChild(technician);
            target.appendChild(article);
        });
    }

    function renderCardiacFailureForm(order) {
        const form = document.querySelector('[data-cardiac-failure]');
        if (!form) return;
        const latestAttempt = order.cardiac_attempts?.[order.cardiac_attempts.length - 1];
        form.hidden = scope !== 'cardiac'
            || order.status !== 'in_progress'
            || latestAttempt?.status !== 'in_progress';
        form.reset();
    }

    async function removeCardiacFile(file) {
        if (!window.confirm(`Remove "${file.name}" from the active order view? Its history and stored file will be retained.`)) {
            return;
        }
        try {
            await parseRequest(
                `/api/cardiac/files/${file.cardiac_file_id}/delete`,
                { method: 'DELETE' },
                'Could not remove this ECG file.'
            );
            showMessage('ECG file removed from the active view. Its history is retained.', 'success');
            await loadOrders();
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function uploadCardiacFiles(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const input = form.querySelector('[name="files[]"]');
        if (!input.files.length || !state.activeOrder) {
            showMessage('Choose one or more files for the selected ECG order.');
            return;
        }
        const allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
        const allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf'];
        const files = [...input.files];
        const totalBytes = files.reduce((total, file) => total + file.size, 0);
        const invalidFile = files.find((file) => {
            const extension = file.name.split('.').pop().toLowerCase();
            return !allowedExtensions.includes(extension)
                || (file.type !== '' && !allowedMimeTypes.includes(file.type))
                || file.size < 1
                || file.size > 15 * 1024 * 1024;
        });
        if (files.length > 10 || invalidFile || totalBytes > 40 * 1024 * 1024) {
            showMessage(invalidFile
                ? 'Select JPG, JPEG, PNG, or PDF files no larger than 15 MB each.'
                : files.length > 10
                    ? 'Select no more than 10 files at a time.'
                    : 'The combined upload must be no larger than 40 MB.');
            return;
        }
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            await parseRequest(
                `/api/cardiac/orders/${state.activeOrder.test_order_id}/files`,
                { method: 'POST', body: new FormData(form) },
                'Could not upload ECG files.'
            );
            showMessage('ECG files uploaded.', 'success');
            await loadOrders();
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    function setupCardiacUpload() {
        const form = document.querySelector('[data-cardiac-upload]');
        if (!form) return;
        form.addEventListener('submit', uploadCardiacFiles);
        const input = form.querySelector('[name="files[]"]');
        const preview = form.querySelector('[data-upload-preview]');
        const previewUrls = [];
        function clearPreviews() {
            previewUrls.splice(0).forEach((url) => URL.revokeObjectURL(url));
            preview.replaceChildren();
        }
        form.addEventListener('cardiac:clear-previews', clearPreviews);
        input.addEventListener('change', () => {
            clearPreviews();
            [...input.files].forEach((file) => {
                const item = document.createElement('div');
                item.className = 'radiology-upload-preview-item';
                if (file.type.startsWith('image/')) {
                    const image = document.createElement('img');
                    image.src = URL.createObjectURL(file);
                    previewUrls.push(image.src);
                    image.alt = `Preview of ${file.name}`;
                    item.appendChild(image);
                }
                const name = document.createElement('span');
                name.textContent = `${file.name} · ${(file.size / (1024 * 1024)).toFixed(2)} MB`;
                item.appendChild(name);
                preview.appendChild(item);
            });
        });
    }

    function clearCardiacUploadPreviews(form) {
        form.dispatchEvent(new Event('cardiac:clear-previews'));
    }

    async function submitCardiacFailure(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity() || !state.activeOrder) return;
        if (!window.confirm('Record this ECG examination attempt as unsuccessful? The order will remain open for another attempt.')) {
            return;
        }
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            const reason = new FormData(form).get('reason');
            await parseRequest(
                `/api/cardiac/orders/${state.activeOrder.test_order_id}/examinations`,
                {
                    method: 'POST',
                    body: JSON.stringify({ reason }),
                },
                'Could not record the unsuccessful ECG examination.'
            );
            showMessage('Unsuccessful examination recorded. The order remains open for another attempt.', 'success');
            await loadOrders();
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    function createRadiologyFileCard(file, order) {
        const article = document.createElement('article');
        article.className = 'radiology-file-card';
        const heading = document.createElement('strong');
        heading.textContent = file.name;
        article.appendChild(heading);
        const details = document.createElement('p');
        details.textContent = `${file.kind === 'image' ? 'Image' : 'Supporting document'} · ${formatDateTime(file.uploaded_at)} · ${file.uploaded_by || 'Radiographer'}`;
        article.appendChild(details);

        if (file.deleted_at) {
            const removed = document.createElement('p');
            removed.className = 'radiology-file-removed';
            removed.textContent = `Removed from active view ${formatDateTime(file.deleted_at)}${file.deleted_by ? ` by ${file.deleted_by}` : ''}. File history is retained.`;
            article.appendChild(removed);
            return article;
        }

        const url = radiologyFileUrl(file);
        if (file.kind === 'image') {
            const previewLink = document.createElement('button');
            previewLink.type = 'button';
            previewLink.className = 'radiology-image-preview';
            previewLink.setAttribute('aria-label', `Preview and zoom image ${file.name}`);
            const image = document.createElement('img');
            image.src = url;
            image.alt = `${file.name} for X-ray order ${order.test_order_id}`;
            image.loading = 'lazy';
            previewLink.addEventListener('click', () => {
                window.CareBridgeRadiologyImageViewer.open(
                    url,
                    image.alt,
                    file.name
                );
            });
            previewLink.appendChild(image);
            article.appendChild(previewLink);
        }

        const actions = document.createElement('div');
        actions.className = 'radiology-file-actions';
        const open = document.createElement('a');
        open.href = url;
        open.target = '_blank';
        open.rel = 'noopener';
        open.textContent = file.kind === 'image' ? 'Open image' : 'Open document';
        actions.appendChild(open);
        const download = document.createElement('a');
        download.href = radiologyFileUrl(file, true);
        download.textContent = 'Download';
        actions.appendChild(download);
        if (scope === 'radiology') {
            const remove = makeButton('Remove', () => removeRadiologyFile(file, order));
            remove.classList.add('button-small');
            actions.appendChild(remove);
        }
        article.appendChild(actions);
        return article;
    }

    function renderRadiologyFiles(order) {
        const target = document.querySelector('[data-radiology-files]');
        if (!target) return;
        target.replaceChildren();
        const files = order.radiology_files || [];
        if (files.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'testing-empty';
            empty.textContent = 'No radiology images or supporting documents have been uploaded.';
            target.appendChild(empty);
        } else {
            files.forEach((file) => target.appendChild(createRadiologyFileCard(file, order)));
        }

        const form = document.querySelector('[data-radiology-upload]');
        if (form) {
            form.hidden = scope !== 'radiology'
                || !['in_progress', 'completed'].includes(order.status);
            form.dataset.orderId = String(order.test_order_id);
            form.reset();
            clearUploadPreviews(form);
        }
    }

    function renderImagingGallery(orders) {
        const target = document.querySelector('[data-radiology-gallery]');
        if (!target) return;
        target.replaceChildren();
        const files = [];
        orders.forEach((order) => {
            (order.radiology_files || []).forEach((file) => {
                files.push({ order, file });
            });
        });
        if (files.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'testing-empty';
            empty.textContent = 'No radiology images or supporting documents are recorded yet.';
            target.appendChild(empty);
            return;
        }
        files.forEach(({ order, file }) => {
            const article = createRadiologyFileCard(file, order);
            const context = document.createElement('p');
            context.textContent = `${order.patient_name} · ${order.patient_number} · Visit #${order.visit_id} · X-ray order #${order.test_order_id}`;
            article.insertBefore(context, article.children[1] || null);
            target.appendChild(article);
        });
    }

    async function removeRadiologyFile(file, order) {
        if (!window.confirm(`Remove "${file.name}" from the active order view? Its history and stored file will be retained.`)) {
            return;
        }
        try {
            await parseRequest(
                `/api/radiology/files/${file.radiology_file_id}/delete`,
                { method: 'DELETE' },
                'Could not remove this radiology file.'
            );
            showMessage('File removed from the active view. Its history is retained.', 'success');
            await loadOrders();
        } catch (error) {
            showMessage(error.message);
        }
    }

    async function uploadRadiologyFiles(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const input = form.querySelector('[name="files[]"]');
        if (!input.files.length || !state.activeOrder) {
            showMessage('Choose one or more files for the selected X-ray order.');
            return;
        }
        const allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
        const allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf'];
        const files = [...input.files];
        const totalBytes = files.reduce((total, file) => total + file.size, 0);
        const invalidFile = files.find((file) => {
            const extension = file.name.split('.').pop().toLowerCase();
            return !allowedExtensions.includes(extension)
                || !allowedMimeTypes.includes(file.type)
                || file.size < 1
                || file.size > 15 * 1024 * 1024;
        });
        if (files.length > 10 || invalidFile || totalBytes > 40 * 1024 * 1024) {
            showMessage(invalidFile
                ? 'Select JPG, JPEG, PNG, or PDF files no larger than 15 MB each.'
                : files.length > 10
                    ? 'Select no more than 10 files at a time.'
                    : 'The combined upload must be no larger than 40 MB.');
            return;
        }
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            await parseRequest(
                `/api/radiology/orders/${state.activeOrder.test_order_id}/files`,
                { method: 'POST', body: new FormData(form) },
                'Could not upload radiology files.'
            );
            showMessage('Radiology files uploaded.', 'success');
            await loadOrders();
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    function setupRadiologyUpload() {
        const form = document.querySelector('[data-radiology-upload]');
        if (!form) return;
        form.addEventListener('submit', uploadRadiologyFiles);
        const input = form.querySelector('[name="files[]"]');
        const preview = form.querySelector('[data-upload-preview]');
        const previewUrls = [];
        function clearPreviews() {
            previewUrls.splice(0).forEach((url) => URL.revokeObjectURL(url));
            preview.replaceChildren();
        }
        form.addEventListener('radiology:clear-previews', clearPreviews);
        input.addEventListener('change', () => {
            clearPreviews();
            [...input.files].forEach((file) => {
                const item = document.createElement('div');
                item.className = 'radiology-upload-preview-item';
                if (file.type.startsWith('image/')) {
                    const image = document.createElement('img');
                    image.src = URL.createObjectURL(file);
                    previewUrls.push(image.src);
                    image.alt = `Preview of ${file.name}`;
                    item.appendChild(image);
                }
                const name = document.createElement('span');
                name.textContent = `${file.name} · ${(file.size / (1024 * 1024)).toFixed(2)} MB`;
                item.appendChild(name);
                preview.appendChild(item);
            });
        });
    }

    function clearUploadPreviews(form) {
        form.dispatchEvent(new Event('radiology:clear-previews'));
    }

    function renderSpecimenHistory(specimens) {
        const target = document.querySelector('[data-specimen-history]');
        if (!target) return;
        target.replaceChildren();
        if (specimens.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'testing-empty';
            empty.textContent = 'No specimen collection attempts are recorded yet.';
            target.appendChild(empty);
            return;
        }
        specimens.forEach((specimen) => {
            const article = document.createElement('article');
            article.className = 'testing-result-card';
            const heading = document.createElement('strong');
            heading.textContent = `Attempt #${specimen.lab_specimen_id} · ${pretty(specimen.status)}`;
            article.appendChild(heading);
            const text = document.createElement('p');
            text.textContent = `Specimen type: ${specimen.type || 'Not recorded'} · Collected: ${formatDateTime(specimen.collected_at)}`;
            article.appendChild(text);
            if (specimen.rejection_reason) {
                const reason = document.createElement('p');
                reason.className = 'testing-result-notes';
                reason.textContent = `Rejection reason: ${specimen.rejection_reason}`;
                article.appendChild(reason);
            }
            if (specimen.notes) {
                const notes = document.createElement('p');
                notes.className = 'testing-result-notes';
                notes.textContent = `Notes: ${specimen.notes}`;
                article.appendChild(notes);
            }
            const author = document.createElement('small');
            author.textContent = `Opened by ${specimen.recorded_by || 'staff'} · Last updated by ${specimen.updated_by || 'staff'} · ${formatDateTime(specimen.updated_at)}`;
            article.appendChild(author);
            target.appendChild(article);
        });
    }

    function specimenForm(order, action) {
        const form = document.createElement('form');
        form.className = 'testing-specimen-form';
        const typeLabel = document.createElement('label');
        const typeText = document.createElement('span');
        typeText.textContent = 'Specimen type';
        const typeInput = document.createElement('input');
        typeInput.name = 'specimen_type';
        typeInput.maxLength = 100;
        typeInput.required = true;
        typeLabel.append(typeText, typeInput);
        form.appendChild(typeLabel);
        const notesLabel = document.createElement('label');
        const notesText = document.createElement('span');
        notesText.textContent = 'Collection notes';
        const notesInput = document.createElement('textarea');
        notesInput.name = 'notes';
        notesInput.maxLength = 10000;
        notesLabel.append(notesText, notesInput);
        form.appendChild(notesLabel);
        const submit = document.createElement('button');
        submit.type = 'submit';
        submit.className = 'button button-primary';
        submit.textContent = 'Record collection';
        form.appendChild(submit);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const formData = new FormData(form);
            submit.disabled = true;
            await updateSpecimen(order, action, {
                specimen_type: formData.get('specimen_type'),
                notes: formData.get('notes'),
            }, submit);
        });
        return form;
    }

    function rejectionForm(order) {
        const form = document.createElement('form');
        form.className = 'testing-specimen-form';
        const label = document.createElement('label');
        const text = document.createElement('span');
        text.textContent = 'Reason specimen is unsuitable';
        const input = document.createElement('textarea');
        input.name = 'rejection_reason';
        input.maxLength = 10000;
        input.required = true;
        label.append(text, input);
        form.appendChild(label);
        const button = document.createElement('button');
        button.type = 'submit';
        button.className = 'button button-outline button-small';
        button.textContent = 'Reject specimen';
        form.appendChild(button);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            button.disabled = true;
            await updateSpecimen(order, 'reject', {
                rejection_reason: new FormData(form).get('rejection_reason'),
            }, button);
        });
        return form;
    }

    function renderSpecimenActions(order) {
        const target = document.querySelector('[data-specimen-actions]');
        if (!target) return;
        target.replaceChildren();
        if (scope !== 'laboratory') return;
        if (order.status === 'ordered' && order.desk_routed_at) {
            target.appendChild(makeButton(
                'Receive order & begin collection',
                () => startOrder(order),
                'button button-primary'
            ));
            return;
        }
        if (order.status !== 'in_progress' || !order.specimen) return;
        const status = order.specimen.status;
        if (status === 'awaiting_collection') {
            target.appendChild(specimenForm(order, 'collect'));
            target.appendChild(rejectionForm(order));
        } else if (status === 'collected') {
            target.appendChild(makeButton(
                'Begin processing',
                () => updateSpecimen(order, 'processing'),
                'button button-primary'
            ));
            target.appendChild(rejectionForm(order));
        } else if (status === 'processing') {
            target.appendChild(rejectionForm(order));
        } else if (status === 'rejected') {
            target.appendChild(makeButton(
                'Start a new collection attempt',
                () => updateSpecimen(order, 'recollect'),
                'button button-primary'
            ));
        }
    }

    async function updateSpecimen(order, action, payload = {}, button = null) {
        hideMessage();
        if (button) button.disabled = true;
        try {
            await parseRequest(
                `/api/laboratory/orders/${order.test_order_id}/specimens`,
                {
                    method: 'POST',
                    body: JSON.stringify({ action, ...payload }),
                },
                'Could not update specimen tracking.'
            );
            showMessage('Specimen tracking updated.', 'success');
            await loadOrders();
        } catch (error) {
            showMessage(error.message);
        } finally {
            if (button) button.disabled = false;
        }
    }

    function renderResultHistory(results, order) {
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
            if (['radiology', 'cardiac'].includes(scope)
                && result.status === 'preliminary'
                && order.status === 'in_progress') {
                const continueDraft = makeButton('Continue draft', () => {
                    resultForm.elements.result_text.value = result.text;
                    resultForm.elements.notes.value = result.notes || '';
                    resultForm.querySelector('[data-result-status]').value = 'final';
                    resultForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    resultForm.elements.result_text.focus();
                });
                continueDraft.classList.add('button-small');
                article.appendChild(continueDraft);
            }
            target.appendChild(article);
        });
    }

    async function submitResult(event) {
        event.preventDefault();
        hideMessage();
        if (!resultForm.reportValidity() || !state.activeOrder) return;
        const selectedStatus = resultForm.querySelector('[data-result-status]');
        const resultStatus = ['laboratory', 'radiology', 'cardiac'].includes(scope)
            ? selectedStatus.value
            : 'final';
        const confirmation = resultStatus === 'preliminary'
            ? (['radiology', 'cardiac'].includes(scope)
                ? `Save this ${scope === 'cardiac' ? 'ECG' : 'radiology'} report as a draft? Doctors will see it only after submission.`
                : 'Save this preliminary result entry? It will remain a draft for doctor review.')
            : resultStatus === 'amended'
                ? 'Append this additional result entry without changing the existing result?'
                : 'Save this final test result? The order will be marked completed.';
        if (!window.confirm(confirmation)) return;
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
                        result_status: resultStatus,
                    }),
                },
                'Could not save this result.'
            );
            showMessage(resultStatus === 'preliminary'
                ? (['radiology', 'cardiac'].includes(scope)
                    ? `${scope === 'cardiac' ? 'ECG' : 'Radiology'} report draft saved. Submit it when ready for doctor review.`
                    : 'Preliminary result saved. It remains a draft.')
                : resultStatus === 'amended'
                    ? 'Additional result entry saved without overwriting prior results.'
                    : (['radiology', 'cardiac'].includes(scope)
                        ? `${scope === 'cardiac' ? 'ECG' : 'Radiology'} report submitted. The doctor can now review the report and attachments.`
                        : 'Final result saved. The doctor can review it from the visit record.'), 'success');
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
        const testTypeFilter = document.querySelector('[data-test-type-filter]');
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
        if (testTypeFilter) {
            testTypeFilter.addEventListener('change', (event) => {
                state.testType = event.target.value;
                loadOrders();
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
            setupRadiologyUpload();
            setupCardiacUpload();
            if (resultForm) resultForm.addEventListener('submit', submitResult);
            const failureForm = document.querySelector('[data-cardiac-failure]');
            if (failureForm) failureForm.addEventListener('submit', submitCardiacFailure);
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
