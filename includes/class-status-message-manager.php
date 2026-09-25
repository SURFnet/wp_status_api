<?php
// Voorkom direct toegang tot het bestand
if (!defined('ABSPATH')) {
    exit;
}

// Status Melding Manager Klasse - verantwoordelijk voor het beheren van de status melding
class Status_Message_Manager {
    
    // Cache voor status data
    private $status_cache = null;

    // Gedeelde history manager (geïnjecteerd door Status_API_Plugin)
    private $history_manager = null;

    // Minimaal aantal seconden tussen twee updates van 'status_last_expiry_check' buiten cron
    const EXPIRY_CHECK_LOG_INTERVAL = 300;
    
    // Status constanten
    const STATUS_OPTIONS = array(
        'geen' => array('label' => 'Geen', 'color' => '#999999', 'bg_color' => '#f5f5f5'),
        'green' => array('label' => 'Groen', 'color' => '#008939', 'bg_color' => '#B8E3C9'),
        'orange' => array('label' => 'Oranje', 'color' => '#FFC100', 'bg_color' => '#FEF8D3'),
        'red' => array('label' => 'Rood', 'color' => '#DA362D', 'bg_color' => '#FFCDCA')
    );
    
    /**
     * Constructor
     */
    public function __construct($history_manager = null) {
        $this->history_manager = $history_manager;

        // Registreer scripts en styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Verwerk formulier acties
        add_action('admin_init', array($this, 'process_form_actions'));
        
        // Registreer cron event voor controle vervaldatum groene status
        add_action('init', array($this, 'register_cron_events'));
        
        // Voeg actie toe voor status controle
        add_action('check_green_status_expiry', array($this, 'check_and_update_expired_status'));
    }
    
    /**
     * Plugin activatie
     */
    public function activate() {
        // Zorg ervoor dat de default statusdata bestaat
        if (false === get_option('status_message_data')) {
            update_option('status_message_data', $this->get_default_status_data());
        }
        
        // Planner voor cron job
        if (!wp_next_scheduled('check_green_status_expiry')) {
            wp_schedule_event(time(), 'hourly', 'check_green_status_expiry');
        }
    }
    
    /**
     * Plugin deactivatie
     */
    public function deactivate() {
        // Verwijder alle geplande cron jobs van deze hook (ook eventuele dubbele)
        wp_clear_scheduled_hook('check_green_status_expiry');
    }

    /**
     * Haal de history manager op; valt terug op een eigen instantie als er geen is
     * geïnjecteerd (bijv. bij `new Status_Message_Manager()` vanuit externe code).
     */
    private function get_history_manager() {
        if ($this->history_manager === null) {
            $this->history_manager = new Status_History_Manager();
        }
        return $this->history_manager;
    }
    
    /**
     * Helper functie voor default status data
     */
    private function get_default_status_data() {
        return array(
            'title' => '',
            'content' => '',
            'status' => 'geen',
            'expiry_date' => null
        );
    }
    
    /**
     * Helper functie om status data op te halen met cache
     */
    private function get_status_data($force_refresh = false) {
        if ($this->status_cache === null || $force_refresh) {
            $this->status_cache = get_option('status_message_data', $this->get_default_status_data());
        }
        return $this->status_cache;
    }
    
    /**
     * Update status data en cache
     */
    private function update_status_data($data) {
        $this->status_cache = $data;
        return update_option('status_message_data', $data);
    }
    
    /**
     * Registreer cron events
     */
    public function register_cron_events() {
        if (!wp_next_scheduled('check_green_status_expiry')) {
            wp_schedule_event(time(), 'hourly', 'check_green_status_expiry');
        }
    }
    
    /**
     * Controleer en update vervallen status
     */
    public function check_and_update_expired_status() {
        $status_data = $this->get_status_data();
        
        // Controleer alleen als de status groen is en een vervaldatum heeft
        if ($status_data['status'] !== 'green' || empty($status_data['expiry_date'])) {
            return;
        }
        
        $current_time = current_time('timestamp');
        $expiry_time = strtotime($status_data['expiry_date']);
        
        // Als de vervaldatum is verstreken, zet status naar 'geen'
        $expired = $expiry_time <= $current_time;
        if ($expired) {
            $status_data['status'] = 'geen';
            $this->update_status_data($status_data);
            
            // Log naar historie als automatische statuswijziging
            $this->get_history_manager()->log_status_change(
                'geen',
                $status_data['title'],
                $status_data['content'],
                null,
                'Automatisch verlopen',
                'system'
            );
            
            // Log de statuswijziging
            error_log(sprintf(
                'Status API: Status is verlopen en teruggezet naar "geen" - Verlopen op: %s - Huidige tijd: %s',
                date('Y-m-d H:i:s', $expiry_time),
                date('Y-m-d H:i:s', $current_time)
            ));
        }
        
        // Update laatst uitgevoerde check timestamp (voor debugging).
        // Altijd vanuit cron of bij een statuswijziging; anders (API-calls, admin) maximaal
        // eens per EXPIRY_CHECK_LOG_INTERVAL seconden om database-writes te beperken.
        $last_check = get_option('status_last_expiry_check');
        $last_check_time = is_string($last_check) ? strtotime($last_check) : false;
        if ($expired || wp_doing_cron() || $last_check_time === false
            || ($current_time - $last_check_time) >= self::EXPIRY_CHECK_LOG_INTERVAL) {
            update_option('status_last_expiry_check', current_time('mysql'));
        }
    }
    
    /**
     * Scripts en styles toevoegen
     */
    public function enqueue_admin_scripts($hook) {
        // Alleen laden op onze admin pagina's
        if (strpos($hook, 'status-api') === false) {
            return;
        }
        
        // Native datumveld (<input type="date">); geen jQuery UI of externe CDN nodig
        wp_enqueue_script(
            'status-api-status-page',
            plugins_url('assets/js/status-page.js', STATUS_API_PLUGIN_FILE),
            array(),
            (string) @filemtime(plugin_dir_path(STATUS_API_PLUGIN_FILE) . 'assets/js/status-page.js'),
            true
        );
        wp_localize_script('status-api-status-page', 'statusApiStatusPage', array(
            'today' => wp_date('Y-m-d'),
        ));
    }
    
    /**
     * Verwerk formulier acties
     */
    public function process_form_actions() {
        // Controleer of we op de status pagina zijn
        if (!isset($_GET['page']) || $_GET['page'] !== 'status-api') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Verwerk status formulier
        if (isset($_POST['status_action']) && $_POST['status_action'] === 'save') {
            // Controleer nonce
            if (!isset($_POST['status_nonce']) || !wp_verify_nonce($_POST['status_nonce'], 'save_status')) {
                wp_die('Beveiligingscontrole mislukt. Probeer het opnieuw.');
            }
            
            // Verkrijg huidige status voor vergelijking
            $current_status = $this->get_status_data();
            
            // Bereid nieuwe status data voor
            $new_status_data = $this->prepare_status_data_from_form();

            // Ongeldige vervaldatum/-tijd: niet opslaan, invoer bewaren en melding tonen
            if ($new_status_data === false) {
                set_transient($this->get_form_draft_key(), $this->get_form_draft(), 10 * MINUTE_IN_SECONDS);
                wp_safe_redirect(add_query_arg(
                    array(
                        'page' => 'status-api',
                        'message' => 'invalid_expiry'
                    ),
                    admin_url('admin.php')
                ));
                exit;
            }
            
            // Bepaal type wijziging
            $change_note = $this->determine_change_note($current_status, $new_status_data);
            
            // Sla status data op
            $this->update_status_data($new_status_data);
            
            // Log naar historie
            $this->get_history_manager()->log_status_change(
                $new_status_data['status'],
                $new_status_data['title'],
                $new_status_data['content'],
                $new_status_data['expiry_date'],
                $change_note
            );
            
            // Redirect met succes bericht
            wp_safe_redirect(add_query_arg(
                array(
                    'page' => 'status-api', 
                    'message' => 'updated'
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }
    
    /**
     * Bepaal wat er is gewijzigd voor het logboek
     */
    private function determine_change_note($old_status, $new_status) {
        $changes = array();
        
        // Check of dit een nieuwe melding is
        if (empty($old_status['title']) && empty($old_status['content']) && $old_status['status'] === 'geen') {
            return 'Nieuwe melding aangemaakt';
        }
        
        // Check wijzigingen
        if ($old_status['status'] !== $new_status['status']) {
            $old_label = self::STATUS_OPTIONS[$old_status['status']]['label'];
            $new_label = self::STATUS_OPTIONS[$new_status['status']]['label'];
            $changes[] = "Status gewijzigd van {$old_label} naar {$new_label}";
        }
        
        if ($old_status['title'] !== $new_status['title']) {
            $changes[] = "Titel gewijzigd";
        }
        
        if ($old_status['content'] !== $new_status['content']) {
            $changes[] = "Inhoud gewijzigd";
        }
        
        if ($old_status['expiry_date'] !== $new_status['expiry_date']) {
            if (empty($old_status['expiry_date']) && !empty($new_status['expiry_date'])) {
                $changes[] = "Vervaldatum toegevoegd";
            } elseif (!empty($old_status['expiry_date']) && empty($new_status['expiry_date'])) {
                $changes[] = "Vervaldatum verwijderd";
            } else {
                $changes[] = "Vervaldatum gewijzigd";
            }
        }
        
        return !empty($changes) ? implode(', ', $changes) : 'Update zonder wijzigingen';
    }
    
    /**
     * Bereid status data voor uit formulier
     */
    private function prepare_status_data_from_form() {
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'geen';

        if (!array_key_exists($status, self::STATUS_OPTIONS)) {
            $status = 'geen';
        }

        $status_data = array(
            'title' => $title,
            'content' => $content,
            'status' => $status,
            'expiry_date' => null
        );
        
        // Bereid expiry date voor voor groene status
        if ($status === 'green' && isset($_POST['expiry_date']) && !empty($_POST['expiry_date'])) {
            $date = trim(sanitize_text_field(wp_unslash($_POST['expiry_date'])));
            $time = isset($_POST['expiry_time']) && !empty($_POST['expiry_time']) 
                  ? trim(sanitize_text_field(wp_unslash($_POST['expiry_time'])))
                  : '00:00';

            $expiry_date = $this->normalize_expiry($date, $time);
            if ($expiry_date === false) {
                return false;
            }

            $status_data['expiry_date'] = $expiry_date;
        }
        
        return $status_data;
    }

    /**
     * Valideer vervaldatum (Y-m-d) en -tijd (H:i of H:i:s).
     * Retourneert 'Y-m-d H:i' of false bij een ongeldige (of niet-bestaande) datum/tijd.
     */
    private function normalize_expiry($date, $time) {
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            $time = substr($time, 0, 5);
        }

        $value = $date . ' ' . $time;
        $parsed = DateTime::createFromFormat('!Y-m-d H:i', $value);
        if ($parsed === false || $parsed->format('Y-m-d H:i') !== $value) {
            return false;
        }

        return $value;
    }

    private function get_form_draft_key() {
        return 'status_api_form_draft_' . get_current_user_id();
    }

    /**
     * Bewaar de ingevulde formulierwaarden, zodat ze na een validatiefout niet verloren gaan
     */
    private function get_form_draft() {
        return array(
            'title' => isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '',
            'content' => isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '',
            'status' => isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'geen',
            'expiry_date' => isset($_POST['expiry_date']) ? sanitize_text_field(wp_unslash($_POST['expiry_date'])) : '',
            'expiry_time' => isset($_POST['expiry_time']) ? sanitize_text_field(wp_unslash($_POST['expiry_time'])) : '',
        );
    }
    
    /**
     * Get status info
     */
    private function get_status_info($status) {
        return isset(self::STATUS_OPTIONS[$status]) ? self::STATUS_OPTIONS[$status] : self::STATUS_OPTIONS['geen'];
    }
    
    /**
     * Render countdown display
     */
    private function render_countdown($expiry_date) {
        $now = current_time('timestamp');
        $expiry = strtotime($expiry_date);
        $time_remaining = $expiry - $now;
        
        if ($time_remaining <= 0) {
            return 'Verlopen';
        }
        
        $days = floor($time_remaining / (60 * 60 * 24));
        $hours = floor(($time_remaining % (60 * 60 * 24)) / (60 * 60));
        $minutes = floor(($time_remaining % (60 * 60)) / 60);
        
        $countdown = array();
        if ($days > 0) {
            $countdown[] = $days . ' ' . ($days == 1 ? 'dag' : 'dagen');
        }
        if ($hours > 0 || $days > 0) {
            $countdown[] = $hours . ' ' . ($hours == 1 ? 'uur' : 'uren');
        }
        $countdown[] = $minutes . ' ' . ($minutes == 1 ? 'minuut' : 'minuten');
        
        return implode(', ', array_slice($countdown, 0, 2)) . (count($countdown) > 2 ? ' en ' . end($countdown) : '');
    }
    
    /**
     * Toon status pagina
     */
    public function display_status_page() {
        // Voer een real-time check uit voor verlopen statussen
        $this->check_and_update_expired_status();
        
        // Verkrijg huidige status data
        $status_data = $this->get_status_data(true); // Force refresh na possible update
        
        $title = $status_data['title'];
        $content = $status_data['content'];
        $status = $status_data['status'];
        
        $expiry_date_only = '';
        $expiry_time_only = '';
        
        if (!empty($status_data['expiry_date'])) {
            $expiry_date_only = date('Y-m-d', strtotime($status_data['expiry_date']));
            $expiry_time_only = date('H:i', strtotime($status_data['expiry_date']));
        }
        
        $status_info = $this->get_status_info($status);

        // Waarden voor het formulier; na een validatiefout de eerder ingevulde waarden
        $form_title = $title;
        $form_content = $content;
        $form_status = $status;
        $form_expiry_date = $expiry_date_only;
        $form_expiry_time = $expiry_time_only;

        $invalid_expiry = isset($_GET['message']) && $_GET['message'] === 'invalid_expiry';
        if ($invalid_expiry) {
            $draft = get_transient($this->get_form_draft_key());
            if (is_array($draft)) {
                $form_title = $draft['title'];
                $form_content = $draft['content'];
                $form_status = array_key_exists($draft['status'], self::STATUS_OPTIONS) ? $draft['status'] : 'geen';
                $form_expiry_date = $draft['expiry_date'];
                $form_expiry_time = $draft['expiry_time'];
                delete_transient($this->get_form_draft_key());
            }
        }
        
        ?>
        <div class="wrap">
            <h1>Status Melding</h1>
            
            <?php if (isset($_GET['message']) && $_GET['message'] === 'updated') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Status melding bijgewerkt.</p>
                </div>
            <?php elseif ($invalid_expiry) : ?>
                <div class="notice notice-error is-dismissible">
                    <p>Status melding <strong>niet</strong> opgeslagen: de vervaldatum of -tijd is ongeldig. Gebruik het formaat JJJJ-MM-DD en UU:MM.</p>
                </div>
            <?php endif; ?>
            
            <div class="card" style="padding: 20px;">
                <h2>Huidige Status</h2>
                
                <div style="margin-bottom: 20px; background-color: <?php echo $status_info['bg_color']; ?>; padding: 15px; border-radius: 5px;">
                    <div style="margin-bottom: 10px;">
                        <strong>Status:</strong> 
                        <span style="display:inline-block; width:12px; height:12px; background-color:<?php echo $status_info['color']; ?>; margin-right:5px; border-radius:50%;"></span>
                        <?php echo esc_html($status_info['label']); ?>
                    </div>
                    
                    <?php if ($status === 'green' && !empty($status_data['expiry_date'])) : ?>
                        <p>
                            <strong>Vervalt op:</strong> <?php echo esc_html(date_i18n('j F Y H:i', strtotime($status_data['expiry_date']))); ?><br>
                            <strong>Huidige tijd:</strong> <?php echo esc_html(date_i18n('j F Y H:i')); ?><br>
                            <strong>Nog geldig voor:</strong> <?php echo esc_html($this->render_countdown($status_data['expiry_date'])); ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($title)) : ?>
                        <h3 style="color: <?php echo $status_info['color']; ?>; margin-top: 15px; margin-bottom: 10px;"><?php echo esc_html($title); ?></h3>
                    <?php endif; ?>
                    
                    <?php if (!empty($content)) : ?>
                        <div class="status-content">
                            <?php echo wpautop($content); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <h2>Status Melding Bewerken</h2>
            <p>Er kan altijd maar één status melding actief zijn. De nieuwste status overschrijft altijd de vorige.</p>
            
            <form method="post" action="">
                <input type="hidden" name="status_action" value="save">
                <?php wp_nonce_field('save_status', 'status_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="title">Titel</label></th>
                        <td>
                            <input type="text" name="title" id="title" class="regular-text" value="<?php echo esc_attr($form_title); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="content">Inhoud</label></th>
                        <td>
                            <?php
                            wp_editor($form_content, 'content', array(
                                'textarea_name' => 'content',
                                'media_buttons' => false,
                                'textarea_rows' => 10
                            ));
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status">
                                <?php foreach (self::STATUS_OPTIONS as $key => $info) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($form_status, $key); ?>>
                                        <?php echo esc_html($info['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="expiry-date-field" style="<?php echo ($form_status !== 'green') ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="expiry_date">Vervaldatum</label></th>
                        <td>
                            <input type="date" name="expiry_date" id="expiry_date" value="<?php echo esc_attr($form_expiry_date); ?>" pattern="\d{4}-\d{2}-\d{2}" placeholder="JJJJ-MM-DD">
                            <p class="description">Datum waarop de status terug naar 'geen' gaat.</p>
                        </td>
                    </tr>
                    <tr class="expiry-date-field" style="<?php echo ($form_status !== 'green') ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="expiry_time">Vervaltijd</label></th>
                        <td>
                            <input type="time" name="expiry_time" id="expiry_time" value="<?php echo esc_attr($form_expiry_time); ?>">
                            <p class="description">Tijd waarop de status terug naar 'geen' gaat.</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="Status Update Opslaan">
                </p>
            </form>
        </div>
        <?php
    }
}
