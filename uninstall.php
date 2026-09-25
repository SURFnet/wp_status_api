<?php
/**
 * Wordt uitgevoerd wanneer de plugin via WordPress wordt verwijderd (niet bij deactiveren).
 *
 * Standaard blijven alle gegevens (API clients, status, historie) bewaard, zodat een
 * verwijder-en-herinstalleer actie geen integraties breekt. Om bij verwijderen ook alle
 * gegevens op te ruimen, zet in wp-config.php:
 *
 *     define('STATUS_API_DELETE_DATA_ON_UNINSTALL', true);
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Ruim de gegevens van de huidige site op.
 */
function status_api_uninstall_site($delete_data) {
    global $wpdb;

    // Geplande cron jobs worden altijd verwijderd; die hebben zonder plugin geen functie
    wp_clear_scheduled_hook('check_green_status_expiry');

    if (!$delete_data) {
        return;
    }

    $options = array(
        'status_api_key',
        'status_api_secret',
        'status_api_clients',
        'status_api_clients_last_used',
        'status_message_data',
        'status_last_expiry_check',
        'status_api_db_version',
    );
    foreach ($options as $option) {
        delete_option($option);
    }

    // Transients: rate limiting en formulier-concepten
    foreach (array('status_api_auth_fails_', 'status_api_form_draft_') as $prefix) {
        $like_value = $wpdb->esc_like('_transient_' . $prefix) . '%';
        $like_timeout = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like_value,
            $like_timeout
        ));
    }

    $table = $wpdb->prefix . 'status_api_history';
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

$status_api_delete_data = defined('STATUS_API_DELETE_DATA_ON_UNINSTALL') && STATUS_API_DELETE_DATA_ON_UNINSTALL;

if (is_multisite()) {
    $status_api_site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
    foreach ($status_api_site_ids as $status_api_site_id) {
        switch_to_blog($status_api_site_id);
        status_api_uninstall_site($status_api_delete_data);
        restore_current_blog();
    }
} else {
    status_api_uninstall_site($status_api_delete_data);
}
