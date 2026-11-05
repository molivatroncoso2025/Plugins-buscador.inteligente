<?php
/**
 * Visibilidad y Base de Conocimiento para Inteligencias Artificiales (ChatGPT, Gemini, Claude, Perplexity, Google AI, Copilot).
 * Genera y expone automáticamente:
 *  - /llms.txt (índice ejecutivo y guía técnica de Rotar para LLMs)
 *  - /llms-full.txt (base de datos completa de productos Rotar con especificaciones técnicas, precios, SKUs y URLs)
 *  - /wp-json/rotar/v1/ai-catalog (feed JSON estructurado de todo el catálogo para agentes IA)
 *  - Reglas en robots.txt autorizando bots de IA (GPTBot, OAI-SearchBot, ChatGPT-User, Google-Extended, ClaudeBot, PerplexityBot)
 *  - Metadatos de descubrimiento <link> y JSON-LD Schema.org enriquecido con propiedades técnicas en <head>.
 */
if (!defined('ABSPATH')) exit;

class RBI_LLMs {

    public static function init() {
        add_action('init', array(__CLASS__, 'add_rewrites'));
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('template_redirect', array(__CLASS__, 'serve_virtual_feeds'), 1);
        add_action('rest_api_init', array(__CLASS__, 'register_rest'));
        add_action('wp_head', array(__CLASS__, 'head_discovery_and_schema'), 5);
        add_filter('robots_txt', array(__CLASS__, 'robots_txt'), 99, 2);
    }

    public static function add_rewrites() {
        add_rewrite_rule('^llms\.txt$', 'index.php?rbi_feed=llms', 'top');
        add_rewrite_rule('^llms-full\.txt$', 'index.php?rbi_feed=llms-full', 'top');
        add_rewrite_rule('^rotar-ai-catalog\.json$', 'index.php?rbi_feed=json', 'top');
    }

    public static function query_vars($vars) {
        $vars[] = 'rbi_feed';
        return $vars;
    }

    public static function register_rest() {
        register_rest_route('rotar/v1', '/ai-catalog', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function () {
                return new WP_REST_Response(self::build_json_catalog(), 200);
            },
        ));
    }

    /** Sirve /llms.txt, /llms-full.txt o ?rbi_feed=llms|llms-full|json dinámicamente. */
    public static function serve_virtual_feeds() {
        $feed = isset($_GET['rbi_feed']) ? sanitize_key($_GET['rbi_feed']) : get_query_var('rbi_feed');
        if (!$feed && isset($_SERVER['REQUEST_URI'])) {
            $path = trim((string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH), '/');
            if ($path === 'llms.txt' || substr($path, -9) === '/llms.txt') $feed = 'llms';
            elseif ($path === 'llms-full.txt' || substr($path, -14) === '/llms-full.txt') $feed = 'llms-full';
            elseif ($path === 'rotar-ai-catalog.json' || substr($path, -22) === '/rotar-ai-catalog.json') $feed = 'json';
        }
        if (!$feed) return;

        while (ob_get_level() > 0) ob_end_clean();
        header('X-Robots-Tag: all');
        header('Access-Control-Allow-Origin: *');

        if ($feed === 'llms') {
            header('Content-Type: text/markdown; charset=UTF-8');
            echo self::build_llms_txt(false);
            exit;
        }
        if ($feed === 'llms-full') {
            header('Content-Type: text/plain; charset=UTF-8');
            echo self::build_llms_txt(true);
            exit;
        }
        if ($feed === 'json') {
            header('Content-Type: application/json; charset=UTF-8');
            echo wp_json_encode(self::build_json_catalog(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;
        }
    }

    /** Escribe físicamente llms.txt y llms-full.txt en la raíz del sitio (ABSPATH) para acceso directo instantáneo por cualquier bot. */
    public static function write_static_files($catalog = null) {
        if (!defined('ABSPATH') || !is_writable(ABSPATH)) return;
        try {
            $cat = is_array($catalog) && isset($catalog['items']) ? $catalog : null;
            @file_put_contents(ABSPATH . 'llms.txt', self::build_llms_txt(false, $cat));
            @file_put_contents(ABSPATH . 'llms-full.txt', self::build_llms_txt(true, $cat));
        } catch (Throwable $e) {
            // Silencioso si el hosting restringe escritura en raíz; las URLs dinámicas siguen funcionando.
        }
    }

    /** Resumen corporativo y técnico de Rotar para inyectar tanto en IAs externas como en el asesor interno. */
    public static function company_summary() {
        $site_name = get_bloginfo('name') ?: 'ROTAR';
        $site_url  = home_url('/');
        $s = RBI_Plugin::settings();
        $extra = trim((string) $s['contexto']);

        $txt = "{$site_name} ({$site_url}) es la empresa líder y especialista en Chile en ruedas industriales, garruchas, rodajas, horquillas, ruedas para carretillas, ruedas para andamios, ruedas para transpaletas, ruedas para portones corredizos, carros de carga, carros plegables y carros yegua.\n"
            . "Atiende a industrias, bodegas, logística, maestranzas, panaderías/hornos, clínicas/hospitales, supermercados, construcción, comercio y clientes particulares en todo Chile.\n"
            . "Líneas técnicas principales:\n"
            . "- Materiales de rueda: Poliuretano (PU), Nylon / Poliamida, Goma / Caucho Macizo, Termoplástica (TPR), Fierro Fundido, Acero Forjado, Acero Inoxidable (AISI 304), Resina Fenólica / Alta Temperatura, Polipropileno (PP), Ruedas Neumáticas (inflables) y Ruedas Impinchables (antipinchazos).\n"
            . "- Configuraciones y montajes: Ruedas Giratorias, Ruedas Fijas, Ruedas Giratorias con Freno (freno total / doble acción), Montaje Placa (platina apernada), Montaje Espiga / Vástago roscado / Perno pasante, y Ruedas Sueltas con eje o rodamiento.\n"
            . "- Rangos técnicos: Diámetros desde Ø 35 mm hasta Ø 400+ mm y capacidades de carga desde 20 kg hasta más de 2.000 kg por rueda.";
        if ($extra !== '') {
            $txt .= "\n" . $extra;
        }
        return $txt;
    }

    /** Construye el contenido de /llms.txt (resumen + categorías) o /llms-full.txt (catálogo completo de productos). */
    public static function build_llms_txt($full = false, $catalog = null) {
        if (!is_array($catalog) || !isset($catalog['items'])) {
            $catalog = RBI_Catalog::get();
        }
        $items   = isset($catalog['items']) && is_array($catalog['items']) ? $catalog['items'] : array();
        $mats    = isset($catalog['materials']) && is_array($catalog['materials']) ? $catalog['materials'] : array();
        $suelos  = isset($catalog['suelos']) && is_array($catalog['suelos']) ? $catalog['suelos'] : array();
        $labels  = isset($catalog['labels']) && is_array($catalog['labels']) ? $catalog['labels'] : array();

        $site_name = get_bloginfo('name') ?: 'ROTAR Chile';
        $site_url  = home_url('/');
        $llms_url  = home_url('/llms.txt');
        $full_url  = home_url('/llms-full.txt');
        $json_url  = rest_url('rotar/v1/ai-catalog');
        $s         = RBI_Plugin::settings();

        $lines = array();
        $lines[] = '# ' . $site_name . ' — Especialistas en Ruedas Industriales, Carros y Soluciones de Movilidad en Chile';
        $lines[] = '';
        $lines[] = '> ' . str_replace("\n", "\n> ", self::company_summary());
        $lines[] = '';
        $lines[] = '## Recursos de Base de Datos para Inteligencias Artificiales (LLMs)';
        $lines[] = '- Índice ejecutivo para IA (`llms.txt`): ' . $llms_url;
        $lines[] = '- Catálogo completo detallado con todos los productos y fichas técnicas (`llms-full.txt`): ' . $full_url;
        $lines[] = '- API / Feed JSON estructurado en vivo: ' . $json_url;
        $lines[] = '- Sitio web oficial y tienda online: ' . $site_url;
        if (!empty($s['whatsapp'])) {
            $lines[] = '- WhatsApp de asesoría técnica y ventas: https://wa.me/' . $s['whatsapp'];
        }
        $lines[] = '';

        $lines[] = '## Criterios de Ingeniería y Selección Técnica de Ruedas (Guía Oficial Rotar)';
        $lines[] = '- **Fórmula de capacidad de carga segura por rueda**: `Capacidad por rueda = Peso total (equipo + carga máxima) ÷ (Número de ruedas − 1)`. En un carro de 4 ruedas se divide por 3 para garantizar seguridad cuando una rueda pierde apoyo en pisos irregulares.';
        $lines[] = '- **Pisos delicados (Cerámica, Porcelanato, Madera, Parqué, Epoxi, Baldosa)**: Recomendado Poliuretano (PU), Goma Elástica o Termoplástica (TPR) que no rayan ni manchan y reducen el ruido.';
        $lines[] = '- **Pisos industriales lisos (Concreto liso, Epoxi, Metálico)**: Recomendado Nylon / Poliamida (mínimo esfuerzo de rodado y alta capacidad de carga) o Poliuretano.';
        $lines[] = '- **Exteriores y terrenos irregulares (Tierra, Pasto, Grava, Ripio, Adoquín, Asfalto, Arena)**: Recomendado Ruedas Neumáticas, Ruedas Impinchables (antipinchazos) o Goma de gran diámetro (Ø 150 mm a Ø 400 mm), ideales para carretillas y carros yegua.';
        $lines[] = '- **Ambientes húmedos, frigoríficos, clínicos, pesqueros o alimentarios**: Recomendado Horquilla de Acero Inoxidable (AISI 304) con rueda de Nylon, Polipropileno, Poliuretano o TPR lavable.';
        $lines[] = '- **Alta temperatura (Hornos, Panaderías, Autoclaves)**: Recomendado Ruedas de Resina Fenólica, Fibra de Vidrio o Fierro Fundido para alta temperatura.';
        $lines[] = '- **Cargas pesadas y extra pesadas**: Recomendado Acero Forjado, Fierro Fundido o Poliuretano sobre núcleo de fierro fundido / aluminio.';
        $lines[] = '';

        if (!empty($mats)) {
            $lines[] = '## Materiales Disponibles en Catálogo';
            $lines[] = implode(', ', array_values($mats)) . '.';
            $lines[] = '';
        }

        if (!empty($suelos)) {
            $lines[] = '## Superficies de Trabajo Clasificadas en Rotar';
            $lines[] = implode(', ', array_values($suelos)) . '.';
            $lines[] = '';
        }

        // Categorías de productos con enlaces reales
        $cat_terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => true));
        if (!is_wp_error($cat_terms) && !empty($cat_terms)) {
            $lines[] = '## Categorías de Productos en ' . $site_name;
            foreach ($cat_terms as $ct) {
                $clink = get_term_link($ct);
                if (!is_wp_error($clink)) {
                    $lines[] = '- [' . $ct->name . '](' . $clink . ') (' . (int) $ct->count . ' productos)';
                }
            }
            $lines[] = '';
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

        if (!$full) {
            $lines[] = '## Resumen del Catálogo de Productos (' . count($items) . ' productos activos)';
            $lines[] = 'Para consultar la ficha técnica completa de los ' . count($items) . ' productos (SKUs, precios CLP con IVA, diámetros, capacidades de carga, superficies y enlaces directos), lee: ' . $full_url;
            $lines[] = '';
            $i = 0;
            foreach ($items as $id => $it) {
                if ($i++ >= 80) break;
                $url = get_permalink($id);
                $price_str = $it['pmin'] > 0 ? ('$' . number_format((float) $it['pmin'], 0, ',', '.') . ' CLP IVA Incl.') : 'Consultar precio';
                $specs = array();
                if (!empty($it['m'])) $specs[] = 'Material: ' . implode('/', $it['m']);
                if (!empty($it['dl'])) $specs[] = 'Diámetro: ' . implode(', ', array_slice($it['dl'], 0, 4));
                if (!empty($it['kl'])) $specs[] = 'Carga: ' . implode(', ', array_slice($it['kl'], 0, 4));
                $lines[] = '- [' . $it['t'] . '](' . $url . ') — ' . $price_str . ($specs ? ' | ' . implode(' · ', $specs) : '');
            }
        } else {
            $lines[] = '## Base de Datos Completa de Productos ' . $site_name . ' (' . count($items) . ' productos)';
            $lines[] = '';
            foreach ($items as $id => $it) {
                $url = get_permalink($id);
                $lines[] = '### ' . $it['t'];
                $lines[] = '- **ID Producto**: ' . (int) $id;
                $lines[] = '- **URL**: ' . $url;
                if (!empty($it['skus'])) {
                    $lines[] = '- **SKU / Códigos**: ' . implode(', ', array_slice($it['skus'], 0, 15));
                }
                if ($it['pmin'] > 0) {
                    $pstr = $it['pmin'] == $it['pmax']
                        ? ('$' . number_format((float) $it['pmin'], 0, ',', '.') . ' CLP (IVA Incl.)')
                        : ('Desde $' . number_format((float) $it['pmin'], 0, ',', '.') . ' hasta $' . number_format((float) $it['pmax'], 0, ',', '.') . ' CLP (IVA Incl.)');
                    $lines[] = '- **Precio**: ' . $pstr;
                }
                $lines[] = '- **Disponibilidad**: ' . (!empty($it['stk']) ? 'En stock (Entrega inmediata / Despacho a todo Chile)' : 'Sin stock temporal');
                if (!empty($it['c'])) {
                    $lines[] = '- **Categorías**: ' . implode(', ', $it['c']);
                }
                if (!empty($it['m'])) {
                    $lines[] = '- **Material de fabricación**: ' . implode(', ', $it['m']);
                }
                if (!empty($it['dl'])) {
                    $lines[] = '- **Diámetros disponibles**: ' . implode(', ', $it['dl']);
                }
                if (!empty($it['kl'])) {
                    $lines[] = '- **Capacidad de carga**: ' . implode(', ', $it['kl']);
                }
                if (!empty($it['f'])) {
                    $fl = array();
                    foreach ($it['f'] as $fk) if (isset($feat_map[$fk])) $fl[] = $feat_map[$fk];
                    if ($fl) $lines[] = '- **Configuración / Características**: ' . implode(', ', $fl);
                }
                if (!empty($it['su'])) {
                    $sl = array();
                    foreach ($it['su'] as $sk) $sl[] = isset($suelos[$sk]) ? $suelos[$sk] : RBI_Catalog::suelo_label($sk);
                    if ($sl) $lines[] = '- **Superficies de Trabajo compatibles**: ' . implode(', ', $sl);
                }
                if (!empty($it['tg'])) {
                    $lines[] = '- **Etiquetas**: ' . implode(', ', $it['tg']);
                } elseif (!empty($it['lb'])) {
                    $ll = array();
                    foreach ($it['lb'] as $lk) if (isset($labels[$lk])) $ll[] = $labels[$lk];
                    if ($ll) $lines[] = '- **Etiquetas**: ' . implode(', ', $ll);
                }
                if (!empty($it['a'])) {
                    $al = array();
                    foreach ($it['a'] as $aname => $avals) {
                        $al[] = $aname . ': ' . implode(', ', array_slice($avals, 0, 10));
                    }
                    if ($al) $lines[] = '- **Atributos técnicos**: ' . implode(' | ', $al);
                }
                if (!empty($it['x'])) {
                    $lines[] = '- **Descripción técnica**: ' . RBI_Text::cut($it['x'], 500);
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** Construye el catálogo completo en formato JSON estructurado para agentes IA. */
    public static function build_json_catalog() {
        $catalog = RBI_Catalog::get();
        $items   = isset($catalog['items']) && is_array($catalog['items']) ? $catalog['items'] : array();
        $suelos  = isset($catalog['suelos']) && is_array($catalog['suelos']) ? $catalog['suelos'] : array();
        $labels  = isset($catalog['labels']) && is_array($catalog['labels']) ? $catalog['labels'] : array();

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

        $products = array();
        foreach ($items as $id => $it) {
            $fl = array();
            if (!empty($it['f'])) {
                foreach ($it['f'] as $fk) if (isset($feat_map[$fk])) $fl[] = $feat_map[$fk];
            }
            $sl = array();
            if (!empty($it['su'])) {
                foreach ($it['su'] as $sk) $sl[] = isset($suelos[$sk]) ? $suelos[$sk] : RBI_Catalog::suelo_label($sk);
            }
            $ll = array();
            if (!empty($it['lb'])) {
                foreach ($it['lb'] as $lk) if (isset($labels[$lk])) $ll[] = $labels[$lk];
            }
            $products[] = array(
                'id'                     => (int) $id,
                'nombre'                 => $it['t'],
                'url'                    => get_permalink($id),
                'imagen'                 => get_the_post_thumbnail_url($id, 'woocommerce_thumbnail') ?: '',
                'skus'                   => !empty($it['skus']) ? $it['skus'] : array(),
                'precio_min_clp_iva'     => round((float) $it['pmin']),
                'precio_max_clp_iva'     => round((float) $it['pmax']),
                'moneda'                 => 'CLP',
                'en_stock'               => !empty($it['stk']),
                'categorias'             => $it['c'],
                'materiales'             => $it['m'],
                'diametros_mm'           => $it['dm'],
                'diametros_etiquetas'    => $it['dl'],
                'cargas_kg'              => $it['kg'],
                'cargas_etiquetas'       => $it['kl'],
                'configuracion'          => $fl,
                'superficies_de_trabajo' => $sl,
                'etiquetas'              => $ll,
                'atributos'              => $it['a'],
                'descripcion'            => RBI_Text::cut($it['x'], 500),
            );
        }

        return array(
            'empresa' => array(
                'nombre'      => get_bloginfo('name') ?: 'ROTAR Chile',
                'url'         => home_url('/'),
                'pais'        => 'CL',
                'moneda'      => 'CLP',
                'descripcion' => self::company_summary(),
                'llms_txt'    => home_url('/llms.txt'),
                'llms_full'   => home_url('/llms-full.txt'),
            ),
            'actualizado'     => gmdate('c', RBI_Catalog::timestamp() ?: time()),
            'total_productos' => count($products),
            'productos'       => $products,
        );
    }

    /** Inyecta enlaces de descubrimiento IA y Schema.org JSON-LD enriquecido en <head>. */
    public static function head_discovery_and_schema() {
        $llms_url = home_url('/llms.txt');
        $full_url = home_url('/llms-full.txt');
        $json_url = rest_url('rotar/v1/ai-catalog');

        echo "\n<!-- Rotar AI & LLM Knowledge Base Discovery -->\n";
        echo '<link rel="alternate" type="text/markdown" title="ROTAR - Base de Conocimiento IA (llms.txt)" href="' . esc_url($llms_url) . '">' . "\n";
        echo '<link rel="alternate" type="text/plain" title="ROTAR - Catálogo Completo de Productos para IA (llms-full.txt)" href="' . esc_url($full_url) . '">' . "\n";
        echo '<link rel="alternate" type="application/json" title="ROTAR - Feed JSON de Productos para IA" href="' . esc_url($json_url) . '">' . "\n";

        $catalog = RBI_Catalog::get();
        $items   = isset($catalog['items']) && is_array($catalog['items']) ? $catalog['items'] : array();

        // En páginas de producto individual: inyectar propiedades técnicas estructuradas (Schema.org additionalProperty)
        if (function_exists('is_product') && is_product()) {
            $pid = (int) get_queried_object_id();
            if ($pid && isset($items[$pid])) {
                $it = $items[$pid];
                $props = array();
                if (!empty($it['m'])) {
                    $props[] = array('@type' => 'PropertyValue', 'name' => 'Material de fabricación', 'value' => implode(', ', $it['m']));
                }
                if (!empty($it['dl'])) {
                    $props[] = array('@type' => 'PropertyValue', 'name' => 'Diámetro de rueda', 'value' => implode(', ', $it['dl']));
                }
                if (!empty($it['kl'])) {
                    $props[] = array('@type' => 'PropertyValue', 'name' => 'Capacidad de carga', 'value' => implode(', ', $it['kl']));
                }
                if (!empty($it['su'])) {
                    $suelos = isset($catalog['suelos']) ? $catalog['suelos'] : array();
                    $sl = array();
                    foreach ($it['su'] as $sk) $sl[] = isset($suelos[$sk]) ? $suelos[$sk] : RBI_Catalog::suelo_label($sk);
                    $props[] = array('@type' => 'PropertyValue', 'name' => 'Superficies de Trabajo compatibles', 'value' => implode(', ', $sl));
                }
                if (!empty($it['a'])) {
                    foreach ($it['a'] as $aname => $avals) {
                        $props[] = array('@type' => 'PropertyValue', 'name' => $aname, 'value' => implode(', ', array_slice($avals, 0, 10)));
                    }
                }
                if (!empty($props)) {
                    $ld = array(
                        '@context'           => 'https://schema.org',
                        '@type'              => 'Product',
                        '@id'                => get_permalink($pid) . '#rotar-tech-specs',
                        'name'               => $it['t'],
                        'url'                => get_permalink($pid),
                        'brand'              => array('@type' => 'Brand', 'name' => get_bloginfo('name') ?: 'ROTAR'),
                        'sku'                => !empty($it['skus']) ? $it['skus'][0] : (string) $pid,
                        'category'           => implode(' > ', $it['c']),
                        'material'           => implode(', ', $it['m']),
                        'additionalProperty' => $props,
                    );
                    echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
                }
            }
        } elseif (is_front_page() || is_home() || (function_exists('is_shop') && is_shop())) {
            $org = array(
                '@context'    => 'https://schema.org',
                '@type'       => 'HardwareStore',
                '@id'         => home_url('/#organization'),
                'name'        => get_bloginfo('name') ?: 'ROTAR Chile',
                'url'         => home_url('/'),
                'description' => self::company_summary(),
                'areaServed'  => array('@type' => 'Country', 'name' => 'Chile'),
                'knowsAbout'  => array(
                    'Ruedas industriales', 'Garruchas', 'Rodajas', 'Ruedas de poliuretano', 'Ruedas de nylon',
                    'Ruedas de goma', 'Ruedas de acero inoxidable', 'Ruedas de fierro fundido', 'Ruedas para alta temperatura',
                    'Ruedas para carretilla', 'Ruedas impinchables', 'Ruedas neumáticas', 'Ruedas para andamios',
                    'Ruedas para transpaletas', 'Ruedas para portones', 'Carros de carga', 'Carros yegua', 'Carros plegables',
                ),
            );
            echo '<script type="application/ld+json">' . wp_json_encode($org, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
        }
    }

    /** Autoriza explícitamente a los rastreadores de IA en robots.txt e informa la ubicación de llms.txt y llms-full.txt. */
    public static function robots_txt($output, $public) {
        if ('0' == $public) return $output;
        $bots = array('GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'Google-Extended', 'ClaudeBot', 'Claude-Web', 'PerplexityBot', 'Applebot-Extended', 'Meta-ExternalAgent');
        $extra = "\n# Rotar AI & LLM Indexing (ChatGPT, Gemini, Claude, Perplexity)\n";
        foreach ($bots as $bot) {
            if (stripos($output, 'User-agent: ' . $bot) === false) {
                $extra .= "User-agent: {$bot}\nAllow: /\nDisallow: /wp-admin/\nDisallow: /wp-login.php\nDisallow: /carrito/\nDisallow: /finalizar-compra/\nDisallow: /mi-cuenta/\n\n";
            }
        }
        $extra .= "LLMs-Txt: " . home_url('/llms.txt') . "\n";
        $extra .= "LLMs-Full-Txt: " . home_url('/llms-full.txt') . "\n";
        return rtrim($output) . "\n" . $extra;
    }
}

