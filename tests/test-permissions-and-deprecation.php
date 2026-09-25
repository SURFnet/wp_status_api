<?php
/**
 * 0.9.12: capabilities (punt 7) en verouderde query-authenticatie (punt 9).
 */

section('Capabilities');
check('status capability standaard edit_posts', Status_API_Plugin::get_status_capability() === 'edit_posts');
check('history beheer standaard manage_options', Status_API_Plugin::get_history_manage_capability() === 'manage_options');
add_filter('status_api_status_capability', function () { return 'edit_others_posts'; });
check('status capability via filter', Status_API_Plugin::get_status_capability() === 'edit_others_posts');
add_filter('status_api_status_capability', function () { return ''; });
check('ongeldige filterwaarde => edit_posts', Status_API_Plugin::get_status_capability() === 'edit_posts');

/**
 * Simuleer het opslaan van de statusmelding en geef terug hoe het request eindigde.
 */
function status_api_test_save($caps) {
    wp_test_reset();
    $GLOBALS['wp_test']['caps'] = $caps;
    $_GET = array('page' => 'status-api');
    $_POST = array('status_action' => 'save', 'status_nonce' => 'valid', 'title' => 'T', 'content' => 'C', 'status' => 'red');
    $manager = new Status_Message_Manager(new Status_History_Manager());
    try {
        $manager->process_form_actions();
    } catch (WP_Test_Exit $e) {
        return $e->getMessage();
    }
    return 'geen actie';
}

$result = status_api_test_save(array('edit_posts'));
check('redacteur (edit_posts) kan status opslaan', strpos($result, 'redirect:') === 0 && strpos($result, 'message=updated') !== false);
check('status daadwerkelijk opgeslagen', get_option('status_message_data')['status'] === 'red');
$result = status_api_test_save(array('read'));
check('abonnee krijgt duidelijke weigering', strpos($result, 'die:') === 0);
check('niets opgeslagen zonder recht', get_option('status_message_data') === false);

section('Query-authenticatie: Deprecation header en registratie');
wp_test_reset();
$api = new Status_API_Manager();
list($key, $secret) = call_private($api, 'create_api_client', 'A');

check('query auth standaard toegestaan', $api->check_api_authentication(new WP_Test_Request(array('api_key' => $key, 'api_secret' => $secret))) === true);
$headers = $api->get_status()->get_headers();
check('Deprecation header bij query auth', ($headers['Deprecation'] ?? null) === '@' . Status_API_Manager::QUERY_AUTH_DEPRECATED_SINCE);
$methods = get_option('status_api_clients_auth_methods');
check('query-gebruik geregistreerd per client', !empty($methods[$key]['query']) && empty($methods[$key]['bearer']));

$token = call_private($api, 'generate_bearer_token', $key, $secret);
check('bearer auth werkt', $api->check_api_authentication(new WP_Test_Request(array(), array('Authorization' => 'Bearer ' . $token))) === true);
$headers = $api->get_status()->get_headers();
check('geen Deprecation header bij bearer', !isset($headers['Deprecation']));
$methods = get_option('status_api_clients_auth_methods');
check('bearer-gebruik geregistreerd', !empty($methods[$key]['bearer']) && !empty($methods[$key]['query']));

$GLOBALS['wp_test']['writes'] = array();
$api->check_api_authentication(new WP_Test_Request(array(), array('Authorization' => 'Bearer ' . $token)));
check('registratie gethrottled (geen extra write)', !in_array('status_api_clients_auth_methods', $GLOBALS['wp_test']['writes'], true));

section('Query-authenticatie uitgeschakeld via filter');
add_filter('status_api_allow_query_auth', function () { return false; });
$result = $api->check_api_authentication(new WP_Test_Request(array('api_key' => $key, 'api_secret' => $secret)));
check('query auth geweigerd als uitgeschakeld', $result instanceof WP_Error && $result->data['status'] === 401);
check('bearer blijft werken', $api->check_api_authentication(new WP_Test_Request(array(), array('Authorization' => 'Bearer ' . $token))) === true);

section('Opruimen bij verwijderen');
call_private($api, 'revoke_api_client', $key);
call_private($api, 'delete_api_client', $key);
check('methode-registratie verwijderd met client', !isset(get_option('status_api_clients_auth_methods')[$key]));
