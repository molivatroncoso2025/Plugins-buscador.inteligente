<?php
/**
 * Índice del catálogo WooCommerce (cacheado en una opción no autoload).
 * Guarda por producto: textos, atributos, etiquetas, medidas numéricas, características y pesos de tokens.
 */
if (!defined('ABSPATH')) exit;

class RBI_Catalog {

    const OPT       = 'rbi_catalog_v1';
    const OPT_TS    = 'rbi_catalog_ts_v1';
    const OPT_STALE = 'rbi_catalog_stale';
    const LOCK      = 'rbi_catalog_lock';

    private static $mem = null;
    private static $rebuild_queued = false;

    public static function init() {
        // Cambios en productos/términos/stock: el índice se marca como desactualizado y se
        // reconstruye en segundo plano (los visitantes siguen buscando con el índice anterior).
        add_action('save_post_product', array(__CLASS__, 'mark_stale'));
        add_action('woocommerce_update_product', array(__CLASS__, 'mark_stale'));
        add_action('woocommerce_product_set_stock', array(__CLASS__, 'mark_stale'));
        add_action('woocommerce_variation_set_stock', array(__CLASS__, 'mark_stale'));
        add_action('woocommerce_product_set_stock_status', array(__CLASS__, 'mark_stale'));
        add_action('edited_term', array(__CLASS__, 'mark_stale'));
        add_action('created_term', array(__CLASS__, 'mark_stale'));
        add_action('delete_term', array(__CLASS__, 'mark_stale'));
        add_action('set_object_terms', array(__CLASS__, 'on_set_object_terms'), 10, 4);
        add_action('delete_post', array(__CLASS__, 'maybe_clear_on_delete'));

        // Cambios en el plugin Rotar Etiquetas de Productos y en Superficies de Trabajo.
        foreach (array('rep_save_label', 'rep_delete_label', 'rep_assign_by_category', 'rep_save_cat_label_config', 'rep_bulk_assign', 'rep_save_assignments', 'rep_save_label_categories', 'rotar_fp_superficie_assign') as $act) {
            add_action('wp_ajax_' . $act, array(__CLASS__, 'mark_stale'), 1);
        }
    }

    public static function on_set_object_terms($object_id, $terms, $tt_ids, $taxonomy) {
        if (in_array($taxonomy, array('product_tag', 'product_cat'), true) || strpos((string) $taxonomy, 'pa_') === 0) {
            self::mark_stale();
        }
    }

    /** Marca el índice para reconstrucción (barato: no borra datos). */
    public static function mark_stale() {
        if ((int) get_option(self::OPT_STALE, 0) === 0) update_option(self::OPT_STALE, time(), false);
    }

    /** Borra el índice por completo (se reconstruye en la próxima búsqueda). */
    public static function clear() {
        self::$mem = null;
        delete_option(self::OPT);
        delete_option(self::OPT_TS);
        delete_option(self::OPT_STALE);
    }

    public static function maybe_clear_on_delete($post_id) {
        $pt = get_post_type($post_id);
        if ($pt === 'product' || $pt === 'product_variation') self::mark_stale();
    }

    public static function timestamp() {
        return (int) get_option(self::OPT_TS, 0);
    }

    public static function get() {
        if (self::$mem !== null) return self::$mem;
        $cat = get_option(self::OPT);
        if (is_array($cat) && isset($cat['v'], $cat['items'], $cat['df'], $cat['labels'], $cat['suelos'], $cat['cats_meta']) && (int) $cat['v'] >= 9) {
            self::$mem = $cat;
            if ((int) get_option(self::OPT_STALE, 0) > 0) self::queue_rebuild();
            elseif (class_exists('RBI_LLMs') && defined('ABSPATH') && !file_exists(ABSPATH . 'llms.txt')) {
                add_action('shutdown', array('RBI_LLMs', 'write_static_files'));
            }
            return self::$mem;
        }
        return self::$mem = self::build();
    }

    /** Reconstruye al final de la petición, después de enviar la respuesta al visitante. */
    private static function queue_rebuild() {
        if (self::$rebuild_queued || get_transient(self::LOCK)) return;
        self::$rebuild_queued = true;
        add_action('shutdown', function () {
            if (get_transient(RBI_Catalog::LOCK)) return;
            set_transient(RBI_Catalog::LOCK, 1, 5 * MINUTE_IN_SECONDS);
            // Libera al visitante antes de reconstruir (LiteSpeed / PHP-FPM, si están disponibles).
            foreach (array('litespeed_finish_request', 'fastcgi_finish_request') as $fn) {
                if (function_exists($fn)) { call_user_func($fn); break; }
            }
            try {
                RBI_Catalog::build();
            } catch (Throwable $e) {
                error_log('[Rotar Buscador Inteligente] Error reconstruyendo índice: ' . $e->getMessage());
            }
            delete_transient(RBI_Catalog::LOCK);
        }, 999);
    }

    private static function materials($pid, $title = '', $cats = array(), $tags = array(), $attrs = array()) {
        $raw = get_post_meta($pid, 'material_de_fabricacion', true);
        if (empty($raw) && function_exists('get_field')) $raw = get_field('material_de_fabricacion', $pid, false);
        $vals = array();
        if (is_array($raw)) {
            array_walk_recursive($raw, function ($v) use (&$vals) {
                if (is_scalar($v) && trim((string) $v) !== '') $vals[] = trim((string) $v);
            });
        } elseif (trim((string) $raw) !== '') {
            $vals[] = trim((string) $raw);
        }
        foreach ((array) $attrs as $alabel => $avals) {
            if (stripos($alabel, 'material') !== false) {
                foreach ((array) $avals as $av) {
                    if (trim((string) $av) !== '') $vals[] = trim((string) $av);
                }
            }
        }
        // Detectar materiales desde título, categorías y etiquetas si no estaban en meta o para completar variantes
        $haystack = RBI_Text::norm($title . ' ' . implode(' ', (array) $cats) . ' ' . implode(' ', (array) $tags));
        $known_mats = array(
            '/\bacero forjado\b/'                      => 'Acero Forjado',
            '/\b(fierro fundido|hierro fundido)\b/'    => 'Fierro Fundido',
            '/\bpoliuretano\b/'                        => 'Poliuretano',
            '/\bpoliamida\b/'                          => 'Poliamida',
            '/\b(nylon|nailon)\b/'                     => 'Nylon',
            '/\bcaucho\b/'                             => 'Caucho Macizo',
            '/\bgoma\b/'                               => 'Goma',
            '/\b(termoplastica|termoplastico|tpr)\b/'  => 'Termoplástica',
            '/\b(fenolica|resina fenolica)\b/'         => 'Resina Fenólica',
            '/\bpolipropileno\b/'                      => 'Polipropileno',
        );
        $existing_keys = array();
        foreach ($vals as $v) $existing_keys[self::material_key($v)] = true;
        foreach ($known_mats as $re => $mlabel) {
            $mk = self::material_key($mlabel);
            if (!isset($existing_keys[$mk]) && preg_match($re, $haystack)) {
                $vals[] = $mlabel;
                $existing_keys[$mk] = true;
            }
        }
        return array_values(array_unique($vals));
    }

    /**
     * Obtiene las etiquetas del producto desde "Rotar Etiquetas de Productos",
     * estado de descuento/oferta/sin stock y etiquetas nativas de WooCommerce (product_tag).
     */
    private static function product_labels($pid, $p, $in_stock, $cids, $wc_tag_names) {
        $names = array();
        $suppressed_no_stock = array('entrega inmediata', 'oferta', 'nuevo');
        $suppressed_in_stock = array('sin stock', 'agotado', 'sin existencia', 'no disponible', 'out of stock');

        if (function_exists('rep_get_badges_for_product')) {
            try {
                $badges = (array) call_user_func('rep_get_badges_for_product', (int) $pid);
            } catch (Throwable $e) {
                $badges = array();
            }
            $hidden_ids = array();
            if (!empty($cids) && !empty($badges) && defined('REP_TABLE_CATCFG')) {
                global $wpdb;
                $t_catcfg = $wpdb->prefix . REP_TABLE_CATCFG;
                $ph = implode(',', array_fill(0, count($cids), '%d'));
                $h = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT label_id FROM {$t_catcfg} WHERE term_id IN ({$ph}) AND hidden = 1", ...$cids));
                if (is_array($h)) $hidden_ids = array_map('intval', $h);
            }
            foreach ($badges as $b) {
                if (!is_object($b) || empty($b->name)) continue;
                if (!empty($b->id) && in_array((int) $b->id, $hidden_ids, true)) continue;
                $clean = RBI_Text::clean($b->name);
                $norm = strtolower($clean);
                if (!$in_stock && in_array($norm, $suppressed_no_stock, true)) continue;
                if ($in_stock && in_array($norm, $suppressed_in_stock, true)) continue;
                $names[] = $clean;
            }
        }

        // Si el producto tiene descuento activo y está en stock, incluir etiqueta de Oferta.
        $has_discount = false;
        $discount_pct = 0;
        if ($in_stock) {
            if (function_exists('rep_get_product_discount_pct')) {
                try {
                    $pct = call_user_func('rep_get_product_discount_pct', $p);
                    if ($pct !== null && (int) $pct >= 1) {
                        $has_discount = true;
                        $discount_pct = (int) $pct;
                    }
                } catch (Throwable $e) {}
            } elseif (method_exists($p, 'is_on_sale') && $p->is_on_sale()) {
                $has_discount = true;
            }
        }
        if ($has_discount) {
            $has_oferta = false;
            foreach ($names as $n) {
                if (stripos($n, 'oferta') !== false || stripos($n, 'descuento') !== false) {
                    $has_oferta = true;
                    break;
                }
            }
            if (!$has_oferta) $names[] = 'Oferta';
        } elseif (!$in_stock) {
            $has_oos_lbl = false;
            foreach ($names as $n) {
                if (in_array(strtolower($n), $suppressed_in_stock, true)) {
                    $has_oos_lbl = true;
                    break;
                }
            }
            if (!$has_oos_lbl) $names[] = 'Sin Stock';
        }

        // Incluir también las etiquetas nativas de WooCommerce (product_tag) que no sean de superficie (las de superficie ya tienen su propio filtro).
        foreach ((array) $wc_tag_names as $wt) {
            $wt = trim((string) $wt);
            if ($wt === '' || self::is_known_suelo($wt)) continue;
            $names[] = $wt;
        }

        return array('names' => array_values(array_unique($names)), 'on_sale' => $has_discount, 'pct' => $discount_pct);
    }

    /** Mapa canónico de Superficies de Trabajo donde pueden trabajar las ruedas. */
    public static function suelo_map() {
        return array(
            'asfalto'          => 'Asfalto',
            'arena'            => 'Arena',
            'ceramico'         => 'Cerámica',
            'concreto-liso'    => 'Concreto Liso',
            'concreto-rugoso'  => 'Concreto Rugoso',
            'epoxi'            => 'Epoxi',
            'madera'           => 'Madera',
            'metalico'         => 'Metálico',
            'pasto'            => 'Pasto',
            'tierra'           => 'Tierra',
            'alta-temperatura' => 'Alta Temp.',
            'humedo'           => 'Húmedo/ Químico',
            'grava'            => 'Grava/ Césped',
            'adoquin'          => 'Adoquín',
            'alfombra'         => 'Alfombra',
            'parquet'          => 'Parqué',
            'baldosa'          => 'Baldosa',
        );
    }

    /** Normaliza variantes de las etiquetas de superficie hacia su clave canónica. */
    public static function suelo_key($raw) {
        $k = sanitize_title(RBI_Text::norm($raw));
        if ($k === '') return '';
        $aliases = array(
            'ceramica'          => 'ceramico',
            'porcelanato'       => 'ceramico',
            'cemento-liso'      => 'concreto-liso',
            'cemento-rugoso'    => 'concreto-rugoso',
            'epoxico'           => 'epoxi',
            'piso-epoxico'      => 'epoxi',
            'piso-flotante'     => 'madera',
            'parque'            => 'parquet',
            'alta-temp'         => 'alta-temperatura',
            'humedo-quimico'    => 'humedo',
            'quimico'           => 'humedo',
            'grava-cesped'      => 'grava',
            'cesped'            => 'pasto',
            'baldosas'          => 'baldosa',
            'adoquines'         => 'adoquin',
        );
        return isset($aliases[$k]) ? $aliases[$k] : $k;
    }

    public static function suelo_label($raw) {
        $k = self::suelo_key($raw);
        $map = self::suelo_map();
        return isset($map[$k]) ? $map[$k] : '';
    }

    public static function is_known_suelo($raw) {
        $k = self::suelo_key($raw);
        $map = self::suelo_map();
        return isset($map[$k]);
    }

    /**
     * Extrae ÚNICAMENTE las Superficies de Trabajo válidas (suelo_map) que estén
     * asignadas como etiqueta en el producto (product_tag o etiqueta del producto).
     */
    private static function product_suelos($wc_tags, $lbl_names) {
        $out = array();
        foreach ((array) $wc_tags as $t) {
            if (!self::is_known_suelo($t)) continue;
            $k = self::suelo_key($t);
            $out[$k] = self::suelo_label($k);
        }
        foreach ((array) $lbl_names as $ln) {
            if (!self::is_known_suelo($ln)) continue;
            $k = self::suelo_key($ln);
            $out[$k] = self::suelo_label($k);
        }
        return $out;
    }

    private static function add_weights(&$w, $tokens, $weight) {
        foreach ($tokens as $t) {
            if (!isset($w[$t]) || $w[$t] < $weight) $w[$t] = $weight;
        }
    }

    public static function material_key($label) {
        return sanitize_title(RBI_Text::norm($label));
    }

    public static function label_key($label) {
        return sanitize_title(RBI_Text::norm($label));
    }

    public static function build() {
        if (!function_exists('wc_get_product')) return array('n' => 0, 'df' => array(), 'items' => array(), 'materials' => array(), 'cats' => array(), 'labels' => array(), 'suelos' => array());
        if (function_exists('set_time_limit')) @set_time_limit(300);
        // Cambios que ocurran durante la construcción volverán a marcar el índice.
        delete_option(self::OPT_STALE);

        $ids = get_posts(array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ));

        $items = array();
        $df = array();
        $materials = array();
        $cats_all = array();
        $cats_meta = array();
        $labels_all = array();
        $suelos_all = array();
        $tags_all = array();
        $pos = 0;

        global $wpdb;
        $var_skus_by_parent = array();
        $var_desc_by_parent = array();
        $vrows = $wpdb->get_results("
            SELECT p.post_parent, pm.meta_key, pm.meta_value
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key IN ('_sku', '_variation_description')
            WHERE p.post_type = 'product_variation'
              AND p.post_status = 'publish'
              AND pm.meta_value != ''
        ");
        if (is_array($vrows)) {
            foreach ($vrows as $vr) {
                $pp = (int) $vr->post_parent;
                if ($pp <= 0) continue;
                if ($vr->meta_key === '_sku') {
                    $var_skus_by_parent[$pp][] = trim((string) $vr->meta_value);
                } elseif ($vr->meta_key === '_variation_description') {
                    $var_desc_by_parent[$pp][] = RBI_Text::clean((string) $vr->meta_value);
                }
            }
        }
        $hidden_cat_slugs = array('historico-ruedas', 'rueda-sin-categoria', 'uncategorized', 'sin-categorizar');
        $all_wc_cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => true));
        if (is_array($all_wc_cats)) {
            foreach ($all_wc_cats as $wct) {
                if (!is_object($wct) || empty($wct->term_id)) continue;
                $cslug = isset($wct->slug) ? strtolower(urldecode((string) $wct->slug)) : '';
                $cname = RBI_Text::clean($wct->name);
                if ($cname === '' || in_array($cslug, $hidden_cat_slugs, true)) continue;
                $cslug_words = RBI_Text::clean(str_replace(array('-', '_'), ' ', $cslug));
                $clink = function_exists('get_term_link') ? get_term_link($wct, 'product_cat') : '';
                $curl  = (!is_wp_error($clink) && is_string($clink) && $clink !== '') ? $clink : home_url('/categoria-producto/' . $cslug . '/');
                $cats_meta[(int) $wct->term_id] = array(
                    'id'    => (int) $wct->term_id,
                    'name'  => $cname,
                    'slug'  => $cslug,
                    'url'   => $curl,
                    'count' => (int) $wct->count,
                    'toks'  => array_values(array_unique(RBI_Text::tokens($cname . ' ' . $cslug_words))),
                );
            }
        }

        foreach ($ids as $pid) {
            $pid = (int) $pid;
            $p = wc_get_product($pid);
            if (!$p) continue;
            if (method_exists($p, 'get_catalog_visibility') && $p->get_catalog_visibility() === 'hidden') continue;

            $title = RBI_Text::clean(get_the_title($pid));
            $terms = get_the_terms($pid, 'product_cat');
            $cats = array(); $cids = array(); $cat_slug_texts = array();
            if (is_array($terms)) {
                $all_cat_terms = array();
                foreach ($terms as $term) {
                    if (!is_object($term) || empty($term->term_id)) continue;
                    $all_cat_terms[(int) $term->term_id] = $term;
                    if (function_exists('get_ancestors')) {
                        foreach ((array) get_ancestors((int) $term->term_id, 'product_cat', 'taxonomy') as $anc_id) {
                            $anc_id = (int) $anc_id;
                            if ($anc_id > 0 && !isset($all_cat_terms[$anc_id])) {
                                $anc_term = get_term($anc_id, 'product_cat');
                                if ($anc_term && !is_wp_error($anc_term)) {
                                    $all_cat_terms[$anc_id] = $anc_term;
                                }
                            }
                        }
                    }
                }
                foreach ($all_cat_terms as $cid_int => $term) {
                    $cslug = isset($term->slug) ? strtolower(urldecode((string) $term->slug)) : '';
                    $cname = RBI_Text::clean($term->name);
                    if ($cname === '' || in_array($cslug, $hidden_cat_slugs, true)) continue;
                    $cats[] = $cname;
                    $cids[] = (int) $cid_int;
                    $cslug_words = RBI_Text::clean(str_replace(array('-', '_'), ' ', $cslug));
                    if ($cslug_words !== '') $cat_slug_texts[] = $cslug_words;
                    if (stripos($cname, 'soluciones') === false && stripos($cslug, 'soluciones') === false) {
                        $cats_all[(int) $cid_int] = $cname;
                    }
                    if (!isset($cats_meta[(int) $cid_int])) {
                        $clink = function_exists('get_term_link') ? get_term_link($term, 'product_cat') : '';
                        $curl  = (!is_wp_error($clink) && is_string($clink) && $clink !== '') ? $clink : home_url('/categoria-producto/' . $cslug . '/');
                        $cats_meta[(int) $cid_int] = array(
                            'id'    => (int) $cid_int,
                            'name'  => $cname,
                            'slug'  => $cslug,
                            'url'   => $curl,
                            'count' => 1,
                            'toks'  => array_values(array_unique(RBI_Text::tokens($cname . ' ' . $cslug_words))),
                        );
                    }
                }
            }
            $tag_terms = wp_get_post_terms($pid, 'product_tag', array('fields' => 'all'));
            $tags = array();
            $tag_names = array();
            if (is_array($tag_terms)) {
                foreach ($tag_terms as $tt) {
                    if (!empty($tt->name)) {
                        $tname = RBI_Text::clean($tt->name);
                        $tags[] = $tname;
                        $tag_names[] = $tname;
                    }
                    if (!empty($tt->slug)) {
                        $tslug = RBI_Text::clean(str_replace(array('-', '_'), ' ', urldecode($tt->slug)));
                        $tags[] = $tslug;
                    }
                    if (!empty($tt->description)) {
                        $tags[] = RBI_Text::clean($tt->description);
                    }
                }
            }
            $short = RBI_Text::clean(strip_shortcodes($p->get_short_description()));
            $desc = RBI_Text::clean(strip_shortcodes($p->get_description()));
            $var_desc = !empty($var_desc_by_parent[$pid]) ? implode(' ', array_unique($var_desc_by_parent[$pid])) : '';
            $full_desc_text = trim($short . ' ' . $desc . ' ' . $var_desc);

            $attrs = array(); $diam_labels = array(); $kg_labels = array();
            foreach ((array) $p->get_attributes() as $attr) {
                if (!is_a($attr, 'WC_Product_Attribute')) continue;
                $name = $attr->get_name();
                $label = wc_attribute_label($name, $p);
                $vals = $attr->is_taxonomy() ? wc_get_product_terms($pid, $name, array('fields' => 'names')) : $attr->get_options();
                $vals = array_values(array_filter(array_map('trim', array_map('strval', (array) $vals))));
                if (empty($vals)) continue;
                $vals = array_slice(array_values(array_unique($vals)), 0, 40);
                $attrs[$label] = $vals;
                $slug = sanitize_title(remove_accents(preg_replace('/^pa_/', '', $name)));
                if (strpos($slug, 'diametro') !== false) $diam_labels = array_merge($diam_labels, $vals);
                if (strpos($slug, 'carga') !== false) $kg_labels = array_merge($kg_labels, $vals);
            }

            $mats = self::materials($pid, $title, $cats, $tags, $attrs);
            $mkeys = array();
            foreach ($mats as $m) {
                $k = self::material_key($m);
                if ($k === '') continue;
                $mkeys[] = $k;
                if (!isset($materials[$k])) $materials[$k] = $m;
            }
            $sku = (string) $p->get_sku();
            $all_skus = $sku !== '' ? array($sku) : array();
            if (!empty($var_skus_by_parent[$pid])) {
                $all_skus = array_values(array_unique(array_merge($all_skus, $var_skus_by_parent[$pid])));
            }
            $sku_text = implode(' ', $all_skus);

            $in_stock = $p->is_in_stock();
            if (function_exists('rep_product_is_out_of_stock')) {
                try { $in_stock = !call_user_func('rep_product_is_out_of_stock', $p); } catch (Throwable $e) {}
            }
            $lbl_info = self::product_labels($pid, $p, $in_stock, $cids, $tag_names);
            $lbl_names = $lbl_info['names'];
            $lkeys = array();
            foreach ($lbl_names as $ln) {
                $lk = self::label_key($ln);
                if ($lk === '') continue;
                $lkeys[] = $lk;
                if (!isset($labels_all[$lk])) $labels_all[$lk] = $ln;
            }

            $su_map = self::product_suelos($tags, $lbl_names);
            $skeys = array();
            $su_names = array();
            foreach ($su_map as $sk => $slabel) {
                $skeys[] = $sk;
                $su_names[] = $slabel;
                if (!isset($suelos_all[$sk])) $suelos_all[$sk] = $slabel;
            }

            // Todas las etiquetas del producto unificadas (Rotar Etiquetas + WooCommerce product_tag + Superficies)
            $all_tag_names = array_values(array_unique(array_filter(array_merge($lbl_names, $tag_names, $su_names))));
            $tkeys = array();
            foreach ($all_tag_names as $tn_lbl) {
                $tk = self::label_key($tn_lbl);
                if ($tk === '') continue;
                $tkeys[] = $tk;
                if (!isset($tags_all[$tk])) $tags_all[$tk] = $tn_lbl;
            }

            $dm = array();
            foreach ($diam_labels as $v) { $x = RBI_Text::number_value($v, 'diam'); if ($x > 0) $dm[] = round($x); }
            $kg = array();
            foreach ($kg_labels as $v) { $x = RBI_Text::number_value($v, 'kg'); if ($x > 0) $kg[] = $x; }

            // Sumar medidas presentes en el título o descripción corta sin borrar las de variaciones/atributos.
            $tm = RBI_Text::measures($title . ' ' . $short);
            if (!empty($tm['diam_mm'])) {
                foreach ($tm['diam_mm'] as $td) {
                    $td_r = round((float) $td);
                    if ($td_r > 0 && !in_array($td_r, $dm, true)) {
                        $dm[] = $td_r;
                        if (empty($diam_labels)) $diam_labels[] = 'Ø' . $td_r . ' MM';
                    }
                }
            }
            if (!empty($tm['kg'])) {
                foreach ($tm['kg'] as $tkg) {
                    $tkg_f = (float) $tkg;
                    if ($tkg_f > 0 && !in_array($tkg_f, $kg, true)) {
                        $kg[] = $tkg_f;
                        if (empty($kg_labels)) $kg_labels[] = 'Carga ' . round($tkg_f) . ' Kg';
                    }
                }
            }

            // Características estructurales y especiales (desde título, categorías, etiquetas, atributos y materiales).
            $attr_text = '';
            foreach ($attrs as $l => $vals) $attr_text .= ' ' . $l . ' ' . implode(' ', $vals);
            $feat_source = $title . ' ' . implode(' ', $cats) . ' ' . implode(' ', $tags) . ' ' . implode(' ', $lbl_names) . ' ' . $attr_text . ' ' . implode(' ', $mats) . ' ' . $short;
            $ft = array_flip(RBI_Text::tokens($feat_source));
            $fnorm = RBI_Text::norm($feat_source);
            $f = array();
            if (isset($ft['fren']) || isset($ft['bloque']) || isset($ft['trab'])) $f[] = 'freno';
            if (isset($ft['giratori']) || isset($ft['giratoria']) || isset($ft['loca']) || isset($ft['pivotant'])) $f[] = 'giratoria';
            if (isset($ft['fija']) || isset($ft['fijo']) || isset($ft['rigid'])) $f[] = 'fija';
            if (isset($ft['plac']) || isset($ft['platin'])) $f[] = 'placa';
            if (isset($ft['espig']) || isset($ft['vastag']) || isset($ft['pern']) || preg_match('/\b(con perno|montaje perno|espiga|vastago)\b/', $fnorm)) $f[] = 'espiga';
            if (isset($ft['inox']) || isset($ft['inoxidable']) || preg_match('/\b(inoxidable|inox|aisi)\b/', $fnorm)) $f[] = 'inox';
            if (isset($ft['neumatic']) || isset($ft['inflabl'])) $f[] = 'neumatica';
            if (isset($ft['impinchabl']) || isset($ft['antipinchaz']) || isset($ft['maciz'])) $f[] = 'impinchable';
            if (isset($ft['horn']) || isset($ft['fenolic']) || preg_match('/\balta temperatura\b/', $fnorm)) $f[] = 'alta_temp';

            $pmin = $pmax = 0;
            if ($p instanceof WC_Product_Variable) {
                $pmin = (float) $p->get_variation_price('min', true);
                $pmax = (float) $p->get_variation_price('max', true);
            } else {
                $pmin = $pmax = (float) $p->get_price();
            }

            $tag_extra_text = implode(' ', $tags) . ' ' . implode(' ', $lbl_names) . ' ' . implode(' ', $su_names);
            if (!empty($lbl_info['on_sale']) || in_array('oferta', $lkeys, true)) {
                $tag_extra_text .= ' oferta descuento rebaja promocion ' . (!empty($lbl_info['pct']) ? $lbl_info['pct'] . '% descuento' : '');
            }
            if (!$in_stock) {
                $tag_extra_text .= ' sin stock agotado';
            }
            $tag_toks = array_values(array_unique(RBI_Text::tokens($tag_extra_text)));

            $cat_full_text = implode(' ', $cats) . ' ' . implode(' ', $cat_slug_texts);
            $cat_toks = array_values(array_unique(RBI_Text::tokens($cat_full_text)));

            $w = array();
            self::add_weights($w, RBI_Text::tokens($desc . ' ' . $var_desc), 2.5);
            self::add_weights($w, RBI_Text::tokens($short), 3.5);
            self::add_weights($w, RBI_Text::tokens($attr_text), 3.8);
            self::add_weights($w, RBI_Text::tokens(implode(' ', $mats)), 5.0);
            self::add_weights($w, $tag_toks, 6.0);
            self::add_weights($w, $cat_toks, 6.2);
            self::add_weights($w, RBI_Text::tokens($title), 6.5);
            if ($sku_text !== '') self::add_weights($w, RBI_Text::tokens($sku_text), 7.0);
            foreach ($w as $tok => $_) $df[$tok] = isset($df[$tok]) ? $df[$tok] + 1 : 1;

            $title_toks = array_values(array_unique(RBI_Text::tokens($title)));
            $desc_toks  = array_values(array_unique(RBI_Text::tokens($full_desc_text)));

            // Clasificar si el producto es un Carro / Equipo completo ('carro') o una Rueda / Horquilla / Accesorio ('rueda').
            $title_norm_low = RBI_Text::norm($title);
            $cat_norm_low   = RBI_Text::norm($cat_full_text);
            $starts_wheel   = (bool) preg_match('/^(rueda|ruedas|rodaja|rodajas|garrucha|garruchas|horquilla|horquillas|polin|polines|llanta|llantas)\b/', $title_norm_low);
            $starts_carro   = (bool) preg_match('/^(carro|carros|yegua|yeguas|transpaleta|transpaletas|apilador|carretilla|carretillas)\b/', $title_norm_low);
            $in_carro_cat   = (bool) preg_match('/\b(carros|carro de carga|carros de carga|carros plegables|carro plegable|carros yegua|carro yegua)\b/', $cat_norm_low)
                              && !preg_match('/\bruedas para carro/', $cat_norm_low);
            $prod_type      = (!$starts_wheel && ($starts_carro || $in_carro_cat)) ? 'carro' : 'rueda';

            $items[$pid] = array(
                'o'    => $pos++,
                't'    => $title,
                'pt'   => $prod_type,
                'tw'   => $title_toks,
                'cw'   => $cat_toks,
                'tg'   => $all_tag_names,
                'tk'   => array_values(array_unique($tkeys)),
                'tgw'  => $tag_toks,
                'dw'   => $desc_toks,
                'dn'   => RBI_Text::cut(RBI_Text::norm($full_desc_text), 1500),
                'tn'   => RBI_Text::norm($title . ' ' . $sku_text . ' ' . $cat_full_text . ' ' . $tag_extra_text),
                'sku'  => $sku,
                'skus' => $all_skus,
                'c'    => array_values(array_unique($cats)),
                'cid'  => array_values(array_unique($cids)),
                'lb'   => array_values(array_unique($lkeys)),
                'su'   => array_values(array_unique($skeys)),
                'm'    => $mats,
                'mk'   => array_values(array_unique($mkeys)),
                'a'    => $attrs,
                'x'    => RBI_Text::cut($short !== '' ? $short . ($desc !== '' ? ' — ' . $desc : '') : $full_desc_text, 600),
                'dl'   => array_values(array_unique($diam_labels)),
                'kl'   => array_values(array_unique($kg_labels)),
                'dm'   => array_values(array_unique($dm)),
                'kg'   => array_values(array_unique($kg)),
                'f'    => array_values(array_unique($f)),
                'pmin' => $pmin,
                'pmax' => $pmax,
                'stk'  => $in_stock ? 1 : 0,
                'w'    => $w,
            );
        }

        asort($materials, SORT_NATURAL | SORT_FLAG_CASE);
        asort($cats_all, SORT_NATURAL | SORT_FLAG_CASE);
        asort($labels_all, SORT_NATURAL | SORT_FLAG_CASE);
        asort($tags_all, SORT_NATURAL | SORT_FLAG_CASE);

        // Conservar el orden canónico de Superficies de Trabajo y mostrar solo las detectadas en productos.
        $suelos_ordered = array();
        foreach (self::suelo_map() as $sk => $slabel) {
            if (isset($suelos_all[$sk])) $suelos_ordered[$sk] = $slabel;
        }

        $cat = array('v' => 9, 'n' => count($items), 'df' => $df, 'items' => $items, 'materials' => $materials, 'cats' => $cats_all, 'cats_meta' => $cats_meta, 'labels' => $labels_all, 'suelos' => $suelos_ordered, 'tags' => $tags_all);
        update_option(self::OPT, $cat, false);
        update_option(self::OPT_TS, time(), false);
        self::$mem = $cat;
        if (class_exists('RBI_LLMs')) {
            RBI_LLMs::write_static_files();
        }
        return $cat;
    }
}
