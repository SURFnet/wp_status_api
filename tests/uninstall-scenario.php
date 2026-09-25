<?php
/**
 * Draait uninstall.php in een eigen proces voor één scenario: keep | delete | multisite.
 * Exit code 1 als een controle faalt.
 */

require __DIR__ . '/bootstrap.php';

$scenario = isset($argv[1]) ? $argv[1] : 'keep';
$GLOBALS['multisite'] = ($scenario === 'multisite');
$GLOBALS['switched'] = array();

function is_multisite() { return $GLOBALS['multisite']; }
function get_sites($args) { return array(1, 2); }
function switch_to_blog($id) { $GLOBALS['switched'][] = $id; $GLOBALS['wpdb']->prefix = $id === 1 ? 'wp_' : "wp_{$id}_"; }
function restore_current_blog() { $GLOBALS['wpdb']->prefix = 'wp_'; }

define('WP_UNINSTALL_PLUGIN', 'wp_status_api/status-api.php');
if ($scenario !== 'keep') {
    define('STATUS_API_DELETE_DATA_ON_UNINSTALL', true);
}

update_option('status_api_clients', array('k' => array('secret' => 's')));
update_option('status_message_data', array('status' => 'red'));
update_option('status_api_db_version', '1.0');

require dirname(__DIR__) . '/uninstall.php';

$cleared = in_array('check_green_status_expiry', $GLOBALS['wp_test']['cleared_hooks'], true);
$queries = implode("\n", $GLOBALS['wp_test']['queries']);

if ($scenario === 'keep') {
    check('keep: cron wordt altijd opgeruimd', $cleared);
    check('keep: clients blijven bewaard (standaard)', get_option('status_api_clients') !== false);
    check('keep: status blijft bewaard', get_option('status_message_data') !== false);
    check('keep: tabel blijft bestaan', strpos($queries, 'DROP TABLE') === false);
} elseif ($scenario === 'delete') {
    check('delete: clients verwijderd', get_option('status_api_clients') === false);
    check('delete: status verwijderd', get_option('status_message_data') === false);
    check('delete: db-versie verwijderd', get_option('status_api_db_version') === false);
    check('delete: tabel gedropt', strpos($queries, 'DROP TABLE IF EXISTS wp_status_api_history') !== false);
    check('delete: rate-limit transients opgeruimd', strpos($queries, 'status\\_api\\_auth\\_fails\\_') !== false);
} else {
    check('multisite: alle sites doorlopen', $GLOBALS['switched'] === array(1, 2));
    check('multisite: tabel van subsite gedropt', strpos($queries, 'DROP TABLE IF EXISTS wp_2_status_api_history') !== false);
}

exit($GLOBALS['wp_test_results']['fail'] > 0 ? 1 : 0);
