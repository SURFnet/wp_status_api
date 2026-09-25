<?php
/**
 * Testrunner: php tests/run.php
 * Exit code 0 = alles geslaagd, 1 = één of meer tests mislukt.
 */

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/test-*.php') as $test_file) {
    echo "\n== " . basename($test_file) . "\n";
    wp_test_reset();
    require $test_file;
}

// uninstall.php definieert constanten en functies; daarom per scenario in een eigen proces
section('uninstall.php (apart proces)');
foreach (array('keep', 'delete', 'multisite') as $scenario) {
    $output = array();
    $exit_code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/uninstall-scenario.php') . ' ' . $scenario . ' 2>&1', $output, $exit_code);
    foreach ($output as $line) {
        echo $line . "\n";
    }
    check("uninstall scenario '{$scenario}' proces zonder fouten", $exit_code === 0);
}

$results = $GLOBALS['wp_test_results'];
echo "\n{$results['pass']} geslaagd, {$results['fail']} mislukt\n";
exit($results['fail'] > 0 ? 1 : 0);
