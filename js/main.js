// Main Application Logic: Theme Engine (Light/Dark/System) + PWA Service Worker & Install Manager
(function () {
    function getSystemPreference() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function applyThemeState(scheme) {
        const meta = document.querySelector('meta[name="color-scheme"]');
        if (meta) {
            meta.content = scheme;
        }
        const resolved = (scheme === 'light dark' || scheme === 'system') ? getSystemPreference() : scheme;
        const isDark = resolved === 'dark';
        document.documentElement.classList.toggle('dark', isDark);
        document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');

        // Dispatch custom event so any page (e.g. configuracion.php) can update UI pills
        window.dispatchEvent(new CustomEvent('app-theme-changed', {
            detail: { scheme: scheme, resolved: resolved, isDark: isDark }
        }));
    }

    window.setAppTheme = function (mode) {
        const sysPref = getSystemPreference();
        try {
            if (mode === 'system' || mode === 'light dark') {
                localStorage.removeItem('color-scheme');
                applyThemeState('light dark');
                return;
            }
            localStorage.setItem('color-scheme', mode);
            applyThemeState(mode);
        } catch (e) {
            applyThemeState(mode === 'system' ? 'light dark' : mode);
        }
    };

    window.toggleAppTheme = function () {
        const isCurrentlyDark = document.documentElement.classList.contains('dark');
        const target = isCurrentlyDark ? 'light' : 'dark';
        const sysPref = getSystemPreference();
        try {
            if (target === sysPref && (document.documentElement.getAttribute('data-default-theme') === 'system')) {
                localStorage.removeItem('color-scheme');
                applyThemeState('light dark');
            } else {
                localStorage.setItem('color-scheme', target);
                applyThemeState(target);
            }
        } catch (e) {
            applyThemeState(target);
        }
    };

    // Sync across tabs
    window.addEventListener('storage', (e) => {
        if (e.key === 'color-scheme') {
            const defaultTheme = document.documentElement.getAttribute('data-default-theme') || 'light';
            const scheme = e.newValue || (defaultTheme === 'system' ? 'light dark' : defaultTheme);
            applyThemeState(scheme);
        }
    });

    // Listen for OS theme changes when in system mode
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            const stored = localStorage.getItem('color-scheme');
            const defaultTheme = document.documentElement.getAttribute('data-default-theme') || 'light';
            if (!stored && defaultTheme === 'system') {
                applyThemeState('light dark');
            }
        });
    }

    // PWA Service Worker Registration & Install Prompt
    window.deferredPwaPrompt = null;

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js').then((reg) => {
                window.swRegistration = reg;
                window.dispatchEvent(new CustomEvent('pwa-sw-ready', { detail: { registration: reg } }));
            }).catch((err) => {
                console.warn('SW registration warning:', err);
            });
        });
    }

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        window.deferredPwaPrompt = e;
        window.dispatchEvent(new CustomEvent('pwa-install-available'));
    });

    window.addEventListener('appinstalled', () => {
        window.deferredPwaPrompt = null;
        window.dispatchEvent(new CustomEvent('pwa-installed'));
    });

    window.triggerPwaInstall = async function () {
        if (window.deferredPwaPrompt) {
            window.deferredPwaPrompt.prompt();
            const { outcome } = await window.deferredPwaPrompt.userChoice;
            if (outcome === 'accepted') {
                window.deferredPwaPrompt = null;
                window.showToast('Aplicación PWA instalada correctamente en tu dispositivo.', 'success', 'PWA Instalada');
            }
            return outcome;
        } else {
            const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            if (isStandalone) {
                window.showToast('La aplicación PWA ya se encuentra instalada y ejecutándose en modo independiente.', 'info', 'Modo App Activo');
            } else {
                window.showToast('En Chrome/Edge pulsa el ícono "Instalar aplicación" en la barra de direcciones; en Safari iOS usa Compartir > Agregar a inicio.', 'info', 'Instalación PWA', { duration: 6000 });
            }
            return 'manual';
        }
    };

    // =========================================================================
    // MODERN TOAST NOTIFICATION ENGINE (4 Customizable Styles & 4 Positions)
    // =========================================================================
    function inferToastType(msg) {
        const lower = (msg || '').toLowerCase();
        if (lower.includes('error') || lower.includes('❌') || lower.includes('rechazad') || lower.includes('inválid') || lower.includes('falló')) {
            return 'error';
        }
        if (lower.includes('debe ') || lower.includes('ingresa') || lower.includes('selecciona') || lower.includes('verifica') || lower.includes('⚠️') || lower.includes('contingencia') || lower.includes('pendiente')) {
            return 'warning';
        }
        if (lower.includes('✅') || lower.includes('correctamente') || lower.includes('éxito') || lower.includes('emitido') || lower.includes('aceptado') || lower.includes('actualizad') || lower.includes('guardad') || lower.includes('encontrado')) {
            return 'success';
        }
        return 'info';
    }

    function getToastContainer(positionOverride) {
        const pos = positionOverride || document.documentElement.getAttribute('data-toast-pos') || 'top-right';
        let container = document.getElementById('app-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'app-toast-container';
            document.body.appendChild(container);
        }
        container.setAttribute('data-pos', pos);
        return container;
    }

    window.showToast = function (message, type, title, options) {
        const opts = options || {};
        const cleanMsg = String(message || '').replace(/^\s*[✅❌⚠️🛡️]\s*/, '').trim();
        const resolvedType = type || inferToastType(String(message || ''));
        const style = opts.style || document.documentElement.getAttribute('data-toast-style') || 'glass';
        const pos = opts.position || document.documentElement.getAttribute('data-toast-pos') || 'top-right';
        const duration = opts.duration || 4200;

        const defaultTitles = {
            success: 'Operación Exitosa',
            error: 'Atención Requerida',
            warning: 'Aviso del Sistema',
            info: 'Información'
        };
        const icons = {
            success: 'fa-solid fa-circle-check',
            error: 'fa-solid fa-circle-xmark',
            warning: 'fa-solid fa-triangle-exclamation',
            info: 'fa-solid fa-circle-info'
        };

        const resolvedTitle = title || defaultTitles[resolvedType] || 'Notificación';
        const iconClass = icons[resolvedType] || icons.info;

        const container = getToastContainer(pos);
        const toast = document.createElement('div');
        toast.className = `app-toast app-toast--${style} app-toast--${resolvedType}`;
        toast.setAttribute('role', 'status');

        toast.innerHTML = `
            <div class="app-toast__icon">
                <i class="${iconClass}"></i>
            </div>
            <div class="app-toast__body">
                <p class="app-toast__title"></p>
                <p class="app-toast__msg"></p>
            </div>
            <button type="button" class="app-toast__close" aria-label="Cerrar">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div class="app-toast__progress" style="animation-duration: ${duration}ms"></div>
        `;

        toast.querySelector('.app-toast__title').textContent = resolvedTitle;
        toast.querySelector('.app-toast__msg').textContent = cleanMsg;

        const removeToast = () => {
            if (toast._removing) return;
            toast._removing = true;
            toast.classList.add('app-toast--leaving');
            setTimeout(() => {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 260);
        };

        toast.querySelector('.app-toast__close').addEventListener('click', removeToast);
        container.appendChild(toast);

        // Trigger entry animation
        requestAnimationFrame(() => {
            toast.classList.add('app-toast--visible');
        });

        if (duration > 0) {
            setTimeout(removeToast, duration);
        }
        return toast;
    };

    // Replace native window.alert with our Modern Toast
    window.alert = function (msg) {
        window.showToast(String(msg || ''));
    };

    // =========================================================================
    // MODERN CONFIRMATION MODAL ENGINE (4 Customizable Styles)
    // =========================================================================
    window.showConfirm = function (options) {
        const opts = typeof options === 'string' ? { message: options } : (options || {});
        const msg = opts.message || '¿Estás seguro de que deseas continuar con esta acción?';
        const lowerMsg = msg.toLowerCase();

        // Auto-detect variant if not specified
        let variant = opts.variant || 'primary';
        if (!opts.variant) {
            if (lowerMsg.includes('eliminar') || lowerMsg.includes('cerrar') || lowerMsg.includes('restaurar') || lowerMsg.includes('desactivar') || lowerMsg.includes('anular')) {
                variant = 'danger';
            } else if (lowerMsg.includes('sunat') || lowerMsg.includes('emitir') || lowerMsg.includes('enviar')) {
                variant = 'primary';
            } else {
                variant = 'warning';
            }
        }

        const defaultTitles = {
            danger: 'Confirmar Acción',
            warning: 'Verificación Requerida',
            primary: 'Confirmar Operación'
        };
        const defaultIcons = {
            danger: 'fa-solid fa-triangle-exclamation',
            warning: 'fa-solid fa-circle-question',
            primary: 'fa-solid fa-paper-plane'
        };

        const title = opts.title || defaultTitles[variant] || 'Confirmación';
        const confirmText = opts.confirmText || (variant === 'danger' ? 'Sí, confirmar' : 'Continuar');
        const cancelText = opts.cancelText || 'Cancelar';
        const icon = opts.icon || defaultIcons[variant];
        const modalStyle = opts.style || document.documentElement.getAttribute('data-modal-style') || 'modern';

        return new Promise((resolve) => {
            const existing = document.getElementById('app-confirm-backdrop');
            if (existing) existing.remove();

            const backdrop = document.createElement('div');
            backdrop.id = 'app-confirm-backdrop';
            backdrop.className = `app-modal-backdrop app-modal-backdrop--${modalStyle}`;

            backdrop.innerHTML = `
                <div class="app-modal app-modal--${modalStyle} app-modal--${variant}" role="dialog" aria-modal="true">
                    <div class="app-modal__handle"></div>
                    <div class="app-modal__strip"></div>
                    <div class="app-modal__content">
                        <div class="app-modal__icon-wrap">
                            <div class="app-modal__icon">
                                <i class="${icon}"></i>
                            </div>
                        </div>
                        <div class="app-modal__text">
                            <h3 class="app-modal__title"></h3>
                            <p class="app-modal__desc"></p>
                        </div>
                    </div>
                    <div class="app-modal__actions">
                        <button type="button" class="app-modal__btn app-modal__btn--cancel"></button>
                        <button type="button" class="app-modal__btn app-modal__btn--confirm"></button>
                    </div>
                </div>
            `;

            backdrop.querySelector('.app-modal__title').textContent = title;
            backdrop.querySelector('.app-modal__desc').textContent = msg;
            const btnCancel = backdrop.querySelector('.app-modal__btn--cancel');
            const btnConfirm = backdrop.querySelector('.app-modal__btn--confirm');
            btnCancel.textContent = cancelText;
            btnConfirm.textContent = confirmText;

            const close = (result) => {
                if (backdrop._closing) return;
                backdrop._closing = true;
                backdrop.classList.remove('app-modal--open');
                document.removeEventListener('keydown', onKey);
                setTimeout(() => {
                    if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
                    resolve(result);
                }, 200);
            };

            const onKey = (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    close(false);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    close(true);
                }
            };

            btnCancel.addEventListener('click', () => close(false));
            btnConfirm.addEventListener('click', () => close(true));
            backdrop.addEventListener('click', (e) => {
                if (e.target === backdrop) close(false);
            });
            document.addEventListener('keydown', onKey);

            document.body.appendChild(backdrop);
            requestAnimationFrame(() => {
                backdrop.classList.add('app-modal--open');
                btnConfirm.focus();
            });
        });
    };

    // Upgrade any legacy inline onclick="return confirm('...')" or onsubmit="return confirm('...')" to data-confirm
    function upgradeNativeConfirms() {
        document.querySelectorAll('[onclick*="confirm("], [onsubmit*="confirm("]').forEach((el) => {
            const attrName = el.hasAttribute('onsubmit') ? 'onsubmit' : 'onclick';
            const raw = el.getAttribute(attrName) || '';
            const match = raw.match(/confirm\(\s*['"]([^'"]+)['"]\s*\)/);
            if (match && match[1]) {
                el.setAttribute('data-confirm', match[1]);
                el.removeAttribute(attrName);
            }
        });
    }

    // Global delegated listener for [data-confirm] on links, buttons, and forms
    function setupConfirmInterceptors() {
        document.addEventListener('click', async (e) => {
            const trigger = e.target.closest('a[data-confirm], button[data-confirm]');
            if (!trigger) return;
            if (trigger._confirmedBypass) {
                trigger._confirmedBypass = false;
                return;
            }
            e.preventDefault();
            e.stopPropagation();

            const message = trigger.getAttribute('data-confirm');
            const title = trigger.getAttribute('data-confirm-title') || '';
            const confirmText = trigger.getAttribute('data-confirm-text') || '';
            const variant = trigger.getAttribute('data-confirm-variant') || '';

            const ok = await window.showConfirm({ message, title, confirmText, variant });
            if (!ok) return;

            if (trigger.tagName === 'A' && trigger.href) {
                window.location.href = trigger.href;
            } else if (trigger.tagName === 'BUTTON' && trigger.form) {
                trigger._confirmedBypass = true;
                trigger.click();
            }
        }, true);

        document.addEventListener('submit', async (e) => {
            const form = e.target.closest('form[data-confirm]');
            if (!form) return;
            if (form._confirmedBypass) {
                form._confirmedBypass = false;
                return;
            }
            e.preventDefault();
            e.stopPropagation();

            const message = form.getAttribute('data-confirm');
            const title = form.getAttribute('data-confirm-title') || '';
            const confirmText = form.getAttribute('data-confirm-text') || '';
            const variant = form.getAttribute('data-confirm-variant') || '';

            const ok = await window.showConfirm({ message, title, confirmText, variant });
            if (ok) {
                form._confirmedBypass = true;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        }, true);
    }

    // Automatically fire a Toast when PHP renders a server-side feedback banner
    function triggerServerFlashToasts() {
        const banners = document.querySelectorAll('[data-auto-toast], main .bg-emerald-50, main .bg-green-50, main .bg-red-50, main .bg-rose-50');
        banners.forEach((banner) => {
            if (banner._toastFired || banner.closest('.hidden')) return;
            // Only pick actual feedback alerts (containing check or exclamation icon)
            const hasStatusIcon = banner.querySelector('.fa-circle-check, .fa-circle-exclamation, .fa-triangle-exclamation');
            if (!hasStatusIcon && !banner.hasAttribute('data-auto-toast')) return;
            const text = banner.textContent.replace(/\s+/g, ' ').trim();
            if (!text || text.length > 260) return;
            banner._toastFired = true;
            const isErr = banner.classList.contains('bg-red-50') || banner.classList.contains('bg-rose-50') || banner.querySelector('.fa-circle-exclamation');
            window.showToast(text, isErr ? 'error' : 'success');
        });
    }

    // Responsive Tables Engine: Transforms all system tables into comfortable mobile cards (< 768px)
    function enhanceTableRow(tr, headers) {
        if (!tr || tr.nodeType !== 1) return;
        const tds = Array.from(tr.children).filter(el => el.tagName === 'TD');
        if (tds.length === 0) return;

        // Empty state row with colspan
        if (tds.length === 1 && tds[0].hasAttribute('colspan')) {
            tr.classList.add('empty-table-row');
            return;
        }

        const isItemRow = tr.classList.contains('item-row');
        if (!isItemRow) {
            tr.classList.add('data-card-row');
        }

        tds.forEach((td, idx) => {
            const rawHeader = (headers[idx] !== undefined ? headers[idx] : '').trim();
            const lowerHeader = rawHeader.toLowerCase();
            const isCheckboxCell = idx === 0 && !!td.querySelector('input[type="checkbox"]');

            if (isCheckboxCell && !rawHeader) {
                td.setAttribute('data-label', 'Seleccionar');
                td.setAttribute('data-checkbox-cell', 'true');
            } else if (!td.hasAttribute('data-label')) {
                td.setAttribute('data-label', rawHeader);
            } else if (rawHeader && td.getAttribute('data-label') !== rawHeader) {
                td.setAttribute('data-label', rawHeader);
            }

            if (isItemRow) {
                if (td.querySelector('.item-desc') || lowerHeader.includes('descrip')) {
                    td.setAttribute('data-field', 'desc');
                } else if (td.classList.contains('btn-remove') || td.querySelector('.btn-remove')) {
                    td.setAttribute('data-field', 'remove');
                    if (!td.getAttribute('data-label')) {
                        td.setAttribute('data-label', 'Quitar');
                    }
                } else if (td.classList.contains('item-total') || lowerHeader.includes('total')) {
                    td.setAttribute('data-field', 'total');
                } else {
                    td.setAttribute('data-field', 'input');
                }
            } else {
                const firstCellHasCheckbox = tds[0] && !!tds[0].querySelector('input[type="checkbox"]');
                if ((idx === 0 && !firstCellHasCheckbox) || (idx === 1 && firstCellHasCheckbox)) {
                    td.setAttribute('data-primary', 'true');
                }
                if (idx === tds.length - 1 && (lowerHeader.includes('acci') || rawHeader === '' || td.querySelector('a, button, form'))) {
                    td.setAttribute('data-actions', 'true');
                    if (!td.getAttribute('data-label')) {
                        td.setAttribute('data-label', 'Acciones');
                    }
                }
                if (!td.querySelector(':scope > .mobile-cell-val')) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'mobile-cell-val';
                    while (td.firstChild) {
                        wrapper.appendChild(td.firstChild);
                    }
                    td.appendChild(wrapper);
                }
            }
        });
    }

    function enhanceResponsiveTables() {
        const tables = document.querySelectorAll('main table');
        tables.forEach((table) => {
            const thead = table.querySelector('thead');
            const tbody = table.querySelector('tbody');
            if (!thead || !tbody) return;

            table.classList.add('responsive-table');
            const scrollWrap = table.closest('.overflow-x-auto');
            if (scrollWrap) {
                scrollWrap.classList.add('responsive-table-wrap');
            }

            const getHeaders = () => Array.from(thead.querySelectorAll('th')).map(th => {
                return th.textContent.replace(/\s+/g, ' ').trim();
            });

            const refreshAllRows = () => {
                const headers = getHeaders();
                Array.from(tbody.querySelectorAll('tr')).forEach(tr => enhanceTableRow(tr, headers));
            };

            refreshAllRows();

            if (!tbody._responsiveObserverAttached) {
                const observer = new MutationObserver((mutations) => {
                    const headers = getHeaders();
                    mutations.forEach((m) => {
                        m.addedNodes.forEach((node) => {
                            if (node.nodeType === 1 && node.tagName === 'TR') {
                                enhanceTableRow(node, headers);
                            }
                        });
                    });
                });
                observer.observe(tbody, { childList: true });
                tbody._responsiveObserverAttached = true;
            }
        });
    }

    window.enhanceResponsiveTables = enhanceResponsiveTables;

    setupConfirmInterceptors();

    document.addEventListener('DOMContentLoaded', () => {
        upgradeNativeConfirms();
        enhanceResponsiveTables();
        triggerServerFlashToasts();
    });
})();
