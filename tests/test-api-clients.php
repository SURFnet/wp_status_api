<?php
/**
 * API clients: migratie, lifecycle, authenticatie en rate limiting.
 */

$api = new Status_API_Manager();

section('Migratie legacy options');
update_option('status_api_key', 'legacykey');
update_option('status_api_secret', 'legacysecret');
call_private($api, 'maybe_migrate_single_key_to_clients');
$clients = call_private($api, 'get_api_clients');
check('pre-0.9.9 site migreert legacy client', isset($clients['legacykey']) && $clients['legacykey']['label'] === 'Legacy');

call_private($api, 'revoke_api_client', 'legacykey');
call_private($api, 'delete_api_client', 'legacykey');
check('legacy options opgeruimd na verwijderen', get_option('status_api_key') === false);

update_option('status_api_key', 'legacykey');
update_option('status_api_secret', 'legacysecret');
call_private($api, 'maybe_migrate_single_key_to_clients');
check('verwijderde client komt niet terug', call_private($api, 'get_api_clients') === array());
$result = $api->check_api_authentication(new WP_Test_Request(array('api_key' => 'legacykey', 'api_secret' => 'legacysecret')));
check('oude legacy credentials geweigerd', $result instanceof WP_Error && $result->data['status'] === 401);

wp_test_reset();
list($key, $secret) = call_private($api, 'create_api_client', 'A');
$new_secret = call_private($api, 'regenerate_api_client_secret', $key);
check('legacy secret gesynchroniseerd bij regenereren', get_option('status_api_secret') === $new_secret);

section('Authenticatie en last_used_at');
wp_test_reset();
list($key, $secret) = call_private($api, 'create_api_client', 'A');
$GLOBALS['wp_test']['writes'] = array();
check('query-param auth werkt', $api->check_api_authentication(new WP_Test_Request(array('api_key' => $key, 'api_secret' => $secret))) === true);
check('clients option niet geschreven door API-call', !in_array('status_api_clients', $GLOBALS['wp_test']['writes'], true));
check('last_used apart geschreven', in_array('status_api_clients_last_used', $GLOBALS['wp_test']['writes'], true));
$GLOBALS['wp_test']['writes'] = array();
$api->check_api_authentication(new WP_Test_Request(array('api_key' => $key, 'api_secret' => $secret)));
check('tweede call binnen 5 min schrijft niets', $GLOBALS['wp_test']['writes'] === array());
$clients = call_private($api, 'get_api_clients');
check('last_used_at zichtbaar via get_api_clients', !empty($clients[$key]['last_used_at']));

$GLOBALS['wp_test']['options']['status_api_clients'][$key]['last_used_at'] = time() + 100;
$clients = call_private($api, 'get_api_clients');
check('hoogste last_used_at wint (pre-0.9.10 data)', $clients[$key]['last_used_at'] === time() + 100);

wp_test_reset();
list($key, $secret) = call_private($api, 'create_api_client', 'A');
call_private($api, 'revoke_api_client', $key);
check('ingetrokken client geweigerd', $api->check_api_authentication(new WP_Test_Request(array('api_key' => $key, 'api_secret' => $secret))) instanceof WP_Error);

wp_test_reset();
list($key, $secret) = call_private($api, 'create_api_client', 'A');
$token = call_private($api, 'generate_bearer_token', $key, $secret);
check('bearer auth werkt', $api->check_api_authentication(new WP_Test_Request(array(), array('Authorization' => 'Bearer ' . $token))) === true);
check('bearer met fout secret geweigerd', $api->check_api_authentication(new WP_Test_Request(array(), array('Authorization' => 'Bearer ' . call_private($api, 'generate_bearer_token', $key, 'fout')))) instanceof WP_Error);
check('ongeldige base64 geweigerd', $api->check_api_authentication(new WP_Test_Request(array(), array('Authorization' => 'Bearer %%%'))) instanceof WP_Error);

section('Rate limiting');
wp_test_reset();
list($key, $secret) = call_private($api, 'create_api_client', 'A');
for ($i = 0; $i < 20; $i++) {
    $api->check_api_authentication(new WP_Test_Request(array('api_key' => $key, 'api_secret' => 'fout')));
}
$result = $api->check_api_authentication(new WP_Test_Request(array('api_key' => $key, 'api_secret' => 'fout')));
check('21e foute poging => 429', $result instanceof WP_Error && $result->data['status'] === 429);
check('geldige client op geblokkeerd IP => toegang', $api->check_api_authentication(new WP_Test_Request(array('api_key' => $key, 'api_secret' => $secret))) === true);

add_filter('status_api_client_ip', function () { return '10.0.0.2'; });
$result = $api->check_api_authentication(new WP_Test_Request(array('api_key' => $key, 'api_secret' => 'fout')));
check('ander IP via filter => 401', $result instanceof WP_Error && $result->data['status'] === 401);
add_filter('status_api_client_ip', function () { return 'geen-ip'; });
check('ongeldig IP uit filter => unknown', call_private($api, 'get_client_ip') === 'unknown');

wp_test_reset();
add_filter('status_api_auth_rate_limit_max_attempts', function () { return 2; });
$api->check_api_authentication(new WP_Test_Request());
$api->check_api_authentication(new WP_Test_Request());
$result = $api->check_api_authentication(new WP_Test_Request());
check('max attempts filter', $result instanceof WP_Error && $result->data['status'] === 429);
$result = $api->check_api_authentication(new WP_Test_Request(array('api_key' => array('x'), 'api_secret' => array('y'))));
check('array params => geen fatal', $result instanceof WP_Error);
