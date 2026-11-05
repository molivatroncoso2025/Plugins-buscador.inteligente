/* Rotar Buscador Inteligente - filtro horizontal por categoría */
(function () {
    'use strict';

    var CFG = window.RBI_CFG || {};

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

    function post(url, params) {
        var fd = new FormData();
        Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (txt) {
                var json = null;
                try { json = JSON.parse(txt); } catch (e) {
                    var m = txt.match(/\{[\s\S]*"success"[\s\S]*\}\s*$/);
                    if (m) { try { json = JSON.parse(m[0]); } catch (e2) { } }
                }
                if (!json || !json.success) throw new Error((json && json.data && json.data.msg) || 'No pudimos cargar los productos.');
                return json.data;
            });
    }

    function cardHtml(p) {
        return '<article class="rbi-card" data-id="' + esc(p.id) + '">' +
            (p.etiquetas || '') + // HTML generado y escapado por el plugin de etiquetas
            '<a class="rbi-card__img" href="' + esc(p.url) + '"><img loading="lazy" src="' + esc(p.img) + '" alt="' + esc(p.titulo) + '"></a>' +
            '<a class="rbi-card__title" href="' + esc(p.url) + '" title="' + esc(p.titulo) + '">' + esc(p.titulo) + '</a>' +
            '<div class="rbi-card__foot">' +
            (p.antes ? '<span class="rbi-card__old">Antes: <del>' + esc(p.antes) + '</del></span>' : '') +
            (p.precio ? '<span class="rbi-card__price"><strong>' + esc(p.precio) + '</strong><small>IVA Incl.</small></span>' : '') +
            (!p.stock && !p.etiquetas ? '<span class="rbi-card__nostock">Sin stock</span>' : '') +
            '</div>' +
            '<a class="rbi-card__btn" href="' + esc(p.url) + '">Ver producto</a>' +
            '</article>';
    }

    function init(root) {
        var ajax = root.getAttribute('data-ajax') || CFG.ajax || '/wp-admin/admin-ajax.php';
        var catId = root.getAttribute('data-cat');
        var auto = root.getAttribute('data-auto') === '1';
        var perPage = parseInt(root.getAttribute('data-per-page'), 10) || 12;

        var $ = function (s) { return root.querySelector(s); };
        var $$ = function (s) { return Array.prototype.slice.call(root.querySelectorAll(s)); };

        var input = $('[data-cf="q"]'), selects = $$('select[data-cf]');
        var results = $('.rbi-cat__results'), grid = $('.rbi-grid'), more = $('.rbi-more');
        var total = $('.rbi-cat__total'), status = $('.rbi-cat__status'), sortSel = $('.rbi-cat__sort');
        var clearBtn = $('.rbi-cat__clear'), toggle = $('.rbi-cat__toggle');

        var state = { reqId: 0, order: [], shown: 0, active: false };

        /* Listado nativo de la categoría: se oculta solo mientras hay filtros activos. */
        var nativeHost = null;
        if (auto && root.parentElement) {
            nativeHost = root.parentElement;
        } else {
            var w = document.querySelector('.elementor-widget-wc-archive-products, .elementor-widget-woocommerce-products');
            var ul = document.querySelector('ul.products');
            nativeHost = w || (ul ? ul.parentElement : null);
            if (nativeHost && nativeHost.contains(root)) nativeHost = null;
        }
        function setNativeHidden(h) {
            if (!nativeHost) return;
            if (auto) {
                // Solo lo que viene después del filtro dentro del listado (orden, productos, paginación).
                for (var el = root.nextElementSibling; el; el = el.nextElementSibling) {
                    if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE') continue;
                    el.classList.toggle('rbi-cat-hide', h);
                }
            } else {
                nativeHost.classList.toggle('rbi-cat-hide', h);
            }
        }

        function getFilters() {
            var f = {};
            selects.forEach(function (s) { if (s.value) f[s.getAttribute('data-cf')] = s.value; });
            var q = input ? input.value.trim() : '';
            if (q.length >= 2) f.q = q;
            return f;
        }

        function fillSelect(sel, list) {
            var cur = sel.value, empty = sel.getAttribute('data-empty') || 'Todos';
            var html = '<option value="">' + esc(empty) + '</option>';
            (list || []).forEach(function (o) {
                var dis = (!o.n && String(o.v) !== cur) ? ' disabled' : '';
                html += '<option value="' + esc(o.v) + '"' + dis + '>' + esc(cap(o.l)) + ' (' + o.n + ')</option>';
            });
            sel.innerHTML = html;
            sel.value = cur;
            if (sel.value !== cur) sel.value = '';
            // Si el filtro no aplica a esta categoría (sin opciones), se oculta.
            var field = sel.closest ? sel.closest('.rbi-cat__field') : sel.parentNode;
            if (field) field.hidden = !(list && list.length) && !cur;
        }

        function renderFacets(fc) {
            if (!fc) return;
            selects.forEach(function (s) {
                var k = s.getAttribute('data-cf');
                var list = fc[k] || [];
                if (k === 'tipo' || k === 'montaje') list = list.filter(function (o) { return o.n > 0 || String(o.v) === s.value; });
                fillSelect(s, list);
            });
        }

        function updateMore() {
            var left = state.order.length - state.shown;
            more.hidden = left <= 0;
            if (left > 0) more.textContent = 'Ver más productos (' + left + ')';
        }

        function run() {
            var f = getFilters();
            var active = Object.keys(f).length > 0;
            $$('.rbi-cat__field').forEach(function (fl) {
                var el = fl.querySelector('[data-cf]');
                fl.classList.toggle('is-set', !!(el && el.value.trim()));
            });
            var myId = ++state.reqId;
            state.active = active;
            clearBtn.disabled = !active && !(input && input.value);
            root.classList.toggle('rbi-cat--active', active);
            root.classList.add('rbi-cat--busy');

            var params = {
                action: 'rbi_search', base_cat: catId, q: f.q || '', ia: '0', page_only: '1',
                history: '[]', per_page: active ? perPage : 1, sort: sortSel ? sortSel.value : ''
            };
            Object.keys(f).forEach(function (k) { if (k !== 'q') params[k] = f[k]; });

            post(ajax, params).then(function (data) {
                if (myId !== state.reqId) return;
                renderFacets(data.facets);
                if (!active) {
                    results.hidden = true;
                    setNativeHidden(false);
                    status.textContent = '';
                    return;
                }
                state.order = data.order || [];
                state.shown = 0;
                grid.innerHTML = '';
                var n = data.total || 0;
                total.textContent = n ? (n + ' producto' + (n > 1 ? 's' : '') + ' encontrado' + (n > 1 ? 's' : '')) : 'Sin resultados';
                status.textContent = n ? ('· ' + n + ' resultado' + (n > 1 ? 's' : '')) : '· sin resultados';
                if (!n) grid.innerHTML = '<div class="rbi-empty">No hay productos de esta categoría con esa combinación. Prueba quitando algún filtro.</div>';
                grid.insertAdjacentHTML('beforeend', (data.cards || []).map(cardHtml).join(''));
                state.shown = (data.cards || []).length;
                updateMore();
                results.hidden = false;
                setNativeHidden(true);
            }).catch(function (err) {
                if (myId !== state.reqId) return;
                if (window.console) console.warn('[Filtro categoría]', err.message);
                if (active) {
                    results.hidden = false;
                    setNativeHidden(true);
                    more.hidden = true;
                    grid.innerHTML = '<div class="rbi-empty">No pudimos cargar los productos en este momento. Intenta nuevamente.</div>';
                }
            }).then(function () {
                if (myId === state.reqId) root.classList.remove('rbi-cat--busy');
            });
        }

        selects.forEach(function (s) { s.addEventListener('change', run); });
        if (sortSel) sortSel.addEventListener('change', run);

        var t = null, lastQ = '';
        if (input) {
            input.addEventListener('input', function () {
                clearTimeout(t);
                clearBtn.disabled = !state.active && !input.value;
                t = setTimeout(function () {
                    var q = input.value.trim();
                    var eff = q.length >= 2 ? q : '';
                    if (eff === lastQ) return;
                    lastQ = eff;
                    run();
                }, 450);
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); clearTimeout(t); lastQ = input.value.trim().length >= 2 ? input.value.trim() : ''; run(); }
            });
        }

        clearBtn.addEventListener('click', function () {
            selects.forEach(function (s) { s.value = ''; });
            if (input) input.value = '';
            lastQ = '';
            if (sortSel) sortSel.value = 'relevancia';
            run();
        });

        if (toggle) {
            toggle.addEventListener('click', function () {
                var open = !root.classList.contains('rbi-cat--open');
                root.classList.toggle('rbi-cat--open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        more.addEventListener('click', function () {
            var ids = state.order.slice(state.shown, state.shown + perPage);
            if (!ids.length) return;
            more.disabled = true;
            post(ajax, { action: 'rbi_cards', ids: ids.join(','), motivos: '{}' })
                .then(function (d) {
                    grid.insertAdjacentHTML('beforeend', (d.cards || []).map(cardHtml).join(''));
                    state.shown += ids.length;
                    updateMore();
                })
                .catch(function () { })
                .then(function () { more.disabled = false; });
        });

        // Carga inicial: solo opciones (con conteos) de esta categoría; el listado nativo sigue visible.
        run();
    }

    function boot() {
        var nodes = document.querySelectorAll('[data-rbi-cat]');
        for (var i = 0; i < nodes.length; i++) {
            if (!nodes[i].__rbiCat) { nodes[i].__rbiCat = 1; init(nodes[i]); }
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
