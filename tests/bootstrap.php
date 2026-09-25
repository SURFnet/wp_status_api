<?php
/**
 * Minimale WordPress-stubs zodat de plugin-klassen zonder WordPress getest kunnen worden.
 * Geen externe afhankelijkheden; draait op PHP 7.4 t/m 8.x.
 */

define('ABSPATH', __DIR__ . '/fixtures/');
define('MINUTE_IN_SECONDS', 60);
define('STATUS_API_PLUGIN_FILE', dirname(__DIR__) . '/status-api.php');

// error_log() van de plugin (bijv. bij automatisch verlopen) niet in de testuitvoer tonen
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

$GLOBALS['wp_test'] = array();

function wp_test_reset() {
    $GLOBALS['wp_test'] = array(
        'options' => array(),
        'transients' => array(),
        'writes' => array(),
        'filters' => array(),
        'actions' => array(),
        'cleared_hooks' => array(),
        'dbdelta' => array(),
        'doing_cron' => false,
        'queries' => array(),
    );
    $_POST = array();
    $_GET = array();
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
}
wp_test_reset();

// --- Hooks / filters
function add_action($tag, $callback, $priority = 10) { $GLOBALS['wp_test']['actions'][] = array($tag, $callback); }
function add_filter($tag, $callback) { $GLOBALS['wp_test']['filters'][$tag] = $callback; }
function apply_filters($tag, $value, ...$args) {
    return isset($GLOBALS['wp_test']['filters'][$tag]) ? call_user_func($GLOBALS['wp_test']['filters'][$tag], $value, ...$args) : $value;
}
function register_activation_hook() {}
function register_deactivation_hook() {}

// --- Options / transients
function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['wp_test']['options']) ? $GLOBALS['wp_test']['options'][$key] : $default;
}
function update_option($key, $value, $autoload = null) {
    $GLOBALS['wp_test']['writes'][] = $key;
    $GLOBALS['wp_test']['options'][$key] = $value;
    return true;
}
function delete_option($key) { unset($GLOBALS['wp_test']['options'][$key]); return true; }
function get_transient($key) { return isset($GLOBALS['wp_test']['transients'][$key]) ? $GLOBALS['wp_test']['transients'][$key] : false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['wp_test']['transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['wp_test']['transients'][$key]); return true; }

// --- Cron
function wp_next_scheduled($hook) { return false; }
function wp_schedule_event() { return true; }
function wp_clear_scheduled_hook($hook) { $GLOBALS['wp_test']['cleared_hooks'][] = $hook; return 0; }
function wp_doing_cron() { return $GLOBALS['wp_test']['doing_cron']; }

// --- Diversen
function wp_parse_args($args, $defaults) { return array_merge($defaults, (array) $args); }
function wp_generate_password($length) { return substr(bin2hex(random_bytes($length)), 0, $length); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function wp_kses_post($value) { return $value; }
function wp_timezone() { return new DateTimeZone('Europe/Amsterdam'); }
function wp_timezone_string() { return 'Europe/Amsterdam'; }
function wp_date($format) { return (new DateTimeImmutable('now', wp_timezone()))->format($format); }
function site_url($path = '') { return 'https://example.test' . $path; }
function current_time($type) {
    $now = new DateTimeImmutable('now', wp_timezone());
    if ($type === 'mysql') {
        return $now->format('Y-m-d H:i:s');
    }
    return time() + $now->getOffset();
}
function get_current_user_id() { return 1; }
function wp_get_current_user() { return (object) array('display_name' => 'Tester', 'user_login' => 'tester'); }
function plugins_url($path, $file) { return 'https://example.test/wp-content/plugins/wp_status_api/' . $path; }
function plugin_dir_path($file) { return dirname($file) . '/'; }
function dbDelta($sql) { $GLOBALS['wp_test']['dbdelta'][] = $sql; return array(); }

class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct($code = '', $message = '', $data = '') {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
}

class WP_REST_Response {
    public $data;
    public $headers = array();
    public function __construct($data = null) { $this->data = $data; }
    public function header($key, $value) { $this->headers[$key] = $value; }
    public function get_data() { return $this->data; }
    public function get_headers() { return $this->headers; }
}
function rest_ensure_response($response) {
    return ($response instanceof WP_REST_Response) ? $response : new WP_REST_Response($response);
}

class WP_Test_Request {
    private $params;
    private $headers;
    public function __construct($params = array(), $headers = array()) { $this->params = $params; $this->headers = $headers; }
    public function get_param($key) { return isset($this->params[$key]) ? $this->params[$key] : null; }
    public function get_header($key) { return isset($this->headers[$key]) ? $this->headers[$key] : null; }
}

class WP_Test_DB {
    public $prefix = 'wp_';
    public $options = 'wp_options';
    public $inserts = array();
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function insert($table, $data, $format = null) { $this->inserts[] = array($table, $data); return 1; }
    public function query($sql) { $GLOBALS['wp_test']['queries'][] = $sql; return true; }
    public function prepare($query, ...$args) { return vsprintf(str_replace('%s', "'%s'", $query), $args); }
    public function esc_like($text) { return addcslashes($text, '_%\\'); }
}
$GLOBALS['wpdb'] = new WP_Test_DB();

require dirname(__DIR__) . '/includes/class-status-api-plugin.php';
require dirname(__DIR__) . '/includes/class-status-api-manager.php';
require dirname(__DIR__) . '/includes/class-status-message-manager.php';
require dirname(__DIR__) . '/includes/class-status-history-manager.php';

// --- Mini test-framework
$GLOBALS['wp_test_results'] = array('pass' => 0, 'fail' => 0);

function check($name, $condition) {
    if ($condition) {
        $GLOBALS['wp_test_results']['pass']++;
        echo "  ok    {$name}\n";
    } else {
        $GLOBALS['wp_test_results']['fail']++;
        echo "  FAIL  {$name}\n";
    }
}

function section($name) {
    echo "\n{$name}\n";
}

/**
 * Roep een private/protected methode aan.
 */
function call_private($object, $method, ...$args) {
    $reflection = new ReflectionMethod($object, $method);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    return $reflection->invoke($object, ...$args);
}
