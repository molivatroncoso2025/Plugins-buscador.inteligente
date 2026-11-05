<?php
/**
 * Shortcodes y assets del front.
 *  [rotar_buscador_inteligente]                 → buscador completo (barra + filtros + resultados)
 *  [rotar_buscador_inteligente estilo="barra"]  → barra compacta; filtros y resultados se despliegan al buscar
 *  Opciones: titulo="..."  filtros="no"  ia="no"
 */
if (!defined('ABSPATH'))
    exit;

class RBI_Front
{

    private static $count = 0;

    public static function init()
    {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register'));
        add_shortcode('rotar_buscador_inteligente', array(__CLASS__, 'shortcode'));
        // Alias por compatibilidad con la versión anterior del asesor.
        if (!shortcode_exists('rotar_asesor_ia')) {
            add_shortcode('rotar_asesor_ia', array(__CLASS__, 'shortcode_barra'));
        }
    }

    public static function register()
    {
        wp_register_style('rbi', RBI_URL . 'assets/rbi.css', array(), RBI_VERSION);
        wp_register_script('rbi', RBI_URL . 'assets/rbi.js', array(), RBI_VERSION, true);
        $s = RBI_Plugin::settings();
        wp_localize_script('rbi', 'RBI_CFG', array(
            'ajax' => admin_url('admin-ajax.php'),
            'whatsapp' => $s['whatsapp'],
        ));
    }

    public static function shortcode_barra($atts = array())
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['estilo'] = 'barra';
        return self::shortcode($atts);
    }

    public static function shortcode($atts = array())
    {
        $s = RBI_Plugin::settings();
        if (empty($s['enabled']))
            return '';

        $atts = shortcode_atts(array(
            'estilo' => 'completo',
            'titulo' => null,
            'filtros' => 'si',
            'ia' => 'si',
            'cantidad' => 5,
        ), $atts, 'rotar_buscador_inteligente');
        $cantidad = max(1, min(50, (int) $atts['cantidad']));

        $estilo = $atts['estilo'] === 'barra' ? 'barra' : 'completo';
        $titulo = $atts['titulo'] !== null ? $atts['titulo'] : ($estilo === 'barra' ? '' : $s['titulo']);
        $filtros = !in_array(strtolower($atts['filtros']), array('no', '0', 'false'), true);
        $ia_ok = RBI_Plugin::ai_available() && !in_array(strtolower($atts['ia']), array('no', '0', 'false'), true);

        if (!wp_script_is('rbi', 'registered'))
            self::register();
        wp_enqueue_style('rbi');
        wp_enqueue_script('rbi');
        self::$count++;

        $uid = 'rbi-' . self::$count;

        ob_start();
        ?>
        <div class="rbi rbi--<?php echo esc_attr($estilo); ?>" id="<?php echo esc_attr($uid); ?>" data-rbi
            data-estilo="<?php echo esc_attr($estilo); ?>" data-ia="<?php echo $ia_ok ? '1' : '0'; ?>"
            data-filtros="<?php echo $filtros ? '1' : '0'; ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-wa="<?php echo esc_attr($s['whatsapp']); ?>" data-cantidad="<?php echo (int) $cantidad; ?>">
            <div class="rbi-top">
                <?php if ($titulo !== ''): ?>
                    <h2 class="rbi-title"><?php echo esc_html($titulo); ?></h2>
                <?php endif; ?>
                <form class="rbi-bar" autocomplete="off" role="search">
                    <div class="rbi-bar__box">
                        <span class="rbi-bar__icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                                stroke-linecap="round">
                                <circle cx="11" cy="11" r="7" />
                                <path d="m20 20-3.5-3.5" />
                            </svg>
                        </span>
                        <input type="search" class="rbi-bar__input" maxlength="400" aria-label="Buscar productos"
                            placeholder="<?php echo esc_attr($ia_ok ? 'Busca por nombre, etiqueta o descripción, o cuéntanos qué necesitas…' : 'Buscar por nombre, etiqueta, descripción, material o medida…'); ?>">
                    </div>
                    <button type="submit" class="rbi-bar__btn">BUSCAR</button>
                </form>
            </div>

            <div class="rbi-body" <?php echo $estilo === 'barra' ? ' hidden' : ''; ?>>
                <?php if ($filtros): ?>
                    <aside class="rbi-filters" aria-label="Filtros avanzados">
                        <div class="rbi-filters__head">
                            <strong>Filtros avanzados</strong>
                            <button type="button" class="rbi-filters__close" aria-label="Cerrar filtros">×</button>
                        </div>
                        <label class="rbi-field"><span>Material de fabricación</span>
                            <select data-f="material">
                                <option value="">Todos</option>
                            </select></label>
                        <label class="rbi-field"><span>Diámetro</span>
                            <select data-f="diam">
                                <option value="">Todos</option>
                            </select></label>
                        <label class="rbi-field"><span>Capacidad de Carga</span>
                            <select data-f="carga">
                                <option value="">Cualquiera</option>
                            </select></label>
                        <label class="rbi-field"><span>Categoría</span>
                            <select data-f="cat">
                                <option value="">Todas</option>
                            </select></label>
                        <label class="rbi-field"><span>Tipo de rueda</span>
                            <select data-f="tipo">
                                <option value="">Todas</option>
                            </select></label>
                        <label class="rbi-field"><span>Superficies de Trabajo</span>
                            <select data-f="suelo">
                                <option value="">Todas</option>
                            </select></label>
                        <label class="rbi-field"><span>Montaje</span>
                            <select data-f="montaje">
                                <option value="">Todos</option>
                            </select></label>
                        <div class="rbi-clear-wrap"><button type="button" class="rbi-clear">✕ Limpiar filtros</button></div>
                        <button type="button" class="rbi-filters__apply">Ver resultados</button>
                    </aside>
                <?php endif; ?>

                <div class="rbi-main">
                    <div class="rbi-thread" aria-live="polite"></div>
                    <div class="rbi-toolbar">
                        <span class="rbi-count"></span>
                        <div class="rbi-toolbar__right">
                            <?php if ($filtros): ?><button type="button" class="rbi-filters__open">☰
                                    Filtros</button><?php endif; ?>
                            <select class="rbi-sort" aria-label="Ordenar">
                                <option value="relevancia">Más relevantes</option>
                                <option value="precio-asc">Precio: menor a mayor</option>
                                <option value="precio-desc">Precio: mayor a menor</option>
                                <option value="diam-asc">Diámetro: menor a mayor</option>
                                <option value="diam-desc">Diámetro: mayor a menor</option>
                                <option value="carga-desc">Mayor capacidad de carga</option>
                                <option value="nombre">Nombre A–Z</option>
                            </select>
                        </div>
                    </div>
                    <div class="rbi-grid"></div>
                    <div class="rbi-more-wrap"><button type="button" class="rbi-more" hidden>Ver más productos</button></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

