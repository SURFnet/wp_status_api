<?php
/**
 * Audit-log voor beheeracties op API clients (0.9.12).
 */

/**
 * Audit-regels die in de testrun naar de audit-tabel zijn geschreven.
 */
function status_api_test_audit_rows() {
    $rows = array();
    foreach ($GLOBALS['wpdb']->inserts as $insert) {
        if ($insert[0] === 'wp_status_api_audit') {
            $rows[] = $insert[1];
        }
    }
    return $rows;
}

section('Schema');
$audit = new Status_Audit_Log();
$audit->maybe_upgrade();
$sql = end($GLOBALS['wp_test']['dbdelta']);
check('audit-tabel via dbDelta aangemaakt', strpos($sql, 'CREATE TABLE wp_status_api_audit') !== false && strpos($sql, 'PRIMARY KEY  (id)') !== false);
check('audit db-versie opgeslagen', get_option('status_api_audit_db_version') === Status_Audit_Log::DB_VERSION);

section('Beheeracties worden gelogd');
wp_test_reset();
$api = new Status_API_Manager(new Status_Audit_Log());
list($key, $secret) = call_private($api, 'create_api_client', 'Dashboard');
call_private($api, 'regenerate_api_client_secret', $key);
call_private($api, 'revoke_api_client', $key);
call_private($api, 'regenerate_api_client_secret', $key);
call_private($api, 'revoke_api_client', $key);
call_private($api, 'delete_api_client', $key);

$rows = status_api_test_audit_rows();
$events = array_map(function ($row) { return $row['event']; }, $rows);
check('alle acties in de juiste volgorde', $events === array(
    'client_created',
    'client_secret_regenerated',
    'client_revoked',
    'client_reactivated',
    'client_revoked',
    'client_deleted',
));
check('label vastgelegd (ook bij verwijderen)', $rows[5]['client_label'] === 'Dashboard');
check('alleen key-prefix gelogd', $rows[0]['client_key_prefix'] === substr($key, 0, 8) && strlen($rows[0]['client_key_prefix']) === 8);
check('gebruiker vastgelegd', $rows[0]['changed_by'] === 'Tester' && $rows[0]['user_id'] === 1);

$serialized = serialize($rows);
check('nooit het secret of de volledige key gelogd', strpos($serialized, $secret) === false && strpos($serialized, $key) === false);

section('Legacy migratie gelogd');
wp_test_reset();
update_option('status_api_key', 'legacykey123456');
update_option('status_api_secret', 'legacysecret');
$api = new Status_API_Manager(new Status_Audit_Log());
call_private($api, 'maybe_migrate_single_key_to_clients');
$rows = status_api_test_audit_rows();
check('migratie gelogd', count($rows) === 1 && $rows[0]['event'] === 'client_migrated' && $rows[0]['client_key_prefix'] === 'legacyke');

section('BC: constructor zonder argumenten');
wp_test_reset();
$api = new Status_API_Manager();
call_private($api, 'create_api_client', 'X');
check('fallback audit-log werkt', count(status_api_test_audit_rows()) === 1);

section('Labels');
check('bekend event heeft Nederlands label', Status_Audit_Log::get_event_label('client_reactivated') === 'Secret geregenereerd en client heractiveerd');
check('onbekend event valt terug op code', Status_Audit_Log::get_event_label('iets_anders') === 'iets_anders');
