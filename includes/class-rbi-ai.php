<?php
/**
 * Conexión con la IA (Google Gemini u OpenAI) y prompts del asesor.
 */
if (!defined('ABSPATH'))
    exit;

class RBI_AI
{

    public static function endpoint($provider = null)
    {
        if ($provider === null) {
            $s = RBI_Plugin::settings();
            $provider = $s['provider'];
        }
        return $provider === 'openai'
            ? 'https://api.openai.com/v1/chat/completions'
            : 'https://generativelanguage.googleapis.com/v1beta/models/';
    }

    public static function host($provider = null)
    {
        return wp_parse_url(self::endpoint($provider), PHP_URL_HOST);
    }

    /** ¿WordPress bloquea la conexión saliente (WP_HTTP_BLOCK_EXTERNAL)? */
    public static function is_blocked($provider = null)
    {
        if (!class_exists('WP_Http'))
            require_once ABSPATH . WPINC . '/class-wp-http.php';
        $http = new WP_Http();
        return (bool) $http->block_request(self::endpoint($provider) . 'test');
    }

    const DOWN_KEY = 'rbi_ai_down';

    /** Motivo por el que la IA está en pausa (créditos, clave inválida...), o ''. */
    public static function down_reason()
    {
        $r = get_transient(self::DOWN_KEY);
        if (!is_string($r) || $r === '')
            return '';
        // Si quedó un error antiguo por modelo 404 o límite temporal 429, limpiarlo automáticamente.
        if (strpos($r, ' 404:') !== false || strpos($r, ' 429:') !== false) {
            delete_transient(self::DOWN_KEY);
            return '';
        }
        return $r;
    }

    public static function clear_down()
    {
        delete_transient(self::DOWN_KEY);
    }

    private static function api_error($provider, $code, $j)
    {
        $msg = isset($j['error']['message']) ? $j['error']['message'] : 'respuesta vacía';
        // Solo errores permanentes de clave/facturación pausan la IA 15 min.
        if (in_array($code, array(401, 402, 403), true)) {
            set_transient(self::DOWN_KEY, $provider . ' ' . $code . ': ' . $msg, 15 * MINUTE_IN_SECONDS);
        }
        return new WP_Error('rbi_api', $provider . ' ' . $code . ': ' . $msg);
    }

    public static function call_json($system, $messages, $timeout = 25)
    {
        $s = RBI_Plugin::settings();
        $key = RBI_Plugin::api_key();
        $model = RBI_Plugin::model();
        if ($key === '')
            return new WP_Error('rbi_no_key', 'Falta la API key de IA.');
        $down = self::down_reason();
        if ($down !== '')
            return new WP_Error('rbi_down', $down);
        if (self::is_blocked()) {
            return new WP_Error('rbi_blocked', 'WordPress bloquea la conexión a ' . self::host() . ' (WP_HTTP_BLOCK_EXTERNAL en wp-config.php). Agrega ese dominio a WP_ACCESSIBLE_HOSTS.');
        }

        if ($s['provider'] === 'openai') {
            $models = array_values(array_unique(array($model, 'gpt-4o-mini', 'gpt-4o')));
            $msgs = array(array('role' => 'system', 'content' => $system));
            foreach ($messages as $m)
                $msgs[] = array('role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['text']);
            $text = '';
            $last_err = null;
            foreach ($models as $try_model) {
                $resp = wp_remote_post(self::endpoint('openai'), array(
                    'timeout' => $timeout,
                    'headers' => array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key),
                    'body' => wp_json_encode(array(
                        'model' => $try_model,
                        'messages' => $msgs,
                        'temperature' => 0.3,
                        'response_format' => array('type' => 'json_object'),
                    )),
                ));
                if (is_wp_error($resp))
                    return $resp;
                $code = (int) wp_remote_retrieve_response_code($resp);
                $j = json_decode(wp_remote_retrieve_body($resp), true);
                if ($code === 200 && !empty($j['choices'][0]['message']['content'])) {
                    $text = $j['choices'][0]['message']['content'];
                    break;
                }
                $last_err = self::api_error('OpenAI', $code, $j);
                if ($code !== 404 && $code !== 400)
                    return $last_err;
            }
            if ($text === '')
                return $last_err ? $last_err : new WP_Error('rbi_api', 'OpenAI: sin respuesta');
        } else {
            $models = array_values(array_unique(array($model, 'gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-1.5-flash')));
            $contents = array();
            foreach ($messages as $m) {
                $contents[] = array('role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => array(array('text' => $m['text'])));
            }
            $text = '';
            $last_err = null;
            foreach ($models as $try_model) {
                $gen = array('temperature' => 0.3, 'responseMimeType' => 'application/json');
                if (stripos($try_model, '2.5-flash') !== false)
                    $gen['thinkingConfig'] = array('thinkingBudget' => 0);

                $resp = wp_remote_post(self::endpoint('gemini') . rawurlencode($try_model) . ':generateContent', array(
                    'timeout' => $timeout,
                    'headers' => array('Content-Type' => 'application/json', 'x-goog-api-key' => $key),
                    'body' => wp_json_encode(array(
                        'systemInstruction' => array('parts' => array(array('text' => $system))),
                        'contents' => $contents,
                        'generationConfig' => $gen,
                    )),
                ));
                if (is_wp_error($resp))
                    return $resp;
                $code = (int) wp_remote_retrieve_response_code($resp);
                $j = json_decode(wp_remote_retrieve_body($resp), true);
                if ($code === 200 && !empty($j['candidates'][0]['content']['parts'])) {
                    foreach ($j['candidates'][0]['content']['parts'] as $part) {
                        if (!empty($part['thought']))
                            continue;
                        if (isset($part['text']))
                            $text .= $part['text'];
                    }
                    if ($text !== '')
                        break;
                }
                $last_err = self::api_error('Gemini', $code, $j);
                if ($code !== 404 && $code !== 400)
                    return $last_err;
            }
            if ($text === '')
                return $last_err ? $last_err : new WP_Error('rbi_api', 'Gemini: sin respuesta');
        }

        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
        $data = json_decode($text, true);
        if (!is_array($data) && preg_match('/\{.*\}/s', $text, $mm))
            $data = json_decode($mm[0], true);
        if (!is_array($data))
            return new WP_Error('rbi_json', 'La IA no devolvió un JSON válido.');
        return $data;
    }

    /** Prueba de conexión para el panel de administración. */
    public static function test()
    {
        self::clear_down();
        $r = self::call_json('Responde SOLO con JSON: {"ok":true,"mensaje":"conexión correcta"}', array(array('role' => 'user', 'text' => 'Prueba')), 20);
        return $r;
    }

    public static function intent($q, $history)
    {
        $system = "Eres el Analista Técnico y Comercial de Rotar (Chile), especialista en ruedas industriales, garruchas, rodajas, carros y soluciones de movimiento.\n"
            . "Tu misión es analizar con precisión ejecutiva lo que el cliente necesita (considerando todo el historial de conversación) para ayudarle a mejorar su búsqueda y encontrar coincidencias tanto por nombre del producto, por etiquetas (tags) y por descripción técnica del producto.\n"
            . "Devuelve SOLO un objeto JSON con:\n"
            . "- keywords: array (máx 15) de términos en español para encontrar el producto exacto buscando en el nombre del producto, etiquetas y descripción: incluye lo que pidió más sinónimos técnicos, aplicaciones y términos que suelen aparecer en la descripción o etiquetas del producto (garrucha, rodaja, giratoria, fija, freno, doble freno, placa, espiga, vástago, perno, poliuretano, nylon, goma, termoplástica, elástica, fenólica, fierro, acero inoxidable, neumática, impinchable, portón, andamio, transpaleta, carretilla, yegua...).\n"
            . "- materiales: array de materiales recomendados para la necesidad del cliente (poliuretano, nylon, goma, termoplástica, caucho, hierro, acero, fenólica, polipropileno, neumática, inoxidable). Vacío si no se puede inferir.\n"
            . "- suelos: array de Superficies de Trabajo detectadas o compatibles según lo que describe el cliente (valores posibles: asfalto, arena, ceramico, concreto-liso, concreto-rugoso, epoxi, madera, metalico, pasto, tierra, alta-temperatura, humedo, grava, adoquin, alfombra, parquet, baldosa). Vacío si no menciona ni implica superficie.\n"
            . "- caracteristicas: array de todas las características especiales o etiquetas requeridas o inferidas (freno, giratoria, fija, placa, espiga, inoxidable, alta temperatura, silenciosa, no mancha, amortiguacion, carga pesada, neumatica, porton, andamio, transpaleta, entrega inmediata, oferta, descuento, nuevo, destacado...).\n"
            . "- diametro_mm: número en mm o null (convierte pulgadas a mm: 1\"=25, 2\"=50, 3\"=75, 4\"=100, 5\"=125, 6\"=150, 8\"=200, 10\"=250).\n"
            . "- carga_kg_por_rueda: número o null. Si el cliente da el peso total y cantidad de ruedas: aplica margen de seguridad profesional = peso_total / (num_ruedas - 1). Si da peso total de un carro/mueble/equipo sin indicar ruedas, asume 4 ruedas (peso_total / 3). Si indica carga directa por rueda, usa ese valor.\n"
            . "- nota_carga: explicación ejecutiva breve del cálculo de carga por rueda con factor de seguridad (si aplica); si no, cadena vacía.\n"
            . "- uso: resumen ejecutivo de la aplicación y exigencias especiales del cliente (ej. 'Carro panadero de 400 kg para horno de alta temperatura, requiere ruedas silenciosas con freno').\n"
            . "- busca_rueda: true si busca ruedas/garruchas/rodajas (aunque mencione el carro o equipo donde se instalarán), false solo si busca comprar un carro completo, transpaleta u otro equipo.";
        $msgs = $history;
        $msgs[] = array('role' => 'user', 'text' => $q);
        return self::call_json($system, $msgs, 18);
    }

    public static function advise($q, $history, $intent, $candidates, $max_res)
    {
        $s = RBI_Plugin::settings();
        $system = "Eres el Ejecutivo Comercial y Asesor Técnico Senior de Rotar (Chile), líder especialista en ruedas industriales, garruchas, rodajas y soluciones de movilidad.\n"
            . "Tu objetivo es atender al cliente como el mejor ejecutivo experto de la empresa: cálido, proactivo, claro y técnicamente impecable, analizando el **nombre**, las **etiquetas** y la **descripción** de los productos en CANDIDATOS para encontrar la mejor opción y **ayudar al cliente a mejorar y afinar su búsqueda según sus necesidades**.\n\n"
            . "CÓMO DEBES ASESORAR Y AYUDAR A MEJORAR LA BÚSQUEDA:\n"
            . "1. Diagnóstico personalizado: Comienza validando lo que el cliente busca (por nombre, etiqueta, descripción, aplicación, peso/carga, Superficie de Trabajo o característica especial como freno, inoxidable, silenciosa, alta temperatura, montaje placa o espiga) y explícale con criterio experto POR QUÉ cierta configuración y material es la mejor decisión técnica para su necesidad.\n"
            . "2. Cálculo de ingeniería seguro: Si el cliente menciona un peso total, explícale el cálculo profesional de Rotar: Capacidad por rueda = Peso total (equipo + carga) ÷ (N° de ruedas − 1), asegurando que si una rueda queda en el aire por irregularidades del piso, las demás soporten la carga sin fallar.\n"
            . "3. Recomendación con nombre propio: Menciona en tu respuesta las mejores opciones reales del listado CANDIDATOS (aprovechando la información de su nombre, etiquetas y descripción, y destacando sus ventajas clave en **negrita**: material, diámetro, capacidad de carga, superficie compatible o tipo de montaje) y recomienda una combinación óptima cuando corresponda (ej. en carros: 2 giratorias con freno + 2 fijas para control y dirección).\n"
            . "4. Guía proactiva para mejorar su búsqueda: Si la búsqueda del cliente es general o falta algún dato clave para afinar al 100% la elección (peso total a mover, Superficie de Trabajo, diámetro, si requiere placa o espiga, o si necesita freno/inoxidable), indícale brevemente cómo puede mejorar su búsqueda y hazle 1 o 2 preguntas estratégicas y amables al final.\n\n"
            . "REGLAS ESTRICTAS DE CATÁLOGO Y FORMATO:\n"
            . "- Recomienda ÚNICAMENTE productos incluidos en CANDIDATOS usando su 'id' exacto. Jamás inventes productos, medidas, cargas ni precios.\n"
            . "- Prioriza productos con stock ('sí') y que cumplan lo buscado en su 'nombre', 'etiquetas', 'descripcion', 'configuracion', 'suelos', 'material', 'cargas' y 'diametros'.\n"
            . "- 'recomendados': ordénalos del mejor calce técnico al menor (máximo {$max_res}). Si ningún candidato cumple exactamente, selecciona las alternativas más cercanas de CANDIDATOS aclarando la diferencia en tu respuesta.\n"
            . "- 'motivo': 1 frase ejecutiva y persuasiva (máx 28 palabras) que destaque exactamente por qué este producto calza con su búsqueda o necesidad.\n"
            . "- 'respuesta': entre 70 y 150 palabras, redactada en español de Chile profesional y cercano (tuteando). Usa **negritas** para resaltar conceptos clave y saltos de línea o viñetas cortas (•) para que sea muy fácil y agradable de leer.\n"
            . "- 'sugerencias': incluye SIEMPRE 4 búsquedas mejoradas o filtros conversacionales específicos redactados en primera persona para que el cliente pueda mejorar/afinar su búsqueda con un solo clic según sus necesidades (ej. 'Necesito que sean giratorias con freno', 'El carro cargará 400 kg en total', 'Para piso de cerámica sin que raye', 'En acero inoxidable para humedad').\n\n"
            . "CRITERIOS TÉCNICOS EXPERTOS ROTAR:\n"
            . "- Si 'busca_carro' es true (ej. el cliente busca 'carro' o 'carros' sin pedir ruedas): presenta y recomienda TODOS los modelos de **Carros** disponibles en CANDIDATOS (carros de carga, carros plegables, carros yegua, transpaletas) según su capacidad y formato, sin recomendar ruedas sueltas.\n"
            . "- Si hay 'categorias_detectadas' (ej. 'Ruedas para portón', 'Carros', etc.): menciona explícitamente la categoría encontrada y recomienda los productos de esa categoría presentes en CANDIDATOS.\n"
            . "- Ruedas para Portón Corredizo / Rejas: Ruedas de acero con canal en V (para riel angular), canal en U / redondo (para riel redondo/tubo) o con caja/soporte para soldar o apernar según el peso del portón.\n"
            . "- Superficies delicadas (Cerámica, Madera, Parqué, Epoxi, Baldosa): Poliuretano (alta carga, no marca, alta durabilidad) o Goma / Termoplástica TPR (máxima absorción de impacto y silencio).\n"
            . "- Superficies industriales lisas (Concreto Liso, Epoxi, Metálico): Nylon / Polipropileno (mínimo esfuerzo de empuje, alta carga e higiene) o Poliuretano (si se requiere menor ruido y mayor tracción).\n"
            . "- Superficies exigentes o exteriores (Concreto Rugoso, Asfalto, Adoquín, Tierra, Pasto, Grava/Césped, Arena): Neumáticas, Impinchables o Goma Elástica de mayor diámetro (Ø 150 mm o más para superar obstáculos con suavidad).\n"
            . "- Ambientes Húmedos / Químicos / Alimentarios / Frigoríficos / Clínicos: Horquilla de Acero Inoxidable con rueda de Nylon, Polipropileno, Poliuretano o TPR lavable.\n"
            . "- Alta Temperatura (Hornos, Panaderías, Autoclaves): Ruedas Fenólicas, Fibra de Vidrio o Hierro Fundido de alta temperatura.\n"
            . "- Cargas extremas o metalmecánica: Hierro Fundido, Acero Forjado o Poliuretano sobre núcleo de fierro/aluminio de alto tonelaje.\n"
            . "\nPERFIL CORPORATIVO Y LÍNEAS DE ROTAR:\n"
            . (class_exists('RBI_LLMs') ? RBI_LLMs::company_summary() : trim($s['contexto'])) . "\n"
            . "\nResponde SOLO con JSON válido: {\"respuesta\":\"...\",\"recomendados\":[{\"id\":123,\"motivo\":\"...\"}],\"sugerencias\":[\"...\"]}";

        $ctx = array(
            'consulta_cliente' => $q,
            'necesidad_detectada' => array(
                'uso' => $intent['uso'],
                'busca_carro' => !empty($intent['busca_carro']),
                'categorias_detectadas' => isset($intent['categorias_detectadas']) ? $intent['categorias_detectadas'] : array(),
                'suelos' => isset($intent['suelos']) ? $intent['suelos'] : array(),
                'diametro_mm' => $intent['diametro_mm'],
                'carga_kg_por_rueda' => $intent['carga_kg'],
                'materiales' => $intent['materiales'],
                'caracteristicas' => $intent['caracteristicas'],
                'nota_carga' => $intent['nota_carga'],
                'filtros_activos' => isset($intent['filtros_activos']) ? $intent['filtros_activos'] : array(),
            ),
            'CANDIDATOS' => $candidates,
        );
        $msgs = $history;
        $msgs[] = array('role' => 'user', 'text' => wp_json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::call_json($system, $msgs, 35);
    }
}
