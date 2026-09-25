<?php
/**
 * Status melding: formulierverwerking, validatie, verlopen en cron.
 */

class Status_Test_History_Spy extends Status_History_Manager {
    public $logged = array();
    public function log_status_change($status, $title, $content, $expiry_date, $change_note, $changed_by = null) {
        $this->logged[] = func_get_args();
    }
}

$history = new Status_Test_History_Spy();
$manager = new Status_Message_Manager($history);

section('Vervaldatum validatie');
check('geldige datum', call_private($manager, 'normalize_expiry', '2026-10-01', '14:30') === '2026-10-01 14:30');
check('H:i:s geaccepteerd', call_private($manager, 'normalize_expiry', '2026-10-01', '14:30:00') === '2026-10-01 14:30');
check('31 februari geweigerd', call_private($manager, 'normalize_expiry', '2026-02-31', '10:00') === false);
check('onzin geweigerd', call_private($manager, 'normalize_expiry', 'morgen', '10:00') === false);
check('25:00 geweigerd', call_private($manager, 'normalize_expiry', '2026-10-01', '25:00') === false);

$_POST = array('title' => 'x', 'content' => 'y', 'status' => 'green', 'expiry_date' => '01-10-2026', 'expiry_time' => '10:00');
check('prepare => false bij ongeldig', call_private($manager, 'prepare_status_data_from_form') === false);
$_POST['expiry_date'] = '2026-10-01';
$data = call_private($manager, 'prepare_status_data_from_form');
check('prepare => data bij geldig', $data['expiry_date'] === '2026-10-01 10:00');
$_POST = array('title' => 'x', 'status' => 'orange', 'expiry_date' => 'onzin');
check('niet-groen negeert vervaldatum', call_private($manager, 'prepare_status_data_from_form')['expiry_date'] === null);
$_POST = array('title' => 'x', 'status' => 'paars');
check('onbekende status => geen', call_private($manager, 'prepare_status_data_from_form')['status'] === 'geen');

section('Slashes (WordPress voegt magic quotes toe aan $_POST)');
$_POST = array('title' => "Storing\\'s", 'content' => 'Zie \\"link\\"', 'status' => 'red');
$data = call_private($manager, 'prepare_status_data_from_form');
check('titel zonder backslash', $data['title'] === "Storing's");
check('inhoud zonder backslash', $data['content'] === 'Zie "link"');

section('Automatisch verlopen');
wp_test_reset();
$history = new Status_Test_History_Spy();
$manager = new Status_Message_Manager($history);
update_option('status_message_data', array('title' => 'T', 'content' => 'C', 'status' => 'green', 'expiry_date' => '2000-01-01 00:00'));
$manager->check_and_update_expired_status();
check('verlopen groene status => geen', get_option('status_message_data')['status'] === 'geen');
check('geïnjecteerde history manager gebruikt', count($history->logged) === 1 && $history->logged[0][4] === 'Automatisch verlopen');
check('expiry check gelogd bij statuswijziging', get_option('status_last_expiry_check') !== false);

section('status_last_expiry_check throttle');
wp_test_reset();
$manager = new Status_Message_Manager(new Status_Test_History_Spy());
update_option('status_message_data', array('title' => 'T', 'content' => 'C', 'status' => 'green', 'expiry_date' => '2099-01-01 00:00'));
$manager->check_and_update_expired_status();
check('eerste check schrijft', in_array('status_last_expiry_check', $GLOBALS['wp_test']['writes'], true));
$GLOBALS['wp_test']['writes'] = array();
$manager->check_and_update_expired_status();
check('tweede check binnen 5 min schrijft niet', !in_array('status_last_expiry_check', $GLOBALS['wp_test']['writes'], true));
$GLOBALS['wp_test']['doing_cron'] = true;
$manager->check_and_update_expired_status();
check('cron schrijft altijd', in_array('status_last_expiry_check', $GLOBALS['wp_test']['writes'], true));
$GLOBALS['wp_test']['doing_cron'] = false;
$GLOBALS['wp_test']['writes'] = array();
update_option('status_last_expiry_check', date('Y-m-d H:i:s', current_time('timestamp') - 600));
$GLOBALS['wp_test']['writes'] = array();
$manager->check_and_update_expired_status();
check('na 5 min weer geschreven', in_array('status_last_expiry_check', $GLOBALS['wp_test']['writes'], true));

section('Cron opruimen');
wp_test_reset();
$manager->deactivate();
check('deactivate verwijdert alle cron events', $GLOBALS['wp_test']['cleared_hooks'] === array('check_green_status_expiry'));

section('BC: constructor zonder argumenten');
$standalone = new Status_Message_Manager();
check('fallback history manager', call_private($standalone, 'get_history_manager') instanceof Status_History_Manager);
