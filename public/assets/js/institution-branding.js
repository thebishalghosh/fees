(() => {
    const form = document.querySelector('[data-branding-form]');
    const preview = document.querySelector('[data-branding-preview]');
    if (!(form instanceof HTMLFormElement) || !(preview instanceof HTMLElement)) {
        return;
    }

    const keys = [
        'brand_primary_color',
        'brand_secondary_color',
        'brand_accent_color',
        'brand_sidebar_background',
        'brand_sidebar_text',
        'brand_header_background',
        'brand_header_text',
        'brand_page_background',
        'brand_surface_background',
        'brand_button_background',
        'brand_button_text',
        'login_background_color',
        'login_primary_color',
    ];
    const propertyNames = {
        brand_primary_color: '--preview-primary',
        brand_secondary_color: '--preview-secondary',
        brand_accent_color: '--preview-accent',
        brand_sidebar_background: '--preview-sidebar',
        brand_sidebar_text: '--preview-sidebar-text',
        brand_header_background: '--preview-header',
        brand_header_text: '--preview-header-text',
        brand_page_background: '--preview-page',
        brand_surface_background: '--preview-surface',
        brand_button_background: '--preview-button',
        brand_button_text: '--preview-button-text',
        login_background_color: '--preview-login-background',
        login_primary_color: '--preview-login-primary',
    };
    const welcomeText = form.querySelector('[name="login_welcome_text"]');
    const loginPreviewText = preview.querySelector('.preview-login-card > span:not(.preview-login-mark)');
    const initialValues = new Map();
    for (const key of keys) {
        const field = form.querySelector(`[data-color-value="${key}"]`);
        if (field instanceof HTMLInputElement) {
            initialValues.set(key, field.dataset.initialValue || field.value);
        }
    }
    if (welcomeText instanceof HTMLInputElement) {
        initialValues.set('login_welcome_text', welcomeText.dataset.initialValue || welcomeText.value);
    }

    const valuesChanged = () => {
        for (const [name, initialValue] of initialValues) {
            const field = form.elements.namedItem(name);
            if (field instanceof HTMLInputElement && field.value !== initialValue) {
                return true;
            }
        }
        return false;
    };
    const syncPreview = () => {
        for (const key of keys) {
            const field = form.querySelector(`[data-color-value="${key}"]`);
            if (!(field instanceof HTMLInputElement) || !/^#[0-9a-f]{6}$/i.test(field.value)) {
                continue;
            }
            preview.style.setProperty(propertyNames[key], field.value);
            const picker = form.querySelector(`[data-color-picker="${key}"]`);
            if (picker instanceof HTMLInputElement) {
                picker.value = field.value;
            }
        }
        if (welcomeText instanceof HTMLInputElement && loginPreviewText instanceof HTMLElement) {
            loginPreviewText.textContent = welcomeText.value.trim() || 'Welcome to your institution portal';
        }
        const warning = form.querySelector('[data-unsaved-message]');
        if (warning instanceof HTMLElement) {
            warning.hidden = !valuesChanged();
        }
    };

    for (const key of keys) {
        const field = form.querySelector(`[data-color-value="${key}"]`);
        const picker = form.querySelector(`[data-color-picker="${key}"]`);
        if (field instanceof HTMLInputElement) {
            field.addEventListener('input', syncPreview);
            field.addEventListener('change', syncPreview);
        }
        if (picker instanceof HTMLInputElement) {
            picker.addEventListener('input', () => {
                if (field instanceof HTMLInputElement) {
                    field.value = picker.value.toUpperCase();
                    syncPreview();
                }
            });
        }
    }
    if (welcomeText instanceof HTMLInputElement) {
        welcomeText.addEventListener('input', syncPreview);
    }

    const resetButton = form.querySelector('[data-reset-branding]');
    if (resetButton instanceof HTMLButtonElement) {
        resetButton.addEventListener('click', () => {
            for (const input of form.querySelectorAll('[data-default-value]')) {
                if (input instanceof HTMLInputElement) {
                    input.value = input.dataset.defaultValue || '';
                }
            }
            syncPreview();
        });
    }

    form.addEventListener('submit', () => {
        window.removeEventListener('beforeunload', warnBeforeLeaving);
    });
    const warnBeforeLeaving = (event) => {
        if (!valuesChanged()) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    };
    window.addEventListener('beforeunload', warnBeforeLeaving);
    syncPreview();
})();
