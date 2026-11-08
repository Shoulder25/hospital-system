'use strict';

(() => {
    const root = document.body;
    const layout = root.querySelector('.dashboard-layout');
    const sidebar = layout && layout.querySelector('.sidebar');
    const title = layout && layout.querySelector('.topbar-title');
    if (!layout || !sidebar || !title) return;

    root.classList.add('staff-layout');
    if (!sidebar.id) sidebar.id = 'staff-sidebar';
    const navigation = sidebar.querySelector('.side-nav');
    if (navigation && !navigation.hasAttribute('tabindex')) navigation.tabIndex = 0;

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
    sidebar.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => closeDrawer(false));
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeDrawer();
    });
    mobileQuery.addEventListener('change', () => {
        mobileOpen = false;
        render();
    });
    render();
})();
