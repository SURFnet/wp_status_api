<?php
// Voorkom direct toegang tot het bestand
if (!defined('ABSPATH')) {
    exit;
}

// Audit-log Klasse - legt beheeracties op API clients vast (wie, wat, wanneer)
class Status_Audit_Log {

    private $table_name;

    // Verhoog dit bij elke wijziging aan het tabelschema in install_table()
    const DB_VERSION = '1.0';
    const DB_VERSION_OPTION = 'status_api_audit_db_version';

    // Aantal tekens van de API key dat wordt gelogd (identificatie zonder de volledige key vast te leggen)
    const KEY_PREFIX_LENGTH = 8;

    const EVENT_LABELS = array(
        'client_created' => 'Client aangemaakt',
        'client_migrated' => 'Legacy client gemigreerd',
        'client_revoked' => 'Client ingetrokken',
        'client_secret_regenerated' => 'Secret geregenereerd',
        'client_reactivated' => 'Secret geregenereerd en client heractiveerd',
        'client_deleted' => 'Client verwijderd',
    );

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'status_api_audit';

        // Houd het schema up-to-date, ook na updates en per site in een multisite-netwerk
        add_action('plugins_loaded', array($this, 'maybe_upgrade'));
    }

    /**
     * Plugin activatie - maak database tabel
     */
    public function activate() {
        $this->install_table();
    }

    public function maybe_upgrade() {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            $this->install_table();
        }
    }

    /**
     * Maak of werk de tabel bij via dbDelta (zelfde formaatregels als de historie-tabel)
     */
    private function install_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  event varchar(40) NOT NULL,
  client_label varchar(191) DEFAULT NULL,
  client_key_prefix varchar(20) DEFAULT NULL,
  user_id bigint(20) DEFAULT NULL,
  changed_by varchar(100) DEFAULT NULL,
  event_date datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY event (event),
  KEY event_date (event_date)
) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Leg een beheeractie vast. Het secret wordt nooit gelogd; van de API key alleen
     * de eerste KEY_PREFIX_LENGTH tekens.
     */
    public function log($event, $api_key, $client_label) {
        global $wpdb;

        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id > 0) {
            $user = wp_get_current_user();
            $changed_by = $user->display_name ?: $user->user_login ?: 'Onbekend';
        } else {
            $changed_by = 'system';
        }

        $wpdb->insert(
            $this->table_name,
            array(
                'event' => (string) $event,
                'client_label' => (string) $client_label,
                'client_key_prefix' => substr((string) $api_key, 0, self::KEY_PREFIX_LENGTH),
                'user_id' => $user_id > 0 ? $user_id : null,
                'changed_by' => $changed_by,
                'event_date' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s')
        );
    }

    /**
     * Haal de meest recente audit-regels op
     */
    public function get_entries($limit = 100) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} ORDER BY event_date DESC, id DESC LIMIT %d",
            (int) $limit
        ));
    }

    public static function get_event_label($event) {
        return isset(self::EVENT_LABELS[$event]) ? self::EVENT_LABELS[$event] : $event;
    }
}
