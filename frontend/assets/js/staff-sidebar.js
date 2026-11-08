'use strict';

(() => {
    const root = document.body;
    const layout = root.querySelector('.dashboard-layout');
    const sidebar = layout && layout.querySelector('.sidebar');
    const title = layout && layout.querySelector('.topbar-title');
    if (!layout || !sidebar || !title) return;

    root.classList.add('staff-layout');
    if (!sidebar.id) sidebar.id = 'staff-sidebar';

    const toggle = document.createElement('button');
    toggle.className = 'staff-sidebar-toggle';
    toggle.type = 'button';
    toggle.setAttribute('aria-controls', sidebar.id);
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Open navigation');
    toggle.innerHTML = '<span aria-hidden="true"></span><span aria-hidden="true"></span><span aria-hidden="true"></span>';
    title.prepend(toggle);

    const closeButton = document.createElement('button');
    closeButton.className = 'staff-sidebar-close';
    closeButton.type = 'button';
    closeButton.setAttribute('aria-label', 'Close navigation');
    closeButton.innerHTML = '<span aria-hidden="true">×</span>';
    sidebar.appendChild(closeButton);

    const overlay = document.createElement('button');
    overlay.className = 'staff-sidebar-overlay';
    overlay.type = 'button';
    overlay.setAttribute('aria-label', 'Close navigation');
    overlay.hidden = true;
    layout.prepend(overlay);

    const mobileQuery = window.matchMedia('(max-width: 720px)');
    const preferenceKey = 'carebridgeStaffSidebarCollapsed';
    let desktopCollapsed = false;
    try {
        desktopCollapsed = window.localStorage.getItem(preferenceKey) === 'true';
    } catch (error) {
        desktopCollapsed = false;
    }
    let mobileOpen = false;

    function render() {
        const isMobile = mobileQuery.matches;
        const isOpen = isMobile ? mobileOpen : !desktopCollapsed;
        root.classList.toggle('staff-sidebar-collapsed', !isMobile && desktopCollapsed);
        root.classList.toggle('staff-drawer-open', isMobile && mobileOpen);
        overlay.hidden = !(isMobile && mobileOpen);
        toggle.setAttribute('aria-expanded', String(isOpen));
        toggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
        sidebar.setAttribute('aria-hidden', String(!isOpen));
        sidebar.inert = !isOpen;
    }

    function closeDrawer(returnFocus = true) {
        if (!mobileQuery.matches || !mobileOpen) return;
        mobileOpen = false;
        render();
        if (returnFocus) toggle.focus();
    }

    toggle.addEventListener('click', () => {
        if (mobileQuery.matches) {
            mobileOpen = !mobileOpen;
            render();
            if (mobileOpen) closeButton.focus();
            return;
        }

        desktopCollapsed = !desktopCollapsed;
        try {
            window.localStorage.setItem(preferenceKey, String(desktopCollapsed));
        } catch (error) {
            // The current page still retains the selected state.
        }
        render();
    });
    closeButton.addEventListener('click', () => closeDrawer());
    overlay.addEventListener('click', () => closeDrawer());
    sidebar.addEventListener('click', (event) => {
        if (event.target.closest('a')) closeDrawer(false);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeDrawer();
    });
    mobileQuery.addEventListener('change', () => {
        mobileOpen = false;
        render();
    });

    const projectBase = new URL('../../../', document.currentScript.src).pathname;
    const frontendBase = `${projectBase}frontend/`;
    const roleModules = {
        receptionist: [
            ['Reception overview', 'reception/index.php', 'Reception'],
            ['Find patient', 'reception/find-patient.php', 'Reception'],
            ['Register patient', 'reception/register-patient.php', 'Reception'],
            ['Appointments', 'reception/appointments.php', 'Reception'],
            ['Arrivals and check-in', 'reception/arrivals.php', 'Reception'],
            ['Patient records', 'reception/patient-records.php', 'Reception'],
            ['Patient queue', 'reception/queue.php', 'Reception'],
            ['Referrals', 'reception/referrals.php', 'Reception'],
        ],
        cashier: [
            ['Cashier / Billing', 'billing/index.php', 'Billing'],
        ],
        emergency_nurse: [
            ['Emergency Dashboard', 'emergency/index.php', 'Emergency'],
            ['Incoming Patients', 'emergency/queue.php', 'Emergency'],
            ['Patient Assessment', 'emergency/assessment.php', 'Emergency'],
            ['Active Patients', 'emergency/active.php', 'Emergency'],
            ['Doctor Handover', 'emergency/handover.php', 'Emergency'],
            ['Emergency History', 'emergency/history.php', 'Emergency'],
        ],
        emergency_doctor: [
            ['Emergency Doctor dashboard', 'emergency/doctor.php', 'Emergency'],
            ['Awaiting Doctor', 'emergency/doctor-queue.php', 'Emergency'],
            ['Nurse Handovers', 'emergency/doctor-handover.php', 'Emergency'],
            ['Clinical Assessment', 'emergency/doctor-assessment.php', 'Emergency'],
            ['Treatment & Management', 'emergency/doctor-treatment.php', 'Emergency'],
            ['Investigations & Prescriptions', 'emergency/doctor-orders.php', 'Emergency'],
            ['Specialist & Outside Referrals', 'emergency/doctor-referrals.php', 'Emergency'],
            ['Patient Outcomes', 'emergency/doctor-outcomes.php', 'Emergency'],
            ['Emergency History', 'emergency/doctor-history.php', 'Emergency'],
        ],
        nurse: [
            ['Nursing', 'nursing/index.php', 'Nursing'],
            ['Incoming patient queue', 'nursing/queue.php', 'Nursing'],
            ['Completed assessments', 'nursing/completed.php', 'Nursing'],
            ['Doctor referrals', 'nursing/referrals.php', 'Nursing'],
        ],
        general_doctor: [
            ['General doctor', 'doctor/index.php', 'General Doctor'],
            ['Consultation queue', 'doctor/queue.php', 'General Doctor'],
            ['Clinical record', 'doctor/clinical-record.php', 'General Doctor'],
            ['Doctor referrals', 'doctor/referrals.php', 'General Doctor'],
            ['Completed consultations', 'doctor/history.php', 'General Doctor'],
        ],
        testing_desk_clerk: [
            ['Testing centre desk', 'testing-desk/index.php', 'Testing'],
        ],
        laboratory_technician: [
            ['Laboratory', 'laboratory/index.php', 'Testing'],
        ],
        radiographer: [
            ['Radiology', 'radiology/index.php', 'Testing'],
        ],
        cardiac_technician: [
            ['Cardiac', 'cardiac/index.php', 'Testing'],
        ],
        resident_specialist: [
            ['Specialists', 'specialists/index.php', 'Specialists'],
        ],
        locum_specialist: [
            ['Specialists', 'specialists/index.php', 'Specialists'],
        ],
        therapist: [
            ['Therapy', 'therapy/index.php', 'Therapy'],
        ],
        pharmacist: [
            ['Pharmacy', 'pharmacy/index.php', 'Pharmacy'],
        ],
    };
    const adminModules = [
        ['Reception desk', 'reception/index.php', 'Reception'],
        ['Nursing', 'nursing/index.php', 'Nursing'],
        ['General doctor', 'doctor/index.php', 'General Doctor'],
        ['Emergency Dashboard', 'emergency/index.php', 'Emergency'],
        ['Incoming Patients', 'emergency/queue.php', 'Emergency'],
        ['Patient Assessment', 'emergency/assessment.php', 'Emergency'],
        ['Active Patients', 'emergency/active.php', 'Emergency'],
        ['Doctor Handover', 'emergency/handover.php', 'Emergency'],
        ['Emergency History', 'emergency/history.php', 'Emergency'],
        ['Emergency Doctor dashboard', 'emergency/doctor.php', 'Emergency'],
        ['Awaiting Doctor', 'emergency/doctor-queue.php', 'Emergency'],
        ['Nurse Handovers', 'emergency/doctor-handover.php', 'Emergency'],
        ['Clinical Assessment', 'emergency/doctor-assessment.php', 'Emergency'],
        ['Treatment & Management', 'emergency/doctor-treatment.php', 'Emergency'],
        ['Investigations & Prescriptions', 'emergency/doctor-orders.php', 'Emergency'],
        ['Specialist & Outside Referrals', 'emergency/doctor-referrals.php', 'Emergency'],
        ['Patient Outcomes', 'emergency/doctor-outcomes.php', 'Emergency'],
        ['Emergency History', 'emergency/doctor-history.php', 'Emergency'],
        ['Testing centre desk', 'testing-desk/index.php', 'Testing'],
        ['Laboratory', 'laboratory/index.php', 'Testing'],
        ['Radiology', 'radiology/index.php', 'Testing'],
        ['Cardiac', 'cardiac/index.php', 'Testing'],
        ['Cashier / Billing', 'billing/index.php', 'Billing'],
        ['Specialists', 'specialists/index.php', 'Specialists'],
        ['Therapy', 'therapy/index.php', 'Therapy'],
        ['Pharmacy', 'pharmacy/index.php', 'Pharmacy'],
    ];

    function addLink(nav, label, href, icon) {
        const link = document.createElement('a');
        const target = new URL(href, window.location.origin);
        link.className = 'side-link';
        link.href = target.href;
        const symbol = document.createElement('span');
        symbol.setAttribute('aria-hidden', 'true');
        symbol.textContent = icon;
        link.append(symbol, document.createTextNode(` ${label}`));
        if (target.pathname === window.location.pathname) {
            link.classList.add('active');
            link.setAttribute('aria-current', 'page');
        }
        nav.appendChild(link);
    }

    function renderSharedSidebar(user) {
        const role = user.role.name;
        const links = role === 'system_administrator'
            ? [
                ['Administration dashboard', 'admin/dashboard.php', '⌂'],
                ['Staff accounts', 'admin/users.php', '♙'],
                ['Fee management', 'admin/fees.php', '$'],
                ['Visit status tracker', 'visit-status.php', '◷'],
                ...adminModules.map(([label, path]) => [label, path, '＋']),
            ]
            : [
                ['Staff workspace', 'dashboard.php', '⌂'],
                ...(roleModules[role] || []).map(([label, path]) => [label, path, '＋']),
                ...(['emergency_nurse', 'emergency_doctor', 'nurse', 'general_doctor',
                    'resident_specialist', 'locum_specialist', 'testing_desk_clerk',
                    'laboratory_technician', 'radiographer', 'cardiac_technician',
                    'therapist', 'pharmacist'].includes(role)
                    ? [['Visit status tracker', 'visit-status.php', '◷']]
                    : []),
            ];
        const brand = document.createElement('a');
        brand.className = 'brand-lockup sidebar-brand';
        brand.href = `${frontendBase}dashboard.php`;
        brand.innerHTML = '<span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span>';
        const caption = document.createElement('p');
        caption.className = 'sidebar-caption';
        caption.textContent = 'CAREBRIDGE WORKSPACE';
        const nav = document.createElement('nav');
        nav.className = 'side-nav';
        nav.setAttribute('aria-label', 'CareBridge navigation');
        nav.tabIndex = 0;
        links.forEach(([label, path, icon]) => addLink(nav, label, `${frontendBase}${path}`, icon));

        const footer = document.createElement('div');
        footer.className = 'sidebar-bottom';
        const identity = document.createElement('div');
        identity.className = 'staff-identity-card';
        const avatar = document.createElement('span');
        avatar.className = 'user-avatar';
        avatar.textContent = String(user.display_name || 'U').slice(0, 1);
        const details = document.createElement('div');
        const name = document.createElement('strong');
        name.textContent = user.display_name;
        const id = document.createElement('small');
        id.textContent = `Staff ID ${user.user_id}`;
        const roleName = document.createElement('small');
        roleName.textContent = role.replaceAll('_', ' ');
        details.append(name, id, roleName);
        identity.append(avatar, details);
        const version = document.createElement('span');
        version.className = 'sidebar-version';
        version.innerHTML = 'CareBridge<br>Academic prototype';
        footer.append(identity, version);
        sidebar.replaceChildren(brand, caption, nav, footer, closeButton);
    }

    async function loadSharedNavigation() {
        const apiUrl = new URL(`${projectBase}backend/index.php`, window.location.origin);
        apiUrl.searchParams.set('route', '/api/auth/me');
        try {
            const response = await fetch(apiUrl.href, {
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const payload = await response.json();
            if (!response.ok || !payload.success || !payload.data?.user?.role?.name) {
                throw new Error(payload.message || 'The current user navigation could not be loaded.');
            }
            renderSharedSidebar(payload.data.user);
        } catch (error) {
            console.error('Unable to render the shared CareBridge navigation:', error);
        }
    }

    loadSharedNavigation();
    render();
})();
