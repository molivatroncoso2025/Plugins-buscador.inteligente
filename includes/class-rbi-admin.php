<?php
/**
 * Panel de administración: ajustes, diagnóstico de conexión, índice y registro de búsquedas.
 */
if (!defined('ABSPATH')) exit;

class RBI_Admin {

    const SLUG = 'rotar-buscador-inteligente';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
        add_action('admin_init', array(__CLASS__, 'actions'));
        add_action('wp_ajax_rbi_admin_test', array(__CLASS__, 'ajax_test'));
        add_action('wp_ajax_rbi_admin_test_llms', array(__CLASS__, 'ajax_test_llms'));
    }

    public static function menu() {
        add_menu_page('Buscador Inteligente', 'Buscador IA', 'manage_options', self::SLUG, array(__CLASS__, 'page'), 'dashicons-search', 56);
    }

    public static function register() {
        register_setting('rbi_settings_group', RBI_Plugin::OPT_SETTINGS, array(
            'type'              => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize'),
            'default'           => RBI_Plugin::defaults(),
        ));
    }

    public static function sanitize($in) {
        $old = RBI_Plugin::settings();
        $in = is_array($in) ? $in : array();
        $out = RBI_Plugin::defaults();

        $out['enabled']        = !empty($in['enabled']) ? 1 : 0;
        $out['cat_filtros']    = !empty($in['cat_filtros']) ? 1 : 0;
        $out['provider']       = (isset($in['provider']) && $in['provider'] === 'openai') ? 'openai' : 'gemini';
        $out['model']          = isset($in['model']) ? sanitize_text_field($in['model']) : '';
        $out['contexto']       = isset($in['contexto']) ? sanitize_textarea_field($in['contexto']) : '';
        $out['sinonimos']      = isset($in['sinonimos']) ? sanitize_textarea_field($in['sinonimos']) : '';
        $out['whatsapp']       = isset($in['whatsapp']) ? preg_replace('/[^0-9]/', '', (string) $in['whatsapp']) : '';
        $out['rate_limit']     = isset($in['rate_limit']) ? max(0, min(500, (int) $in['rate_limit'])) : 30;
        $out['max_candidatos'] = isset($in['max_candidatos']) ? max(5, min(40, (int) $in['max_candidatos'])) : 20;
        $out['max_resultados'] = isset($in['max_resultados']) ? max(1, min(20, (int) $in['max_resultados'])) : 8;
        $out['per_page']       = isset($in['per_page']) ? max(4, min(48, (int) $in['per_page'])) : 12;
        $out['titulo']         = isset($in['titulo']) ? sanitize_text_field($in['titulo']) : '';
        $out['chips']          = isset($in['chips']) ? sanitize_textarea_field($in['chips']) : '';

        $new_key = isset($in['api_key']) ? trim(sanitize_text_field($in['api_key'])) : '';
        if (!empty($in['api_key_clear'])) $out['api_key'] = '';
        elseif ($new_key !== '') $out['api_key'] = $new_key;
        else $out['api_key'] = $old['api_key'];

        // El proveedor se detecta por el formato de la clave; una clave nueva quita la pausa de la IA.
        $out['provider'] = strpos($out['api_key'], 'sk-') === 0 ? 'openai' : 'gemini';
        if ($new_key !== '' || !empty($in['api_key_clear'])) RBI_AI::clear_down();
        $out['chips'] = $old['chips'];
        return $out;
    }

    public static function actions() {
        if (!current_user_can('manage_options') || !isset($_GET['page']) || $_GET['page'] !== self::SLUG) return;

        if (isset($_GET['rbi_rebuild'])) {
            check_admin_referer('rbi_rebuild');
            RBI_Catalog::clear();
            RBI_Catalog::build();
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&rbi_msg=rebuilt'));
            exit;
        }
        if (isset($_GET['rbi_clear_log'])) {
            check_admin_referer('rbi_clear_log');
            delete_option(RBI_Plugin::OPT_LOG);
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&rbi_msg=log'));
            exit;
        }
        if (isset($_GET['rbi_clear_learn'])) {
            check_admin_referer('rbi_clear_learn');
            if (class_exists('RBI_Learn')) RBI_Learn::clear();
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&rbi_msg=learn'));
            exit;
        }
    }

    public static function ajax_test() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('msg' => 'Sin permisos'));
        check_ajax_referer('rbi_admin_test');
        $r = RBI_AI::test();
        if (is_wp_error($r)) wp_send_json_error(array('msg' => $r->get_error_message()));
        wp_send_json_success(array('msg' => 'Conexión correcta con ' . RBI_AI::host() . ' (modelo ' . RBI_Plugin::model() . ').'));
    }

    public static function ajax_test_llms() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('msg' => 'Sin permisos'));
        check_ajax_referer('rbi_admin_test');
        if (!class_exists('RBI_LLMs')) wp_send_json_error(array('msg' => 'Módulo RBI_LLMs no disponible.'));
        $cat = RBI_Catalog::get();
        $total = isset($cat['items']) && is_array($cat['items']) ? count($cat['items']) : 0;
        if ($total === 0) {
            $cat = RBI_Catalog::build();
            $total = isset($cat['items']) && is_array($cat['items']) ? count($cat['items']) : 0;
        }
        RBI_LLMs::write_static_files($cat);
        $txt_full = RBI_LLMs::build_llms_txt(true, $cat);
        $f_short  = file_exists(ABSPATH . 'llms.txt');
        $f_full   = file_exists(ABSPATH . 'llms-full.txt');
        if ($total === 0 || strlen($txt_full) < 200) {
            wp_send_json_error(array('msg' => 'No se encontraron productos publicados para indexar.'));
        }
        $kb = round(strlen($txt_full) / 1024, 1);
        $msg = sprintf(
            'Todo operativo: %d productos indexados (%s KB en llms-full.txt)%s.',
            $total,
            $kb,
            ($f_short && $f_full) ? ' · Archivos físicos generados en la raíz' : ' · Sirviendo dinámicamente'
        );
        wp_send_json_success(array('msg' => $msg));
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;
        $s = RBI_Plugin::settings();
        $opt = RBI_Plugin::OPT_SETTINGS;
        $has_key = RBI_Plugin::api_key() !== '';
        $cat = get_option(RBI_Catalog::OPT);
        $n = is_array($cat) && isset($cat['n']) ? (int) $cat['n'] : 0;
        $ts = RBI_Catalog::timestamp();
        $log = get_option(RBI_Plugin::OPT_LOG, array());
        $blocked = $has_key && RBI_AI::is_blocked();
        $host = RBI_AI::host();
        $base = admin_url('admin.php?page=' . self::SLUG);
        ?>
        <div class="wrap">
            <h1>🔎 Rotar Buscador Inteligente <small style="font-size:12px;color:#777">v<?php echo esc_html(RBI_VERSION); ?></small></h1>
            <?php settings_errors(RBI_Plugin::OPT_SETTINGS); ?>
            <?php if (isset($_GET['rbi_msg']) && $_GET['rbi_msg'] === 'rebuilt'): ?><div class="notice notice-success"><p>Índice reconstruido.</p></div><?php endif; ?>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px;min-width:260px">
                    <strong>Estado del asesor IA</strong><br>
                    <?php if (!$s['enabled']): ?>
                        <span style="color:#a00">● Buscador desactivado</span>
                    <?php elseif (!$has_key): ?>
                        <span style="color:#b26b00">● Modo estándar (sin API key)</span><br><small>La búsqueda inteligente y los filtros funcionan; la asesoría IA requiere API key.</small>
                    <?php elseif ($blocked): ?>
                        <span style="color:#b26b00">● Modo estándar</span><br><small>El servidor no permite conectar con <?php echo esc_html($host); ?>.</small>
                    <?php elseif (RBI_AI::down_reason() !== ''): ?>
                        <span style="color:#b26b00">● Modo estándar (IA en pausa)</span><br><small><?php echo esc_html(RBI_Text::cut(RBI_AI::down_reason(), 160)); ?></small>
                    <?php else: ?>
                        <span style="color:#080">● IA activa</span> · <?php echo esc_html(($s['provider'] === 'openai' ? 'OpenAI' : 'Google Gemini') . ' · ' . RBI_Plugin::model()); ?>
                    <?php endif; ?>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px;min-width:260px">
                    <strong>Índice de productos</strong><br>
                    <?php echo $ts ? esc_html($n . ' productos · ' . date_i18n('d-m-Y H:i', $ts)) : 'Se generará en la primera búsqueda'; ?><br>
                    <a class="button" style="margin-top:6px" href="<?php echo esc_url(wp_nonce_url($base . '&rbi_rebuild=1', 'rbi_rebuild')); ?>">Reconstruir índice</a>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px;min-width:260px">
                    <strong>Shortcodes</strong><br>
                    <code>[rotar_buscador_inteligente]</code> completo<br>
                    <code>[rotar_buscador_inteligente estilo="barra"]</code> barra compacta<br>
                    <small>Opciones: <code>titulo="..."</code> <code>filtros="no"</code> <code>ia="no"</code> <code>cantidad="5"</code></small>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px;min-width:280px">
                    <strong>Base de Datos para IAs (ChatGPT, Gemini, Claude, Perplexity)</strong><br>
                    <span style="color:#080">● Activo y público</span><br>
                    <small>
                        <a href="<?php echo esc_url(home_url('/llms.txt')); ?>" target="_blank" rel="noopener"><code>/llms.txt</code></a> ·
                        <a href="<?php echo esc_url(home_url('/llms-full.txt')); ?>" target="_blank" rel="noopener"><code>/llms-full.txt</code></a> ·
                        <a href="<?php echo esc_url(rest_url('rotar/v1/ai-catalog')); ?>" target="_blank" rel="noopener"><code>JSON IA</code></a>
                    </small><br>
                    <button type="button" class="button" style="margin-top:6px" id="rbi-test-llms">Verificar Base IA</button>
                    <div id="rbi-test-llms-out" style="margin-top:4px;font-size:12px"></div>
                </div>
            </div>

            <?php if ($blocked): ?>
                <p class="description" style="max-width:900px">Para usar el asesor IA, agrega <code><?php echo esc_html($host); ?></code> a <code>WP_ACCESSIBLE_HOSTS</code> en <code>wp-config.php</code>. El buscador funciona igual sin IA.</p>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('rbi_settings_group'); ?>
                <h2>Inteligencia artificial</h2>
                <table class="form-table" role="presentation">
                    <tr><th>Activar buscador</th><td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[enabled]" value="1" <?php checked($s['enabled'], 1); ?>> Mostrar el buscador en los shortcodes</label></td></tr>
                    <tr><th>Filtro en categorías</th><td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[cat_filtros]" value="1" <?php checked(!empty($s['cat_filtros'])); ?>> Mostrar un filtro horizontal sobre los productos de cada categoría</label>
                        <p class="description">Independiente del buscador. También puedes ubicarlo manualmente con <code>[rotar_filtros_categoria]</code>.</p></td></tr>
                    <tr><th>Proveedor IA</th><td>
                        <strong><?php echo $has_key ? esc_html($s['provider'] === 'openai' ? 'OpenAI' : 'Google Gemini') : '—'; ?></strong>
                        <p class="description">Se detecta automáticamente según la API key (las de OpenAI empiezan con <code>sk-</code>).</p></td></tr>
                    <tr><th>API key</th><td>
                        <?php if (RBI_Plugin::config_key() !== ''): ?>
                            <em>Definida en wp-config.php (RBI_API_KEY).</em>
                        <?php else: ?>
                            <input type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr($opt); ?>[api_key]" value="" placeholder="<?php echo $has_key ? '•••••••• guardada (deja vacío para mantenerla)' : 'Pega aquí tu API key'; ?>">
                            <?php if ($has_key): ?><label style="margin-left:8px"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[api_key_clear]" value="1"> Borrar</label><?php endif; ?>
                            <p class="description">Gemini: <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a> · OpenAI: <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a></p>
                        <?php endif; ?>
                        <?php if ($has_key): ?>
                            <p><button type="button" class="button" id="rbi-test">Probar conexión IA</button> <span id="rbi-test-out"></span></p>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th>Modelo</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[model]" value="<?php echo esc_attr($s['model']); ?>" placeholder="<?php echo esc_attr(RBI_Plugin::model()); ?>">
                        <p class="description">Vacío = modelo rápido y económico recomendado.</p></td></tr>
                    <tr><th>Conocimiento del negocio</th><td>
                        <textarea class="large-text" rows="5" name="<?php echo esc_attr($opt); ?>[contexto]" placeholder="Ej: Despachamos a todo Chile. Fabricamos ruedas a medida. Para cargas sobre 2.000 kg sugerir contacto con un ejecutivo..."><?php echo esc_textarea($s['contexto']); ?></textarea>
                        <p class="description">Información y criterios propios que el asesor debe considerar.</p></td></tr>
                    <tr><th>Entrenamiento de términos (sinónimos)</th><td>
                        <textarea class="large-text" rows="4" name="<?php echo esc_attr($opt); ?>[sinonimos]" placeholder="Un grupo de palabras equivalentes por línea, separadas por coma. Ej:&#10;yegua, carro de carga, diablito&#10;horquilla, soporte, base"><?php echo esc_textarea(isset($s['sinonimos']) ? $s['sinonimos'] : ''); ?></textarea>
                        <p class="description">Además del aprendizaje automático por clics de clientes, aquí puedes enseñarle equivalencias o jerga propia.</p></td></tr>
                    <tr><th>WhatsApp asesor humano</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[whatsapp]" value="<?php echo esc_attr($s['whatsapp']); ?>" placeholder="56912345678">
                        <p class="description">Opcional: muestra “Hablar con un asesor” con la consulta del cliente.</p></td></tr>
                    <tr><th>Límites</th><td>
                        Consultas IA por visitante cada 10 min <input type="number" min="0" max="500" style="width:75px" name="<?php echo esc_attr($opt); ?>[rate_limit]" value="<?php echo esc_attr($s['rate_limit']); ?>"><br>
                        Productos analizados por la IA <input type="number" min="5" max="40" style="width:75px" name="<?php echo esc_attr($opt); ?>[max_candidatos]" value="<?php echo esc_attr($s['max_candidatos']); ?>">
                        &nbsp; Productos recomendados <input type="number" min="1" max="20" style="width:75px" name="<?php echo esc_attr($opt); ?>[max_resultados]" value="<?php echo esc_attr($s['max_resultados']); ?>">
                        <p class="description">Las respuestas se guardan en caché: consultas repetidas no consumen IA.</p></td></tr>
                </table>

                <h2>Apariencia</h2>
                <table class="form-table" role="presentation">
                    <tr><th>Título</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[titulo]" value="<?php echo esc_attr($s['titulo']); ?>">
                        <p class="description">Se muestra sobre la barra en el estilo completo. Déjalo vacío para ocultarlo.</p></td></tr>
                    <tr><th>Productos visibles</th><td>PC: <strong>5</strong> productos por fila · Tablet y celular: <strong>4</strong>. El botón “Ver más productos” carga la misma cantidad. En PC puedes cambiarlo con <code>[rotar_buscador_inteligente cantidad="10"]</code>.</td></tr>
                </table>
                <?php submit_button('Guardar ajustes'); ?>
            </form>

            <?php
            $learned = class_exists('RBI_Learn') ? RBI_Learn::stats(25) : array();
            $items_map = is_array($cat) && isset($cat['items']) ? $cat['items'] : array();
            ?>
            <h2>Aprendizaje automático (clics de clientes)</h2>
            <?php if (empty($learned)): ?>
                <p class="description">A medida que los clientes busquen y entren a ver productos, el buscador y la IA irán aprendiendo qué productos prefieren para cada búsqueda y los subirán automáticamente en el ranking.</p>
            <?php else: ?>
                <p class="description">Productos que el buscador ha aprendido a priorizar según las elecciones reales de los clientes:</p>
                <table class="widefat striped" style="max-width:900px;margin-bottom:12px">
                    <thead><tr><th>Búsqueda</th><th>Clics totales</th><th>Productos preferidos por los clientes</th></tr></thead>
                    <tbody>
                    <?php foreach ($learned as $lrow):
                        $pnames = array();
                        foreach ($lrow['top'] as $pid => $cnt) {
                            $title = isset($items_map[$pid]['t']) ? $items_map[$pid]['t'] : ('#' . $pid);
                            $pnames[] = esc_html($title) . ' (' . (int) $cnt . ')';
                        }
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($lrow['q']); ?></strong></td>
                            <td><?php echo (int) $lrow['total']; ?></td>
                            <td><?php echo implode(' · ', $pnames); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url($base . '&rbi_clear_learn=1', 'rbi_clear_learn')); ?>">Reiniciar aprendizaje</a></p>
            <?php endif; ?>

            <h2>Últimas búsquedas de clientes</h2>
            <?php if (empty($log)): ?>
                <p>Aún no hay búsquedas registradas.</p>
            <?php else: ?>
                <p class="description">Las búsquedas con <strong style="color:#a00">0 resultados</strong> indican productos que faltan o descripciones que conviene mejorar.</p>
                <table class="widefat striped" style="max-width:900px">
                    <thead><tr><th>Fecha</th><th>Consulta</th><th>Modo</th><th>Resultados</th></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($log) as $row): ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('d-m-Y H:i', (int) $row['t'])); ?></td>
                            <td><?php echo esc_html($row['q']); ?></td>
                            <td><?php echo esc_html($row['m'] === 'ia' ? 'IA' : 'Estándar'); ?></td>
                            <td><?php echo (int) $row['n'] === 0 ? '<strong style="color:#a00">0</strong>' : (int) $row['n']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url($base . '&rbi_clear_log=1', 'rbi_clear_log')); ?>">Vaciar registro</a></p>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var nonce = '<?php echo esc_js(wp_create_nonce('rbi_admin_test')); ?>';
            function bindTest(btnId, outId, action) {
                var b = document.getElementById(btnId);
                if (!b) return;
                b.addEventListener('click', function () {
                    var out = document.getElementById(outId);
                    out.textContent = 'Verificando…'; out.style.color = '';
                    var fd = new FormData();
                    fd.append('action', action);
                    fd.append('_ajax_nonce', nonce);
                    fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (j) { out.textContent = (j.success ? '✅ ' : '❌ ') + (j.data && j.data.msg ? j.data.msg : ''); out.style.color = j.success ? '#080' : '#a00'; })
                        .catch(function (e) { out.textContent = '❌ ' + e; out.style.color = '#a00'; });
                });
            }
            bindTest('rbi-test', 'rbi-test-out', 'rbi_admin_test');
            bindTest('rbi-test-llms', 'rbi-test-llms-out', 'rbi_admin_test_llms');
        })();
        </script>
        <?php
    }
}

