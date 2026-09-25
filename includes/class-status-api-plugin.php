<?php
// Voorkom direct toegang tot het bestand
if (!defined('ABSPATH')) {
    exit;
}

// Hoofdklasse voor de plugin
class Status_API_Plugin {
    
    // Instance van de plugin (singleton pattern)
    private static $instance = null;
    
    // API Manager, Status Manager en History Manager
    private $api_manager;
    public $status_manager;
    private $history_manager;
    
    /**
     * Constructor - initialiseert de plugin
     */
    private function __construct() {
        // Laad de managers (history eerst, die wordt gedeeld met de status manager)
        $this->history_manager = new Status_History_Manager();
        $this->api_manager = new Status_API_Manager();
        $this->status_manager = new Status_Message_Manager($this->history_manager);

        // Voeg admin menu's toe
        add_action('admin_menu', array($this, 'add_admin_menus'), 10);

        // Register activation and deactivation hooks (op het hoofdbestand van de plugin)
        register_activation_hook(STATUS_API_PLUGIN_FILE, array($this, 'activate_plugin'));
        register_deactivation_hook(STATUS_API_PLUGIN_FILE, array($this, 'deactivate_plugin'));
    }
    
    /**
     * Singleton pattern - zorgt voor één instantie van de plugin
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Voeg alle admin menu's toe in de juiste volgorde
     */
    public function add_admin_menus() {
        // Voeg hoofdmenu toe
        add_menu_page(
            'Status API',
            'Status API',
            'edit_posts',
            'status-api',
            array($this->status_manager, 'display_status_page'),
            'dashicons-database-view',
            30
        );
        
        // Voeg Status Melding submenu toe (kopie van hoofdmenu)
        add_submenu_page(
            'status-api',
            'Status Melding',
            'Status Melding',
            'edit_posts',
            'status-api',
            array($this->status_manager, 'display_status_page')
        );
        
        // Voeg Historie submenu toe
        add_submenu_page(
            'status-api',
            'Status Historie',
            'Status Historie',
            'edit_posts',
            'status-api-history',
            array($this->history_manager, 'display_history_page')
        );
        
        // Voeg API Instellingen submenu toe
        add_submenu_page(
            'status-api',
            'API Instellingen',
            'API Instellingen',
            'manage_options',
            'status-api-settings',
            array($this->api_manager, 'display_settings_page')
        );
    }
    
    /**
     * Plugin activatie hook
     */
    public function activate_plugin() {
        // Voer activatie taken uit voor alle managers
        $this->api_manager->activate();
        $this->status_manager->activate();
        $this->history_manager->activate();
        
        // Spoel de rewrite rules door
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivatie hook
     */
    public function deactivate_plugin() {
        // Voer deactivatie taken uit voor alle managers
        $this->api_manager->deactivate();
        $this->status_manager->deactivate();
        $this->history_manager->deactivate();
        
        // Spoel de rewrite rules door
        flush_rewrite_rules();
    }
}
