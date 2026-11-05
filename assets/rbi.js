/* Rotar Buscador Inteligente - front */
(function () {
    'use strict';

    var CFG = window.RBI_CFG || {};
    var TIMEOUT_MS = 70000;

    // Si falta la configuración (HTML copiado en vez de shortcode, optimizadores de JS...),
    // se deduce la URL de admin-ajax desde la ubicación de este mismo archivo.
    if (!CFG.ajax) {
        var me = document.currentScript || document.querySelector('script[src*="rotar-buscador-inteligente/assets/rbi"]');
        var src = me && me.src ? me.src : '';
        var cut = src.indexOf('/wp-content/');
        CFG.ajax = cut > -1 ? src.slice(0, cut) + '/wp-admin/admin-ajax.php' : '/wp-admin/admin-ajax.php';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }
    /* Primera letra en mayúscula y el resto en minúscula (ej: "NEUMÁTICA CAUCHO" -> "Neumática caucho"). */
    function cap(s) {
        s = String(s == null ? '' : s).trim().toLocaleLowerCase('es');
        return s.charAt(0).toLocaleUpperCase('es') + s.slice(1);
    }
    function nl2p(s) {
        var safe = esc(s).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        return safe.split(/\n{2,}/).map(function (p) {
            return '<p>' + p.replace(/\n/g, '<br>') + '</p>';
        }).join('');
    }

    /** POST robusto: timeout, lectura como texto y mensajes de error claros. */
    function post(params) {
        var fd = new FormData();
        Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
        var ctrl = window.AbortController ? new AbortController() : null;
        var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, TIMEOUT_MS) : null;

        return fetch(CFG.ajax, { method: 'POST', body: fd, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
            .then(function (r) {
                return r.text().then(function (txt) {
                    var json = null;
                    try { json = JSON.parse(txt); } catch (e) {
                        var m = txt.match(/\{[\s\S]*"success"[\s\S]*\}\s*$/);
                        if (m) { try { json = JSON.parse(m[0]); } catch (e2) { } }
                    }
                    if (!json) {
                        if (window.console) console.error('[Buscador] Respuesta no válida (HTTP ' + r.status + '):', txt.slice(0, 800));
                        throw new Error(r.status >= 500 ? 'El servidor tuvo un error (' + r.status + '). Intenta nuevamente en unos segundos.'
                            : (txt.trim() === '0' ? 'El buscador no está activo en el servidor.' : 'Respuesta inesperada del servidor (' + r.status + ').'));
                    }
                    if (!json.success) throw new Error((json.data && json.data.msg) || 'No pudimos procesar tu búsqueda.');
                    return json.data;
                });
            }, function (err) {
                if (err && err.name === 'AbortError') throw new Error('La búsqueda tardó demasiado. Intenta nuevamente.');
                throw new Error('No hay conexión con el servidor. Revisa tu internet e intenta nuevamente.');
            })
            .finally(function () { if (timer) clearTimeout(timer); });
    }

    function init(root) {
        // La configuración viaja en el propio HTML (inmune a optimizadores de JS/caché).
        if (root.getAttribute('data-ajax')) CFG.ajax = root.getAttribute('data-ajax');
        if (root.hasAttribute('data-wa')) CFG.whatsapp = root.getAttribute('data-wa');

        var $ = function (sel) { return root.querySelector(sel); };
        var $$ = function (sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); };

        var estilo = root.getAttribute('data-estilo');
        var iaEnabled = root.getAttribute('data-ia') === '1';
        var form = $('.rbi-bar'), input = $('.rbi-bar__input'), btn = $('.rbi-bar__btn');
        // Limpieza para HTML antiguo pegado en la página: sin interruptor, sin checks y botón fijo.
        $$('.rbi-ai-toggle, .rbi-checks').forEach(function (el) { el.remove(); });
        $$('.rbi-clear').forEach(function (el) { el.classList.remove('rbi-link'); el.textContent = '✕ Limpiar filtros'; });
        // Botón "Limpiar" al final del panel de filtros y ajustes para HTML antiguo pegado.
        (function () {
            var fb = root.querySelector('.rbi-filters'), cl = root.querySelector('.rbi-clear');
            if (!fb) return;
            var cargaSel = fb.querySelector('[data-f="carga"]');
            if (cargaSel && cargaSel.parentNode) {
                var sp = cargaSel.parentNode.querySelector('span');
                if (sp) sp.textContent = 'Capacidad de Carga';
            }
            var etqSel = fb.querySelector('[data-f="etiqueta"]');
            if (etqSel) {
                var etqField = etqSel.closest ? etqSel.closest('.rbi-field') : etqSel.parentNode;
                if (etqField) etqField.remove(); else etqSel.remove();
            }
            if (!fb.querySelector('[data-f="suelo"]')) {
                var tipoSel = fb.querySelector('[data-f="tipo"]');
                var lblSuelo = document.createElement('label');
                lblSuelo.className = 'rbi-field';
                lblSuelo.innerHTML = '<span>Superficies de Trabajo</span><select data-f="suelo"><option value="">Todas</option></select>';
                if (tipoSel && tipoSel.parentNode && tipoSel.parentNode.nextSibling) {
                    fb.insertBefore(lblSuelo, tipoSel.parentNode.nextSibling);
                } else {
                    fb.appendChild(lblSuelo);
                }
            } else {
                var sueloSel = fb.querySelector('[data-f="suelo"]');
                if (sueloSel && sueloSel.parentNode) {
                    var spSuelo = sueloSel.parentNode.querySelector('span');
                    if (spSuelo) spSuelo.textContent = 'Superficies de Trabajo';
                }
            }
            if (!cl || cl.parentNode.classList.contains('rbi-clear-wrap')) return;
            var wrap = document.createElement('div');
            wrap.className = 'rbi-clear-wrap';
            wrap.appendChild(cl);
            var apply = fb.querySelector('.rbi-filters__apply');
            fb.insertBefore(wrap, apply || null);
        })();
        if (btn) btn.textContent = 'BUSCAR';
        var body = $('.rbi-body'), thread = $('.rbi-thread'), grid = $('.rbi-grid');
        var count = $('.rbi-count'), more = $('.rbi-more'), sortSel = $('.rbi-sort');
        var filtersBox = $('.rbi-filters'), chips = $('.rbi-chips');

        var baseCantidad = parseInt(root.getAttribute('data-cantidad'), 10) || 5;
        // Responsive (≤1280px, donde la grilla es de 4 o 2 columnas): siempre 4 productos.
        function calcPerPage() {
            return (window.matchMedia && window.matchMedia('(max-width: 1280px)').matches) ? 4 : baseCantidad;
        }

        var state = {
            q: '', ia: false, history: [], histForQ: [], order: [], shown: 0,
            perPage: calcPerPage(),
            motivos: {}, busy: false, reqId: 0, started: false
        };

        function aiOn() { return iaEnabled; }

        function getFilters() {
            var f = {};
            $$('[data-f]').forEach(function (el) {
                var k = el.getAttribute('data-f');
                if (el.type === 'checkbox') { if (el.checked) f[k] = '1'; }
                else if (el.value) f[k] = el.value;
            });
            return f;
        }

        function hasActiveFilters() {
            return Object.keys(getFilters()).length > 0;
        }

        function syncActiveState() {
            var active = Boolean(state.q) || hasActiveFilters();
            root.classList.toggle('rbi--active', active);
            if (barClear) barClear.hidden = !(input.value.trim().length > 0 || active);
        }

        function fillSelect(sel, list, emptyLabel) {
            if (!sel) return;
            var cur = sel.value;
            var html = '<option value="">' + esc(emptyLabel) + '</option>';
            (list || []).forEach(function (o) {
                var dis = (!o.n && String(o.v) !== cur) ? ' disabled' : '';
                html += '<option value="' + esc(o.v) + '"' + dis + '>' + esc(cap(o.l)) + ' (' + o.n + ')</option>';
            });
            sel.innerHTML = html;
            sel.value = cur;
            if (sel.value !== cur) sel.value = '';
        }

        function renderFacets(fc) {
            if (!filtersBox || !fc) return;
            fillSelect($('[data-f="material"]'), fc.material, 'Todos');
            fillSelect($('[data-f="diam"]'), fc.diam, 'Todos');
            fillSelect($('[data-f="carga"]'), fc.carga, 'Cualquiera');
            fillSelect($('[data-f="cat"]'), fc.cat, 'Todas');
            fillSelect($('[data-f="tipo"]'), fc.tipo, 'Todas');
            fillSelect($('[data-f="suelo"]'), fc.suelo, 'Todas');
            fillSelect($('[data-f="montaje"]'), fc.montaje, 'Todos');
            ['freno', 'inox', 'stock'].forEach(function (k) {
                var em = $('[data-c="' + k + '"]');
                if (em) em.textContent = '(' + (fc[k] || 0) + ')';
            });
        }

        function cardHtml(p) {
            var motivo = p.motivo || state.motivos[p.id] || '';
            var specs = ''; // Las tarjetas no muestran especificaciones (material, diámetro, carga).
            var badges = ''; // Sin etiquetas (freno, giratoria, espiga...) en las tarjetas.
            return '<article class="rbi-card' + (motivo ? ' rbi-card--rec' : '') + '" data-id="' + esc(p.id) + '">' +
                (motivo ? '<span class="rbi-card__flag">Recomendado</span>' : '') +
                (p.etiquetas || '') + // HTML generado y escapado por el plugin de etiquetas
                '<a class="rbi-card__img" href="' + esc(p.url) + '"><img loading="lazy" src="' + esc(p.img) + '" alt="' + esc(p.titulo) + '"></a>' +
                (badges ? '<div class="rbi-card__badges">' + badges + '</div>' : '') +
                '<a class="rbi-card__title" href="' + esc(p.url) + '" title="' + esc(p.titulo) + '">' + esc(p.titulo) + '</a>' +
                (specs ? '<ul class="rbi-card__specs">' + specs + '</ul>' : '') +
                '<div class="rbi-card__foot">' +
                (p.antes ? '<span class="rbi-card__old">Antes: <del>' + esc(p.antes) + '</del></span>' : '') +
                (p.precio ? '<span class="rbi-card__price"><strong>' + esc(p.precio) + '</strong><small>IVA Incl.</small></span>' : '') +
                (!p.stock && !p.etiquetas ? '<span class="rbi-card__nostock">Sin stock</span>' : '') +
                '</div>' +
                (motivo ? '<p class="rbi-card__why">✔ ' + esc(motivo) + '</p>' : '') +
                '<a class="rbi-card__btn" href="' + esc(p.url) + '">Ver producto</a>' +
                '</article>';
        }

        function updateMore() {
            var left = state.order.length - state.shown;
            more.hidden = left <= 0;
            if (left > 0) more.textContent = 'Ver más productos (' + left + ')';
        }

        function renderResults(data, append) {
            if (!append) {
                grid.innerHTML = '';
                state.order = data.order || [];
                state.shown = 0;
                count.textContent = data.total ? (data.total + ' producto' + (data.total > 1 ? 's' : '') + ' encontrado' + (data.total > 1 ? 's' : '')) : 'Sin resultados';
                if (!data.total) grid.innerHTML = '<div class="rbi-empty">No hay productos con esta combinación. Prueba quitando filtros o describiendo tu necesidad de otra forma.</div>';
            }
            var html = (data.cards || []).map(cardHtml).join('');
            grid.insertAdjacentHTML('beforeend', html);
            state.shown += (data.cards || []).length;
            updateMore();
        }

        function addMsg(cls, html) {
            var d = document.createElement('div');
            d.className = 'rbi-msg ' + cls;
            d.innerHTML = html;
            thread.appendChild(d);
            return d;
        }

        function waLink(q) {
            if (!CFG.whatsapp) return '';
            return '<a class="rbi-wa" target="_blank" rel="noopener" href="https://wa.me/' + esc(CFG.whatsapp) +
                '?text=' + encodeURIComponent('Hola, necesito asesoría para elegir una rueda: ' + q) + '">💬 Hablar con un asesor</a>';
        }

        function botMsg(data, q) {
            var catsHtml = '';
            if (data.categorias && data.categorias.length) {
                catsHtml = '<div class="rbi-msg__cats">' +
                    '<span class="rbi-msg__cats-label">📂 Categoría' + (data.categorias.length > 1 ? 's' : '') + ' relacionada' + (data.categorias.length > 1 ? 's' : '') + ':</span>' +
                    data.categorias.map(function (c) {
                        return '<a class="rbi-cat-pill" href="' + esc(c.url) + '">' +
                            '<strong>' + esc(c.nombre) + '</strong>' +
                            (c.count ? ' <span>(' + esc(c.count) + ' productos)</span>' : '') +
                            ' <em>Ver categoría →</em></a>';
                    }).join('') +
                    '</div>';
            }
            var html = '<div class="rbi-msg__bubble">' +
                '<span class="rbi-msg__tag">' + (data.modo === 'ia' ? '👨‍💼 Ejecutivo Técnico IA · Rotar' : '🔎 Asistente de Búsqueda Inteligente · Rotar') + '</span>' +
                nl2p(data.respuesta || '') +
                (data.recomendados ? '<p class="rbi-msg__hint">⭐ He seleccionado y destacado las <strong>' + data.recomendados + ' mejor' + (data.recomendados > 1 ? 'es opciones' : ' opción') + '</strong> para ti al inicio de los resultados.</p>' : '') +
                catsHtml +
                '</div>';
            var foot = '';
            if (data.sugerencias && data.sugerencias.length) {
                foot += '<span class="rbi-msg__refine-label">✨ Mejora tu búsqueda según tu necesidad:</span>';
                foot += data.sugerencias.map(function (s) { return '<button type="button" class="rbi-chip rbi-chip--follow" data-q="' + esc(s) + '">' + esc(s) + '</button>'; }).join('');
            }
            foot += waLink(q);
            if (foot) html += '<div class="rbi-msg__foot">' + foot + '</div>';
            if (data.aviso) html += '<div class="rbi-msg__admin">Solo administradores: ' + esc(data.aviso) + '</div>';
            return addMsg('rbi-msg--bot', html);
        }

        function setBusy(b) {
            state.busy = b;
            root.classList.toggle('rbi--busy', b);
            if (btn) btn.disabled = b;
        }

        function scrollTo(el) {
            if (!el) return;
            var r = el.getBoundingClientRect();
            if (r.top < 80 || r.top > window.innerHeight * 0.7) {
                try { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { el.scrollIntoView(); }
            }
        }

        /**
         * kind: 'ask'    → nueva consulta de texto (con IA si está activa)
         *       'refine' → cambio de filtros/orden (sin nueva llamada a la IA)
         *       'init'   → carga inicial
         */
        function run(kind) {
            var myId = ++state.reqId;
            state.started = true;
            state.perPage = calcPerPage();
            syncActiveState();
            var params = {
                action: 'rbi_search',
                q: state.q,
                ia: (kind === 'ask' ? state.ia : (kind === 'sort' && state.ia)) ? '1' : '0',
                history: JSON.stringify(kind === 'ask' ? state.history : state.histForQ),
                per_page: state.perPage,
                sort: sortSel ? sortSel.value : ''
            };
            if (kind !== 'ask') params.page_only = '1';
            var f = getFilters();
            Object.keys(f).forEach(function (k) { params[k] = f[k]; });

            var loader = null, userMsg = null;
            if (kind === 'ask' && state.ia) {
                userMsg = addMsg('rbi-msg--user', '<div class="rbi-msg__bubble">' + esc(state.q) + '</div>');
                loader = addMsg('rbi-msg--bot rbi-msg--loading', '<div class="rbi-msg__bubble"><span class="rbi-dots"><i></i><i></i><i></i></span> Consultando con tu Ejecutivo Técnico IA de Rotar…</div>');
                scrollTo(loader);
            }
            setBusy(true);

            post(params).then(function (data) {
                if (myId !== state.reqId) return;
                if (loader) loader.remove();
                if (kind === 'ask') {
                    state.motivos = (data.motivos && !Array.isArray(data.motivos)) ? data.motivos : {};
                    if (data.respuesta || (data.sugerencias && data.sugerencias.length)) {
                        if (!userMsg) addMsg('rbi-msg--user', '<div class="rbi-msg__bubble">' + esc(state.q) + '</div>');
                        botMsg(data, state.q);
                    } else if (userMsg) {
                        userMsg.remove();
                    }
                    state.histForQ = state.history.slice();
                    state.history.push({ role: 'user', text: state.q });
                    var names = (data.cards || []).slice(0, 5).map(function (c) { return c.titulo; }).join('; ');
                    state.history.push({ role: 'assistant', text: (data.respuesta || '') + (names ? ' [Productos mostrados: ' + names + ']' : '') });
                    state.history = state.history.slice(-6);
                }
                renderFacets(data.facets);
                renderResults(data, false);
                if (kind === 'ask') scrollTo(thread.lastElementChild || $('.rbi-toolbar'));
            }).catch(function (err) {
                if (myId !== state.reqId) return;
                if (loader) loader.remove();
                if (userMsg) userMsg.remove();
                if (window.console) console.warn('[Buscador]', err.message);
                // Sin avisos: se informa discretamente en la zona de resultados.
                state.lastKind = kind;
                count.textContent = '';
                more.hidden = true;
                grid.innerHTML = '<div class="rbi-empty">No pudimos cargar los productos en este momento. ' +
                    '<button type="button" class="rbi-link rbi-retry">Volver a intentar</button></div>';
            }).then(function () {
                if (myId === state.reqId) setBusy(false);
            });
        }

        function clearAll() {
            state.q = ''; state.history = []; state.histForQ = []; state.motivos = {}; state.ia = false;
            thread.innerHTML = '';
            input.value = '';
            $$('[data-f]').forEach(function (el) { if (el.type === 'checkbox') el.checked = false; else el.value = ''; });
            if (sortSel) sortSel.value = 'relevancia';
            if (chips) chips.hidden = false;
            syncActiveState();
            if (estilo === 'barra') { body.hidden = true; } else { run('init'); }
            input.focus();
        }

        function buildFilterQuery() {
            var parts = [];
            $$('select[data-f]').forEach(function (sel) {
                if (!sel.value) return;
                var opt = sel.options[sel.selectedIndex];
                var txt = opt ? opt.text.replace(/\s*\(\d+\)\s*$/, '').trim() : sel.value;
                var lblNode = sel.parentNode ? sel.parentNode.querySelector('span') : null;
                var lbl = lblNode ? lblNode.textContent.trim() : sel.getAttribute('data-f');
                parts.push(lbl + ': ' + txt);
            });
            return parts.length ? 'Recomiéndame la mejor rueda según estos filtros: ' + parts.join(', ') : '';
        }

        function ask(q) {
            q = (q || '').trim();
            if (q === '' && hasActiveFilters()) {
                q = buildFilterQuery();
            }
            if (q === '' && state.q) {
                clearAll();
                return;
            }
            if (q.length < 2 || state.busy) return;
            if (body.hidden) body.hidden = false;
            if (chips) chips.hidden = true;
            state.q = q;
            state.ia = aiOn();
            if (sortSel) sortSel.value = 'relevancia';
            input.value = q;
            syncActiveState();
            run('ask');
        }

        // Asegurar contenedor .rbi-bar__box para que en móvil el botón quede fuera del recuadro blanco de búsqueda
        var barBox = form.querySelector('.rbi-bar__box');
        if (!barBox) {
            barBox = document.createElement('div');
            barBox.className = 'rbi-bar__box';
            var iconEl = form.querySelector('.rbi-bar__icon');
            if (iconEl) barBox.appendChild(iconEl);
            if (input) barBox.appendChild(input);
            form.insertBefore(barBox, btn || form.firstChild);
        }

        // Botón "✕" dentro del recuadro de búsqueda para limpiar todo (texto, búsqueda, IA y filtros)
        var barClear = document.createElement('button');
        barClear.type = 'button';
        barClear.className = 'rbi-bar__clear';
        barClear.setAttribute('aria-label', 'Limpiar búsqueda');
        barClear.title = 'Limpiar búsqueda';
        barClear.textContent = '✕';
        barClear.hidden = true;
        barClear.addEventListener('click', function () {
            clearAll();
        });
        barBox.appendChild(barClear);

        input.addEventListener('input', function () {
            if (barClear) barClear.hidden = !(input.value.trim().length > 0 || state.q || hasActiveFilters());
        });

        form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });

        root.addEventListener('click', function (e) {
            var cardLink = e.target.closest('.rbi-card a[href]');
            if (cardLink && state.q) {
                var cardEl = cardLink.closest('.rbi-card');
                var pid = cardEl ? cardEl.getAttribute('data-id') : '';
                if (pid) {
                    try {
                        var fd = new FormData();
                        fd.append('action', 'rbi_click');
                        fd.append('q', state.q);
                        fd.append('id', pid);
                        if (navigator.sendBeacon) navigator.sendBeacon(CFG.ajax, fd);
                        else fetch(CFG.ajax, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true });
                    } catch (err) { }
                }
            }
            var chip = e.target.closest('.rbi-chip');
            if (chip && root.contains(chip)) { ask(chip.getAttribute('data-q') || chip.textContent); return; }
            if (e.target.closest('.rbi-retry')) {
                run(state.lastKind || 'refine');
                return;
            }
            if (e.target.closest('.rbi-clear')) {
                $$('[data-f]').forEach(function (el) { if (el.type === 'checkbox') el.checked = false; else el.value = ''; });
                run('refine');
                return;
            }
            if (e.target.closest('.rbi-filters__open')) { root.classList.add('rbi--filters-open'); return; }
            if (e.target.closest('.rbi-filters__close') || e.target.closest('.rbi-filters__apply')) {
                root.classList.remove('rbi--filters-open');
                scrollTo(grid);
            }
        });

        $$('[data-f]').forEach(function (el) {
            el.addEventListener('change', function () {
                if (body.hidden) body.hidden = false;
                run('refine');
            });
        });
        if (sortSel) sortSel.addEventListener('change', function () { run('sort'); });

        more.addEventListener('click', function () {
            var ids = state.order.slice(state.shown, state.shown + state.perPage);
            if (!ids.length) return;
            more.disabled = true;
            post({ action: 'rbi_cards', ids: ids.join(','), motivos: JSON.stringify(state.motivos) })
                .then(function (d) { renderResults(d, true); })
                .catch(function (err) { alert(err.message); })
                .then(function () { more.disabled = false; });
        });

        $$('.rbi-reset').forEach(function (el) { el.remove(); });

        // Al girar el celular o cambiar el tamaño de ventana, recalcular cuántos productos mostrar.
        var resizeT = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeT);
            resizeT = setTimeout(function () {
                if (state.started && !body.hidden && calcPerPage() !== state.perPage && !state.busy) run('sort');
            }, 300);
        });

        // Búsqueda desde la URL (?rbi=texto)
        var urlQ = '';
        try { urlQ = new URLSearchParams(window.location.search).get('rbi') || ''; } catch (e) { }

        if (urlQ) ask(urlQ);
        else if (estilo !== 'barra') run('init');
    }

    function styleBuscadorTabs() {
        var tabs = document.querySelectorAll('.tabs-buscador .elementor-tab-title, .elementor-widget-tabs .elementor-tab-title, .e-n-tab-title, [role="tab"]');
        Array.prototype.forEach.call(tabs, function (tab) {
            var txt = (tab.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase()
                .replace(/[áàäâ]/g, 'a').replace(/[éèëê]/g, 'e').replace(/[íìïî]/g, 'i').replace(/[óòöô]/g, 'o').replace(/[úùüû]/g, 'u');
            var isTecnico = txt.indexOf('buscador tecnico') !== -1 || (tab.closest('.tabs-buscador') && tab.getAttribute('data-tab') === '2');
            if (isTecnico) {
                tab.style.setProperty('background-color', '#003555', 'important');
                tab.style.setProperty('background', '#003555', 'important');
                tab.style.setProperty('color', '#ffffff', 'important');
                tab.style.setProperty('border-color', '#003555', 'important');
                tab.style.setProperty('border-radius', '5px 5px 0 0', 'important');
            }
        });
    }

    function boot() {
        styleBuscadorTabs();
        document.addEventListener('click', function (e) {
            if (e.target && e.target.closest && e.target.closest('.elementor-tab-title, .e-n-tab-title, [role="tab"]')) {
                setTimeout(styleBuscadorTabs, 20);
            }
        });
        window.addEventListener('resize', styleBuscadorTabs);
        var nodes = document.querySelectorAll('[data-rbi]');
        for (var i = 0; i < nodes.length; i++) {
            if (!nodes[i].__rbi) { nodes[i].__rbi = 1; init(nodes[i]); }
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
