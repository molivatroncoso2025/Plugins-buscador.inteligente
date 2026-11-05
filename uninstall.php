<?php
/**
 * Desinstalación de Rotar Buscador Inteligente: elimina ajustes, índice, registro y cachés.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

foreach (array('rbi_settings', 'rbi_search_log', 'rbi_catalog_v1', 'rbi_catalog_ts_v1', 'rbi_catalog_stale', 'rbi_learn_v1', 'rbi_legacy_cleaned') as $opt) {
    delete_option($opt);
}

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_rbi\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rbi\\_%'");

