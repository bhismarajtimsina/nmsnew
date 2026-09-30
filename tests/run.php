<?php
/**
 * Minimal test runner.
 *
 * Each file in tests/Unit/ returns an array of "name" => closure. Assertions are
 * recorded through the Assert helper in bootstrap.php. Exit code is non-zero when
 * anything fails, so this is usable directly as a CI step.
 *
 * Usage (inside the wca container):
 *   php /www/tests/run.php
 * From the host:
 *   docker compose exec -T wca php /www/tests/run.php
 */

require __DIR__ . '/bootstrap.php';

$files = glob(TEST_ROOT . '/Unit/*Test.php');
sort($files);

$totalCases = 0;
foreach ($files as $file) {
    $suite = basename($file, '.php');
    $cases = require $file;
    if (!is_array($cases)) {
        Assert::context($suite);
        Assert::true(false, 'test file did not return an array of cases');
        continue;
    }
    echo "\n{$suite}\n";
    foreach ($cases as $name => $case) {
        Assert::context("{$suite}/{$name}");
        $before = count(Assert::$failures);
        try {
            $case();
        } catch (\Throwable $e) {
            Assert::true(false, 'threw ' . get_class($e) . ': ' . $e->getMessage());
        }
        $ok = count(Assert::$failures) === $before;
        printf("  %s %s\n", $ok ? '[ok]  ' : '[FAIL]', $name);
        $totalCases++;
    }
}

$failed = count(Assert::$failures);
echo "\n" . str_repeat('-', 60) . "\n";
printf("%d cases, %d assertions passed, %d failed\n", $totalCases, Assert::$passed, $failed);

if ($failed) {
    echo "\nFailures:\n";
    foreach (Assert::$failures as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}
echo "OK\n";
exit(0);
