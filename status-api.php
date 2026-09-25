<?php
/**
 * Plugin Name: Status API
 * Description: Deze plugin maakt een API end-point aan voor het geven van storings informatie.
 * Version: 0.9.11
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Author: Hanno-Wybren Mook
 * License: Proprietary
 * Update URI: https://github.com/SURFnet/wp_status_api
 */

// Voorkom direct toegang tot het bestand
if (!defined('ABSPATH')) {
    exit;
}

// Hoofdbestand van de plugin; nodig voor activatie-hooks en asset-URL's vanuit includes/.
// Let op: dit bestand (wp_status_api/status-api.php) nooit hernoemen, anders deactiveert
// WordPress de plugin op alle sites.
define('STATUS_API_PLUGIN_FILE', __FILE__);

// Updates via GitHub Releases (Plugin Update Checker)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';

    if (class_exists('\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
        $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/SURFnet/wp_status_api',
            __FILE__,
            'wp_status_api'
        );

        // Gebruik de zip asset van een GitHub Release (consistent met onze build).
        if (method_exists($update_checker, 'getVcsApi') && $update_checker->getVcsApi()) {
            $update_checker->getVcsApi()->enableReleaseAssets();
        }
    }
}

require_once __DIR__ . '/includes/class-status-api-plugin.php';
require_once __DIR__ . '/includes/class-status-api-manager.php';
require_once __DIR__ . '/includes/class-status-message-manager.php';
require_once __DIR__ . '/includes/class-status-history-manager.php';

// Start de plugin
global $status_api_plugin;
$status_api_plugin = Status_API_Plugin::get_instance();
