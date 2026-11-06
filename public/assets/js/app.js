(() => {
    'use strict';

    const nav = document.getElementById('site-nav');
    const updateNavOnScroll = () => {
        if (nav && nav.classList.contains('site-navbar-public')) {
            nav.classList.toggle('scrolled', window.scrollY > 40);
        }
    };

    updateNavOnScroll();
    window.addEventListener('scroll', updateNavOnScroll, { passive: true });

    document.querySelectorAll('[data-auto-show-toast]').forEach((toastElement) => {
        if (window.bootstrap && window.bootstrap.Toast) {
            window.bootstrap.Toast.getOrCreateInstance(toastElement).show();
        }
    });

    const sidebar = document.getElementById('app-sidebar');
    if (document.body.classList.contains('app-page-admin') && sidebar) {
        const sidebarScrollArea = window.matchMedia('(min-width: 992px)').matches
            ? sidebar
            : sidebar.querySelector('.offcanvas-body') || sidebar;
        const sidebarScrollKey = 'feesportal.admin.sidebar.scrollTop';

        const saveSidebarScroll = () => {
            try {
                window.sessionStorage.setItem(sidebarScrollKey, String(sidebarScrollArea.scrollTop));
            } catch (error) {
                console.warn('Unable to preserve the admin sidebar scroll position.', error);
            }
        };

        const restoreSidebarScroll = () => {
            try {
                const savedScrollTop = window.sessionStorage.getItem(sidebarScrollKey);
                if (savedScrollTop !== null && Number.isFinite(Number(savedScrollTop))) {
                    sidebarScrollArea.scrollTop = Number(savedScrollTop);
                }
            } catch (error) {
                console.warn('Unable to restore the admin sidebar scroll position.', error);
            }
        };

        restoreSidebarScroll();
        window.addEventListener('pagehide', saveSidebarScroll);
    }

    const permissionMatrix = document.querySelector('.rbac-permission-matrix');
    if (permissionMatrix) {
        const permissionCheckboxes = Array.from(permissionMatrix.querySelectorAll('[data-rbac-permission]'));
        const updateModuleToggle = (moduleId) => {
            const toggle = permissionMatrix.querySelector(`[data-rbac-module-toggle="${moduleId}"]`);
            const checkboxes = permissionCheckboxes.filter((checkbox) => checkbox.dataset.rbacModule === moduleId);
            if (!toggle) {
                return;
            }
            const enabled = checkboxes.filter((checkbox) => !checkbox.disabled);
            const selectedCount = enabled.filter((checkbox) => checkbox.checked).length;
            toggle.checked = enabled.length > 0 && selectedCount === enabled.length;
            toggle.indeterminate = selectedCount > 0 && selectedCount < enabled.length;
            toggle.disabled = enabled.length === 0;
        };
        const updateSelectionCount = () => {
            const counter = document.querySelector('.rbac-matrix-toolbar > span');
            if (counter) {
                counter.textContent = `${permissionCheckboxes.filter((checkbox) => checkbox.checked).length} permissions selected`;
            }
        };

        permissionMatrix.querySelectorAll('[data-rbac-module-toggle]').forEach((toggle) => {
            toggle.addEventListener('change', () => {
                permissionCheckboxes
                    .filter((checkbox) => checkbox.dataset.rbacModule === toggle.dataset.rbacModuleToggle && !checkbox.disabled)
                    .forEach((checkbox) => {
                        checkbox.checked = toggle.checked;
                    });
                updateModuleToggle(toggle.dataset.rbacModuleToggle);
                updateSelectionCount();
            });
        });
        permissionCheckboxes.forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                updateModuleToggle(checkbox.dataset.rbacModule);
                updateSelectionCount();
            });
        });
        document.querySelector('[data-rbac-select-all]')?.addEventListener('click', () => {
            permissionCheckboxes.forEach((checkbox) => {
                if (!checkbox.disabled) {
                    checkbox.checked = true;
                }
            });
            permissionMatrix.querySelectorAll('[data-rbac-module-toggle]').forEach((toggle) => updateModuleToggle(toggle.dataset.rbacModuleToggle));
            updateSelectionCount();
        });
        document.querySelector('[data-rbac-deselect-all]')?.addEventListener('click', () => {
            permissionCheckboxes.forEach((checkbox) => {
                if (!checkbox.disabled) {
                    checkbox.checked = false;
                }
            });
            permissionMatrix.querySelectorAll('[data-rbac-module-toggle]').forEach((toggle) => updateModuleToggle(toggle.dataset.rbacModuleToggle));
            updateSelectionCount();
        });
        permissionMatrix.querySelectorAll('[data-rbac-module-toggle]').forEach((toggle) => updateModuleToggle(toggle.dataset.rbacModuleToggle));
        updateSelectionCount();
    }

    document.querySelectorAll('form[data-rbac-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.rbacConfirm || 'Confirm this role change?')) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('#site-menu .nav-link[href^="#"]').forEach((link) => {
        link.addEventListener('click', () => {
            const menu = document.getElementById('site-menu');
            if (menu && menu.classList.contains('show') && window.bootstrap) {
                window.bootstrap.Collapse.getOrCreateInstance(menu).hide();
            }
        });
    });
})();
