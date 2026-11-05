<?php
/**
 * Utilidades de texto: normalización, tokens (con stemming simple en español),
 * sinónimos del rubro (incluyendo los configurados en el panel) y extracción de medidas.
 */
if (!defined('ABSPATH')) exit;

class RBI_Text {

    private static $syn_map = null;

    private static $stop = array(
        'de'=>1,'la'=>1,'el'=>1,'los'=>1,'las'=>1,'un'=>1,'una'=>1,'unos'=>1,'unas'=>1,'y'=>1,'o'=>1,'a'=>1,
        'en'=>1,'para'=>1,'por'=>1,'con'=>1,'que'=>1,'del'=>1,'al'=>1,'se'=>1,'su'=>1,'sus'=>1,
        'es'=>1,'son'=>1,'mi'=>1,'me'=>1,'necesito'=>1,'necesita'=>1,'quiero'=>1,'busco'=>1,'buscando'=>1,
        'buscar'=>1,'tengo'=>1,'hay'=>1,'algo'=>1,'como'=>1,'mas'=>1,'muy'=>1,'lo'=>1,'le'=>1,'les'=>1,'este'=>1,
        'esta'=>1,'estos'=>1,'estas'=>1,'ese'=>1,'esa'=>1,'eso'=>1,'tipo'=>1,'cual'=>1,'cuales'=>1,
        'donde'=>1,'puedo'=>1,'pueden'=>1,'favor'=>1,'hola'=>1,'gracias'=>1,'tienen'=>1,'tiene'=>1,
        'venden'=>1,'vende'=>1,'recomiendan'=>1,'recomienda'=>1,'recomendar'=>1,'ayuda'=>1,'ayudar'=>1,
        'sea'=>1,'sean'=>1,'algun'=>1,'alguna'=>1,'the'=>1,'ser'=>1,'hasta'=>1,'no'=>1,'si'=>1,
        'cotizacion'=>1,'cotizar'=>1,'presupuesto'=>1,'precio'=>1,'precios'=>1,'valor'=>1,'comprar'=>1,
        'mejor'=>1,'mejores'=>1,'ideal'=>1,'ideales'=>1,'servir'=>1,'sirva'=>1,'sirven'=>1,'sirve'=>1,
        'trabajar'=>1,'trabajo'=>1,'usar'=>1,'uso'=>1,'poner'=>1,'colocar'=>1,'instalar'=>1,'cambiar'=>1,
        'aprox'=>1,'aproximadamente'=>1,'alrededor'=>1,'cada'=>1,'total'=>1,'peso'=>1,'pesos'=>1,
        'soporte'=>1,'soportar'=>1,'soporta'=>1,'aguante'=>1,'aguantar'=>1,'aguanta'=>1,'resista'=>1,'resistir'=>1,
        'mover'=>1,'cargar'=>1,'llevar'=>1,'transporte'=>1,'transportar'=>1,'medida'=>1,'medidas'=>1,'tamano'=>1,
        'etiqueta'=>1,'etiquetas'=>1,'producto'=>1,'productos'=>1,'catalogo'=>1,'tienda'=>1,
    );

    private static $syn_groups = array(
        array('rueda','rodaja','garrucha','rodachin','castor'),
        array('carro','carrito','coche'),
        array('carretilla','carretillas'),
        array('yegua','yeguas','diablito'),
        array('transpaleta','zorra','apilador','paletera'),
        array('andamio','andamios','torre'),
        array('freno','bloqueo','traba','frenos'),
        array('giratoria','giratorio','rotativa','pivotante','loca','giro'),
        array('fija','fijo','rigida','rigido'),
        array('poliuretano','pu','uretano'),
        array('nylon','nailon','poliamida','tecnopolimero'),
        array('goma','caucho','hule','elastomero'),
        array('termoplastica','termoplastico','tpr'),
        array('fierro','hierro','fundido','fundicion'),
        array('acero','forjado','metal','metalica'),
        array('inoxidable','inox','aisi'),
        array('fenolica','resina','baquelita'),
        array('temperatura','calor','horno','termica'),
        array('silenciosa','silencioso','silencio'),
        array('pesada','pesado','industrial','robusta','robusto'),
        array('liviana','liviano','ligera','ligero'),
        array('hospital','hospitalaria','clinica','camilla','medica'),
        array('mueble','silla','oficina','mobiliario'),
        array('placa','plato','platina','apernada'),
        array('espiga','perno','vastago','rosca','hilo'),
        array('polipropileno','pp'),
        array('neumatica','neumatico','inflable','camara'),
        array('impinchable','antipinchazo','maciza','macizo'),
        array('antiestatica','antiestatico','conductiva'),
        array('porton','reja','corredera','canal'),
        array('oferta','ofertas','descuento','descuentos','rebaja','promocion','promo','liquidacion'),
        array('entrega','despacho','envio'),
        array('inmediata','inmediato','disponible'),
        array('nuevo','nueva','nuevos','nuevas','novedad','lanzamiento'),
        array('destacado','destacada','destacados','destacadas','recomendado'),
        array('ceramica','ceramico','porcelanato'),
        array('concreto','cemento','hormigon'),
        array('epoxi','epoxico'),
        array('pasto','cesped','jardin'),
        array('grava','ripio','gravilla'),
        array('asfalto','pavimento'),
    );

    public static function clean($txt) {
        $txt = wp_strip_all_tags((string) $txt);
        $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = str_replace("\xC2\xA0", ' ', $txt);
        return trim(preg_replace('/\s+/u', ' ', $txt));
    }

    public static function cut($txt, $len) {
        $txt = trim(preg_replace('/\s+/u', ' ', (string) $txt));
        if (function_exists('mb_strlen')) {
            if (mb_strlen($txt, 'UTF-8') > $len) return rtrim(mb_substr($txt, 0, $len, 'UTF-8')) . '…';
            return $txt;
        }
        return strlen($txt) > $len ? rtrim(substr($txt, 0, $len)) . '…' : $txt;
    }

    /** Diccionario rápido de errores ortográficos y de tipeo frecuentes en el rubro. */
    private static $typo_map = array(
        'ruedaa'        => 'rueda',
        'reuda'         => 'rueda',
        'reudas'        => 'ruedas',
        'ruda'          => 'rueda',
        'rudas'         => 'ruedas',
        'caretilla'     => 'carretilla',
        'caretillas'    => 'carretillas',
        'carretiya'     => 'carretilla',
        'carretiyas'    => 'carretillas',
        'carretila'     => 'carretilla',
        'carretilas'    => 'carretillas',
        'karetilla'     => 'carretilla',
        'llegua'        => 'yegua',
        'lleguas'       => 'yeguas',
        'yewa'          => 'yegua',
        'poluretano'    => 'poliuretano',
        'poliuritano'   => 'poliuretano',
        'puliuretano'   => 'poliuretano',
        'poliuretno'    => 'poliuretano',
        'poliuretan'    => 'poliuretano',
        'poleuretano'   => 'poliuretano',
        'naylon'        => 'nylon',
        'nailon'        => 'nylon',
        'nilon'         => 'nylon',
        'poliamda'      => 'poliamida',
        'jiratoria'     => 'giratoria',
        'jiratorias'    => 'giratorias',
        'jiratorio'     => 'giratorio',
        'giratoia'      => 'giratoria',
        'giratora'      => 'giratoria',
        'giatoria'      => 'giratoria',
        'inoxsidable'   => 'inoxidable',
        'inoxidavle'    => 'inoxidable',
        'inoxidables'   => 'inoxidable',
        'inocsidable'   => 'inoxidable',
        'inocs'         => 'inox',
        'inpinchable'   => 'impinchable',
        'inpinchables'  => 'impinchables',
        'antipinchaso'  => 'antipinchazo',
        'antipinchasos' => 'antipinchazo',
        'numatica'      => 'neumatica',
        'numaticas'     => 'neumaticas',
        'neomatica'     => 'neumatica',
        'neomaticas'    => 'neumaticas',
        'neumatca'      => 'neumatica',
        'traspaleta'    => 'transpaleta',
        'traspaletas'   => 'transpaletas',
        'transpalet'    => 'transpaleta',
        'traspalet'     => 'transpaleta',
        'andamo'        => 'andamio',
        'andamos'       => 'andamios',
        'andamioo'      => 'andamio',
        'bastago'       => 'vastago',
        'espija'        => 'espiga',
        'orquilla'      => 'horquilla',
        'orquillas'     => 'horquillas',
        'fiero'         => 'fierro',
        'fundio'        => 'fundido',
        'forjao'        => 'forjado',
        'termoplastca'  => 'termoplastica',
        'fenolca'       => 'fenolica',
        'ospitalaria'   => 'hospitalaria',
        'ospital'       => 'hospital',
        'ceramca'       => 'ceramica',
        'seramica'      => 'ceramica',
        'porselanato'   => 'porcelanato',
        'porcelanto'    => 'porcelanato',
        ' cmento'       => ' cemento',
        'semento'       => 'cemento',
        'afalto'        => 'asfalto',
        'gravila'       => 'gravilla',
        'grabilla'      => 'gravilla',
        'adoqin'        => 'adoquin',
    );

    public static function norm($txt) {
        $txt = html_entity_decode(wp_strip_all_tags((string) $txt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = str_replace(array('″', '”', '“', '"', "''"), ' pulg ', $txt);
        $txt = function_exists('mb_strtolower') ? mb_strtolower($txt, 'UTF-8') : strtolower($txt);
        $txt = remove_accents($txt);
        $txt = preg_replace('/(\d)\.(\d{3})(?!\d)/', '$1$2', $txt); // 1.000 kg → 1000 kg
        $txt = preg_replace('/(\d),(\d)/', '$1.$2', $txt);
        $txt = preg_replace('/[^a-z0-9\.]+/', ' ', $txt);
        $txt = preg_replace('/(\d)([a-z])/', '$1 $2', $txt);
        $txt = preg_replace('/([a-z])(\d)/', '$1 $2', $txt);
        $txt = trim(preg_replace('/\s+/', ' ', $txt));
        if ($txt === '') return '';
        $words = explode(' ', $txt);
        foreach ($words as &$w) {
            if (isset(self::$typo_map[$w])) {
                $w = self::$typo_map[$w];
            }
        }
        unset($w);
        return implode(' ', $words);
    }

    /** Clave fonética en español (absorbe confusiones b/v, c/s/z, ll/y, g/j, qu/k, h muda, np/mp y letras duplicadas). */
    public static function phonetic($w) {
        $w = strtolower((string) $w);
        if ($w === '' || preg_match('/\d/', $w)) return $w;
        $w = str_replace(array('ch', 'll', 'qu', 'gue', 'gui', 'ce', 'ci', 'ge', 'gi', 'nv', 'np', 'nb'), array('x', 'y', 'k', 'ge', 'gi', 'se', 'si', 'je', 'ji', 'mb', 'mp', 'mb'), $w);
        $w = strtr($w, array('v' => 'b', 'z' => 's', 'k' => 'c', 'w' => 'u', 'h' => ''));
        $w = preg_replace('/([a-z])\1+/', '$1', $w); // colapsar letras repetidas (rr -> r, tt -> t, etc.)
        return $w;
    }

    /**
     * Busca términos del vocabulario del catálogo que coincidan fonéticamente o por distancia Levenshtein
     * cuando el usuario escribe una palabra con error ortográfico o de tipeo.
     */
    public static function fuzzy_expand($token, $vocab) {
        $len = strlen($token);
        if ($len < 4 || empty($vocab)) return array();
        $ph = self::phonetic($token);
        $max_dist = $len >= 7 ? 2 : 1;
        $matches = array();
        foreach ($vocab as $v) {
            if (!is_string($v)) continue;
            $vlen = strlen($v);
            if ($vlen < 4 || abs($vlen - $len) > $max_dist) continue;
            // 1. Equivalencia fonética exacta en español (ej. "jiratoria" == "giratoria", "caretilla" == "carretilla")
            if ($ph !== '' && self::phonetic($v) === $ph) {
                $matches[$v] = 0.92;
                continue;
            }
            // 2. Misma letra inicial (o fonética inicial) y distancia de edición pequeña (1 o 2 letras cambiadas/omitidas/invertidas)
            if ($v[0] !== $token[0] && substr(self::phonetic($v), 0, 1) !== substr($ph, 0, 1)) continue;
            $d = levenshtein($token, $v);
            if ($d === 1) {
                $matches[$v] = 0.88;
            } elseif ($d === 2 && $max_dist >= 2) {
                $matches[$v] = 0.78;
            }
        }
        return $matches;
    }

    public static function stem($w) {
        if (preg_match('/\d/', $w)) return $w;
        $len = strlen($w);
        if ($len > 5 && preg_match('/[lrndzj]es$/', $w)) {
            $w = substr($w, 0, -2);
        } elseif ($len > 4 && substr($w, -1) === 's') {
            $w = substr($w, 0, -1);
        }
        if (strlen($w) > 4 && preg_match('/[ao]$/', $w)) $w = substr($w, 0, -1);
        return $w;
    }

    public static function tokens($txt) {
        $n = self::norm($txt);
        if ($n === '') return array();
        $out = array();
        foreach (explode(' ', $n) as $p) {
            $p = trim($p, '.');
            if ($p === '' || isset(self::$stop[$p])) continue;
            if (strlen($p) < 2 && !ctype_digit($p)) continue;
            $out[] = self::stem($p);
        }
        return $out;
    }

    public static function syn_map() {
        if (self::$syn_map !== null) return self::$syn_map;
        $groups = self::$syn_groups;
        if (class_exists('RBI_Plugin')) {
            $s = RBI_Plugin::settings();
            if (!empty($s['sinonimos'])) {
                foreach (preg_split('/\r\n|\r|\n/', (string) $s['sinonimos']) as $line) {
                    $parts = array_filter(array_map('trim', explode(',', $line)));
                    if (count($parts) >= 2) {
                        $toks = array();
                        foreach ($parts as $p) {
                            foreach (self::tokens($p) as $t) $toks[] = $t;
                        }
                        if (count($toks) >= 2) $groups[] = $toks;
                    }
                }
            }
        }
        $map = array();
        foreach ($groups as $g) {
            $stems = array_values(array_unique(array_map(array(__CLASS__, 'stem'), $g)));
            foreach ($stems as $s) {
                $map[$s] = isset($map[$s]) ? array_values(array_unique(array_merge($map[$s], $stems))) : $stems;
            }
        }
        return self::$syn_map = $map;
    }

    /** Medidas en texto libre: diámetros (mm), cargas (kg) y número de ruedas. */
    public static function measures($txt) {
        $raw_low = function_exists('mb_strtolower') ? mb_strtolower((string) $txt, 'UTF-8') : strtolower((string) $txt);
        $t = self::norm($txt);
        $r = array('diam_mm' => array(), 'kg' => array(), 'ruedas' => null);

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(mm|milimetros?|cm|centimetros?|pulg|pulgadas?|in)\b/', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $v = (float) $x[1];
                $u = $x[2];
                if ($u === 'cm' || strpos($u, 'centim') === 0) $v *= 10;
                elseif ($u === 'pulg' || $u === 'in' || strpos($u, 'pulgad') === 0) $v *= 25.4;
                if ($v > 10 && $v < 2000) $r['diam_mm'][] = round($v);
            }
        }
        if (empty($r['diam_mm'])) {
            if (preg_match_all('/(?:ø|diametro|medida)\s*(\d{2,3})\b/u', $raw_low, $m)) {
                foreach ($m[1] as $d) {
                    $v = (float) $d;
                    if ($v >= 25 && $v <= 600) $r['diam_mm'][] = round($v);
                }
            } elseif (preg_match_all('/\b(\d{2,3})\s*x\s*\d{2,3}\b/', $t, $m)) {
                foreach ($m[1] as $d) {
                    $v = (float) $d;
                    if ($v >= 25 && $v <= 600) $r['diam_mm'][] = round($v);
                }
            }
        }
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(kg|kgs|kilos?|kilogramos?|ton|tons|toneladas?)\b/', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $v = (float) $x[1];
                if (strpos($x[2], 'ton') === 0) $v *= 1000;
                if ($v > 0) $r['kg'][] = $v;
            }
        }
        if (preg_match('/\b(\d{1,2})\s*(ruedas?|garruchas?|rodajas?)\b/', $t, $m)) $r['ruedas'] = (int) $m[1];
        return $r;
    }

    public static function number_value($raw, $kind) {
        $m = self::measures($raw);
        if ($kind === 'diam' && !empty($m['diam_mm'])) return (float) $m['diam_mm'][0];
        if ($kind === 'kg' && !empty($m['kg'])) return (float) $m['kg'][0];
        if (!preg_match('/(\d+(?:\.\d+)?)/', self::norm($raw), $x)) return 0;
        $v = (float) $x[1];
        if ($kind === 'diam' && $v > 0 && $v <= 16) $v *= 25.4; // sin unidad y pequeño = pulgadas
        return $v;
    }
}
