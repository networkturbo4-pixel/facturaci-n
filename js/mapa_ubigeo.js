/**
 * js/mapa_ubigeo.js
 * Selector interactivo de mapa (Leaflet + OpenStreetMap + Nominatim) con cálculo
 * automático de dirección, coordenadas (Lat/Lng) y Ubigeo SUNAT/INEI de Perú.
 */
(function (window) {
    'use strict';

    let leafletPromise = null;
    let modalEl = null;
    let mapInstance = null;
    let markerInstance = null;
    let activeCallback = null;

    // Diccionario de Ubigeos INEI/SUNAT (Lima Metropolitana, Callao y principales ciudades/capitales del Perú)
    const UBIGEO_DISTRITOS = {
        // Lima Metropolitana (1501xx)
        'lima': '150101', 'cercado de lima': '150101', 'ancon': '150102', 'ate': '150103', 'ate vitarte': '150103',
        'barranco': '150104', 'brena': '150105', 'carabayllo': '150106', 'chaclacayo': '150107', 'chorrillos': '150108',
        'cieneguilla': '150109', 'comas': '150110', 'el agustino': '150111', 'independencia': '150112', 'jesus maria': '150113',
        'la molina': '150114', 'la victoria': '150115', 'lince': '150116', 'los olivos': '150117', 'lurigancho': '150118',
        'chosica': '150118', 'lurin': '150119', 'magdalena del mar': '150120', 'magdalena': '150120', 'pueblo libre': '150121',
        'miraflores': '150122', 'pachacamac': '150123', 'pucusana': '150124', 'puente piedra': '150125', 'punta hermosa': '150126',
        'punta negra': '150127', 'rimac': '150128', 'san bartolo': '150129', 'san borja': '150130', 'san isidro': '150131',
        'san juan de lurigancho': '150132', 'san juan de miraflores': '150133', 'san luis': '150134',
        'san martin de porres': '150135', 'san miguel': '150136', 'santa anita': '150137', 'santa maria del mar': '150138',
        'santa rosa': '150139', 'santiago de surco': '150140', 'surco': '150140', 'surquillo': '150141',
        'villa el salvador': '150142', 'villa maria del triunfo': '150143',
        // Callao (0701xx)
        'callao': '070101', 'bellavista': '070102', 'carmen de la legua': '070103', 'la perla': '070104',
        'la punta': '070105', 'ventanilla': '070106', 'mi peru': '070107',
        // Principales ciudades y capitales
        'chachapoyas': '010101', 'bagua': '010201', 'huaraz': '020101', 'chimbote': '021801', 'nuevo chimbote': '021809',
        'abancay': '030101', 'andahuaylas': '030201', 'arequipa': '040101', 'cayma': '040103', 'cerro colorado': '040104',
        'jose luis bustamante y rivero': '040129', 'yanahuara': '040126', 'ayacucho': '050101', 'cajamarca': '060101',
        'cusco': '080101', 'wanchaq': '080108', 'san sebastian': '080105', 'huancavelica': '090101',
        'huanuco': '100101', 'tingo maria': '100601', 'ica': '110101', 'chincha alta': '110201', 'pisco': '110501',
        'huancayo': '120101', 'el tambo': '120114', 'trujillo': '130101', 'victor larco herrera': '130111', 'huanchaco': '130104',
        'chiclayo': '140101', 'jose leonardo ortiz': '140105', 'la victoria chiclayo': '140106', 'lambayeque': '140301',
        'huacho': '150801', 'canete': '150501', 'san vicente de canete': '150501', 'barranca': '150201', 'huaral': '150601',
        'iquitos': '160101', 'punchana': '160108', 'puerto maldonado': '170101', 'moquegua': '180101', 'ilo': '180301',
        'cerro de pasco': '190101', 'piura': '200101', 'castilla': '200104', 'sullana': '200601', 'talara': '200701', 'paita': '200501',
        'puno': '210101', 'juliaca': '211101', 'moyobamba': '220101', 'tarapoto': '220901',
        'tacna': '230101', 'tumbes': '240101', 'pucallpa': '250101', 'calleria': '250101', 'yarinacocha': '250105'
    };

    const UBIGEO_DEPARTAMENTOS = {
        'amazonas': '010101', 'ancash': '020101', 'apurimac': '030101', 'arequipa': '040101',
        'ayacucho': '050101', 'cajamarca': '060101', 'callao': '070101', 'cusco': '080101',
        'huancavelica': '090101', 'huanuco': '100101', 'ica': '110101', 'junin': '120101',
        'la libertad': '130101', 'lambayeque': '140101', 'lima': '150101', 'loreto': '160101',
        'madre de dios': '170101', 'moquegua': '180101', 'pasco': '190101', 'piura': '200101',
        'puno': '210101', 'san martin': '220101', 'tacna': '230101', 'tumbes': '240101', 'ucayali': '250101'
    };

    function norm(str) {
        return (str || '').toString().toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s]/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function inferirUbigeo(addr) {
        if (!addr) return '150101';
        const candidates = [
            addr.city_district, addr.suburb, addr.town, addr.village,
            addr.municipality, addr.city, addr.county
        ].map(norm).filter(Boolean);

        for (const c of candidates) {
            if (UBIGEO_DISTRITOS[c]) return UBIGEO_DISTRITOS[c];
            for (const [key, code] of Object.entries(UBIGEO_DISTRITOS)) {
                if (c.includes(key) || key.includes(c)) return code;
            }
        }
        const dep = norm(addr.state || addr.region || '');
        for (const [key, code] of Object.entries(UBIGEO_DEPARTAMENTOS)) {
            if (dep.includes(key)) return code;
        }
        return '150101';
    }

    function formatDireccionNominatim(data) {
        if (!data) return '';
        const a = data.address || {};
        const calle = [a.road || a.pedestrian || a.residential || '', a.house_number || ''].filter(Boolean).join(' ').trim();
        const zona = a.suburb || a.neighbourhood || a.city_district || a.town || a.village || '';
        const ciudad = a.city || a.county || a.state || '';
        const parts = [calle, zona, ciudad].filter(Boolean);
        return parts.length > 0 ? parts.join(', ') : (data.display_name || '');
    }

    function loadLeaflet() {
        if (window.L) return Promise.resolve(window.L);
        if (leafletPromise) return leafletPromise;

        leafletPromise = new Promise((resolve, reject) => {
            if (!document.getElementById('leaflet-css')) {
                const link = document.createElement('link');
                link.id = 'leaflet-css';
                link.rel = 'stylesheet';
                link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                document.head.appendChild(link);
            }
            const script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = () => resolve(window.L);
            script.onerror = () => reject(new Error('No se pudo cargar la librería de mapas (Leaflet).'));
            document.head.appendChild(script);
        });
        return leafletPromise;
    }

    function ensureModal() {
        if (modalEl) return modalEl;
        modalEl = document.createElement('div');
        modalEl.id = 'modal_mapa_ubigeo';
        modalEl.className = 'hidden fixed inset-0 z-[10000] bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-3 sm:p-5';
        modalEl.innerHTML = `
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col max-h-[92vh]">
                <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-xl bg-brand-50 dark:bg-brand-500/15 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm">
                            <i class="fa-solid fa-map-location-dot"></i>
                        </div>
                        <div>
                            <h3 id="mapa_modal_titulo" class="text-sm font-extrabold text-slate-900 dark:text-white">Seleccionar Ubicación en el Mapa</h3>
                            <p class="text-[11px] text-slate-400">Haz clic o arrastra el pin para autocompletar dirección, ubigeo y coordenadas</p>
                        </div>
                    </div>
                    <button type="button" id="btn_cerrar_mapa_modal" class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center justify-center">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="p-4 space-y-3 overflow-y-auto">
                    <div class="flex flex-col sm:flex-row gap-2">
                        <div class="relative flex-1 flex">
                            <input type="text" id="inp_mapa_buscar" placeholder="Buscar calle, avenida, distrito o referencia en Perú..." class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-l-xl px-3.5 py-2 text-xs text-slate-800 dark:text-white focus:outline-none focus:border-brand-500">
                            <button type="button" id="btn_mapa_buscar" class="px-3.5 py-2 bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold rounded-r-xl flex items-center gap-1.5 shrink-0">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <span>Buscar</span>
                            </button>
                        </div>
                        <button type="button" id="btn_mapa_gps" class="px-3 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold rounded-xl flex items-center justify-center gap-1.5 shrink-0">
                            <i class="fa-solid fa-crosshairs text-brand-600"></i>
                            <span>Mi ubicación</span>
                        </button>
                    </div>

                    <div id="mapa_ubigeo_canvas" class="w-full h-64 sm:h-72 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 z-0"></div>

                    <div id="mapa_status_bar" class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center gap-2 min-h-[18px]">
                        <i class="fa-solid fa-circle-info text-brand-500"></i>
                        <span>Haz clic en cualquier punto del mapa para obtener su dirección y ubigeo.</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-2.5 pt-1">
                        <div class="sm:col-span-6">
                            <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1">Dirección Detectada</label>
                            <input type="text" id="mapa_res_dir" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-semibold text-slate-800 dark:text-white">
                        </div>
                        <div class="sm:col-span-3">
                            <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1">Ubigeo (6 dígitos)</label>
                            <input type="text" id="mapa_res_ubigeo" maxlength="6" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-mono font-bold text-slate-800 dark:text-white">
                        </div>
                        <div class="sm:col-span-3">
                            <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1">Coordenadas (Lat, Lng)</label>
                            <input type="text" id="mapa_res_coords" readonly class="w-full bg-slate-100 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 rounded-xl px-2.5 py-2 text-[11px] font-mono text-slate-600 dark:text-slate-300">
                        </div>
                    </div>
                </div>

                <div class="px-5 py-3.5 bg-slate-50 dark:bg-slate-900/60 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                    <button type="button" id="btn_cancelar_mapa_modal" class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">Cancelar</button>
                    <button type="button" id="btn_confirmar_mapa_modal" class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-sm flex items-center gap-1.5">
                        <i class="fa-solid fa-check"></i>
                        <span>Usar esta ubicación</span>
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modalEl);

        const close = () => modalEl.classList.add('hidden');
        modalEl.querySelector('#btn_cerrar_mapa_modal').addEventListener('click', close);
        modalEl.querySelector('#btn_cancelar_mapa_modal').addEventListener('click', close);
        modalEl.addEventListener('click', (e) => { if (e.target === modalEl) close(); });

        modalEl.querySelector('#btn_confirmar_mapa_modal').addEventListener('click', () => {
            const coordsStr = modalEl.querySelector('#mapa_res_coords').value || '';
            const parts = coordsStr.split(',').map(s => parseFloat(s.trim()));
            const payload = {
                direccion: modalEl.querySelector('#mapa_res_dir').value.trim(),
                ubigeo: modalEl.querySelector('#mapa_res_ubigeo').value.trim() || '150101',
                lat: !isNaN(parts[0]) ? parts[0] : null,
                lng: !isNaN(parts[1]) ? parts[1] : null
            };
            close();
            if (typeof activeCallback === 'function') activeCallback(payload);
        });

        async function reverseGeocode(lat, lng) {
            const status = modalEl.querySelector('#mapa_status_bar');
            modalEl.querySelector('#mapa_res_coords').value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            status.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-brand-500"></i> <span>Consultando dirección y ubigeo...</span>';
            try {
                const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&addressdetails=1&accept-language=es`;
                const r = await fetch(url);
                const data = await r.json();
                const dir = formatDireccionNominatim(data);
                const ubg = inferirUbigeo(data.address);
                modalEl.querySelector('#mapa_res_dir').value = dir;
                modalEl.querySelector('#mapa_res_ubigeo').value = ubg;
                const distLabel = data.address?.city_district || data.address?.suburb || data.address?.town || data.address?.city || '';
                status.innerHTML = `<i class="fa-solid fa-circle-check text-emerald-500"></i> <span>Ubicación detectada${distLabel ? ' (' + distLabel + ')' : ''} • Ubigeo sugerido: <strong>${ubg}</strong></span>`;
            } catch (err) {
                status.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-amber-500"></i> <span>Coordenadas capturadas. Puedes completar la dirección manualmente.</span>';
            }
        }

        function movePin(lat, lng, zoom = null) {
            if (!mapInstance || !markerInstance) return;
            markerInstance.setLatLng([lat, lng]);
            if (zoom) mapInstance.setView([lat, lng], zoom);
            else mapInstance.panTo([lat, lng]);
            reverseGeocode(lat, lng);
        }

        async function buscarLugar() {
            const q = modalEl.querySelector('#inp_mapa_buscar').value.trim();
            if (!q) return;
            const status = modalEl.querySelector('#mapa_status_bar');
            status.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-brand-500"></i> <span>Buscando en Perú...</span>';
            try {
                const url = `https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=pe&addressdetails=1&limit=1&accept-language=es&q=${encodeURIComponent(q)}`;
                const r = await fetch(url);
                const arr = await r.json();
                if (Array.isArray(arr) && arr.length > 0) {
                    const hit = arr[0];
                    movePin(parseFloat(hit.lat), parseFloat(hit.lon), 16);
                } else {
                    status.innerHTML = '<i class="fa-solid fa-circle-exclamation text-amber-500"></i> <span>No se encontraron resultados. Intenta con calle y distrito.</span>';
                }
            } catch (e) {
                status.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-rose-500"></i> <span>Error al buscar la dirección.</span>';
            }
        }

        modalEl.querySelector('#btn_mapa_buscar').addEventListener('click', buscarLugar);
        modalEl.querySelector('#inp_mapa_buscar').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); buscarLugar(); }
        });

        modalEl.querySelector('#btn_mapa_gps').addEventListener('click', () => {
            if (!navigator.geolocation) return;
            navigator.geolocation.getCurrentPosition(
                (pos) => movePin(pos.coords.latitude, pos.coords.longitude, 16),
                () => {
                    modalEl.querySelector('#mapa_status_bar').innerHTML = '<i class="fa-solid fa-triangle-exclamation text-amber-500"></i> <span>No se pudo acceder al GPS del navegador.</span>';
                }
            );
        });

        modalEl._movePin = movePin;
        modalEl._reverseGeocode = reverseGeocode;
        return modalEl;
    }

    window.abrirModalMapaUbigeo = async function (opts = {}) {
        const m = ensureModal();
        activeCallback = opts.onConfirm || null;
        m.querySelector('#mapa_modal_titulo').textContent = opts.titulo || 'Seleccionar Ubicación en el Mapa';
        m.querySelector('#mapa_res_dir').value = opts.direccion || '';
        m.querySelector('#mapa_res_ubigeo').value = opts.ubigeo || '';
        m.classList.remove('hidden');

        try {
            const L = await loadLeaflet();
            const startLat = (opts.lat !== null && opts.lat !== undefined && !isNaN(parseFloat(opts.lat))) ? parseFloat(opts.lat) : -12.046374;
            const startLng = (opts.lng !== null && opts.lng !== undefined && !isNaN(parseFloat(opts.lng))) ? parseFloat(opts.lng) : -77.042793;

            if (!mapInstance) {
                mapInstance = L.map('mapa_ubigeo_canvas').setView([startLat, startLng], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap'
                }).addTo(mapInstance);

                markerInstance = L.marker([startLat, startLng], { draggable: true }).addTo(mapInstance);
                markerInstance.on('dragend', function () {
                    const p = markerInstance.getLatLng();
                    m._reverseGeocode(p.lat, p.lng);
                });
                mapInstance.on('click', function (e) {
                    m._movePin(e.latlng.lat, e.latlng.lng);
                });
            } else {
                setTimeout(() => {
                    mapInstance.invalidateSize();
                    markerInstance.setLatLng([startLat, startLng]);
                    mapInstance.setView([startLat, startLng], 15);
                }, 120);
            }
            m.querySelector('#mapa_res_coords').value = `${startLat.toFixed(6)}, ${startLng.toFixed(6)}`;
        } catch (err) {
            if (window.showToast) window.showToast(err.message, 'error', 'Mapa');
        }
    };
})(window);
