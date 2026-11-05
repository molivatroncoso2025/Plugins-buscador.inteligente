<?php
/**
 * Plugin Name: Rotar Buscador Inteligente (Filtros avanzados + Asesor IA)
 * Description: Buscador avanzado de ruedas y productos: búsqueda por nombre, descripción y etiquetas en lenguaje natural, aprendizaje de las búsquedas de los clientes, filtros técnicos (material, diámetro, carga, categoría, tipo de rueda, superficies de trabajo, montaje) y asesoría con IA (Google Gemini u OpenAI). Shortcode: [rotar_buscador_inteligente]
 * Version: 1.8.0
 * Author: LaGencia
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: rotar-buscador-inteligente
 */

if (!defined('ABSPATH'))
    exit;

define('RBI_VERSION', '1.8.6');
define('RBI_FILE', __FILE__);
define('RBI_DIR', plugin_dir_path(__FILE__));
define('RBI_URL', plugin_dir_url(__FILE__));

require_once RBI_DIR . 'includes/class-rbi-text.php';
require_once RBI_DIR . 'includes/class-rbi-learn.php';
require_once RBI_DIR . 'includes/class-rbi-catalog.php';
require_once RBI_DIR . 'includes/class-rbi-ai.php';
require_once RBI_DIR . 'includes/class-rbi-search.php';
require_once RBI_DIR . 'includes/class-rbi-front.php';
require_once RBI_DIR . 'includes/class-rbi-category.php';
require_once RBI_DIR . 'includes/class-rbi-admin.php';
require_once RBI_DIR . 'includes/class-rbi-llms.php';

final class RBI_Plugin
{

    const OPT_SETTINGS = 'rbi_settings';
    const OPT_LOG = 'rbi_search_log';

    public static function defaults()
    {
        return array(
            'enabled' => 1,
            'provider' => 'gemini',
            'api_key' => '',
            'model' => '',
            'contexto' => '',
            'sinonimos' => '',
            'whatsapp' => '',
            'rate_limit' => 30,
            'max_candidatos' => 20,
            'max_resultados' => 8,
            'per_page' => 12,
            'cat_filtros' => 1,
            'titulo' => 'Encuentra la rueda que necesitas',
            'chips' => "Ruedas con freno para carro de 300 kg\nRueda silenciosa que no raye el piso\nRueda para horno o alta temperatura\nRueda de 100 mm de poliuretano\nRuedas para exterior o piso irregular",
        );
    }

    /** Clave definida opcionalmente en wp-config.php: define('RBI_API_KEY', '...'); */
    public static function config_key()
    {
        return defined('RBI_API_KEY') ? trim((string) constant('RBI_API_KEY')) : '';
    }

    public static function settings()
    {
        $s = get_option(self::OPT_SETTINGS, array());
        $s = wp_parse_args(is_array($s) ? $s : array(), self::defaults());
        // Detecta el proveedor según el formato de la clave (sk-... = OpenAI; el resto = Google Gemini).
        $key = self::config_key() !== '' ? self::config_key() : trim((string) $s['api_key']);
        if ($key !== '') {
            $s['provider'] = strpos($key, 'sk-') === 0 ? 'openai' : 'gemini';
        }
        return $s;
    }

    public static function api_key()
    {
        if (self::config_key() !== '')
            return self::config_key();
        $s = self::settings();
        return trim((string) $s['api_key']);
    }

    public static function model()
    {
        $s = self::settings();
        $m = trim((string) $s['model']);
        // Un modelo de OpenAI no sirve con clave Gemini y viceversa.
        if ($m !== '' && (($s['provider'] === 'gemini') === (stripos($m, 'gemini') === 0)))
            return $m;
        return $s['provider'] === 'openai' ? 'gpt-4o-mini' : 'gemini-2.5-flash';
    }

    public static function ai_available()
    {
        $s = self::settings();
        return !empty($s['enabled']) && self::api_key() !== '' && RBI_AI::down_reason() === '' && !RBI_AI::is_blocked();
    }

    public static function log_query($q, $mode, $n)
    {
        $log = get_option(self::OPT_LOG, array());
        if (!is_array($log))
            $log = array();
        $log[] = array('t' => time(), 'q' => RBI_Text::cut($q, 200), 'm' => $mode, 'n' => (int) $n);
        if (count($log) > 200)
            $log = array_slice($log, -200);
        update_option(self::OPT_LOG, $log, false);
    }

    public static function maybe_migrate()
    {
        // Crea los ajustes y migra los del asesor IA anterior (si existían).
        if (get_option(self::OPT_SETTINGS) !== false)
            return;
        $old = get_option('rotar_bt_ia_settings');
        $new = self::defaults();
        if (is_array($old)) {
            foreach (array('provider', 'api_key', 'model', 'contexto', 'whatsapp', 'rate_limit', 'max_candidatos', 'max_resultados') as $k) {
                if (isset($old[$k]) && $old[$k] !== '')
                    $new[$k] = $old[$k];
            }
        }
        add_option(self::OPT_SETTINGS, $new, '', false);
    }

    /** Elimina datos huérfanos de la versión de prueba integrada en el Buscador Técnico. */
    public static function cleanup_legacy()
    {
        if (get_option('rbi_legacy_cleaned'))
            return;
        foreach (array('rotar_bt_ia_catalog_v1', 'rotar_bt_ia_catalog_ts_v1', 'rotar_bt_ia_catalog_v2', 'rotar_bt_ia_catalog_ts_v2', 'rotar_bt_ia_log', 'rotar_bt_ia_settings') as $o) {
            delete_option($o);
        }
        update_option('rbi_legacy_cleaned', 1, false);
    }

    public static function init()
    {
        if (!class_exists('WooCommerce')) {
            if (is_admin()) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p><strong>Rotar Buscador Inteligente</strong> requiere WooCommerce activo.</p></div>';
                });
            }
            return;
        }
        self::maybe_migrate();
        if (is_admin())
            add_action('admin_init', array(__CLASS__, 'cleanup_legacy'));

        RBI_Learn::init();
        RBI_Catalog::init();
        RBI_Search::init();
        RBI_Front::init();
        RBI_Category::init();
        RBI_LLMs::init();
        if (is_admin())
            RBI_Admin::init();
    }
}

add_action('plugins_loaded', array('RBI_Plugin', 'init'));

register_activation_hook(__FILE__, array('RBI_Plugin', 'maybe_migrate'));

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=rotar-buscador-inteligente')) . '">Ajustes</a>');
    return $links;
});
