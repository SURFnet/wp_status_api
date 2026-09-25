<?php
// Voorkom direct toegang tot het bestand
if (!defined('ABSPATH')) {
    exit;
}

// API Manager Klasse - verantwoordelijk voor API authenticatie en endpoints
class Status_API_Manager {
    
    // API instellingen
    private $api_key_option = 'status_api_key';
    private $api_secret_option = 'status_api_secret';
    private $api_clients_option = 'status_api_clients';
    private $api_clients_last_used_option = 'status_api_clients_last_used';
    private $api_clients_auth_methods_option = 'status_api_clients_auth_methods';

    // Minimaal aantal seconden tussen twee updates van last_used_at per client
    const LAST_USED_THROTTLE = 300;

    // Authenticatie via api_key/api_secret in de URL is verouderd sinds dit moment (2026-09-25 UTC).
    // Wordt meegestuurd in de Deprecation header (RFC 9745).
    const QUERY_AUTH_DEPRECATED_SINCE = 1790294400;

    // Methode waarmee het huidige request is geauthenticeerd: 'query', 'bearer' of null
    private $auth_method = null;

    // Audit-log voor beheeracties op clients (geïnjecteerd door Status_API_Plugin)
    private $audit_log = null;

    /**
     * Constructor
     */
    public function __construct($audit_log = null) {
        $this->audit_log = $audit_log;

        // Registreer API endpoints
        add_action('rest_api_init', array($this, 'register_api_endpoints'));
        
        // Verwerk formulier acties
        add_action('admin_init', array($this, 'process_form_actions'));

        // Styles en scripts voor de instellingenpagina
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Laad CSS/JS alleen op de API Instellingen pagina
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'status-api-settings') === false) {
            return;
        }

        $dir = plugin_dir_path(STATUS_API_PLUGIN_FILE);
        wp_enqueue_style(
            'status-api-settings-page',
            plugins_url('assets/css/settings-page.css', STATUS_API_PLUGIN_FILE),
            array('dashicons'),
            (string) @filemtime($dir . 'assets/css/settings-page.css')
        );
        wp_enqueue_script(
            'status-api-settings-page',
            plugins_url('assets/js/settings-page.js', STATUS_API_PLUGIN_FILE),
            array(),
            (string) @filemtime($dir . 'assets/js/settings-page.js'),
            true
        );
    }
    
    /**
     * Plugin activatie
     */
    public function activate() {
        $this->maybe_migrate_single_key_to_clients();

        // Genereer default client als er nog geen clients bestaan
        $clients = $this->get_api_clients();
        if (empty($clients)) {
            $this->create_api_client('Default');
        }
    }
    
    /**
     * Plugin deactivatie
     */
    public function deactivate() {
        // Niets nodig voor deactivatie
    }

    /**
     * Haal alle API clients op.
     *
     * Structuur:
     * [
     *   'api_key_string' => [
     *     'label' => 'Clientnaam',
     *     'secret' => '...'
     *     'revoked' => false,
     *     'created_at' => 1710000000,
     *     'secret_regenerated_at' => 1710000000|null,
     *     'last_used_at' => 1710000000|null
     *   ],
     * ]
     */
    private function get_api_clients() {
        $clients = get_option($this->api_clients_option, array());
        if (!is_array($clients)) {
            $clients = array();
        }

        foreach ($clients as $key => $client) {
            if (!is_array($client)) {
                unset($clients[$key]);
                continue;
            }

            $clients[$key] = wp_parse_args($client, array(
                'label' => $key,
                'secret' => '',
                'revoked' => false,
                'created_at' => null,
                'secret_regenerated_at' => null,
                'last_used_at' => null,
            ));
        }

        // last_used_at wordt apart bijgehouden (zie touch_client_last_used), neem de meest recente waarde
        $last_used = $this->get_clients_last_used();
        foreach ($last_used as $key => $timestamp) {
            if (isset($clients[$key]) && (int) $timestamp > (int) $clients[$key]['last_used_at']) {
                $clients[$key]['last_used_at'] = (int) $timestamp;
            }
        }

        return $clients;
    }

    private function get_clients_last_used() {
        $last_used = get_option($this->api_clients_last_used_option, array());
        return is_array($last_used) ? $last_used : array();
    }

    /**
     * Werk last_used_at van een client bij.
     *
     * Wordt apart van de clients option opgeslagen, zodat een API request nooit
     * gelijktijdige wijzigingen in de admin (zoals intrekken) kan overschrijven.
     * Wordt maximaal eens per LAST_USED_THROTTLE seconden per client geschreven.
     */
    private function get_clients_auth_methods() {
        $methods = get_option($this->api_clients_auth_methods_option, array());
        return is_array($methods) ? $methods : array();
    }

    /**
     * Onthoud per client wanneer welke authenticatiemethode ('query' of 'bearer') voor het
     * laatst is gebruikt. Zo is te zien welke integraties nog URL-parameters gebruiken
     * voordat die methode wordt uitgezet. Maximaal eens per LAST_USED_THROTTLE per methode.
     */
    private function touch_client_auth_method($api_key, $method) {
        $now = time();
        $throttle = (int) apply_filters('status_api_last_used_throttle', self::LAST_USED_THROTTLE);
        $methods = $this->get_clients_auth_methods();
        $previous = isset($methods[$api_key][$method]) ? (int) $methods[$api_key][$method] : 0;

        if ($previous > 0 && ($now - $previous) < $throttle) {
            return;
        }

        if (!isset($methods[$api_key]) || !is_array($methods[$api_key])) {
            $methods[$api_key] = array();
        }
        $methods[$api_key][$method] = $now;
        update_option($this->api_clients_auth_methods_option, $methods, false);
    }

    /**
     * Mag authenticatie via api_key/api_secret in de URL (nog) worden gebruikt?
     * Standaard ja (backwards compatible). Uitzetten met:
     *     add_filter('status_api_allow_query_auth', '__return_false');
     */
    public function is_query_auth_allowed() {
        return (bool) apply_filters('status_api_allow_query_auth', true);
    }

    private function touch_client_last_used($api_key, $clients) {
        $now = time();
        $throttle = (int) apply_filters('status_api_last_used_throttle', self::LAST_USED_THROTTLE);
        $previous = isset($clients[$api_key]['last_used_at']) ? (int) $clients[$api_key]['last_used_at'] : 0;

        if ($previous > 0 && ($now - $previous) < $throttle) {
            return;
        }

        $last_used = $this->get_clients_last_used();
        $last_used[$api_key] = $now;
        update_option($this->api_clients_last_used_option, $last_used, false);
    }

    private function save_api_clients($clients) {
        if (!is_array($clients)) {
            $clients = array();
        }
        update_option($this->api_clients_option, $clients);
    }

    /**
     * Leg een beheeractie op een client vast in de audit-log.
     * Valt terug op een eigen instantie als er geen is geïnjecteerd
     * (bijv. bij `new Status_API_Manager()` vanuit externe code).
     */
    private function audit($event, $api_key, $label) {
        if ($this->audit_log === null) {
            $this->audit_log = new Status_Audit_Log();
        }
        $this->audit_log->log($event, $api_key, $label);
    }

    private function maybe_migrate_single_key_to_clients() {
        // Migreer alleen als de clients option nog nooit heeft bestaan (sites van vóór 0.9.9).
        // Een lege lijst betekent dat alle clients bewust zijn verwijderd; die mogen niet
        // terugkomen via de legacy options.
        if (get_option($this->api_clients_option, false) !== false) {
            return;
        }

        $legacy_key = get_option($this->api_key_option);
        $legacy_secret = get_option($this->api_secret_option);
        if (!empty($legacy_key) && !empty($legacy_secret)) {
            $now = time();
            $clients = array(
                $legacy_key => array(
                    'label' => 'Legacy',
                    'secret' => $legacy_secret,
                    'revoked' => false,
                    'created_at' => $now,
                    'secret_regenerated_at' => $now,
                    'last_used_at' => null,
                ),
            );
            $this->save_api_clients($clients);
            $this->audit('client_migrated', $legacy_key, 'Legacy');
        }
    }

    private function create_api_client($label) {
        $label = is_string($label) ? trim($label) : '';
        if ($label === '') {
            $label = 'Client';
        }

        $clients = $this->get_api_clients();

        do {
            $api_key = wp_generate_password(32, false);
        } while (isset($clients[$api_key]));

        $api_secret = wp_generate_password(64, false);

        $now = time();
        $clients[$api_key] = array(
            'label' => $label,
            'secret' => $api_secret,
            'revoked' => false,
            'created_at' => $now,
            'secret_regenerated_at' => $now,
            'last_used_at' => null,
        );

        $this->save_api_clients($clients);
        $this->audit('client_created', $api_key, $label);

        // Houd legacy options gevuld met "eerste" client voor backwards-compat
        if (empty(get_option($this->api_key_option)) || empty(get_option($this->api_secret_option))) {
            update_option($this->api_key_option, $api_key);
            update_option($this->api_secret_option, $api_secret);
        }

        return array($api_key, $api_secret);
    }

    private function revoke_api_client($api_key) {
        $clients = $this->get_api_clients();
        if (!isset($clients[$api_key])) {
            return false;
        }
        $clients[$api_key]['revoked'] = true;
        $this->save_api_clients($clients);
        $this->audit('client_revoked', $api_key, $clients[$api_key]['label']);
        return true;
    }

    private function regenerate_api_client_secret($api_key) {
        $clients = $this->get_api_clients();
        if (!isset($clients[$api_key])) {
            return false;
        }
        $was_revoked = !empty($clients[$api_key]['revoked']);
        $clients[$api_key]['secret'] = wp_generate_password(64, false);
        $clients[$api_key]['revoked'] = false;
        $clients[$api_key]['secret_regenerated_at'] = time();
        $this->save_api_clients($clients);
        $this->audit($was_revoked ? 'client_reactivated' : 'client_secret_regenerated', $api_key, $clients[$api_key]['label']);

        // Houd legacy options in sync zodat er geen verouderd secret achterblijft
        if (get_option($this->api_key_option) === $api_key) {
            update_option($this->api_secret_option, $clients[$api_key]['secret']);
        }

        return $clients[$api_key]['secret'];
    }

    private function delete_api_client($api_key) {
        $clients = $this->get_api_clients();
        if (!isset($clients[$api_key])) {
            return false;
        }
        if (empty($clients[$api_key]['revoked'])) {
            return false;
        }
        $label = $clients[$api_key]['label'];
        unset($clients[$api_key]);
        $this->save_api_clients($clients);
        $this->audit('client_deleted', $api_key, $label);

        $last_used = $this->get_clients_last_used();
        if (isset($last_used[$api_key])) {
            unset($last_used[$api_key]);
            update_option($this->api_clients_last_used_option, $last_used, false);
        }

        $methods = $this->get_clients_auth_methods();
        if (isset($methods[$api_key])) {
            unset($methods[$api_key]);
            update_option($this->api_clients_auth_methods_option, $methods, false);
        }

        // Verwijder legacy options als die naar deze (verwijderde) client wezen
        if (get_option($this->api_key_option) === $api_key) {
            delete_option($this->api_key_option);
            delete_option($this->api_secret_option);
        }

        return true;
    }
    
    /**
     * Verwerk formulier acties
     */
    public function process_form_actions() {
        // Controleer of we op de API instellingen pagina zijn
        if (!isset($_GET['page']) || $_GET['page'] !== 'status-api-settings') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        // Nieuwe client aanmaken
        if (isset($_POST['create_client']) && isset($_POST['create_client_nonce']) &&
            wp_verify_nonce($_POST['create_client_nonce'], 'create_client')) {
            $label = isset($_POST['client_label']) ? sanitize_text_field(wp_unslash($_POST['client_label'])) : 'Client';
            $this->create_api_client($label);

            wp_safe_redirect(add_query_arg(
                array(
                    'page' => 'status-api-settings',
                    'tab' => 'clients',
                    'message' => 'client_created'
                ),
                admin_url('admin.php')
            ));
            exit;
        }

        // Client intrekken
        if (isset($_POST['revoke_client']) && isset($_POST['revoke_client_nonce']) &&
            wp_verify_nonce($_POST['revoke_client_nonce'], 'revoke_client')) {
            $api_key = isset($_POST['client_key']) ? sanitize_text_field(wp_unslash($_POST['client_key'])) : '';
            if ($api_key !== '') {
                $this->revoke_api_client($api_key);
            }

            wp_safe_redirect(add_query_arg(
                array(
                    'page' => 'status-api-settings',
                    'tab' => 'clients',
                    'message' => 'client_revoked'
                ),
                admin_url('admin.php')
            ));
            exit;
        }

        // Client secret regenereren
        if (isset($_POST['regenerate_client_secret']) && isset($_POST['regenerate_client_secret_nonce']) &&
            wp_verify_nonce($_POST['regenerate_client_secret_nonce'], 'regenerate_client_secret')) {
            $api_key = isset($_POST['client_key']) ? sanitize_text_field(wp_unslash($_POST['client_key'])) : '';
            if ($api_key !== '') {
                $this->regenerate_api_client_secret($api_key);
            }

            wp_safe_redirect(add_query_arg(
                array(
                    'page' => 'status-api-settings',
                    'tab' => 'clients',
                    'message' => 'client_secret_regenerated'
                ),
                admin_url('admin.php')
            ));
            exit;
        }

        // Client verwijderen (alleen als hij al ingetrokken is)
        if (isset($_POST['delete_client']) && isset($_POST['delete_client_nonce']) &&
            wp_verify_nonce($_POST['delete_client_nonce'], 'delete_client')) {
            $api_key = isset($_POST['client_key']) ? sanitize_text_field(wp_unslash($_POST['client_key'])) : '';
            if ($api_key !== '') {
                $this->delete_api_client($api_key);
            }

            wp_safe_redirect(add_query_arg(
                array(
                    'page' => 'status-api-settings',
                    'tab' => 'clients',
                    'message' => 'client_deleted'
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }
    
    /**
     * Genereer Bearer token van API key en secret
     */
    private function generate_bearer_token($api_key, $api_secret) {
        // Maak een HMAC-SHA256 hash van de API key met het secret als sleutel
        $signature = hash_hmac('sha256', $api_key, $api_secret);
        
        // Combineer API key en signature met een delimiter
        return base64_encode($api_key . ':' . $signature);
    }
    
    /**
     * Toon instellingen pagina
     */
    public function display_settings_page() {
        $this->maybe_migrate_single_key_to_clients();
        $clients = $this->get_api_clients();
        $auth_methods = $this->get_clients_auth_methods();
        $query_auth_allowed = $this->is_query_auth_allowed();

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'clients';
        if (!in_array($active_tab, array('clients', 'audit', 'docs'), true)) {
            $active_tab = 'clients';
        }

        $example_key = '';
        $example_secret = '';
        foreach ($clients as $client_key => $client) {
            if (!empty($client['revoked'])) {
                continue;
            }
            $example_key = $client_key;
            $example_secret = $client['secret'];
            break;
        }
        $example_bearer_token = (!empty($example_key) && !empty($example_secret)) ? $this->generate_bearer_token($example_key, $example_secret) : '';
        
        ?>
        <div class="wrap">
            <h1>Status API Instellingen</h1>

            <h2 class="nav-tab-wrapper">
                <a
                    href="<?php echo esc_url(add_query_arg(array('page' => 'status-api-settings', 'tab' => 'clients'), admin_url('admin.php'))); ?>"
                    class="nav-tab <?php echo $active_tab === 'clients' ? 'nav-tab-active' : ''; ?>"
                >
                    API clients
                </a>
                <a
                    href="<?php echo esc_url(add_query_arg(array('page' => 'status-api-settings', 'tab' => 'docs'), admin_url('admin.php'))); ?>"
                    class="nav-tab <?php echo $active_tab === 'docs' ? 'nav-tab-active' : ''; ?>"
                >
                    Documentatie
                </a>
                <a
                    href="<?php echo esc_url(add_query_arg(array('page' => 'status-api-settings', 'tab' => 'audit'), admin_url('admin.php'))); ?>"
                    class="nav-tab <?php echo $active_tab === 'audit' ? 'nav-tab-active' : ''; ?>"
                >
                    Audit-log
                </a>
            </h2>
            
            <?php if (isset($_GET['message']) && $_GET['message'] === 'client_created') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Client aangemaakt!</p>
                </div>
            <?php elseif (isset($_GET['message']) && $_GET['message'] === 'client_revoked') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Client ingetrokken!</p>
                </div>
            <?php elseif (isset($_GET['message']) && $_GET['message'] === 'client_secret_regenerated') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Client secret opnieuw gegenereerd! De client is (weer) actief; het oude secret en de oude Bearer token werken niet meer.</p>
                </div>
            <?php elseif (isset($_GET['message']) && $_GET['message'] === 'client_deleted') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Client verwijderd!</p>
                </div>
            <?php endif; ?>

            <?php if ($active_tab === 'clients') : ?>
                <p class="status-api-muted">Maak per integratie een eigen client aan. Je kunt clients intrekken of (indien ingetrokken) verwijderen.</p>

                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px; max-width: 1400px; margin: 12px 0 18px;">
                    <h2 style="margin: 0 0 10px;">
                        <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle;"></span>
                        Nieuwe client
                    </h2>
                    <form method="post" action="" style="display:flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                        <?php wp_nonce_field('create_client', 'create_client_nonce'); ?>
                        <div>
                            <label for="client_label"><strong>Naam</strong></label><br />
                            <input type="text" id="client_label" name="client_label" class="regular-text" placeholder="Bijv. Dashboard, Monitor, Klant X" />
                        </div>
                        <div>
                            <button type="submit" name="create_client" class="button button-primary">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                Client toevoegen
                            </button>
                        </div>
                    </form>
                    <p class="status-api-muted" style="margin: 10px 0 0;">
                        Tip: maak per applicatie/klant een aparte client zodat je eenvoudig kunt intrekken of roteren.
                    </p>
                </div>

                <h2>API clients</h2>
                <table class="widefat striped" style="max-width: 1400px;">
                    <thead>
                        <tr>
                            <th style="width: 160px;">Naam</th>
                            <th>Credentials</th>
                            <th style="width: 260px;">Status</th>
                            <th style="width: 330px;">Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clients)) : ?>
                            <tr><td colspan="4">Nog geen clients.</td></tr>
                        <?php else : ?>
                            <?php $i = 0; foreach ($clients as $client_key => $client) : $i++; ?>
                                <?php
                                    $client_secret = $client['secret'];
                                    $client_token = (!empty($client_key) && !empty($client_secret)) ? $this->generate_bearer_token($client_key, $client_secret) : '';
                                    $client_open_url = add_query_arg(
                                        array(
                                            'api_key' => $client_key,
                                            'api_secret' => $client_secret,
                                        ),
                                        site_url('/wp-json/status-api/v1/status')
                                    );
                                    $is_revoked = !empty($client['revoked']);

                                    $id_key = 'status_api_client_key_' . $i;
                                    $id_secret = 'status_api_client_secret_' . $i;
                                    $id_token = 'status_api_client_token_' . $i;
                                    $id_url = 'status_api_client_url_' . $i;
                                    $id_secret_wrap = 'status_api_client_secret_wrap_' . $i;
                                    $id_token_wrap = 'status_api_client_token_wrap_' . $i;
                                    $id_url_wrap = 'status_api_client_url_wrap_' . $i;
                                ?>
                                <tr class="<?php echo $is_revoked ? 'status-api-row--revoked' : ''; ?>">
                                    <td>
                                        <strong><?php echo esc_html($client['label']); ?></strong><br />
                                        <?php if (!empty($client['created_at'])) : ?>
                                            <span class="status-api-muted">
                                                Aangemaakt: <?php echo esc_html(date_i18n('Y-m-d', (int)$client['created_at'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="margin-bottom: 10px;">
                                            <div class="status-api-muted"><span class="status-api-label"><span class="dashicons dashicons-admin-network"></span><strong>API key</strong></span></div>
                                            <div class="status-api-field">
                                                <input id="<?php echo esc_attr($id_key); ?>" type="text" class="regular-text" value="<?php echo esc_attr($client_key); ?>" readonly />
                                                <button type="button" class="button status-api-copy" data-copy-target="<?php echo esc_attr($id_key); ?>">Kopieer</button>
                                            </div>
                                        </div>
                                        <div style="margin-bottom: 10px;">
                                            <div class="status-api-muted"><span class="status-api-label"><span class="dashicons dashicons-lock"></span><strong>API secret</strong></span></div>
                                            <div class="status-api-field">
                                                <button type="button" class="button status-api-toggle" data-toggle-target="<?php echo esc_attr($id_secret_wrap); ?>">
                                                    <span class="dashicons dashicons-visibility"></span>
                                                    Toon
                                                </button>
                                                <button type="button" class="button status-api-copy" data-copy-target="<?php echo esc_attr($id_secret); ?>">Kopieer</button>
                                            </div>
                                            <div id="<?php echo esc_attr($id_secret_wrap); ?>" class="status-api-hidden" style="margin-top: 8px;">
                                                <div class="status-api-field">
                                                    <input id="<?php echo esc_attr($id_secret); ?>" type="text" class="regular-text" value="<?php echo esc_attr($client_secret); ?>" readonly />
                                                </div>
                                            </div>
                                        </div>
                                        <div style="margin-bottom: 10px;">
                                            <div class="status-api-muted"><span class="status-api-label"><span class="dashicons dashicons-shield"></span><strong>Bearer token</strong></span></div>
                                            <div class="status-api-field">
                                                <button type="button" class="button status-api-toggle" data-toggle-target="<?php echo esc_attr($id_token_wrap); ?>">
                                                    <span class="dashicons dashicons-visibility"></span>
                                                    Toon
                                                </button>
                                                <button type="button" class="button status-api-copy" data-copy-target="<?php echo esc_attr($id_token); ?>">Kopieer</button>
                                            </div>
                                            <div id="<?php echo esc_attr($id_token_wrap); ?>" class="status-api-hidden" style="margin-top: 8px;">
                                                <div class="status-api-field">
                                                    <textarea id="<?php echo esc_attr($id_token); ?>" class="large-text" rows="2" readonly><?php echo esc_textarea($client_token); ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if ($query_auth_allowed) : ?>
                                        <div>
                                            <div class="status-api-muted"><span class="status-api-label"><span class="dashicons dashicons-admin-links"></span><strong>Open endpoint</strong> <span title="Bevat het secret in de URL; gebruik bij voorkeur de Bearer token">(verouderd)</span></span></div>
                                            <div class="status-api-field">
                                                <button type="button" class="button status-api-toggle" data-toggle-target="<?php echo esc_attr($id_url_wrap); ?>">
                                                    <span class="dashicons dashicons-visibility"></span>
                                                    Toon
                                                </button>
                                                <button type="button" class="button status-api-copy" data-copy-target="<?php echo esc_attr($id_url); ?>">Kopieer</button>
                                                <a class="button" href="<?php echo esc_url($client_open_url); ?>" target="_blank" rel="noopener noreferrer">
                                                    <span class="dashicons dashicons-external"></span>
                                                    Open
                                                </a>
                                            </div>
                                            <div id="<?php echo esc_attr($id_url_wrap); ?>" class="status-api-hidden" style="margin-top: 8px;">
                                                <div class="status-api-field">
                                                    <input id="<?php echo esc_attr($id_url); ?>" type="text" class="large-text" value="<?php echo esc_attr($client_open_url); ?>" readonly />
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_revoked) : ?>
                                            <span class="status-api-badge status-api-badge--revoked">
                                                <span class="dashicons dashicons-dismiss"></span>
                                                Ingetrokken
                                            </span>
                                        <?php else : ?>
                                            <span class="status-api-badge status-api-badge--active">
                                                <span class="dashicons dashicons-yes-alt"></span>
                                                Actief
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($client['secret_regenerated_at'])) : ?>
                                            <div class="status-api-muted" style="margin-top: 6px;">
                                                Secret vernieuwd: <?php echo esc_html(date_i18n('Y-m-d H:i', (int)$client['secret_regenerated_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($client['last_used_at'])) : ?>
                                            <div class="status-api-muted" style="margin-top: 6px;">
                                                Laatst gebruikt: <?php echo esc_html(date_i18n('Y-m-d H:i', (int)$client['last_used_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($auth_methods[$client_key]['bearer'])) : ?>
                                            <div class="status-api-muted" style="margin-top: 6px;">
                                                Bearer token: <?php echo esc_html(date_i18n('Y-m-d H:i', (int) $auth_methods[$client_key]['bearer'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($auth_methods[$client_key]['query'])) : ?>
                                            <div style="margin-top: 6px; color: #996800;">
                                                <span class="dashicons dashicons-warning" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                                URL-parameters (verouderd): <?php echo esc_html(date_i18n('Y-m-d H:i', (int) $auth_methods[$client_key]['query'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="status-api-actions">
                                            <form method="post" action="" data-status-api-confirm="Weet je zeker dat je het secret van &quot;<?php echo esc_attr($client['label']); ?>&quot; wilt regenereren? Het huidige secret, de Bearer token en de Open endpoint URL werken daarna direct niet meer.<?php echo $is_revoked ? esc_attr("\n\nLet op: deze client is ingetrokken. Regenereren maakt hem weer ACTIEF met het nieuwe secret.") : ''; ?>">
                                                <?php wp_nonce_field('regenerate_client_secret', 'regenerate_client_secret_nonce'); ?>
                                                <input type="hidden" name="client_key" value="<?php echo esc_attr($client_key); ?>" />
                                                <button type="submit" name="regenerate_client_secret" class="button">
                                                    <span class="dashicons dashicons-update"></span>
                                                    <?php echo $is_revoked ? 'Regenereren &amp; heractiveren' : 'Secret regenereren'; ?>
                                                </button>
                                            </form>
                                            <form method="post" action="" data-status-api-confirm="Weet je zeker dat je &quot;<?php echo esc_attr($client['label']); ?>&quot; wilt intrekken? Deze client heeft daarna direct geen toegang meer.">
                                                <?php wp_nonce_field('revoke_client', 'revoke_client_nonce'); ?>
                                                <input type="hidden" name="client_key" value="<?php echo esc_attr($client_key); ?>" />
                                                <button type="submit" name="revoke_client" class="button">
                                                    <span class="dashicons dashicons-no-alt"></span>
                                                    Intrekken
                                                </button>
                                            </form>
                                            <form method="post" action="" data-status-api-confirm="Weet je zeker dat je &quot;<?php echo esc_attr($client['label']); ?>&quot; definitief wilt verwijderen? Dit kan niet ongedaan worden gemaakt.">
                                                <?php wp_nonce_field('delete_client', 'delete_client_nonce'); ?>
                                                <input type="hidden" name="client_key" value="<?php echo esc_attr($client_key); ?>" />
                                                <button type="submit" name="delete_client" class="button" <?php echo $is_revoked ? '' : 'disabled'; ?>>
                                                    <span class="dashicons dashicons-trash"></span>
                                                    Verwijderen
                                                </button>
                                            </form>
                                        </div>
                                        <?php if (!$is_revoked) : ?>
                                            <div class="status-api-muted" style="margin-top: 6px;">Verwijderen kan pas na intrekken.</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($active_tab === 'audit') : ?>
                <?php
                    if ($this->audit_log === null) {
                        $this->audit_log = new Status_Audit_Log();
                    }
                    $audit_entries = $this->audit_log->get_entries(100);
                ?>
                <h2>Audit-log API clients</h2>
                <p class="status-api-muted">
                    De laatste 100 beheeracties op API clients. Secrets worden nooit gelogd; van de API key alleen de eerste
                    <?php echo (int) Status_Audit_Log::KEY_PREFIX_LENGTH; ?> tekens. Acties van vóór versie 0.9.12 zijn niet vastgelegd.
                </p>
                <?php if (empty($audit_entries)) : ?>
                    <p>Er zijn nog geen acties vastgelegd.</p>
                <?php else : ?>
                    <table class="widefat striped" style="max-width: 1400px;">
                        <thead>
                            <tr>
                                <th style="width: 150px;">Datum/Tijd</th>
                                <th>Actie</th>
                                <th>Client</th>
                                <th style="width: 140px;">API key</th>
                                <th style="width: 180px;">Door</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audit_entries as $entry) : ?>
                                <tr>
                                    <td><?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($entry->event_date))); ?></td>
                                    <td><?php echo esc_html(Status_Audit_Log::get_event_label($entry->event)); ?></td>
                                    <td><?php echo esc_html($entry->client_label); ?></td>
                                    <td><code><?php echo esc_html($entry->client_key_prefix); ?>…</code></td>
                                    <td><?php echo esc_html($entry->changed_by); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php else : ?>
                <h2>API Documentatie</h2>
                <p>De Status API biedt toegang tot de huidige status via het volgende endpoint:</p>
                
                <h3>Huidige status ophalen</h3>
                <p>Endpoint: <code><?php echo esc_html(site_url('/wp-json/status-api/v1/status')); ?></code></p>
                <p>Methode: <code>GET</code></p>
                <p>Authenticatie: Bearer token (aanbevolen) of API sleutel/secret in de URL (verouderd).</p>
                <p>Tip: ga naar het tabblad “API clients” en kopieer daar de Bearer token.</p>
                <h4>Response formaat:</h4>
                <pre>
{
    "title": "Status titel",
    "text": "Status beschrijving",
    "baseURL": "<?php echo esc_html(site_url()); ?>",
    "status": "geen|green|orange|red",
    "timestamp": 1234567890,
    "statusExpiryDate": "2025-12-31 23:59" (of null),
    "statusExpiryTimestamp": 1234567890 (of null),
    "timestampUtc": 1234567890,
    "statusExpiryTimestampUtc": 1234567890 (of null),
    "statusExpiryIso8601": "2025-12-31T23:59:00+01:00" (of null)
}
                </pre>
                <p class="description">
                    <strong>Vervaldatum:</strong> de <code>statusExpiry*</code> velden zijn gevuld zodra er bij een groene status een vervaldatum is ingesteld.
                    Na het automatisch verlopen wordt <code>status</code> <code>"geen"</code>, maar blijft de laatst ingestelde vervaldatum zichtbaar
                    (ter informatie: tot wanneer de vorige melding gold). Controleer dus altijd eerst <code>status</code>.
                </p>
                <p class="description">
                    <strong>Let op tijdzones:</strong> <code>timestamp</code> en <code>statusExpiryTimestamp</code> zijn om historische redenen
                    de lokale sitetijd (<?php echo esc_html(wp_timezone_string()); ?>) weergegeven als Unix-timestamp en wijken dus af van echte UTC-tijd.
                    Gebruik voor nieuwe integraties <code>timestampUtc</code>, <code>statusExpiryTimestampUtc</code> en <code>statusExpiryIso8601</code>.
                </p>
                
                <h3>Authenticatie voorbeelden</h3>
                
                <h4>Methode 1: Bearer Token (Aanbevolen)</h4>
                <p>Gebruik de gecombineerde Bearer token voor maximale veiligheid:</p>
                <pre>
Authorization: Bearer <?php echo esc_html($example_bearer_token); ?>
                </pre>
                
                <h4>Methode 2: API Sleutel en Secret (verouderd)</h4>
                <?php if (!$query_auth_allowed) : ?>
                    <div class="notice notice-warning inline"><p>Deze methode is op deze site <strong>uitgeschakeld</strong> (filter <code>status_api_allow_query_auth</code>). Gebruik de Bearer token.</p></div>
                <?php endif; ?>
                <p>
                    Het secret staat bij deze methode in de URL en belandt daardoor in access-logs, proxy-logs en browsergeschiedenis.
                    Responses op zulke verzoeken bevatten een <code>Deprecation</code> header. Stap bij voorkeur over op de Bearer token;
                    in het tabblad “API clients” zie je per client welke methode recent is gebruikt.
                </p>
                <p>Voeg de volgende parameters toe aan je aanvraag:</p>
                <pre>
api_key=<?php echo esc_html($example_key); ?>&api_secret=<?php echo esc_html($example_secret); ?>
                </pre>
                
                <h3>Voorbeeld API aanroepen</h3>
                
                <h4>cURL voorbeeld met Bearer token:</h4>
                <pre>
curl -H "Authorization: Bearer <?php echo esc_html($example_bearer_token); ?>" \
     <?php echo esc_html(site_url('/wp-json/status-api/v1/status')); ?>
                </pre>
                
                <h4>JavaScript fetch voorbeeld:</h4>
                <pre>
fetch('<?php echo esc_js(site_url('/wp-json/status-api/v1/status')); ?>', {
    headers: {
        'Authorization': 'Bearer <?php echo esc_js($example_bearer_token); ?>'
    }
})
.then(response => response.json())
.then(data => console.log(data));
                </pre>
            
            <h2>Bearer Token Beveiliging</h2>
            <p>De Bearer token wordt afgeleid met <strong>HMAC-SHA256</strong>:</p>
            
            <div style="background-color: #f5f5f5; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <h4 style="margin-top: 0;">Hoe de Bearer token wordt gegenereerd:</h4>
                <ol>
                    <li><strong>HMAC-SHA256 Signature</strong>
                        <ul>
                            <li>Algoritme: SHA-256</li>
                            <li>Input data: API Sleutel (32 karakters)</li>
                            <li>Geheime sleutel: API Secret (64 karakters)</li>
                            <li>Output: 64-karakter hexadecimale hash</li>
                        </ul>
                    </li>
                    <li><strong>Token Constructie</strong>
                        <ul>
                            <li>Formaat: <code>API_KEY:HMAC_SIGNATURE</code></li>
                            <li>Encoding: Base64</li>
                        </ul>
                    </li>
                </ol>
                
                <h4>Wat biedt dit wel en niet?</h4>
                <ul>
                    <li><strong>Secret blijft geheim</strong>: de token bevat het API Secret niet; alleen met het juiste secret kan de correcte signature worden gemaakt.</li>
                    <li><strong>Niet-omkeerbaar</strong>: uit de token is het API Secret niet terug te rekenen.</li>
                    <li><strong>Statisch</strong>: de token verandert niet per verzoek (geen tijdstempel of nonce). Wie de token onderschept kan hem hergebruiken tot het secret wordt geregenereerd of de client wordt ingetrokken. Behandel de token dus als een wachtwoord en gebruik altijd HTTPS.</li>
                    <li><strong>Rotatie</strong>: na &ldquo;Secret regenereren&rdquo; is de oude token direct ongeldig.</li>
                </ul>
                
                <h4>Voorbeeld berekening:</h4>
                <pre style="background-color: #fff; padding: 10px; border: 1px solid #ddd;">
API Sleutel: <?php echo esc_html(substr($example_key, 0, 16)); ?>... (verkort voor veiligheid)
API Secret: <?php echo esc_html(substr($example_secret, 0, 16)); ?>... (verkort voor veiligheid)

HMAC-SHA256: hash_hmac('sha256', api_key, api_secret)
Resultaat: [64 karakters hex]

Bearer Token: base64_encode(api_key + ':' + hmac_signature)
                </pre>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Registreer REST API endpoints
     */
    public function register_api_endpoints() {
        register_rest_route('status-api/v1', '/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_status'),
            'permission_callback' => array($this, 'check_api_authentication')
        ));
    }
    
    /**
     * Rate limiting instellingen voor mislukte authenticatie pogingen
     */
    const AUTH_RATE_LIMIT_MAX_ATTEMPTS = 20;
    const AUTH_RATE_LIMIT_WINDOW = 900; // 15 minuten

    /**
     * Haal het IP-adres van de aanvrager op.
     *
     * Standaard REMOTE_ADDR. Achter een vertrouwde reverse proxy / load balancer kan het
     * echte client-IP via de filter 'status_api_client_ip' worden aangeleverd. Vertrouw
     * proxy-headers (zoals X-Forwarded-For) alleen als het verzoek van je eigen proxy komt.
     */
    private function get_client_ip($request = null) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ip = apply_filters('status_api_client_ip', $ip, $request);

        return (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : 'unknown';
    }

    private function get_rate_limit_max_attempts() {
        return max(1, (int) apply_filters('status_api_auth_rate_limit_max_attempts', self::AUTH_RATE_LIMIT_MAX_ATTEMPTS));
    }

    private function get_rate_limit_window() {
        return max(1, (int) apply_filters('status_api_auth_rate_limit_window', self::AUTH_RATE_LIMIT_WINDOW));
    }

    /**
     * Controleer of het huidige IP-adres is geblokkeerd wegens te veel mislukte pogingen
     */
    private function is_rate_limited($ip) {
        $attempts = (int) get_transient('status_api_auth_fails_' . md5($ip));
        return $attempts >= $this->get_rate_limit_max_attempts();
    }

    /**
     * Registreer een mislukte authenticatiepoging voor rate limiting
     */
    private function record_auth_failure($ip) {
        $transient_key = 'status_api_auth_fails_' . md5($ip);
        $attempts = (int) get_transient($transient_key);
        set_transient($transient_key, $attempts + 1, $this->get_rate_limit_window());
    }

    /**
     * Controleer API authenticatie
     *
     * Geldige credentials worden altijd geaccepteerd, ook als het IP-adres door eerdere
     * mislukte pogingen (bijv. van een andere client achter dezelfde proxy) is geblokkeerd.
     * De rate limit geldt alleen voor mislukte pogingen.
     */
    public function check_api_authentication($request) {
        $this->maybe_migrate_single_key_to_clients();
        $clients = $this->get_api_clients();
        $this->auth_method = null;

        // METHODE 1: Check voor API key en secret in query parameters (verouderd, standaard nog toegestaan)
        $api_key = $request->get_param('api_key');
        $api_secret = $request->get_param('api_secret');

        if ($this->is_query_auth_allowed() && is_string($api_key) && is_string($api_secret) && $api_key !== '' && $api_secret !== '') {
            if (isset($clients[$api_key]) && empty($clients[$api_key]['revoked']) && hash_equals($clients[$api_key]['secret'], $api_secret)) {
                $this->touch_client_last_used($api_key, $clients);
                $this->touch_client_auth_method($api_key, 'query');
                $this->auth_method = 'query';
                return true;
            }
        }

        // METHODE 2: Bearer token authenticatie (beveiligd met HMAC)
        $auth_header = $request->get_header('Authorization') ?: $request->get_header('authorization');

        if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            $token = trim($matches[1]);

            $client_key = $this->validate_bearer_token_multi($token, $clients);
            if ($client_key !== false) {
                $this->touch_client_last_used($client_key, $clients);
                $this->touch_client_auth_method($client_key, 'bearer');
                $this->auth_method = 'bearer';
                return true;
            }
        }

        // Authenticatie mislukt
        $ip = $this->get_client_ip($request);

        if ($this->is_rate_limited($ip)) {
            return new WP_Error(
                'rest_too_many_requests',
                'Te veel mislukte authenticatiepogingen. Probeer het later opnieuw.',
                array('status' => 429)
            );
        }

        $this->record_auth_failure($ip);
        return new WP_Error(
            'rest_forbidden',
            'Toegang geweigerd. Geldige authenticatie vereist.',
            array('status' => 401)
        );
    }

    /**
     * Valideer Bearer token tegen alle clients.
     * Retourneert api_key bij succes, anders false.
     */
    private function validate_bearer_token_multi($token, $clients) {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode(':', $decoded, 2);
        if (count($parts) !== 2) {
            return false;
        }

        list($api_key, $signature) = $parts;
        if (empty($api_key) || empty($signature)) {
            return false;
        }

        if (!isset($clients[$api_key]) || !empty($clients[$api_key]['revoked'])) {
            return false;
        }

        $expected_signature = hash_hmac('sha256', $api_key, $clients[$api_key]['secret']);
        return hash_equals($expected_signature, $signature) ? $api_key : false;
    }
    
    /**
     * Haal status op en retourneer als API response
     */
    public function get_status() {
        // Gebruik de status manager van de parent plugin instance
        global $status_api_plugin;
        if (isset($status_api_plugin->status_manager)) {
            $status_api_plugin->status_manager->check_and_update_expired_status();
        }
        
        // Verkrijg huidige status data met betere defaults
        $status_data = $this->get_status_data();
        
        $expiry = $this->parse_local_datetime($status_data['expiry_date']);

        // Formatteer response.
        // Let op: 'timestamp' en 'statusExpiryTimestamp' zijn (historisch) lokale tijd als
        // Unix-timestamp weergegeven en blijven ongewijzigd voor backwards compatibility.
        // De *Utc en *Iso8601 velden bevatten de correcte, tijdzone-bewuste waarden.
        $response = array(
            'title' => $status_data['title'],
            'text' => $status_data['content'],
            'baseURL' => site_url(),
            'status' => $status_data['status'],
            'timestamp' => current_time('timestamp'),
            'statusExpiryDate' => $status_data['expiry_date'],
            'statusExpiryTimestamp' => !empty($status_data['expiry_date'])
                ? strtotime($status_data['expiry_date'])
                : null,
            'timestampUtc' => time(),
            'statusExpiryTimestampUtc' => $expiry ? $expiry->getTimestamp() : null,
            'statusExpiryIso8601' => $expiry ? $expiry->format(DATE_ATOM) : null,
        );

        $response = rest_ensure_response($response);

        // Voorkom dat caches/CDN's een verouderde status serveren (en URL's met secrets bewaren).
        // Aan te passen via de filter, bijv. 'public, max-age=30' als bewuste keuze.
        $cache_control = apply_filters('status_api_cache_control', 'no-store, private');
        if (is_string($cache_control) && $cache_control !== '' && method_exists($response, 'header')) {
            $response->header('Cache-Control', $cache_control);
        }

        // Signaleer aan de afnemer dat authenticatie via URL-parameters verouderd is (RFC 9745).
        // Clients die de header niet kennen negeren hem; de response zelf is ongewijzigd.
        if ($this->auth_method === 'query' && method_exists($response, 'header')) {
            $response->header('Deprecation', '@' . self::QUERY_AUTH_DEPRECATED_SINCE);
        }

        return $response;
    }

    /**
     * Interpreteer een opgeslagen datum/tijd ('Y-m-d H:i') in de tijdzone van de site.
     * Retourneert een DateTimeImmutable of null.
     */
    private function parse_local_datetime($value) {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timezone = wp_timezone();
        foreach (array('!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d') as $format) {
            $date = DateTimeImmutable::createFromFormat($format, trim($value), $timezone);
            if ($date !== false) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable(trim($value), $timezone);
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Helper functie om status data op te halen
     */
    private function get_status_data() {
        static $cached_status = null;
        
        // Cache de status data voor de duur van de request
        if ($cached_status === null) {
            $defaults = array(
                'title' => '',
                'content' => '',
                'status' => 'geen',
                'expiry_date' => null
            );

            $option = get_option('status_message_data', array());
            $cached_status = wp_parse_args($option, $defaults);

            if (is_string($cached_status['expiry_date']) && trim($cached_status['expiry_date']) === '') {
                $cached_status['expiry_date'] = null;
            }
        }
        
        return $cached_status;
    }
    
    /**
     * Genereer nieuwe API sleutels
     */
    public function generate_api_keys() {
        // Legacy methode: behoud als wrapper om een default client te maken
        list($api_key, $api_secret) = $this->create_api_client('Default');
        update_option($this->api_key_option, $api_key);
        update_option($this->api_secret_option, $api_secret);
    }
}
