<?php
/**
 * Motor de búsqueda: interpretación de la consulta, ranking por relevancia,
 * búsqueda y filtrado por etiquetas, aprendizaje por uso de clientes y endpoints AJAX.
 */
if (!defined('ABSPATH')) exit;

class RBI_Search {

    const CARGA_STEPS = array(50, 100, 150, 200, 300, 500, 750, 1000, 1500, 2000);
    const WHEEL_STEMS = array('rued', 'rodaj', 'garruch', 'rodachin', 'castor');

    public static function init() {
        add_action('wp_ajax_rbi_search', array(__CLASS__, 'ajax_search'));
        add_action('wp_ajax_nopriv_rbi_search', array(__CLASS__, 'ajax_search'));
        add_action('wp_ajax_rbi_cards', array(__CLASS__, 'ajax_cards'));
        add_action('wp_ajax_nopriv_rbi_cards', array(__CLASS__, 'ajax_cards'));
    }

    /* =========================
     * Filtros
     * ========================= */
    public static function read_filters($src) {
        $g = function ($k) use ($src) {
            return isset($src[$k]) ? sanitize_text_field(wp_unslash($src[$k])) : '';
        };
        $f = array(
            'material' => sanitize_title($g('material')),
            'diam'     => (int) $g('diam'),
            'carga'    => (int) $g('carga'),
            'cat'      => (int) $g('cat'),
            'etiqueta' => sanitize_title($g('etiqueta')),
            'suelo'    => RBI_Catalog::suelo_key($g('suelo')),
            'tipo'     => in_array($g('tipo'), array('giratoria', 'fija', 'freno'), true) ? $g('tipo') : '',
            'montaje'  => in_array($g('montaje'), array('placa', 'espiga'), true) ? $g('montaje') : '',
            'freno'    => $g('freno') === '1' ? 1 : 0,
            'inox'     => $g('inox') === '1' ? 1 : 0,
            'stock'    => $g('stock') === '1' ? 1 : 0,
        );
        return $f;
    }

    public static function diam_match($it, $want) {
        foreach ($it['dm'] as $d) {
            if (abs($d - $want) <= max(2, $want * 0.03)) return true;
        }
        return false;
    }

    public static function passes($it, $f, $skip = '') {
        if ($skip !== 'material' && $f['material'] !== '' && !in_array($f['material'], $it['mk'], true)) return false;
        if ($skip !== 'diam' && $f['diam'] > 0 && !self::diam_match($it, $f['diam'])) return false;
        if ($skip !== 'carga' && $f['carga'] > 0 && (empty($it['kg']) || max($it['kg']) < $f['carga'])) return false;
        if ($skip !== 'cat' && $f['cat'] > 0 && !in_array($f['cat'], $it['cid'], true)) return false;
        if ($skip !== 'etiqueta' && $f['etiqueta'] !== '' && (empty($it['lb']) || !in_array($f['etiqueta'], $it['lb'], true))) return false;
        if ($skip !== 'suelo' && $f['suelo'] !== '' && (empty($it['su']) || !in_array($f['suelo'], $it['su'], true))) return false;
        if ($skip !== 'tipo' && $f['tipo'] !== '' && !in_array($f['tipo'], $it['f'], true)) return false;
        if ($skip !== 'montaje' && $f['montaje'] !== '' && !in_array($f['montaje'], $it['f'], true)) return false;
        if ($skip !== 'freno' && $f['freno'] && !in_array('freno', $it['f'], true)) return false;
        if ($skip !== 'inox' && $f['inox'] && !in_array('inox', $it['f'], true)) return false;
        if ($skip !== 'stock' && $f['stock'] && empty($it['stk'])) return false;
        return true;
    }

    public static function facets($catalog, $pool, $f) {
        $items = $catalog['items'];
        $out = array('material' => array(), 'diam' => array(), 'carga' => array(), 'cat' => array(), 'etiqueta' => array(), 'suelo' => array(), 'tipo' => array(), 'montaje' => array(), 'freno' => 0, 'inox' => 0, 'stock' => 0);

        $mat = array(); $dia = array(); $car = array_fill_keys(self::CARGA_STEPS, 0); $cat = array(); $lbl = array(); $sue = array();
        $tipo = array('giratoria' => 0, 'fija' => 0, 'freno' => 0); $mon = array('placa' => 0, 'espiga' => 0);

        foreach ($pool as $id) {
            $it = $items[$id];
            if (self::passes($it, $f, 'material')) foreach ($it['mk'] as $k) $mat[$k] = isset($mat[$k]) ? $mat[$k] + 1 : 1;
            if (self::passes($it, $f, 'diam')) foreach ($it['dm'] as $d) $dia[(int) $d] = isset($dia[(int) $d]) ? $dia[(int) $d] + 1 : 1;
            if (self::passes($it, $f, 'carga') && !empty($it['kg'])) {
                $mx = max($it['kg']);
                foreach (self::CARGA_STEPS as $st) if ($mx >= $st) $car[$st]++;
            }
            if (self::passes($it, $f, 'cat')) foreach ($it['cid'] as $c) $cat[$c] = isset($cat[$c]) ? $cat[$c] + 1 : 1;
            if (self::passes($it, $f, 'etiqueta') && !empty($it['lb'])) foreach ($it['lb'] as $lk) $lbl[$lk] = isset($lbl[$lk]) ? $lbl[$lk] + 1 : 1;
            if (self::passes($it, $f, 'suelo') && !empty($it['su'])) foreach ($it['su'] as $sk) $sue[$sk] = isset($sue[$sk]) ? $sue[$sk] + 1 : 1;
            if (self::passes($it, $f, 'tipo')) foreach (array('giratoria', 'fija', 'freno') as $t) if (in_array($t, $it['f'], true)) $tipo[$t]++;
            if (self::passes($it, $f, 'montaje')) foreach (array('placa', 'espiga') as $t) if (in_array($t, $it['f'], true)) $mon[$t]++;
            if (self::passes($it, $f, 'freno') && in_array('freno', $it['f'], true)) $out['freno']++;
            if (self::passes($it, $f, 'inox') && in_array('inox', $it['f'], true)) $out['inox']++;
            if (self::passes($it, $f, 'stock') && !empty($it['stk'])) $out['stock']++;
        }

        foreach ($catalog['materials'] as $k => $label) {
            if (!empty($mat[$k]) || $f['material'] === $k) $out['material'][] = array('v' => $k, 'l' => $label, 'n' => isset($mat[$k]) ? $mat[$k] : 0);
        }
        ksort($dia, SORT_NUMERIC);
        foreach ($dia as $d => $n) $out['diam'][] = array('v' => $d, 'l' => 'Ø ' . $d . ' mm', 'n' => $n);
        if ($f['diam'] && !isset($dia[$f['diam']])) array_unshift($out['diam'], array('v' => $f['diam'], 'l' => 'Ø ' . $f['diam'] . ' mm', 'n' => 0));
        foreach ($car as $st => $n) {
            if ($n > 0 || $f['carga'] === $st) $out['carga'][] = array('v' => $st, 'l' => 'Desde ' . number_format_i18n($st) . ' kg', 'n' => $n);
        }
        foreach ($catalog['cats'] as $cid => $label) {
            if (!empty($cat[$cid]) || $f['cat'] === (int) $cid) $out['cat'][] = array('v' => (int) $cid, 'l' => $label, 'n' => isset($cat[$cid]) ? $cat[$cid] : 0);
        }
        $catalog_labels = isset($catalog['labels']) && is_array($catalog['labels']) ? $catalog['labels'] : array();
        foreach ($catalog_labels as $lk => $label) {
            if (!empty($lbl[$lk]) || $f['etiqueta'] === $lk) $out['etiqueta'][] = array('v' => $lk, 'l' => $label, 'n' => isset($lbl[$lk]) ? $lbl[$lk] : 0);
        }
        $catalog_suelos = isset($catalog['suelos']) && is_array($catalog['suelos']) ? $catalog['suelos'] : array();
        foreach ($catalog_suelos as $sk => $label) {
            if (!empty($sue[$sk]) || $f['suelo'] === $sk) $out['suelo'][] = array('v' => $sk, 'l' => $label, 'n' => isset($sue[$sk]) ? $sue[$sk] : 0);
        }
        $out['tipo'] = array(
            array('v' => 'giratoria', 'l' => 'Giratoria', 'n' => $tipo['giratoria']),
            array('v' => 'fija', 'l' => 'Fija', 'n' => $tipo['fija']),
            array('v' => 'freno', 'l' => 'Con freno', 'n' => $tipo['freno']),
        );
        $out['montaje'] = array(array('v' => 'placa', 'l' => 'Placa', 'n' => $mon['placa']), array('v' => 'espiga', 'l' => 'Espiga / vástago', 'n' => $mon['espiga']));

        // Todas las opciones de los listados: primera letra en mayúscula y el resto en minúscula.
        foreach (array('material', 'cat', 'etiqueta', 'suelo', 'tipo', 'montaje') as $fk) {
            if (empty($out[$fk]) || !is_array($out[$fk])) continue;
            foreach ($out[$fk] as $i => $o) $out[$fk][$i]['l'] = self::cap_label($o['l']);
        }
        return $out;
    }

    /** "NEUMÁTICA CAUCHO INFLADA" -> "Neumática caucho inflada" (compatible con tildes/ñ). */
    public static function cap_label($s)
    {
        $s = trim(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'));
        if ($s === '') return $s;
        $s = mb_strtolower($s, 'UTF-8');
        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
    }

    /* =========================
     * Interpretación de la consulta
     * ========================= */
    public static function local_intent($q, $history) {
        $text = $q;
        if (count(RBI_Text::tokens($q)) <= 3 && !empty($history)) {
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if ($history[$i]['role'] === 'user') { $text = $history[$i]['text'] . ' ' . $q; break; }
            }
        }
        $m = RBI_Text::measures($text);
        $base = RBI_Text::tokens($text);
        $learned_kw = class_exists('RBI_Learn') ? RBI_Learn::learned_terms($text) : array();
        $nt = RBI_Text::norm($text);
        $q_norm_only = RBI_Text::norm($q);
        $asks_wheel = (bool) array_intersect(self::WHEEL_STEMS, $base) || (bool) preg_match('/\b(ruedas?|rodajas?|garruchas?|horquillas?|polin|polines|llantas?)\b/', $nt);
        $asks_carro = !$asks_wheel && (bool) preg_match('/\b(carros?|carritos?|yeguas?|diablitos?|transpaletas?|zorras?|apiladores?)\b/', $q_norm_only);

        $intent = array(
            'texto'                     => $text,
            'query_original'            => $q,
            'keywords'                  => $learned_kw,
            'materiales'                => array(),
            'materiales_explicitos'     => array(),
            'suelos'                    => array(),
            'caracteristicas'           => array(),
            'caracteristicas_explicitas'=> array(),
            'diametro_mm'               => !empty($m['diam_mm']) ? (float) end($m['diam_mm']) : null,
            'carga_kg'                  => null,
            'uso'                       => '',
            'nota_carga'                => '',
            'busca_rueda'               => $asks_wheel,
            'busca_carro'               => $asks_carro,
        );

        // 1. Detectar materiales explícitamente mencionados por el cliente
        $explicit_mat_rules = array(
            '/\b(poliuretano|uretano|\bpu\b)/'              => array('poliuretano'),
            '/\b(poliamida|tecnopolimero)/'                 => array('poliamida', 'nylon'),
            '/\b(nylon|nailon)/'                            => array('nylon', 'poliamida'),
            '/\b(caucho)/'                                  => array('caucho', 'goma'),
            '/\b(goma)/'                                    => array('goma', 'caucho'),
            '/\b(termoplastica|termoplastico|\btpr\b)/'     => array('termoplastica'),
            '/\b(fierro|hierro|fundido|fundicion)/'         => array('fierro', 'hierro'),
            '/\b(acero forjado|forjado)/'                   => array('acero forjado', 'acero'),
            '/\b(fenolica|resina fenolica|baquelita)/'      => array('fenolica', 'resina'),
            '/\b(polipropileno|\bpp\b)/'                    => array('polipropileno'),
        );
        foreach ($explicit_mat_rules as $re => $mats_list) {
            if (preg_match($re, $nt)) {
                $intent['materiales_explicitos'] = array_merge($intent['materiales_explicitos'], $mats_list);
                $intent['materiales'] = array_merge($intent['materiales'], $mats_list);
            }
        }

        // 2. Detectar características estructurales explícitamente pedidas por el cliente
        if (preg_match('/\b(sin freno|sin frenos)\b/', $nt)) {
            $intent['caracteristicas_explicitas'][] = 'sin_freno';
        } elseif (preg_match('/\b(freno|frenos|bloqueo|traba|con seguro)\b/', $nt)) {
            $intent['caracteristicas_explicitas'][] = 'freno';
            $intent['caracteristicas'][] = 'freno';
        }
        if (preg_match('/\b(giratoria|giratorias|giratorio|loca|locas|pivotante|rotativa|360)\b/', $nt)) {
            $intent['caracteristicas_explicitas'][] = 'giratoria';
            $intent['caracteristicas'][] = 'giratoria';
        }
        if (preg_match('/\b(fija|fijas|fijo|rigida|recta)\b/', $nt)) {
            $intent['caracteristicas_explicitas'][] = 'fija';
            $intent['caracteristicas'][] = 'fija';
        }
        if (preg_match('/\b(placa|platina|base apernada|4 pernos)\b/', $nt)) {
            $intent['caracteristicas_explicitas'][] = 'placa';
            $intent['caracteristicas'][] = 'placa';
        }
        if (preg_match('/\b(espiga|vastago|perno central|con perno|montaje perno|rosca|hilo)\b/', $nt)) {
            $intent['caracteristicas_explicitas'][] = 'espiga';
            $intent['caracteristicas'][] = 'espiga';
        }
        if (preg_match('/\b(inoxidable|inox|aisi 304|aisi 316|aisi)\b/', $nt)) {
            $intent['caracteristicas_explicitas'][] = 'inox';
            $intent['caracteristicas'][] = 'inox';
        }
        if (preg_match('/\b(neumatica|neumatico|inflable|con camara)\b/', $nt)) {
            $intent['caracteristicas_explicitas'][] = 'neumatica';
            $intent['caracteristicas'][] = 'neumatica';
        }
        if (preg_match('/\b(impinchable|antipinchazo|maciza|macizo)\b/', $nt)) {
            $intent['caracteristicas_explicitas'][] = 'impinchable';
            $intent['caracteristicas'][] = 'impinchable';
        }
        if (preg_match('/\b(horno|hornos|alta temperatura|calor extremo|panaderia|autoclave)\b/', $nt)) {
            $intent['caracteristicas_explicitas'][] = 'alta_temp';
            $intent['caracteristicas'][] = 'alta temperatura';
        }

        // 3. Reglas de inferencia técnica por aplicación/contexto
        $rules = array(
            '/\b(no (raye|ray|marque|marca|dane|dana|manche)|silencios|sin ruido|piso delicado|porcelanato|ceramica|ceramico|parquet|parque|madera|flotante|baldosa)/' => array('m' => array('poliuretano', 'goma', 'termoplastica', 'caucho'), 'c' => array('silenciosa', 'no mancha')),
            '/\b(horno|temperatura|calor|caliente|panaderia|autoclave|fundicion)/' => array('m' => array('fenolica', 'fibra vidrio', 'fierro', 'hierro'), 'c' => array('horno', 'alta temperatura'), 's' => array('alta-temperatura')),
            '/\b(exterior|tierra|pasto|cesped|ripio|irregular|calle|jardin|grava|barro|arena|obra|construccion)/' => array('m' => array('neumatica', 'caucho', 'goma'), 'c' => array('neumatica', 'exterior')),
            '/\b(humed|agua|aliment|lavado|quimic|frigorif|pesquer|hospital|clinica|farmaceutic|sanitari|inoxidable|inox)/' => array('m' => array('inoxidable', 'nylon', 'poliamida', 'poliuretano'), 'c' => array('inox', 'acero inoxidable'), 's' => array('humedo')),
            '/\b(pesad|industrial|tonelad|mineri|maquinaria|alto trafico|alta carga)/' => array('m' => array('poliuretano', 'poliamida', 'nylon', 'fierro', 'acero'), 'c' => array('carga pesada')),
            '/\b(porton|portones|puerta corredera|reja|canaleta|riel)\b/' => array('m' => array(), 'c' => array('porton', 'canal', 'riel', 'corredera')),
            '/\b(transpaleta|zorra|apilador|grua horquilla|paletera)\b/' => array('m' => array(), 'c' => array('transpaleta')),
            '/\b(andamio|andamios|torre movil)\b/' => array('m' => array(), 'c' => array('andamio', 'freno')),
            '/\b(carretilla|carretillas)\b/' => array('m' => array(), 'c' => array('carretilla')),
            '/\b(ofert|descuent|rebaj|liquidacion|promo|economica|barata)/' => array('m' => array(), 'c' => array('en oferta', 'oferta', 'descuento')),
            '/\b(entrega inmediata|despacho inmediato|envio inmediato|urgente|stock inmediato)/' => array('m' => array(), 'c' => array('entrega inmediata')),
            '/\b(nuevo|nueva|nuevos|nuevas|novedad|lanzamiento)\b/' => array('m' => array(), 'c' => array('nuevo')),
            '/\b(destacad[oa]s?|recomendad[oa]s?|mas vendid[oa]s?|estrella)\b/' => array('m' => array(), 'c' => array('destacado')),
            '/\b(sin stock|agotad[oa]s?|sin existencia)\b/' => array('m' => array(), 'c' => array('sin stock')),
        );
        $suelo_rules = array(
            '/\b(ceramica|ceramico|porcelanato)\b/'    => 'ceramico',
            '/\b(concreto liso|cemento liso)\b/'       => 'concreto-liso',
            '/\b(concreto rugoso|cemento rugoso)\b/'   => 'concreto-rugoso',
            '/\b(asfalto|pavimento)\b/'                => 'asfalto',
            '/\b(arena|playa)\b/'                      => 'arena',
            '/\b(epoxi|epoxico)\b/'                    => 'epoxi',
            '/\b(madera|piso flotante|flotante)\b/'    => 'madera',
            '/\b(metalico|piso metalico)\b/'           => 'metalico',
            '/\b(pasto|cesped|jardin)\b/'              => 'pasto',
            '/\b(tierra|barro)\b/'                     => 'tierra',
            '/\b(grava|ripio|gravilla)\b/'             => 'grava',
            '/\b(adoquin|adoquines)\b/'                => 'adoquin',
            '/\b(alfombra|alfombrado|moqueta)\b/'      => 'alfombra',
            '/\b(parquet|parque)\b/'                   => 'parquet',
            '/\b(baldosa|baldosas)\b/'                 => 'baldosa',
        );
        foreach ($rules as $re => $add) {
            if (preg_match($re, $nt)) {
                $intent['materiales'] = array_merge($intent['materiales'], $add['m']);
                $intent['caracteristicas'] = array_merge($intent['caracteristicas'], $add['c']);
                if (!empty($add['s'])) $intent['suelos'] = array_merge($intent['suelos'], $add['s']);
            }
        }
        foreach ($suelo_rules as $re => $sk) {
            if (preg_match($re, $nt)) $intent['suelos'][] = $sk;
        }
        $intent['materiales'] = array_values(array_unique($intent['materiales']));
        $intent['materiales_explicitos'] = array_values(array_unique($intent['materiales_explicitos']));
        $intent['suelos'] = array_values(array_unique($intent['suelos']));
        $intent['caracteristicas'] = array_values(array_unique($intent['caracteristicas']));
        $intent['caracteristicas_explicitas'] = array_values(array_unique($intent['caracteristicas_explicitas']));

        if (!empty($m['kg'])) {
            $kg = (float) end($m['kg']);
            $assumed = false;
            if (empty($intent['busca_carro']) && !$m['ruedas'] && preg_match('/\b(carro|carrito|equipo|mueble|maquina|plataforma|contenedor|estante|rack|mesa|camilla|coche|andamio)/', $nt)) {
                $m['ruedas'] = 4;
                $assumed = true;
            }
            if (empty($intent['busca_carro']) && $m['ruedas'] && $m['ruedas'] > 1) {
                $per = $kg / ($m['ruedas'] - 1);
                $intent['carga_kg'] = round($per);
                $intent['nota_carga'] = sprintf('Para %s kg en %d ruedas%s se recomienda una capacidad mínima de ~%s kg por rueda (peso total ÷ (ruedas − 1)).',
                    number_format_i18n($kg), $m['ruedas'], $assumed ? ' (asumiendo 4)' : '', number_format_i18n($per));
            } else {
                $intent['carga_kg'] = $kg;
            }
        }
        return $intent;
    }

    /**
     * Detecta de forma inteligente las categorías de WooCommerce (product_cat) que coinciden
     * con la búsqueda del usuario (ej. "ruedas para porton" -> categoría Ruedas para portón,
     * "carro" / "carros" -> categorías de Carros, etc.) y obtiene también sus productos.
     */
    public static function match_categories($catalog, $q, $intent = array()) {
        $norm_q = RBI_Text::norm($q);
        if ($norm_q === '') return array('cats' => array(), 'pids' => array());

        $q_slug = sanitize_title($norm_q);
        $q_toks = array_values(array_unique(RBI_Text::tokens($q)));
        $q_wants_wheel = !empty($intent['busca_rueda']) || (bool) array_intersect(self::WHEEL_STEMS, $q_toks);
        $q_wants_carro = !empty($intent['busca_carro']);
        $q_core = array_values(array_diff($q_toks, self::WHEEL_STEMS, array('solucion')));

        $cats_meta = isset($catalog['cats_meta']) && is_array($catalog['cats_meta']) ? $catalog['cats_meta'] : array();
        if (empty($cats_meta) && function_exists('get_terms')) {
            $wcts = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => true));
            if (is_array($wcts)) {
                foreach ($wcts as $wct) {
                    if (!is_object($wct) || empty($wct->term_id)) continue;
                    $cslug = strtolower(urldecode((string) $wct->slug));
                    $cname = RBI_Text::clean($wct->name);
                    $clink = get_term_link($wct, 'product_cat');
                    $cats_meta[(int) $wct->term_id] = array(
                        'id'    => (int) $wct->term_id,
                        'name'  => $cname,
                        'slug'  => $cslug,
                        'url'   => (!is_wp_error($clink) && is_string($clink)) ? $clink : home_url('/categoria-producto/' . $cslug . '/'),
                        'count' => (int) $wct->count,
                        'toks'  => array_values(array_unique(RBI_Text::tokens($cname . ' ' . str_replace(array('-', '_'), ' ', $cslug)))),
                    );
                }
            }
        }

        $matched = array();
        foreach ($cats_meta as $cid => $cm) {
            $cid = (int) $cid;
            $c_slug = isset($cm['slug']) ? (string) $cm['slug'] : '';
            $c_name = isset($cm['name']) ? (string) $cm['name'] : '';
            $c_name_norm = RBI_Text::norm($c_name);
            $c_slug_norm = RBI_Text::norm(str_replace(array('-', '_'), ' ', $c_slug));
            $c_toks = isset($cm['toks']) && is_array($cm['toks']) ? $cm['toks'] : RBI_Text::tokens($c_name . ' ' . $c_slug_norm);
            $c_has_wheel = (bool) array_intersect(self::WHEEL_STEMS, $c_toks) || strpos($c_slug, 'rueda') !== false || strpos($c_name_norm, 'rueda') !== false;
            $c_core = array_values(array_diff($c_toks, self::WHEEL_STEMS, array('solucion')));

            // Si el usuario busca específicamente "carro" o "carros" (sin pedir ruedas), no emparejar categorías de "Ruedas para carros".
            if ($q_wants_carro && $c_has_wheel) {
                continue;
            }
            // Si el usuario pide explícitamente "ruedas para...", evitar categorías que son solo carros completos.
            if ($q_wants_wheel && !$c_has_wheel && preg_match('/\b(carro|carros)\b/', $c_name_norm)) {
                continue;
            }

            $score = 0;
            if ($q_slug !== '' && ($q_slug === $c_slug || $norm_q === $c_name_norm || $norm_q === $c_slug_norm)) {
                $score = 100;
            } elseif (!empty($c_core) && !empty($q_core)) {
                $inter = array_intersect($c_core, $q_core);
                $n_inter = count($inter);
                if ($n_inter > 0) {
                    if ($n_inter === count($c_core) && $n_inter === count($q_core)) {
                        $score = 96;
                    } elseif ($n_inter === count($c_core)) {
                        $score = 90;
                    } elseif ($n_inter === count($q_core) && count($q_core) >= 1) {
                        $score = 84;
                    }
                }
            } elseif (empty($q_core) && $q_wants_wheel && $c_slug === 'ruedas') {
                $score = 80;
            }

            if ($score <= 0 && strlen($norm_q) >= 4) {
                if (strpos($c_name_norm, $norm_q) !== false || strpos($c_slug_norm, $norm_q) !== false) {
                    $score = 82;
                }
            }

            if ($score > 0) {
                $matched[$cid] = array(
                    'id'     => $cid,
                    'nombre' => $c_name,
                    'slug'   => $c_slug,
                    'url'    => isset($cm['url']) ? $cm['url'] : home_url('/categoria-producto/' . $c_slug . '/'),
                    'count'  => isset($cm['count']) ? (int) $cm['count'] : 0,
                    'score'  => $score,
                );
            }
        }

        uasort($matched, function ($a, $b) {
            if ($a['score'] !== $b['score']) return $b['score'] - $a['score'];
            return $b['count'] - $a['count'];
        });

        // Obtener todos los IDs de productos pertenecientes a las categorías detectadas (incluyendo subcategorías).
        $cat_pids = array();
        if (!empty($matched)) {
            $top_cids = array_keys($matched);
            if (!empty($catalog['items']) && is_array($catalog['items'])) {
                foreach ($catalog['items'] as $pid => $it) {
                    if (!empty($it['cid']) && array_intersect($top_cids, (array) $it['cid'])) {
                        $cat_pids[(int) $pid] = true;
                    }
                }
            }
            if (function_exists('get_posts')) {
                $db_pids = get_posts(array(
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'tax_query'      => array(
                        array(
                            'taxonomy'         => 'product_cat',
                            'field'            => 'term_id',
                            'terms'            => $top_cids,
                            'include_children' => true,
                        ),
                    ),
                ));
                if (is_array($db_pids)) {
                    foreach ($db_pids as $dpid) {
                        $cat_pids[(int) $dpid] = true;
                    }
                }
            }
        }

        return array(
            'cats' => $matched,
            'pids' => $cat_pids,
        );
    }

    public static function merge_intent($local, $ai) {
        if (!is_array($ai)) return $local;
        foreach (array('keywords', 'materiales', 'caracteristicas') as $k) {
            if (!empty($ai[$k]) && is_array($ai[$k])) {
                $merged = array_merge((array) $local[$k], array_map('sanitize_text_field', array_map('strval', $ai[$k])));
                $local[$k] = array_slice(array_values(array_unique(array_filter($merged))), 0, 15);
            }
        }
        if (!empty($ai['suelos']) && is_array($ai['suelos'])) {
            $suelos = isset($local['suelos']) && is_array($local['suelos']) ? $local['suelos'] : array();
            foreach ($ai['suelos'] as $s) {
                $sk = RBI_Catalog::suelo_key((string) $s);
                if ($sk !== '') $suelos[] = $sk;
            }
            $local['suelos'] = array_slice(array_values(array_unique($suelos)), 0, 10);
        }
        if (!empty($ai['diametro_mm']) && is_numeric($ai['diametro_mm'])) $local['diametro_mm'] = (float) $ai['diametro_mm'];
        if (!empty($ai['carga_kg_por_rueda']) && is_numeric($ai['carga_kg_por_rueda'])) $local['carga_kg'] = (float) $ai['carga_kg_por_rueda'];
        if (!empty($ai['uso'])) $local['uso'] = sanitize_text_field((string) $ai['uso']);
        if (!empty($ai['nota_carga'])) $local['nota_carga'] = sanitize_text_field((string) $ai['nota_carga']);
        if (isset($ai['busca_rueda'])) $local['busca_rueda'] = (bool) $ai['busca_rueda'];
        return $local;
    }

    /* =========================
     * Ranking por relevancia
     * ========================= */
    public static function rank($catalog, $intent, $cat_match = null) {
        $items = $catalog['items'];
        $df = $catalog['df'];
        $N = max(1, (int) $catalog['n']);

        $raw_q = isset($intent['texto']) ? (string) $intent['texto'] : '';
        $norm_q = RBI_Text::norm($raw_q);
        $raw_q_upper = strtoupper(trim($raw_q));
        if ($cat_match === null) {
            $cat_match = self::match_categories($catalog, isset($intent['query_original']) ? $intent['query_original'] : $raw_q, $intent);
        }
        $matched_cats     = isset($cat_match['cats']) && is_array($cat_match['cats']) ? $cat_match['cats'] : array();
        $matched_cat_pids = isset($cat_match['pids']) && is_array($cat_match['pids']) ? $cat_match['pids'] : array();
        $busca_carro      = !empty($intent['busca_carro']);

        $base = array();
        $core_tokens = array();
        foreach (RBI_Text::tokens($raw_q) as $t) {
            if (ctype_digit(str_replace('.', '', $t)) || in_array($t, array('mm', 'kg', 'cm', 'pulg', 'kilo', 'kilos', 'pulgada', 'pulgadas'), true)) continue;
            // Los términos genéricos "rueda/rodaja/garrucha" aparecen en casi todo el catálogo: se ponderan bajo para que manden los términos discriminantes (ej. carretilla, yegua, inox, poliuretano, freno...).
            if (in_array($t, self::WHEEL_STEMS, true)) {
                $base[$t] = 0.30;
            } else {
                $base[$t] = 1.0;
                $core_tokens[$t] = true;
            }
        }

        $terms = $base;
        foreach (array('keywords' => 0.45, 'materiales' => 0.55, 'suelos' => 0.55, 'caracteristicas' => 0.50) as $k => $wq) {
            if (empty($intent[$k])) continue;
            foreach ((array) $intent[$k] as $phrase) {
                foreach (RBI_Text::tokens($phrase) as $t) {
                    if (ctype_digit(str_replace('.', '', $t)) || in_array($t, array('mm', 'kg', 'cm', 'pulg', 'kilo', 'kilos'), true)) continue;
                    if (in_array($t, self::WHEEL_STEMS, true)) continue;
                    if (!isset($terms[$t]) || $terms[$t] < $wq) $terms[$t] = $wq;
                }
            }
        }

        $syn = RBI_Text::syn_map();
        $core_with_syn = $core_tokens;

        // Corrección tolerante a errores de escritura (prefijos + fonética en español + distancia Levenshtein contra el vocabulario del catálogo)
        $vocab = null;
        foreach (array_keys($terms) as $t) {
            $wq = $terms[$t];
            if (strlen($t) < 4 || isset($df[$t])) continue;
            if ($vocab === null) $vocab = array_keys($df);
            foreach ($vocab as $v) {
                if (is_string($v) && strpos($v, $t) === 0 && !isset($terms[$v])) {
                    $terms[$v] = $wq * 0.88;
                    if (isset($core_tokens[$t])) $core_with_syn[$v] = true;
                }
            }
            foreach (RBI_Text::fuzzy_expand($t, $vocab) as $fv => $fmult) {
                $fw = $wq * $fmult;
                if (!isset($terms[$fv]) || $terms[$fv] < $fw) $terms[$fv] = $fw;
                if (isset($core_tokens[$t])) $core_with_syn[$fv] = true;
            }
        }

        // Expansión de sinónimos (incluyendo los términos ya corregidos por ortografía/fonética)
        foreach (array_keys($terms) as $t) {
            $wq = $terms[$t];
            if (isset($syn[$t])) {
                foreach ($syn[$t] as $s) {
                    if (!isset($terms[$s])) $terms[$s] = $wq * 0.75;
                    if (isset($core_tokens[$t]) || isset($core_with_syn[$t])) $core_with_syn[$s] = true;
                }
            }
        }

        // Frase limpia sin palabras genéricas de rueda para detectar coincidencia exacta en título, categorías o etiquetas.
        $clean_phrase_words = array();
        foreach (explode(' ', $norm_q) as $w) {
            if ($w === '' || strlen($w) < 3) continue;
            $st = RBI_Text::stem($w);
            if (in_array($st, self::WHEEL_STEMS, true) || in_array($w, array('para', 'con', 'del', 'las', 'los', 'una', 'uno', 'por', 'tipo', 'etiqueta', 'etiquetas', 'producto', 'productos'), true)) continue;
            $clean_phrase_words[] = $w;
        }
        $clean_phrase = count($clean_phrase_words) >= 1 ? implode(' ', $clean_phrase_words) : '';

        // Detectar si la consulta menciona alguna etiqueta de estado estricta (ej. Entrega Inmediata, Oferta, Destacado, Nuevo, Sin Stock...).
        $strict_status_labels = array();
        if (preg_match('/\b(ofert|descuent|rebaj|liquidacion|promo)/', $norm_q)) $strict_status_labels['oferta'] = true;
        if (preg_match('/\b(entrega inmediata|despacho inmediato|envio inmediato)\b/', $norm_q)) $strict_status_labels['entrega-inmediata'] = true;
        if (preg_match('/\b(sin stock|agotad[oa]s?|sin existencia)\b/', $norm_q)) $strict_status_labels['sin-stock'] = true;
        if (preg_match('/\b(nuevo|nueva|nuevos|nuevas|novedad)\b/', $norm_q)) $strict_status_labels['nuevo'] = true;
        if (preg_match('/\b(destacad[oa]s?)\b/', $norm_q)) $strict_status_labels['destacado'] = true;

        $matched_labels = $strict_status_labels;
        if (!empty($catalog['labels']) && is_array($catalog['labels'])) {
            foreach ($catalog['labels'] as $lk => $lname) {
                $ltoks = RBI_Text::tokens($lname);
                if (empty($ltoks)) continue;
                $all_present = true;
                foreach ($ltoks as $lt) {
                    if (!isset($terms[$lt])) { $all_present = false; break; }
                }
                if ($all_present) $matched_labels[$lk] = true;
            }
        }

        // Detectar si la consulta menciona cualquier etiqueta de producto de WooCommerce (product_tag) o etiqueta unificada.
        $matched_tags = $matched_labels;
        if (!empty($catalog['tags']) && is_array($catalog['tags'])) {
            foreach ($catalog['tags'] as $tk => $tname) {
                $ttoks = RBI_Text::tokens($tname);
                if (empty($ttoks)) continue;
                $all_present = true;
                foreach ($ttoks as $tt) {
                    if (!isset($terms[$tt])) { $all_present = false; break; }
                }
                if ($all_present) $matched_tags[$tk] = true;
            }
        }

        // Detectar tipos de suelo por la consulta o inferencia de la IA contra las etiquetas de suelo de cada producto.
        $matched_suelos = array();
        if (!empty($intent['suelos']) && is_array($intent['suelos'])) {
            foreach ($intent['suelos'] as $sk) {
                $sk = RBI_Catalog::suelo_key($sk);
                if ($sk !== '') $matched_suelos[$sk] = true;
            }
        }
        if (!empty($catalog['suelos']) && is_array($catalog['suelos'])) {
            foreach ($catalog['suelos'] as $sk => $sname) {
                $stoks = RBI_Text::tokens($sname);
                if (empty($stoks)) continue;
                $all_present = true;
                foreach ($stoks as $st) {
                    if (!isset($terms[$st])) { $all_present = false; break; }
                }
                if ($all_present) $matched_suelos[$sk] = true;
            }
        }

        $explicit_feats = array();
        if (!empty($intent['caracteristicas_explicitas']) && is_array($intent['caracteristicas_explicitas'])) {
            foreach ($intent['caracteristicas_explicitas'] as $ef) $explicit_feats[$ef] = true;
        }
        $carac_list = array_map('strtolower', (array) $intent['caracteristicas']);
        $want_feats = array();
        foreach (array(
            'freno' => 'freno', 'giratoria' => 'giratoria', 'fija' => 'fija',
            'placa' => 'placa', 'espiga' => 'espiga', 'inox' => 'inox',
            'inoxidable' => 'inox', 'acero inoxidable' => 'inox',
            'neumatica' => 'neumatica', 'impinchable' => 'impinchable', 'alta_temp' => 'alta_temp',
        ) as $ck => $fk) {
            if (in_array($ck, $carac_list, true)) $want_feats[$fk] = true;
        }

        $explicit_mats = array();
        if (!empty($intent['materiales_explicitos']) && is_array($intent['materiales_explicitos'])) {
            foreach ($intent['materiales_explicitos'] as $em) {
                foreach (RBI_Text::tokens($em) as $mt) $explicit_mats[$mt] = true;
            }
        }

        $idf = array();
        foreach ($terms as $t => $_) {
            $d = isset($df[$t]) ? $df[$t] : 0;
            $idf[$t] = log(1 + ($N - $d + 0.5) / ($d + 0.5));
        }

        $want_d = !empty($intent['diametro_mm']) ? (float) $intent['diametro_mm'] : 0;
        $want_kg = !empty($intent['carga_kg']) ? (float) $intent['carga_kg'] : 0;
        $ncore = count($core_tokens);
        $nbase = max(1, count($base));
        $learned = class_exists('RBI_Learn') ? RBI_Learn::boosts($raw_q) : array();
        $wants_oos = isset($matched_labels['sin-stock']);

        $scores = array();
        $core_hits_by_id = array();
        $any_core_match = false;

        foreach ($items as $id => $it) {
            $score = 0.0;
            $matched = 0;
            $core_matched = 0;
            $tw_set  = !empty($it['tw']) && is_array($it['tw']) ? array_flip($it['tw']) : array();
            $cw_set  = !empty($it['cw']) && is_array($it['cw']) ? array_flip($it['cw']) : array();
            $tgw_set = !empty($it['tgw']) && is_array($it['tgw']) ? array_flip($it['tgw']) : array();
            $dw_set  = !empty($it['dw']) && is_array($it['dw']) ? array_flip($it['dw']) : array();

            // 1. Coincidencia exacta o parcial de SKU (producto padre o variaciones)
            if (strlen($raw_q_upper) >= 3 && !empty($it['skus']) && is_array($it['skus'])) {
                foreach ($it['skus'] as $sku) {
                    $sku_up = strtoupper((string) $sku);
                    if ($sku_up === $raw_q_upper) {
                        $score += 140;
                        $core_matched = max($core_matched, $ncore);
                    } elseif (strpos($sku_up, $raw_q_upper) !== false) {
                        $score += 65;
                        $core_matched = max($core_matched, 1);
                    }
                }
            }

            // 2. Puntaje TF-IDF ponderado + bonificación por coincidencia directa en Nombre del producto, Categoría, Etiquetas, Descripción o Material
            foreach ($terms as $t => $wq) {
                if (isset($it['w'][$t])) {
                    $score += $wq * $idf[$t] * $it['w'][$t];
                    if (isset($base[$t])) $matched++;
                    if (isset($core_tokens[$t])) {
                        $core_matched++;
                        if (isset($tw_set[$t])) {
                            $score += 20 * $idf[$t]; // Coincidencia directa en el nombre del producto
                        }
                        if (isset($cw_set[$t])) {
                            $score += 19 * $idf[$t]; // Coincidencia directa en la categoría del producto
                        }
                        if (isset($tgw_set[$t])) {
                            $score += 18 * $idf[$t]; // Coincidencia directa en las etiquetas del producto
                        }
                        if (isset($dw_set[$t])) {
                            $score += 14 * $idf[$t]; // Coincidencia directa en la descripción del producto
                        } elseif (!isset($tw_set[$t]) && !isset($cw_set[$t]) && !isset($tgw_set[$t]) && $it['w'][$t] >= 3.5) {
                            $score += 11 * $idf[$t]; // Coincidencia en materiales o atributos
                        }
                    } elseif (isset($core_with_syn[$t])) {
                        $core_matched = max($core_matched, 1);
                        if (isset($tw_set[$t]) || isset($cw_set[$t]) || isset($tgw_set[$t])) {
                            $score += 12 * $idf[$t];
                        } elseif (isset($dw_set[$t])) {
                            $score += 9 * $idf[$t];
                        }
                    }
                }
            }

            // 2b. Coincidencia inteligente con Categoría de Producto (ej. "ruedas para porton" -> categoría Ruedas para portón)
            $in_matched_cat = isset($matched_cat_pids[$id]);
            if (!$in_matched_cat && !empty($matched_cats) && !empty($it['cid'])) {
                foreach ($it['cid'] as $cid) {
                    if (isset($matched_cats[$cid])) { $in_matched_cat = true; break; }
                }
            }
            if ($in_matched_cat) {
                $score += 125;
                $matched = max($matched, $nbase);
                $core_matched = max($core_matched, $ncore, 1);
            }

            // 2c. Si el usuario busca "carro" o "carros" (sin pedir ruedas), priorizar todos los Carros y relegar las ruedas sueltas
            if ($busca_carro) {
                $t_low_check = RBI_Text::norm($it['t']);
                $starts_w = (bool) preg_match('/^(rueda|ruedas|rodaja|rodajas|garrucha|garruchas|horquilla|horquillas|polin|polines|llanta|llantas)\b/', $t_low_check);
                $is_carro_prod = (isset($it['pt']) && $it['pt'] === 'carro')
                    || (!$starts_w && ($in_matched_cat || (bool) preg_match('/^(carro|carros|yegua|yeguas|transpaleta|transpaletas|apilador|carretilla|carretillas)\b/', $t_low_check)));
                if ($is_carro_prod) {
                    $score += 135;
                    $matched = max($matched, $nbase);
                    $core_matched = max($core_matched, $ncore, 1);
                } else {
                    $score *= 0.04;
                }
            } elseif (!empty($matched_cats) && !empty($matched_cat_pids) && !$in_matched_cat) {
                $all_core_in_title = ($ncore > 0);
                foreach ($core_tokens as $ctk => $_) {
                    if (!isset($tw_set[$ctk])) { $all_core_in_title = false; break; }
                }
                if (!$all_core_in_title && $score > 0) {
                    $score *= 0.10;
                }
            }

            // 3. Coincidencia de frase o múltiples palabras en Nombre del producto, Etiquetas o Descripción del producto
            if ($clean_phrase !== '' && strlen($clean_phrase) >= 3) {
                $t_norm  = RBI_Text::norm($it['t']);
                $tn_norm = isset($it['tn']) ? $it['tn'] : $t_norm;
                $dn_norm = isset($it['dn']) ? (string) $it['dn'] : RBI_Text::norm(isset($it['x']) ? $it['x'] : '');
                if (strpos($t_norm, $clean_phrase) !== false) {
                    $score += 42;
                    $core_matched = max($core_matched, $ncore);
                } elseif (strpos($tn_norm, $clean_phrase) !== false) {
                    $score += 28;
                    $core_matched = max($core_matched, 1);
                }
                if ($dn_norm !== '' && strpos($dn_norm, $clean_phrase) !== false) {
                    $score += 26;
                    $matched++;
                    $core_matched = max($core_matched, 1);
                } elseif (count($clean_phrase_words) >= 2 && $dn_norm !== '') {
                    $dw_hits = 0;
                    foreach ($clean_phrase_words as $cpw) {
                        if (strpos($dn_norm, $cpw) !== false) $dw_hits++;
                    }
                    if ($dw_hits >= 2) {
                        $score += 12 * ($dw_hits / count($clean_phrase_words));
                        $core_matched = max($core_matched, 1);
                    }
                }
            }

            // 4. Coincidencia con etiquetas del producto (Rotar Etiquetas + WooCommerce product_tag)
            $label_hit = false;
            $strict_label_hit = false;
            if (!empty($matched_labels) && !empty($it['lb'])) {
                foreach ($it['lb'] as $lk) {
                    if (isset($matched_labels[$lk])) {
                        $score += 30;
                        $label_hit = true;
                        $matched++;
                        $core_matched = max($core_matched, 1);
                    }
                    if (isset($strict_status_labels[$lk])) {
                        $strict_label_hit = true;
                    }
                }
            }
            if (!empty($matched_tags) && !empty($it['tk'])) {
                foreach ($it['tk'] as $tk) {
                    if (isset($matched_tags[$tk])) {
                        $score += 26;
                        $label_hit = true;
                        $matched++;
                        $core_matched = max($core_matched, 1);
                    }
                }
            }
            if (!empty($strict_status_labels) && !$strict_label_hit && $score > 0) {
                $score *= 0.30; // Penalizar solo si el usuario pidió una etiqueta de estado estricta (ej. oferta, entrega inmediata) y no la tiene
            }

            // 5. Coincidencia con etiquetas de Superficies de Trabajo del producto.
            $suelo_hit = false;
            if (!empty($matched_suelos) && !empty($it['su'])) {
                foreach ($it['su'] as $sk) {
                    if (isset($matched_suelos[$sk])) {
                        $score += 26;
                        $suelo_hit = true;
                        $matched++;
                        $core_matched = max($core_matched, 1);
                    }
                }
            }
            if (!empty($matched_suelos) && !$suelo_hit && $score > 0) {
                $score *= 0.40;
            }

            if ($core_matched > 0) $any_core_match = true;
            $core_hits_by_id[$id] = $core_matched;

            if ($score > 0) {
                $score *= (0.65 + 0.35 * min(1, $matched / $nbase));
                if ($ncore > 0 && $core_matched >= $ncore) {
                    $score *= 1.45; // El producto cumple TODAS las palabras clave principales del usuario
                }
            }

            // 6. Características estructurales y especiales (freno, sin_freno, giratoria, fija, placa, espiga, inox, neumatica, impinchable, alta_temp).
            $prod_feats = !empty($it['f']) && is_array($it['f']) ? array_flip($it['f']) : array();
            if (!empty($want_feats)) {
                foreach ($want_feats as $fk => $_) {
                    if (isset($prod_feats[$fk])) {
                        $score += isset($explicit_feats[$fk]) ? 22 : 8;
                    } elseif (isset($explicit_feats[$fk]) && $score > 0) {
                        $score *= 0.42; // Penalizar si el usuario pidió explícitamente la característica y el producto no la tiene
                    }
                }
            }
            if (isset($explicit_feats['sin_freno']) && $score > 0) {
                if (isset($prod_feats['freno']) && !isset($prod_feats['giratoria']) && !isset($prod_feats['fija'])) {
                    $score *= 0.45;
                } else {
                    $score += 10;
                }
            }

            // 7. Materiales explícitos solicitados por el usuario
            if (!empty($explicit_mats)) {
                $prod_mat_tokens = array();
                if (!empty($it['m']) && is_array($it['m'])) {
                    foreach ($it['m'] as $mname) {
                        foreach (RBI_Text::tokens($mname) as $mt) $prod_mat_tokens[$mt] = true;
                    }
                }
                foreach ($tw_set as $mt => $_) $prod_mat_tokens[$mt] = true;
                $mat_hit = false;
                foreach ($explicit_mats as $mt => $_) {
                    if (isset($prod_mat_tokens[$mt])) { $mat_hit = true; break; }
                    if (isset($syn[$mt])) {
                        foreach ($syn[$mt] as $ms) {
                            if (isset($prod_mat_tokens[$ms])) { $mat_hit = true; break 2; }
                        }
                    }
                }
                if ($mat_hit) {
                    $score += 24;
                } elseif ($score > 0) {
                    $score *= 0.45;
                }
            }

            // 8. Diámetro solicitado (mm)
            if ($want_d > 0) {
                if (!empty($it['dm'])) {
                    $best = 9.0;
                    foreach ($it['dm'] as $d) $best = min($best, abs($d - $want_d) / $want_d);
                    if ($best <= 0.04) $score += 28;
                    elseif ($best <= 0.10) $score += 18;
                    elseif ($best <= 0.20) $score += 6;
                    elseif ($score > 0) $score *= 0.45;
                } elseif ($score > 0) {
                    $score *= 0.65;
                }
            }

            // 9. Capacidad de carga solicitada (kg por rueda)
            if ($want_kg > 0) {
                if (!empty($it['kg'])) {
                    $max = max($it['kg']);
                    if ($max >= $want_kg) {
                        $score += ($max <= $want_kg * 2.5) ? 24 : 12;
                    } elseif ($max >= $want_kg * 0.85) {
                        $score += 4;
                    } elseif ($score > 0) {
                        $score *= 0.40;
                    }
                }
            }

            // 10. Aprendizaje de clics históricos y disponibilidad en stock
            if (isset($learned[$id])) $score += $learned[$id];
            if ($score > 0) {
                if ($wants_oos) {
                    $score *= empty($it['stk']) ? 1.25 : 0.25;
                } elseif (!empty($it['stk'])) {
                    $score *= 1.12;
                }
            }

            if ($score > 0.01) $scores[$id] = $score;
        }

        // Si el usuario buscó un término específico (ej. "ruedas de carretilla" o "ruedas para carros yegua")
        // y existen productos que coinciden con ese término específico, descartar los productos que solo coincidieron con la palabra genérica "rueda".
        if ($ncore > 0 && $any_core_match) {
            foreach ($scores as $id => $sc) {
                if (empty($core_hits_by_id[$id])) {
                    $scores[$id] = $sc * 0.08;
                }
            }
        }

        arsort($scores);
        return $scores;
    }

    /* =========================
     * Ordenamiento y tarjetas
     * ========================= */
    public static function sort_ids($ids, $items, $sort) {
        $key = null;
        switch ($sort) {
            case 'precio-asc':  $key = function ($it) { return $it['pmin'] > 0 ? $it['pmin'] : PHP_INT_MAX; }; $dir = 1; break;
            case 'precio-desc': $key = function ($it) { return $it['pmax']; }; $dir = -1; break;
            case 'diam-asc':    $key = function ($it) { return $it['dm'] ? min($it['dm']) : PHP_INT_MAX; }; $dir = 1; break;
            case 'diam-desc':   $key = function ($it) { return $it['dm'] ? max($it['dm']) : 0; }; $dir = -1; break;
            case 'carga-desc':  $key = function ($it) { return $it['kg'] ? max($it['kg']) : 0; }; $dir = -1; break;
            case 'nombre':      $key = function ($it) { return remove_accents(strtolower($it['t'])); }; $dir = 1; break;
        }
        if (!$key) return $ids;
        $pos = array_flip($ids);
        usort($ids, function ($a, $b) use ($items, $key, $dir, $pos) {
            $ka = $key($items[$a]); $kb = $key($items[$b]);
            if ($ka == $kb) return $pos[$a] - $pos[$b];
            return ($ka < $kb ? -1 : 1) * $dir;
        });
        return $ids;
    }

    /** Tarjeta para el front. Precio y stock se leen en vivo (siempre actualizados). */
    public static function card($id, $it, $motivo = '') {
        $p = function_exists('wc_get_product') ? wc_get_product($id) : null;
        if (!$p || $p->get_status() !== 'publish') return null; // producto eliminado/oculto desde el último índice

        $img = get_the_post_thumbnail_url($id, 'woocommerce_thumbnail');
        if (!$img && function_exists('wc_placeholder_img_src')) $img = wc_placeholder_img_src();

        $precio = '';
        $antes  = '';
        $amount = 0.0;
        $antes_amount = 0.0;

        if ($p instanceof WC_Product_Variable) {
            $amount     = (float) $p->get_variation_price('min', true);
            $reg_amount = (float) $p->get_variation_regular_price('min', true);
            if ($reg_amount > $amount && $amount > 0) {
                $antes_amount = $reg_amount;
            } else {
                foreach ($p->get_children() as $var_id) {
                    $v_reg  = (float) get_post_meta($var_id, '_regular_price', true);
                    $v_sale = get_post_meta($var_id, '_sale_price', true);
                    if ($v_reg > 0 && $v_sale !== '' && $v_sale !== false) {
                        $v_sale = (float) $v_sale;
                        if ($v_sale > 0 && $v_sale < $v_reg) {
                            if ($amount <= 0 || abs($v_sale - $amount) < 1 || $antes_amount <= 0) {
                                $amount       = $v_sale;
                                $antes_amount = $v_reg;
                                break;
                            }
                        }
                    }
                }
            }
        } else {
            $amount     = (float) wc_get_price_to_display($p);
            $reg_raw    = $p->get_regular_price();
            $reg_amount = $reg_raw !== '' ? (float) wc_get_price_to_display($p, array('price' => $reg_raw)) : (float) get_post_meta($id, '_regular_price', true);
            $sale_meta  = get_post_meta($id, '_sale_price', true);
            if ($reg_amount > $amount && $amount > 0) {
                $antes_amount = $reg_amount;
            } elseif ($sale_meta !== '' && $sale_meta !== false && (float) $sale_meta > 0 && $reg_amount > (float) $sale_meta) {
                $amount       = (float) $sale_meta;
                $antes_amount = $reg_amount;
            }
        }

        if ($amount > 0) {
            $precio = html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8');
            if ($antes_amount > $amount) {
                $antes = html_entity_decode(wp_strip_all_tags(wc_price($antes_amount)), ENT_QUOTES, 'UTF-8');
            }
        }

        // Etiquetas del plugin "Rotar Etiquetas de Productos" (Entrega inmediata, -48%, Sin stock...).
        $etiquetas = '';
        if (function_exists('rep_get_badges_html')) {
            try { $etiquetas = (string) call_user_func('rep_get_badges_html', (int) $id); } catch (Throwable $e) { $etiquetas = ''; }
        }

        $is_oos = function_exists('rep_product_is_out_of_stock')
            ? (bool) call_user_func('rep_product_is_out_of_stock', $p)
            : !$p->is_in_stock();

        return array(
            'id'        => (int) $id,
            'etiquetas' => $etiquetas,
            'titulo'    => $it['t'],
            'url'       => get_permalink($id),
            'img'       => $img,
            'motivo'    => $motivo,
            'antes'     => $antes,
            'precio'    => $precio,
            'stock'     => $is_oos ? 0 : 1,
        );
    }

    private static function candidate_payload($id, $it, $catalog_labels = array(), $catalog_suelos = array(), $clicks = 0) {
        $attrs = array(); $i = 0;
        foreach ($it['a'] as $l => $vals) {
            if ($i++ >= 10) break;
            $attrs[] = $l . ': ' . implode(', ', array_slice($vals, 0, 12));
        }
        $lbs = array();
        if (!empty($it['tg']) && is_array($it['tg'])) {
            $lbs = $it['tg'];
        } elseif (!empty($it['lb'])) {
            foreach ($it['lb'] as $lk) if (isset($catalog_labels[$lk])) $lbs[] = $catalog_labels[$lk];
        }
        $sus = array();
        if (!empty($it['su'])) {
            foreach ($it['su'] as $sk) $sus[] = isset($catalog_suelos[$sk]) ? $catalog_suelos[$sk] : RBI_Catalog::suelo_label($sk);
        }
        $feat_map = array(
            'giratoria'   => 'Giratoria',
            'fija'        => 'Fija',
            'freno'       => 'Con freno',
            'placa'       => 'Montaje Placa',
            'espiga'      => 'Montaje Espiga / Vástago',
            'inox'        => 'Acero Inoxidable',
            'neumatica'   => 'Neumática',
            'impinchable' => 'Impinchable / Antipinchazos',
            'alta_temp'   => 'Alta Temperatura',
        );
        $feats = array();
        if (!empty($it['f'])) {
            foreach ($it['f'] as $fk) if (isset($feat_map[$fk])) $feats[] = $feat_map[$fk];
        }
        $row = array(
            'id'            => (int) $id,
            'nombre'        => $it['t'],
            'configuracion' => implode(', ', $feats),
            'categorias'    => implode(', ', $it['c']),
            'etiquetas'     => implode(', ', $lbs),
            'suelos'        => implode(', ', $sus),
            'material'      => implode(', ', $it['m']),
            'diametros'     => implode(', ', $it['dl']),
            'cargas'        => implode(', ', $it['kl']),
            'atributos'     => implode(' | ', $attrs),
            'descripcion'   => RBI_Text::cut($it['x'], 350),
            'stock'         => $it['stk'] ? 'sí' : 'sin stock',
        );
        if ($clicks > 0) $row['preferencia_clientes_clics'] = (int) $clicks;
        if ($it['pmin'] > 0) $row['precio_clp'] = $it['pmin'] == $it['pmax'] ? round($it['pmin']) : ('desde ' . round($it['pmin']));
        return $row;
    }

    /* =========================
     * Helpers de request
     * ========================= */
    private static function history($raw) {
        $h = json_decode((string) $raw, true);
        if (!is_array($h)) return array();
        $out = array();
        foreach (array_slice($h, -6) as $row) {
            if (!is_array($row) || empty($row['text'])) continue;
            $out[] = array(
                'role' => (isset($row['role']) && $row['role'] === 'assistant') ? 'assistant' : 'user',
                'text' => RBI_Text::cut(sanitize_textarea_field((string) $row['text']), 700),
            );
        }
        return $out;
    }

    private static function client_ip() {
        foreach (array('HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR') as $k) {
            if (!empty($_SERVER[$k])) return sanitize_text_field(wp_unslash($_SERVER[$k]));
        }
        return '0';
    }

    private static function rate_ok() {
        $s = RBI_Plugin::settings();
        $limit = (int) $s['rate_limit'];
        if ($limit <= 0 || current_user_can('manage_options')) return true;
        $key = 'rbi_rl_' . md5(self::client_ip());
        $n = (int) get_transient($key);
        if ($n >= $limit) return false;
        set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    private static function send($ok, $data) {
        while (ob_get_level() > 0) ob_end_clean();
        if ($ok) wp_send_json_success($data);
        wp_send_json_error($data);
    }

    private static function local_answer($intent, $count, $filters_on, $items = array(), $ids = array(), $categorias = array()) {
        if ($count === 0) {
            return $filters_on
                ? 'No encontré productos con esa combinación. Prueba quitando algún filtro o describiendo tu necesidad (por nombre, categoría, etiqueta, descripción, peso o superficie).'
                : 'No encontré productos exactos para esa búsqueda. Puedes buscar por **nombre del producto**, **categoría**, **etiqueta**, **descripción** o indicarme el uso, peso a mover, material o diámetro.';
        }
        $bits = array();
        if (!empty($categorias)) {
            $cnames = array();
            foreach (array_slice($categorias, 0, 2) as $cm) {
                if (!empty($cm['nombre'])) $cnames[] = $cm['nombre'];
            }
            if ($cnames) $bits[] = 'categoría: ' . implode(', ', $cnames);
        }
        if (!empty($intent['diametro_mm'])) $bits[] = 'diámetro ~' . number_format_i18n($intent['diametro_mm']) . ' mm';
        if (!empty($intent['carga_kg'])) $bits[] = 'capacidad ≥ ' . number_format_i18n($intent['carga_kg']) . ' kg' . (empty($intent['busca_carro']) ? ' por rueda' : '');
        if (!empty($intent['materiales'])) $bits[] = 'material: ' . implode('/', array_slice((array) $intent['materiales'], 0, 2));
        if (!empty($intent['caracteristicas'])) $bits[] = implode(', ', array_slice((array) $intent['caracteristicas'], 0, 3));
        if (!empty($intent['suelos'])) $bits[] = 'superficie: ' . implode(', ', array_slice((array) $intent['suelos'], 0, 2));

        $top_names = array();
        foreach (array_slice($ids, 0, 3) as $tid) {
            if (isset($items[$tid]['t'])) $top_names[] = '**' . $items[$tid]['t'] . '**';
        }

        $txt = 'Encontré **' . $count . ' producto' . ($count > 1 ? 's' : '') . '** buscando en categorías, nombres, etiquetas y descripciones del catálogo' . ($bits ? ' (' . implode(' · ', $bits) . ')' : '') . '.';
        if ($top_names) {
            $txt .= ' Destacan: ' . implode(', ', $top_names) . '.';
        }
        if (!empty($intent['nota_carga'])) $txt .= "\n\n" . $intent['nota_carga'];
        if (!empty($intent['busca_carro'])) {
            $txt .= "\n\n💡 Te muestro todos los **carros disponibles**. Puedes afinar según si necesitas **carro plegable**, **carro yegua**, **carro plataforma** o la capacidad de carga requerida.";
        } else {
            $txt .= "\n\n💡 Para afinar tu búsqueda según tu necesidad, puedes indicar el **peso total a mover**, la **superficie de trabajo**, el **diámetro** o elegir una sugerencia rápida abajo.";
        }
        return $txt;
    }

    /**
     * Genera sugerencias inteligentes para ayudar al usuario a mejorar su búsqueda
     * según lo que aún no ha especificado (freno, carga, superficie, material o diámetro)
     * basándose en las opciones reales disponibles en los resultados encontrados.
     */
    private static function local_suggestions($intent, $catalog, $ids, $q) {
        if (!empty($intent['busca_carro'])) {
            return array(
                'Carro plegable de plataforma',
                'Carro yegua de carga',
                'Carro para 300 kg o más',
                'Ruedas de repuesto para carro',
            );
        }
        $sug = array();
        $carac = !empty($intent['caracteristicas']) ? (array) $intent['caracteristicas'] : array();
        $base_q = trim((string) $q);
        if (strlen($base_q) > 38) $base_q = 'Ruedas';

        if (in_array('porton', $carac, true)) {
            return array(
                'Rueda para portón canal V',
                'Rueda para portón canal U',
                'Rueda para portón con soporte / caja',
                'Rueda para portón de alta carga',
            );
        }

        if (!in_array('freno', $carac, true) && !in_array('giratoria', $carac, true)) {
            $sug[] = $base_q . ' giratoria con freno';
        } elseif (!in_array('freno', $carac, true)) {
            $sug[] = $base_q . ' con freno';
        }

        if (empty($intent['carga_kg'])) {
            $sug[] = 'Para carro de 300 kg en total';
        }

        if (empty($intent['suelos'])) {
            $sug[] = 'Para piso delicado o cerámica (no raye)';
        }

        if (empty($intent['materiales'])) {
            $sug[] = $base_q . ' en poliuretano de alta resistencia';
        } elseif (!in_array('inox', $carac, true)) {
            $sug[] = $base_q . ' en acero inoxidable';
        }

        if (empty($intent['diametro_mm']) && count($sug) < 4) {
            $sug[] = $base_q . ' de 100 mm';
        }

        return array_slice(array_values(array_unique($sug)), 0, 4);
    }

    /* =========================
     * AJAX
     * ========================= */
    public static function ajax_search() {
        ob_start();
        try {
            if (function_exists('set_time_limit')) @set_time_limit(120);
            $s = RBI_Plugin::settings();

            $q = isset($_POST['q']) ? sanitize_textarea_field(wp_unslash($_POST['q'])) : '';
            $q = RBI_Text::cut(trim($q), 400);
            $want_ai = !empty($_POST['ia']) && $q !== '';
            $history = isset($_POST['history']) ? self::history(wp_unslash($_POST['history'])) : array();
            $filters = self::read_filters($_POST);
            $sort = isset($_POST['sort']) ? sanitize_key($_POST['sort']) : '';
            $per_page = isset($_POST['per_page']) ? (int) $_POST['per_page'] : (int) $s['per_page'];
            $per_page = max(1, min(50, $per_page));
            $max_res = (int) $s['max_resultados'];
            $notices = array();

            $catalog = RBI_Catalog::get();
            if (empty($catalog['items'])) self::send(false, array('msg' => 'El catálogo aún no está disponible.'));
            $items = $catalog['items'];

            $intent = null; $scores = null; $mode = 'filtros';
            $use_ai = false;
            $cat_match = array('cats' => array(), 'pids' => array());
            $categorias_out = array();

            $filter_scores = function ($raw_scores, $intent_arr, $cat_m) use ($items) {
                if (empty($raw_scores)) return $raw_scores;
                $max_sc = reset($raw_scores);
                $min = $max_sc * 0.25;
                $cat_pids = isset($cat_m['pids']) && is_array($cat_m['pids']) ? $cat_m['pids'] : array();
                $wants_carro = !empty($intent_arr['busca_carro']);

                $has_carro_hits = false;
                if ($wants_carro) {
                    foreach ($raw_scores as $pid => $sc) {
                        if (!isset($items[$pid])) continue;
                        $it = $items[$pid];
                        $t_low = RBI_Text::norm($it['t']);
                        $starts_w = (bool) preg_match('/^(rueda|ruedas|rodaja|rodajas|garrucha|garruchas|horquilla|horquillas|polin|polines|llanta|llantas)\b/', $t_low);
                        if ((isset($it['pt']) && $it['pt'] === 'carro') || (!$starts_w && (isset($cat_pids[$pid]) || preg_match('/^(carro|carros|yegua|yeguas|transpaleta|transpaletas|apilador|carretilla|carretillas)\b/', $t_low)))) {
                            $has_carro_hits = true;
                            break;
                        }
                    }
                }

                $out_sc = array();
                foreach ($raw_scores as $pid => $sc) {
                    if ($wants_carro && $has_carro_hits && isset($items[$pid])) {
                        $it = $items[$pid];
                        $t_low = RBI_Text::norm($it['t']);
                        $starts_w = (bool) preg_match('/^(rueda|ruedas|rodaja|rodajas|garrucha|garruchas|horquilla|horquillas|polin|polines|llanta|llantas)\b/', $t_low);
                        $is_c = (isset($it['pt']) && $it['pt'] === 'carro') || (!$starts_w && (isset($cat_pids[$pid]) || preg_match('/^(carro|carros|yegua|yeguas|transpaleta|transpaletas|apilador|carretilla|carretillas)\b/', $t_low)));
                        if ($is_c) {
                            $out_sc[$pid] = $sc;
                        }
                        continue;
                    }
                    if (isset($cat_pids[$pid]) || $sc >= $min) {
                        $out_sc[$pid] = $sc;
                    }
                }
                return $out_sc;
            };

            if ($q !== '') {
                $mode = 'local';
                $intent = self::local_intent($q, $history);
                $cat_match = self::match_categories($catalog, $q, $intent);
                if (!empty($cat_match['cats'])) {
                    foreach (array_slice($cat_match['cats'], 0, 4) as $cm) {
                        $categorias_out[] = array(
                            'id'     => (int) $cm['id'],
                            'nombre' => (string) $cm['nombre'],
                            'url'    => (string) $cm['url'],
                            'count'  => (int) $cm['count'],
                        );
                    }
                }
                $use_ai = $want_ai && RBI_Plugin::ai_available();
                $scores = self::rank($catalog, $intent, $cat_match);
                $scores = $filter_scores($scores, $intent, $cat_match);

                // Invocar RBI_AI::intent cuando la búsqueda local arrojó pocas coincidencias o cuando el usuario describe su necesidad en una frase.
                $q_word_count = count(array_filter(explode(' ', trim($q))));
                if ($use_ai && empty($cat_match['cats']) && (count($scores) < 5 || $q_word_count >= 4)) {
                    $ikey = 'rbi_int_' . md5(wp_json_encode(array($q, $history, RBI_Plugin::model(), 'v5')));
                    $ai_int = get_transient($ikey);
                    if (!is_array($ai_int)) {
                        if (!self::rate_ok()) {
                            $ai_int = new WP_Error('rbi_rate', 'Límite de consultas IA alcanzado; mostrando búsqueda estándar.');
                        } else {
                            $ai_int = RBI_AI::intent($q, $history);
                            if (!is_wp_error($ai_int)) {
                                set_transient($ikey, $ai_int, DAY_IN_SECONDS);
                                if (class_exists('RBI_Learn')) RBI_Learn::record_ai_terms($q, $ai_int);
                            }
                        }
                    }
                    if (is_wp_error($ai_int)) {
                        $notices[] = $ai_int->get_error_message();
                        $use_ai = false;
                    } else {
                        $was_carro = !empty($intent['busca_carro']);
                        $intent = self::merge_intent($intent, $ai_int);
                        if ($was_carro) $intent['busca_carro'] = true;
                        $scores = self::rank($catalog, $intent, $cat_match);
                        $scores = $filter_scores($scores, $intent, $cat_match);
                    }
                }
                $pool = array_keys($scores);
            } else {
                $pool = array_keys($items);
            }

            // Filtro por categoría (rotar_filtros_categoria): limita todo a los productos de esa categoría y sus subcategorías.
            $base_cat = isset($_POST['base_cat']) ? absint($_POST['base_cat']) : 0;
            if ($base_cat) {
                $pool = array_values(array_filter($pool, function ($id) use ($items, $base_cat) {
                    return in_array($base_cat, $items[$id]['cid'], true);
                }));
            }

            $facets = self::facets($catalog, $pool, $filters);
            if ($base_cat) {
                // El selector "Subcategoría" solo ofrece las hijas directas de la categoría.
                $kids = get_terms(array('taxonomy' => 'product_cat', 'parent' => $base_cat, 'hide_empty' => false, 'fields' => 'ids'));
                $kids = is_array($kids) ? array_map('intval', $kids) : array();
                $facets['cat'] = array_values(array_filter($facets['cat'], function ($o) use ($kids) {
                    return in_array((int) $o['v'], $kids, true);
                }));
            }
            $ids = array();
            foreach ($pool as $id) if (self::passes($items[$id], $filters)) $ids[] = $id;
            if ($scores === null) {
                usort($ids, function ($a, $b) use ($items) { return $items[$a]['o'] - $items[$b]['o']; });
            }

            $filters_on = (bool) array_filter($filters);
            $respuesta = ''; $sugerencias = array(); $motivos = array();

            if ($use_ai) {
                $f_desc = array();
                if (!empty($filters['material']) && isset($catalog['materials'][$filters['material']])) $f_desc[] = 'Material: ' . $catalog['materials'][$filters['material']];
                if (!empty($filters['diam'])) $f_desc[] = 'Diámetro: Ø ' . $filters['diam'] . ' mm';
                if (!empty($filters['carga'])) $f_desc[] = 'Capacidad mínima: ' . $filters['carga'] . ' kg';
                if (!empty($filters['cat']) && isset($catalog['cats'][$filters['cat']])) $f_desc[] = 'Categoría: ' . $catalog['cats'][$filters['cat']];
                if (!empty($filters['etiqueta']) && isset($catalog['labels'][$filters['etiqueta']])) $f_desc[] = 'Etiqueta: ' . $catalog['labels'][$filters['etiqueta']];
                if (!empty($filters['tipo'])) $f_desc[] = 'Tipo de rueda: ' . ucfirst($filters['tipo']);
                if (!empty($filters['suelo'])) {
                    $sl = RBI_Catalog::suelo_label($filters['suelo']);
                    $f_desc[] = 'Superficie de Trabajo: ' . $sl;
                    $intent['suelos'] = array_values(array_unique(array_merge(
                        isset($intent['suelos']) ? (array) $intent['suelos'] : array(),
                        array($sl)
                    )));
                }
                if (!empty($filters['montaje'])) $f_desc[] = 'Montaje: ' . ucfirst($filters['montaje']);
                $intent['filtros_activos'] = $f_desc;
                $intent['categorias_detectadas'] = $categorias_out;

                // Si la consulta es amplia/conversacional y no arrojó coincidencias léxicas directas,
                // entregar al Ejecutivo IA los mejores productos que cumplan los filtros activos (priorizando con stock).
                if (empty($ids)) {
                    $fallback_ids = array();
                    foreach ($items as $fid => $fit) {
                        if (self::passes($fit, $filters)) $fallback_ids[] = $fid;
                    }
                    if (empty($fallback_ids)) $fallback_ids = array_keys($items);
                    usort($fallback_ids, function ($a, $b) use ($items) {
                        if ($items[$a]['stk'] !== $items[$b]['stk']) return $items[$b]['stk'] - $items[$a]['stk'];
                        return $items[$a]['o'] - $items[$b]['o'];
                    });
                    $ids = array_slice($fallback_ids, 0, 60);
                }

                $akey = 'rbi_adv_' . md5(wp_json_encode(array($q, $history, $filters, RBI_Catalog::timestamp(), RBI_Plugin::model(), $s['contexto'], 'v5')));
                $adv = get_transient($akey);
                if (!is_array($adv)) {
                    $cands = array();
                    $cat_labels = isset($catalog['labels']) && is_array($catalog['labels']) ? $catalog['labels'] : array();
                    $cat_suelos = isset($catalog['suelos']) && is_array($catalog['suelos']) ? $catalog['suelos'] : array();
                    $q_clicks = class_exists('RBI_Learn') ? RBI_Learn::query_clicks($q) : array();
                    foreach (array_slice($ids, 0, (int) $s['max_candidatos']) as $id) {
                        $cands[] = self::candidate_payload($id, $items[$id], $cat_labels, $cat_suelos, isset($q_clicks[$id]) ? (int) $q_clicks[$id] : 0);
                    }
                    $adv = RBI_AI::advise($q, $history, $intent, $cands, $max_res);
                    if (!is_wp_error($adv)) set_transient($akey, $adv, 6 * HOUR_IN_SECONDS);
                }
                if (is_wp_error($adv)) {
                    $notices[] = $adv->get_error_message();
                    $use_ai = false;
                } else {
                    $mode = 'ia';
                    $allowed = array_flip($ids);
                    $rec = array();
                    foreach ((array) (isset($adv['recomendados']) ? $adv['recomendados'] : array()) as $r) {
                        $id = isset($r['id']) ? (int) $r['id'] : 0;
                        if (!$id || !isset($allowed[$id]) || isset($motivos[$id])) continue;
                        $motivos[$id] = isset($r['motivo']) ? sanitize_text_field((string) $r['motivo']) : '';
                        $rec[] = $id;
                        if (count($rec) >= $max_res) break;
                    }
                    $ids = array_merge($rec, array_values(array_diff($ids, $rec)));
                    $respuesta = sanitize_textarea_field((string) (isset($adv['respuesta']) ? $adv['respuesta'] : ''));
                    foreach ((array) (isset($adv['sugerencias']) ? $adv['sugerencias'] : array()) as $x) {
                        $x = sanitize_text_field((string) $x);
                        if ($x !== '') $sugerencias[] = RBI_Text::cut($x, 80);
                        if (count($sugerencias) >= 4) break;
                    }
                }
            }
            if ($q !== '' && !$use_ai) {
                $respuesta = self::local_answer($intent, count($ids), $filters_on, $items, $ids, $categorias_out);
            }
            if ($q !== '' && empty($sugerencias)) {
                $sugerencias = self::local_suggestions($intent, $catalog, $ids, $q);
            }

            if ($sort && $sort !== 'relevancia') $ids = self::sort_ids($ids, $items, $sort);
            $ids = array_slice($ids, 0, 600);

            $cards = array();
            foreach (array_slice($ids, 0, $per_page) as $id) {
                $c = self::card($id, $items[$id], isset($motivos[$id]) ? $motivos[$id] : '');
                if ($c) $cards[] = $c;
            }

            if ($q !== '' && empty($_POST['page_only'])) RBI_Plugin::log_query($q, $mode, count($ids));

            $out = array(
                'modo'        => $mode,
                'respuesta'   => $respuesta,
                'sugerencias' => $sugerencias,
                'categorias'  => $categorias_out,
                'total'       => count($ids),
                'order'       => array_map('intval', $ids),
                'motivos'     => $motivos,
                'cards'       => $cards,
                'per_page'    => $per_page,
                'facets'      => $facets,
                'recomendados'=> count($motivos),
            );
            // El buscador nunca muestra avisos técnicos de IA en la web; se registran en el log de PHP
            // como máximo una vez cada 15 minutos para no llenar debug.log.
            if ($notices && !get_transient('rbi_notice_logged')) {
                set_transient('rbi_notice_logged', 1, 15 * MINUTE_IN_SECONDS);
                error_log('[Rotar Buscador Inteligente] IA no disponible (modo estándar): ' . implode(' | ', array_unique($notices)));
            }
            self::send(true, $out);
        } catch (Throwable $e) {
            error_log('[Rotar Buscador Inteligente] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::send(false, array('msg' => 'Ocurrió un error al buscar. Intenta nuevamente.' . (current_user_can('manage_options') ? ' (' . $e->getMessage() . ')' : '')));
        }
    }

    public static function ajax_cards() {
        ob_start();
        try {
            $raw = isset($_POST['ids']) ? (string) wp_unslash($_POST['ids']) : '';
            $ids = array_slice(array_filter(array_map('intval', explode(',', $raw))), 0, 48);
            $motivos = isset($_POST['motivos']) ? json_decode((string) wp_unslash($_POST['motivos']), true) : array();
            $catalog = RBI_Catalog::get();
            $cards = array();
            foreach ($ids as $id) {
                if (!isset($catalog['items'][$id])) continue;
                $m = (is_array($motivos) && isset($motivos[$id])) ? sanitize_text_field((string) $motivos[$id]) : '';
                $c = self::card($id, $catalog['items'][$id], $m);
                if ($c) $cards[] = $c;
            }
            self::send(true, array('cards' => $cards));
        } catch (Throwable $e) {
            error_log('[Rotar Buscador Inteligente] ' . $e->getMessage());
            self::send(false, array('msg' => 'No se pudieron cargar más productos.'));
        }
    }
}
