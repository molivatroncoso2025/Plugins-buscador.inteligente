<?php
/**
 * Aprendizaje automático a partir de las búsquedas y clics de los clientes.
 * Registra qué productos eligen los usuarios para cada consulta (y para cada token),
 * además de los términos técnicos inferidos por la IA, para mejorar el ranking con el uso.
 */
if (!defined('ABSPATH')) exit;

class RBI_Learn {

    const OPT          = 'rbi_learn_v1';
    const MAX_QUERIES  = 500;
    const MAX_TOKENS   = 800;
    const MAX_PIDS     = 25;

    private static $mem = null;

    public static function init() {
        add_action('wp_ajax_rbi_click', array(__CLASS__, 'ajax_click'));
        add_action('wp_ajax_nopriv_rbi_click', array(__CLASS__, 'ajax_click'));
    }

    /** Clave canónica de una consulta (independiente del orden de las palabras y acentos). */
    public static function key($q) {
        $toks = array_values(array_unique(RBI_Text::tokens($q)));
        if (!empty($toks)) {
            sort($toks, SORT_STRING);
            return implode(' ', array_slice($toks, 0, 12));
        }
        return RBI_Text::norm($q);
    }

    public static function get() {
        if (self::$mem !== null) return self::$mem;
        $d = get_option(self::OPT, array());
        if (!is_array($d)) $d = array();
        if (!isset($d['queries']) || !is_array($d['queries'])) $d['queries'] = array();
        if (!isset($d['tokens']) || !is_array($d['tokens'])) $d['tokens'] = array();
        return self::$mem = $d;
    }

    private static function save($d) {
        self::$mem = $d;
        update_option(self::OPT, $d, false);
    }

    public static function clear() {
        self::$mem = null;
        delete_option(self::OPT);
    }

    /** Registra que un cliente buscó $q y entró a ver el producto $pid. */
    public static function record_click($q, $pid) {
        $q = RBI_Text::cut(trim( RBI_Text::clean($q) ), 200);
        $pid = (int) $pid;
        if ($q === '' || $pid <= 0) return;

        $k = self::key($q);
        if ($k === '') return;

        $d = self::get();

        if (!isset($d['queries'][$k])) {
            $d['queries'][$k] = array(
                'q'     => $q,
                'total' => 0,
                'ts'    => time(),
                'pids'  => array(),
                'terms' => array(),
            );
        }
        $row = &$d['queries'][$k];
        $row['q'] = $q;
        $row['total'] = (int) $row['total'] + 1;
        $row['ts'] = time();
        $row['pids'][$pid] = isset($row['pids'][$pid]) ? (int) $row['pids'][$pid] + 1 : 1;
        arsort($row['pids']);
        if (count($row['pids']) > self::MAX_PIDS) {
            $row['pids'] = array_slice($row['pids'], 0, self::MAX_PIDS, true);
        }
        unset($row);

        // Aprendizaje por token individual (para consultas nuevas que compartan palabras clave).
        $toks = array_values(array_unique(RBI_Text::tokens($q)));
        foreach ($toks as $t) {
            if (strlen($t) < 3) continue;
            if (!isset($d['tokens'][$t]) || !is_array($d['tokens'][$t])) {
                $d['tokens'][$t] = array();
            }
            $d['tokens'][$t][$pid] = isset($d['tokens'][$t][$pid]) ? (int) $d['tokens'][$t][$pid] + 1 : 1;
            arsort($d['tokens'][$t]);
            if (count($d['tokens'][$t]) > self::MAX_PIDS) {
                $d['tokens'][$t] = array_slice($d['tokens'][$t], 0, self::MAX_PIDS, true);
            }
        }

        // Limitar tamaño de la tabla de consultas aprendidas.
        if (count($d['queries']) > self::MAX_QUERIES) {
            uasort($d['queries'], function ($a, $b) {
                $ca = isset($a['total']) ? (int) $a['total'] : 0;
                $cb = isset($b['total']) ? (int) $b['total'] : 0;
                if ($ca !== $cb) return $cb - $ca;
                return (isset($b['ts']) ? (int) $b['ts'] : 0) - (isset($a['ts']) ? (int) $a['ts'] : 0);
            });
            $d['queries'] = array_slice($d['queries'], 0, self::MAX_QUERIES, true);
        }

        if (count($d['tokens']) > self::MAX_TOKENS) {
            $d['tokens'] = array_slice($d['tokens'], -self::MAX_TOKENS, null, true);
        }

        self::save($d);
    }

    /** Guarda los términos técnicos que la IA asoció a una consulta de cliente. */
    public static function record_ai_terms($q, $ai_intent) {
        if (!is_array($ai_intent)) return;
        $q = RBI_Text::cut(trim(RBI_Text::clean($q)), 200);
        $k = self::key($q);
        if ($k === '') return;

        $terms = array();
        foreach (array('keywords', 'materiales', 'suelos', 'caracteristicas') as $field) {
            if (!empty($ai_intent[$field]) && is_array($ai_intent[$field])) {
                foreach ($ai_intent[$field] as $phrase) {
                    $phrase = sanitize_text_field((string) $phrase);
                    if ($phrase !== '') $terms[] = $phrase;
                }
            }
        }
        $terms = array_slice(array_values(array_unique($terms)), 0, 15);
        if (empty($terms)) return;

        $d = self::get();
        if (!isset($d['queries'][$k])) {
            $d['queries'][$k] = array(
                'q'     => $q,
                'total' => 0,
                'ts'    => time(),
                'pids'  => array(),
                'terms' => $terms,
            );
        } else {
            $d['queries'][$k]['terms'] = $terms;
            $d['queries'][$k]['ts'] = time();
        }
        self::save($d);
    }

    /** Devuelve términos aprendidos previamente para una consulta similar. */
    public static function learned_terms($q) {
        $k = self::key($q);
        if ($k === '') return array();
        $d = self::get();
        if (!empty($d['queries'][$k]['terms']) && is_array($d['queries'][$k]['terms'])) {
            return $d['queries'][$k]['terms'];
        }
        return array();
    }

    /**
     * Calcula bonificaciones de puntaje ($pid => $boost) para el ranking según el historial
     * de clics de clientes en la misma consulta y en consultas con palabras en común.
     */
    public static function boosts($q) {
        $k = self::key($q);
        if ($k === '') return array();

        $d = self::get();
        $boosts = array();

        // 1. Coincidencia exacta de la consulta normalizada (fuerte).
        if (!empty($d['queries'][$k]['pids']) && is_array($d['queries'][$k]['pids'])) {
            $total = max(1, (int) $d['queries'][$k]['total']);
            foreach ($d['queries'][$k]['pids'] as $pid => $cnt) {
                $pid = (int) $pid;
                $cnt = (int) $cnt;
                if ($cnt <= 0) continue;
                $share = $cnt / $total;
                $boosts[$pid] = min(20.0, (4.5 * log(1 + $cnt)) + (6.0 * $share));
            }
        }

        // 2. Coincidencia por tokens aprendidos de otras búsquedas de clientes.
        $toks = array_values(array_unique(RBI_Text::tokens($q)));
        if (!empty($toks) && !empty($d['tokens'])) {
            $tok_scores = array();
            $tok_hits = array();
            $ntoks = count($toks);
            foreach ($toks as $t) {
                if (empty($d['tokens'][$t]) || !is_array($d['tokens'][$t])) continue;
                $ttotal = max(1, array_sum($d['tokens'][$t]));
                foreach ($d['tokens'][$t] as $pid => $cnt) {
                    $pid = (int) $pid;
                    $cnt = (int) $cnt;
                    if ($cnt <= 0) continue;
                    $s = min(5.0, 2.0 * log(1 + $cnt) * (0.4 + 0.6 * ($cnt / $ttotal)));
                    $tok_scores[$pid] = isset($tok_scores[$pid]) ? $tok_scores[$pid] + $s : $s;
                    $tok_hits[$pid] = isset($tok_hits[$pid]) ? $tok_hits[$pid] + 1 : 1;
                }
            }
            foreach ($tok_scores as $pid => $s) {
                $coverage = $tok_hits[$pid] / $ntoks;
                // Si la consulta tiene varias palabras, premiar más cuando coinciden varios tokens.
                $add = min(10.0, $s * (0.4 + 0.6 * $coverage));
                $boosts[$pid] = isset($boosts[$pid]) ? $boosts[$pid] + $add : $add;
            }
        }

        return $boosts;
    }

    /** Devuelve mapa $pid => clics para informar al asesor IA de la preferencia de los clientes. */
    public static function query_clicks($q) {
        $k = self::key($q);
        if ($k === '') return array();
        $d = self::get();
        if (!empty($d['queries'][$k]['pids']) && is_array($d['queries'][$k]['pids'])) {
            return $d['queries'][$k]['pids'];
        }
        return array();
    }

    /** Resumen de búsquedas con clics para mostrar en el panel de administración. */
    public static function stats($limit = 25) {
        $d = self::get();
        if (empty($d['queries'])) return array();
        $rows = array();
        foreach ($d['queries'] as $row) {
            if (empty($row['total']) || empty($row['pids']) || !is_array($row['pids'])) continue;
            $rows[] = array(
                'q'     => isset($row['q']) ? (string) $row['q'] : '',
                'total' => (int) $row['total'],
                'ts'    => isset($row['ts']) ? (int) $row['ts'] : 0,
                'top'   => array_slice($row['pids'], 0, 5, true),
            );
        }
        usort($rows, function ($a, $b) {
            if ($a['total'] !== $b['total']) return $b['total'] - $a['total'];
            return $b['ts'] - $a['ts'];
        });
        return array_slice($rows, 0, max(1, (int) $limit));
    }

    /** Endpoint AJAX disparado al hacer clic en un producto desde los resultados. */
    public static function ajax_click() {
        while (ob_get_level() > 0) ob_end_clean();
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($q !== '' && $id > 0) {
            self::record_click($q, $id);
        }
        wp_send_json_success();
    }
}
