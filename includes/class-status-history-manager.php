<?php
// Voorkom direct toegang tot het bestand
if (!defined('ABSPATH')) {
    exit;
}

// Status History Manager Klasse - verantwoordelijk voor het beheren van de status historie
class Status_History_Manager {
    
    private $table_name;

    // Verhoog dit bij elke wijziging aan het tabelschema in install_table()
    const DB_VERSION = '1.0';
    const DB_VERSION_OPTION = 'status_api_db_version';
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'status_api_history';
        
        // Verwerk formulier acties
        add_action('admin_init', array($this, 'process_form_actions'));

        // Houd het schema up-to-date, ook na updates via de update-checker (die geen
        // activatie-hook uitvoeren) en op sites binnen een multisite-netwerk.
        add_action('plugins_loaded', array($this, 'maybe_upgrade'));
    }
    
    /**
     * Plugin activatie - maak database tabel
     */
    public function activate() {
        $this->install_table();
    }

    /**
     * Voer de schema-installatie/-upgrade uit als de opgeslagen versie afwijkt
     */
    public function maybe_upgrade() {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            $this->install_table();
        }
    }

    /**
     * Maak of werk de tabel bij via dbDelta.
     * Let op dbDelta-formaat: 'CREATE TABLE' zonder 'IF NOT EXISTS', één kolom per regel
     * en twee spaties na 'PRIMARY KEY'.
     */
    private function install_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL,
  title text,
  content longtext,
  expiry_date datetime DEFAULT NULL,
  change_date datetime NOT NULL,
  change_note text,
  changed_by varchar(100) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY change_date (change_date)
) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }
    
    /**
     * Plugin deactivatie
     */
    public function deactivate() {
        // Behoud de tabel bij deactivatie (verwijder alleen bij uninstall)
    }
    
    /**
     * Log een statuswijziging
     */
    public function log_status_change($status, $title, $content, $expiry_date, $change_note, $changed_by = null) {
        global $wpdb;
        
        if ($changed_by === null) {
            $current_user = wp_get_current_user();
            $changed_by = $current_user->display_name ?: $current_user->user_login ?: 'Onbekend';
        }
        
        $wpdb->insert(
            $this->table_name,
            array(
                'status' => $status,
                'title' => $title,
                'content' => $content,
                'expiry_date' => $expiry_date,
                'change_date' => current_time('mysql'),
                'change_note' => $change_note,
                'changed_by' => $changed_by
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Verwerk formulier acties
     */
    public function process_form_actions() {
        // Controleer of we op de history pagina zijn
        if (!isset($_GET['page']) || $_GET['page'] !== 'status-api-history') {
            return;
        }
        if (!current_user_can(Status_API_Plugin::get_history_manage_capability())) {
            return;
        }
        
        // Verwerk export actie
        if (isset($_POST['export_history']) && isset($_POST['export_history_nonce']) &&
            wp_verify_nonce($_POST['export_history_nonce'], 'export_history')) {
            $this->export_history();
        }
        
        // Verwerk clear history actie
        if (isset($_POST['clear_history']) && isset($_POST['clear_history_nonce']) && 
            wp_verify_nonce($_POST['clear_history_nonce'], 'clear_history')) {
            $this->clear_history();
            
            wp_safe_redirect(add_query_arg(
                array(
                    'page' => 'status-api-history',
                    'message' => 'history_cleared'
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }
    
    /**
     * Haal historie op
     */
    private function get_history($limit = 100, $offset = 0) {
        global $wpdb;
        
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             ORDER BY change_date DESC, id DESC 
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        );
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * Tel totaal aantal historie items
     */
    private function count_history() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }
    
    /**
     * Exporteer historie naar CSV
     */
    private function export_history() {
        global $wpdb;
        
        // Haal alle historie op
        $history = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY change_date DESC");
        
        // Set headers voor download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="status-historie-' . date('Y-m-d-His') . '.csv"');
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // UTF-8 BOM voor Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, array(
            'ID',
            'Status',
            'Titel',
            'Inhoud',
            'Vervaldatum',
            'Wijzigingsdatum',
            'Wijziging',
            'Gewijzigd door'
        ), ';');
        
        // Data
        foreach ($history as $entry) {
            $status_info = Status_Message_Manager::STATUS_OPTIONS[$entry->status] ?? Status_Message_Manager::STATUS_OPTIONS['geen'];
            
            fputcsv($output, array(
                $entry->id,
                $status_info['label'],
                $this->csv_safe($entry->title),
                $this->csv_safe(strip_tags($entry->content)),
                $entry->expiry_date ? date_i18n('j F Y H:i', strtotime($entry->expiry_date)) : '',
                date_i18n('j F Y H:i', strtotime($entry->change_date)),
                $this->csv_safe($entry->change_note),
                $this->csv_safe($entry->changed_by)
            ), ';');
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Voorkom CSV/formule-injectie: waarden die in Excel/LibreOffice als formule
     * worden uitgevoerd (beginnend met = + - @ tab of CR) krijgen een ' als prefix.
     */
    private function csv_safe($value) {
        $value = (string) $value;
        if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Wis historie
     */
    private function clear_history() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
    
    /**
     * Toon historie pagina
     */
    public function display_history_page() {
        // Paginering
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Haal historie op
        $history = $this->get_history($per_page, $offset);
        $total_items = $this->count_history();
        $total_pages = ceil($total_items / $per_page);

        ?>
        <div class="wrap">
            <h1>Status Historie</h1>
            
            <?php if (isset($_GET['message']) && $_GET['message'] === 'history_cleared') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Historie is gewist.</p>
                </div>
            <?php endif; ?>
            
            <p>Overzicht van alle statuswijzigingen en updates.</p>
            
            <?php if (current_user_can(Status_API_Plugin::get_history_manage_capability())) : ?>
            <div style="margin-bottom: 20px;">
                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('export_history', 'export_history_nonce'); ?>
                    <button type="submit" name="export_history" class="button">
                        <span class="dashicons dashicons-download" style="margin-top: 4px;"></span>
                        Exporteer naar CSV
                    </button>
                </form>

                <?php if ($total_items > 0) : ?>
                <form method="post" style="display: inline-block; margin-left: 10px;" onsubmit="return confirm('Weet je zeker dat je de complete historie wilt wissen?');">
                    <?php wp_nonce_field('clear_history', 'clear_history_nonce'); ?>
                    <button type="submit" name="clear_history" class="button">
                        <span class="dashicons dashicons-trash" style="margin-top: 4px;"></span> 
                        Wis Historie
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if (empty($history)) : ?>
                <p>Er zijn nog geen statuswijzigingen gelogd.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 150px;">Datum/Tijd</th>
                            <th style="width: 80px;">Status</th>
                            <th>Titel</th>
                            <th style="width: 200px;">Wijziging</th>
                            <th style="width: 150px;">Gewijzigd door</th>
                            <th style="width: 150px;">Vervaldatum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry) : 
                            $status_info = Status_Message_Manager::STATUS_OPTIONS[$entry->status] ?? Status_Message_Manager::STATUS_OPTIONS['geen'];
                        ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('j M Y H:i', strtotime($entry->change_date))); ?></td>
                            <td>
                                <span style="display:inline-block; width:12px; height:12px; background-color:<?php echo esc_attr($status_info['color']); ?>; margin-right:5px; border-radius:50%;"></span>
                                <?php echo esc_html($status_info['label']); ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($entry->title ?: '(Geen titel)'); ?></strong>
                                <?php if (!empty($entry->content)) : ?>
                                    <details style="margin-top: 5px;">
                                        <summary style="cursor: pointer; color: #2271b1;">Bekijk inhoud</summary>
                                        <div style="margin-top: 10px; padding: 10px; background-color: #f5f5f5; border-radius: 3px;">
                                            <?php echo wpautop(wp_kses_post($entry->content)); ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($entry->change_note); ?></td>
                            <td><?php echo esc_html($entry->changed_by); ?></td>
                            <td>
                                <?php if ($entry->expiry_date) : ?>
                                    <?php echo esc_html(date_i18n('j M Y H:i', strtotime($entry->expiry_date))); ?>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo sprintf('%d items', $total_items); ?></span>
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
