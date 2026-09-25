<?php
/**
 * Historie: schema-upgrades en CSV-export.
 */

section('Schema installatie / upgrade');
$history = new Status_History_Manager();
$history->maybe_upgrade();
check('dbDelta uitgevoerd zonder db-versie', count($GLOBALS['wp_test']['dbdelta']) === 1);
$sql = $GLOBALS['wp_test']['dbdelta'][0];
check('dbDelta-SQL zonder IF NOT EXISTS', strpos($sql, 'IF NOT EXISTS') === false);
check('dbDelta-SQL met twee spaties na PRIMARY KEY', strpos($sql, 'PRIMARY KEY  (id)') !== false);
check('db-versie opgeslagen', get_option('status_api_db_version') === Status_History_Manager::DB_VERSION);
$history->maybe_upgrade();
check('geen dbDelta als versie gelijk is', count($GLOBALS['wp_test']['dbdelta']) === 1);
update_option('status_api_db_version', '0.1');
$history->maybe_upgrade();
check('dbDelta bij oude versie', count($GLOBALS['wp_test']['dbdelta']) === 2);

wp_test_reset();
$history->activate();
check('activate installeert en zet versie', count($GLOBALS['wp_test']['dbdelta']) === 1 && get_option('status_api_db_version') === Status_History_Manager::DB_VERSION);

$GLOBALS['wp_test']['actions'] = array();
new Status_History_Manager();
$hooked = false;
foreach ($GLOBALS['wp_test']['actions'] as $action) {
    if ($action[0] === 'plugins_loaded' && is_array($action[1]) && $action[1][1] === 'maybe_upgrade') {
        $hooked = true;
    }
}
check('maybe_upgrade gekoppeld aan plugins_loaded', $hooked);

section('CSV formule-injectie');
foreach (array('=SUM(A1)', '+1', '-1', '@x', "\tx", "\rx") as $value) {
    check('csv_safe ' . json_encode($value), call_private($history, 'csv_safe', $value) === "'" . $value);
}
check('csv_safe normale tekst', call_private($history, 'csv_safe', 'Storing') === 'Storing');
check('csv_safe null', call_private($history, 'csv_safe', null) === '');
