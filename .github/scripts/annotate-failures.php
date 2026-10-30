<?php

/**
 * Turns Pest's JUnit report into GitHub Actions error annotations and a job summary, so a
 * failing run shows which tests broke without opening the raw log.
 *
 * Usage: php .github/scripts/annotate-failures.php junit.xml
 */
$path = $argv[1] ?? 'junit.xml';

if (! is_file($path)) {
    exit(0);
}

$xml = @simplexml_load_file($path);

if ($xml === false) {
    fwrite(STDERR, "Could not read {$path}.\n");
    exit(0);
}

$escape = static fn (string $text): string => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $text);
$caseName = static function (SimpleXMLElement $case): string {
    $class = (string) $case['class'];
    $name = (string) $case['name'];

    return $class === '' ? $name : preg_replace('/^P\\\\Tests\\\\/', '', $class).' > '.preg_replace('/^__pest_evaluable_/', '', $name);
};

$failures = [];
$total = 0;

foreach ($xml->xpath('//testcase') ?: [] as $case) {
    $total++;

    $problem = $case->failure ?? $case->error;

    if ($problem === null) {
        continue;
    }

    $message = trim((string) $problem);
    $firstLine = trim(strtok($message, "\n"));
    $name = $caseName($case);

    $failures[] = ['name' => $name, 'message' => $message];
    echo '::error title='.$escape($name).'::'.$escape(mb_substr($firstLine, 0, 900))."\n";
}

$summary = getenv('GITHUB_STEP_SUMMARY');

if ($summary !== false && $summary !== '') {
    $lines = '## Test results'.PHP_EOL.PHP_EOL;

    if ($failures === []) {
        $lines .= "All {$total} tests passed.".PHP_EOL;
    } else {
        $lines .= count($failures)." of {$total} tests failed:".PHP_EOL.PHP_EOL;
        $lines .= "| Test | Failure |".PHP_EOL."| --- | --- |".PHP_EOL;

        foreach ($failures as $failure) {
            $lines .= '| '.str_replace('|', '\|', $failure['name']).' | '.str_replace(['|', "\n"], ['\|', ' '], mb_substr(trim(strtok($failure['message'], "\n")), 0, 300)).' |'.PHP_EOL;
        }
    }

    file_put_contents($summary, $lines, FILE_APPEND);
}

exit(0);
