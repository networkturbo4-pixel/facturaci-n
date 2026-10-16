/**
 * js/product_dropdown.js
 * Componente moderno de autocompletado y catálogo desplegable de productos y servicios.
 * Diseñado para reemplazar los datalists nativos con tarjetas visuales, búsqueda instantánea,
 * compatibilidad con tema Claro/Oscuro y navegación por teclado.
 */

(function (window) {
    'use strict';

    function normalizeText(text) {
        return (text || '')
            .toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function highlightMatch(text, query) {
        if (!query) return escapeHtml(text);
        const normText = normalizeText(text);
        const normQuery = normalizeText(query);
        const idx = normText.indexOf(normQuery);
        if (idx === -1) return escapeHtml(text);

        const before = text.substring(0, idx);
        const match = text.substring(idx, idx + query.length);
        const after = text.substring(idx + query.length);

        return escapeHtml(before) +
            '<mark class="bg-amber-100 dark:bg-amber-500/30 text-amber-900 dark:text-amber-200 font-bold px-0.5 rounded">' +
            escapeHtml(match) +
            '</mark>' +
            escapeHtml(after);
    }

    /**
     * Inicializa un desplegable moderno de productos en un contenedor wrapper
     *
     * @param {HTMLElement} wrapper Contenedor .product-dropdown-wrapper
     * @param {Array} productsList Catálogo de productos [{codigo, descripcion, precio_unitario, unidad_medida, stock}, ...]
     * @param {Function} onSelect Callback ejecutado al seleccionar: onSelect(product, rowElement)
     */
    function attachProductDropdown(wrapper, productsList, onSelect) {
        if (!wrapper || wrapper.dataset.dropdownInitialized === 'true') return;
        wrapper.dataset.dropdownInitialized = 'true';

        const input = wrapper.querySelector('.item-desc');
        const toggleBtn = wrapper.querySelector('.btn-toggle-dropdown');
        const menu = wrapper.querySelector('.product-dropdown-menu');
        const listContainer = wrapper.querySelector('.product-items-list');
        const counter = wrapper.querySelector('.dropdown-counter');
        const row = wrapper.closest('.item-row') || wrapper.closest('tr');

        if (!input || !menu || !listContainer) return;

        let activeIndex = -1;
        let currentFiltered = [];

        function renderList(query = '') {
            const normQ = normalizeText(query);
            if (!normQ) {
                currentFiltered = productsList || [];
            } else {
                currentFiltered = (productsList || []).filter(item => {
                    const d = normalizeText(item.descripcion);
                    const c = normalizeText(item.codigo);
                    return d.includes(normQ) || c.includes(normQ);
                });
            }

            if (counter) {
                counter.textContent = currentFiltered.length + (currentFiltered.length === 1 ? ' ítem' : ' ítems');
            }

            listContainer.innerHTML = '';
            activeIndex = -1;

            if (currentFiltered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'p-4 text-center text-xs text-slate-500 dark:text-slate-400';
                empty.innerHTML = `
                    <div class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mx-auto mb-2 text-xs">
                        <i class="fa-solid fa-keyboard"></i>
                    </div>
                    <p class="font-bold text-slate-700 dark:text-slate-300">Sin coincidencias en el catálogo</p>
                    <p class="text-[11px] text-slate-400 mt-1">Presiona <kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 font-mono text-[10px]">Enter</kbd> para usar "${escapeHtml(query)}" como concepto libre.</p>
                `;
                listContainer.appendChild(empty);
                return;
            }

            currentFiltered.forEach((item, index) => {
                const card = document.createElement('div');
                card.className = 'product-card-item p-2.5 rounded-xl cursor-pointer transition-all duration-150 flex items-center justify-between gap-3 hover:bg-brand-50/80 dark:hover:bg-slate-800/80 group border border-transparent hover:border-brand-200/50 dark:hover:border-slate-700 select-none';
                card.dataset.index = index;

                const code = item.codigo || '';
                const isService = code.toUpperCase().startsWith('SRV') || normalizeText(item.descripcion).includes('servicio');
                const iconClass = isService ? 'fa-solid fa-briefcase text-purple-600 dark:text-purple-400' : 'fa-solid fa-box text-emerald-600 dark:text-emerald-400';
                const iconBg = isService ? 'bg-purple-50 dark:bg-purple-500/15' : 'bg-emerald-50 dark:bg-emerald-500/15';

                const priceNum = parseFloat(item.precio_unitario) || 0;
                const stockNum = parseFloat(item.stock) || 0;
                const unitStr = (item.unidad_medida || 'NIU').toUpperCase();

                let stockHtml = '';
                if (!isService && stockNum > 0) {
                    stockHtml = `<span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold"><i class="fa-solid fa-check text-[9px]"></i> Stock: ${stockNum}</span>`;
                } else if (!isService && stockNum <= 0) {
                    stockHtml = `<span class="text-[10px] text-amber-500 font-semibold">Stock bajo</span>`;
                }

                card.innerHTML = `
                    <div class="flex items-center gap-2.5 min-w-0 flex-1">
                        <div class="w-8 h-8 rounded-lg ${iconBg} flex items-center justify-center text-xs shrink-0 group-hover:scale-105 transition-transform">
                            <i class="${iconClass}"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold text-slate-800 dark:text-slate-100 group-hover:text-brand-600 dark:group-hover:text-brand-400 truncate">
                                ${highlightMatch(item.descripcion, query)}
                            </p>
                            <div class="flex items-center gap-1.5 mt-0.5">
                                <span class="px-1.5 py-0.2 rounded text-[10px] font-mono font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200/80 dark:border-slate-700">
                                    ${highlightMatch(code, query)}
                                </span>
                                <span class="text-[10px] text-slate-400 uppercase font-semibold">${escapeHtml(unitStr)}</span>
                                ${stockHtml}
                            </div>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <span class="text-xs font-mono font-extrabold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-500/15 border border-emerald-200/60 dark:border-emerald-500/30 px-2 py-1 rounded-lg">
                            S/ ${priceNum.toFixed(2)}
                        </span>
                    </div>
                `;

                card.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // Previene blur antes de select
                    selectItem(item);
                });

                card.addEventListener('mouseenter', function () {
                    setActiveIndex(index);
                });

                listContainer.appendChild(card);
            });
        }

        function setActiveIndex(idx) {
            const cards = listContainer.querySelectorAll('.product-card-item');
            cards.forEach((c, i) => {
                if (i === idx) {
                    c.classList.add('bg-brand-50/90', 'dark:bg-slate-800', 'ring-2', 'ring-brand-500/40');
                    c.scrollIntoView({ block: 'nearest' });
                } else {
                    c.classList.remove('bg-brand-50/90', 'dark:bg-slate-800', 'ring-2', 'ring-brand-500/40');
                }
            });
            activeIndex = idx;
        }

        function openDropdown() {
            // Cerrar otros dropdowns abiertos
            document.querySelectorAll('.product-dropdown-menu:not(.hidden)').forEach(d => {
                if (d !== menu) d.classList.add('hidden');
            });
            document.querySelectorAll('.btn-toggle-dropdown i.rotate-180').forEach(icon => {
                icon.classList.remove('rotate-180');
            });

            renderList(input.value);
            menu.classList.remove('hidden');
            if (toggleBtn) {
                const icon = toggleBtn.querySelector('i');
                if (icon) icon.classList.add('rotate-180');
            }
        }

        function closeDropdown() {
            menu.classList.add('hidden');
            activeIndex = -1;
            if (toggleBtn) {
                const icon = toggleBtn.querySelector('i');
                if (icon) icon.classList.remove('rotate-180');
            }
        }

        function selectItem(item) {
            input.value = item.descripcion;
            closeDropdown();

            if (typeof onSelect === 'function') {
                onSelect(item, row);
            }

            // Disparar evento nativo 'change' e 'input' para los listeners de la fila
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Eventos
        input.addEventListener('focus', function () {
            openDropdown();
        });

        input.addEventListener('input', function () {
            if (menu.classList.contains('hidden')) {
                openDropdown();
            } else {
                renderList(this.value);
            }
        });

        input.addEventListener('keydown', function (e) {
            if (menu.classList.contains('hidden')) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    openDropdown();
                }
                return;
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                const total = currentFiltered.length;
                if (total > 0) {
                    const next = (activeIndex + 1) >= total ? 0 : activeIndex + 1;
                    setActiveIndex(next);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                const total = currentFiltered.length;
                if (total > 0) {
                    const prev = (activeIndex - 1) < 0 ? total - 1 : activeIndex - 1;
                    setActiveIndex(prev);
                }
            } else if (e.key === 'Enter') {
                if (activeIndex >= 0 && currentFiltered[activeIndex]) {
                    e.preventDefault();
                    selectItem(currentFiltered[activeIndex]);
                } else {
                    closeDropdown();
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
            } else if (e.key === 'Tab') {
                closeDropdown();
            }
        });

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (menu.classList.contains('hidden')) {
                    input.focus();
                    openDropdown();
                } else {
                    closeDropdown();
                }
            });
        }

        // Cerrar al hacer clic fuera
        document.addEventListener('click', function (e) {
            if (!wrapper.contains(e.target)) {
                closeDropdown();
            }
        });
    }

    // Exportar al objeto global
    window.attachProductDropdown = attachProductDropdown;

})(window);
