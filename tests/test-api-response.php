<?php
/**
 * Contracttest op het JSON-formaat van GET /wp-json/status-api/v1/status.
 *
 * Bewaakt backwards compatibility: bestaande velden mogen niet verdwijnen, niet van
 * volgorde of type veranderen. Nieuwe velden alleen achteraan toevoegen.
 */

$api = new Status_API_Manager();

// Volgorde en toegestane types per veld (null toegestaan waar aangegeven)
$contract = array(
    'title' => array('string'),
    'text' => array('string'),
    'baseURL' => array('string'),
    'status' => array('string'),
    'timestamp' => array('integer'),
    'statusExpiryDate' => array('string', 'NULL'),
    'statusExpiryTimestamp' => array('integer', 'NULL'),
    // Sinds 0.9.10
    'timestampUtc' => array('integer'),
    'statusExpiryTimestampUtc' => array('integer', 'NULL'),
    'statusExpiryIso8601' => array('string', 'NULL'),
);

function status_api_check_contract($data, $contract, $label) {
    check("{$label}: velden en volgorde gelijk aan contract", array_keys($data) === array_keys($contract));
    foreach ($contract as $field => $types) {
        $type = array_key_exists($field, $data) ? gettype($data[$field]) : 'ontbreekt';
        check("{$label}: {$field} is " . implode('|', $types), in_array($type, $types, true));
    }
}

section('Contract zonder vervaldatum');
update_option('status_message_data', array('title' => 'T', 'content' => 'C', 'status' => 'red', 'expiry_date' => null));
$response = $api->get_status();
$data = $response->get_data();
status_api_check_contract($data, $contract, 'rood');
check('status waarde', $data['status'] === 'red');
check('expiry velden null', $data['statusExpiryDate'] === null && $data['statusExpiryTimestamp'] === null && $data['statusExpiryIso8601'] === null);

section('Contract met vervaldatum (nieuwe instantie i.v.m. request-cache)');
wp_test_reset();
update_option('status_message_data', array('title' => 'T', 'content' => 'C', 'status' => 'green', 'expiry_date' => '2026-12-31 23:59'));
// get_status_data() cachet per request in een static; roep de parser direct aan
$expiry = call_private($api, 'parse_local_datetime', '2026-12-31 23:59');
check('ISO 8601 met offset', $expiry->format(DATE_ATOM) === '2026-12-31T23:59:00+01:00');
check('UTC timestamp correct', $expiry->getTimestamp() === strtotime('2026-12-31T22:59:00Z'));
check('zomertijd offset', call_private($api, 'parse_local_datetime', '2026-07-01 12:00')->format(DATE_ATOM) === '2026-07-01T12:00:00+02:00');
check('lege waarde => null', call_private($api, 'parse_local_datetime', '') === null);

section('Headers');
check('Cache-Control standaard no-store', ($response->get_headers()['Cache-Control'] ?? null) === 'no-store, private');
add_filter('status_api_cache_control', function () { return 'public, max-age=30'; });
check('Cache-Control via filter aan te passen', ($api->get_status()->get_headers()['Cache-Control'] ?? null) === 'public, max-age=30');
add_filter('status_api_cache_control', function () { return ''; });
check('Cache-Control uit te zetten met lege string', !isset($api->get_status()->get_headers()['Cache-Control']));
