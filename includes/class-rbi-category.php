<?php
/**
 * Filtro horizontal por categoría (independiente del buscador principal).
 *
 *  - Se inserta automáticamente sobre el listado de productos de cada categoría de WooCommerce
 *    (hook woocommerce_before_shop_loop, compatible con el widget "Archive Products" de Elementor).
 *  - Mientras no haya filtros activos se ve el listado normal de la categoría; al filtrar, se muestran
 *    solo los productos de esa categoría (incluye subcategorías) que cumplen los filtros.
 *  - Shortcode para ubicarlo manualmente: [rotar_filtros_categoria]  ó  [rotar_filtros_categoria cat="slug-o-id"]
 */
if (!defined('ABSPATH'))
    exit;

class RBI_Category
{

    private static $done = false;

    public static function init()
    {
        add_shortcode('rotar_filtros_categoria', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 20);
        add_action('woocommerce_before_shop_loop', array(__CLASS__, 'auto'), 5);
    }

    public static function auto_enabled()
    {
        $s = RBI_Plugin::settings();
        return !empty($s['enabled']) && !empty($s['cat_filtros']);
    }

    public static function register()
    {
        if (!wp_style_is('rbi', 'registered'))
            RBI_Front::register();
        wp_register_script('rbi-cat', RBI_URL . 'assets/rbi-cat.js', array(), RBI_VERSION, true);
    }

    /** En categorías, carga los estilos en el <head> para evitar saltos visuales. */
    public static function assets()
    {
        self::register();
        if (function_exists('is_product_category') && is_product_category() && self::auto_enabled()) {
            wp_enqueue_style('rbi');
            wp_enqueue_script('rbi-cat');
        }
    }

    public static function auto()
    {
        if (self::$done || !self::auto_enabled())
            return;
        if (!function_exists('is_product_category') || !is_product_category())
            return;
        $term = get_queried_object();
        if (!$term || empty($term->term_id))
            return;
        echo self::render((int) $term->term_id, true); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public static function shortcode($atts = array())
    {
        $s = RBI_Plugin::settings();
        if (empty($s['enabled']) || self::$done)
            return '';
        $atts = shortcode_atts(array('cat' => ''), $atts, 'rotar_filtros_categoria');
        $cat_id = 0;
        $raw = trim((string) $atts['cat']);
        if ($raw !== '') {
            $t = ctype_digit($raw) ? get_term((int) $raw, 'product_cat') : get_term_by('slug', sanitize_title($raw), 'product_cat');
            if ($t && !is_wp_error($t))
                $cat_id = (int) $t->term_id;
        } elseif (function_exists('is_product_category') && is_product_category()) {
            $q = get_queried_object();
            if ($q && !empty($q->term_id))
                $cat_id = (int) $q->term_id;
        }
        if (!$cat_id)
            return '';
        return self::render($cat_id, false);
    }

    private static function render($cat_id, $auto)
    {
        $term = get_term($cat_id, 'product_cat');
        if (!$term || is_wp_error($term))
            return '';
        self::$done = true;

        self::register();
        wp_enqueue_style('rbi');
        wp_enqueue_script('rbi-cat');

        $children = get_terms(array('taxonomy' => 'product_cat', 'parent' => $cat_id, 'hide_empty' => true, 'fields' => 'ids'));
        $has_children = is_array($children) && !empty($children);

        $fields = array(
            'material' => array('Material', 'Todos'),
            'diam' => array('Diámetro', 'Todos'),
            'carga' => array('Capacidad de carga', 'Cualquiera'),
            'tipo' => array('Tipo de rueda', 'Todas'),
            'suelo' => array('Superficie de trabajo', 'Todas'),
            'montaje' => array('Montaje', 'Todos'),
        );
        if ($has_children)
            $fields = array('cat' => array('Subcategoría', 'Todas')) + $fields;

        ob_start();
        ?>
        <div class="rbi-cat" data-rbi-cat data-cat="<?php echo (int) $cat_id; ?>" data-auto="<?php echo $auto ? '1' : '0'; ?>"
            data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-per-page="<?php echo (int) apply_filters('rbi_cat_per_page', 20); ?>">
            <div class="rbi-cat__bar" role="search" aria-label="<?php echo esc_attr('Filtrar productos de ' . $term->name); ?>">
                <div class="rbi-cat__head">
                    <strong class="rbi-cat__title">Filtrar <?php echo esc_html($term->name); ?></strong>
                    <span class="rbi-cat__status" aria-live="polite"></span>
                    <button type="button" class="rbi-cat__toggle" aria-expanded="false">☰ Filtros</button>
                </div>
                <div class="rbi-cat__fields">
                    <label class="rbi-cat__field rbi-cat__field--q"><span>Buscar en esta categoría</span>
                        <input type="search" data-cf="q" maxlength="120" autocomplete="off"
                            placeholder="Ej: 100 mm con freno"></label>
                    <?php foreach ($fields as $k => $f): ?>
                        <label class="rbi-cat__field"><span><?php echo esc_html($f[0]); ?></span>
                            <select data-cf="<?php echo esc_attr($k); ?>" data-empty="<?php echo esc_attr($f[1]); ?>">
                                <option value=""><?php echo esc_html($f[1]); ?></option>
                            </select></label>
                    <?php endforeach; ?>
                    <div class="rbi-cat__actions">
                        <button type="button" class="rbi-cat__clear" disabled>✕ Limpiar</button>
                    </div>
                </div>
            </div>
            <div class="rbi-cat__results" hidden>
                <div class="rbi-cat__toolbar">
                    <span class="rbi-cat__total"></span>
                    <select class="rbi-cat__sort" aria-label="Ordenar">
                        <option value="relevancia">Más relevantes</option>
                        <option value="precio-asc">Precio: menor a mayor</option>
                        <option value="precio-desc">Precio: mayor a menor</option>
                        <option value="diam-asc">Diámetro: menor a mayor</option>
                        <option value="diam-desc">Diámetro: mayor a menor</option>
                        <option value="carga-desc">Mayor capacidad de carga</option>
                        <option value="nombre">Nombre A–Z</option>
                    </select>
                </div>
                <div class="rbi-grid"></div>
                <div class="rbi-more-wrap"><button type="button" class="rbi-more" hidden>Ver más productos</button></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
