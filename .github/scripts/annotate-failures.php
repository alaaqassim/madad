<?php

/**
 * Turns a JUnit report into GitHub annotations.
 *
 * Without this, a red build says only "exit code 1" on the summary page and
 * the actual failure is buried in the raw log. Annotations put each failure
 * where it happened, and they are visible without opening the log at all.
 *
 * Usage: php .github/scripts/annotate-failures.php <junit.xml>
 */

$path = $argv[1] ?? '';

if (! is_file($path)) {
    fwrite(STDERR, "no JUnit report at {$path}\n");
    exit(0);   // The build has already failed; do not mask its reason.
}

$xml = simplexml_load_file($path);

if ($xml === false) {
    fwrite(STDERR, "unreadable JUnit report at {$path}\n");
    exit(0);
}

/** One line per annotation: GitHub renders only the first otherwise. */
$flatten = static fn (string $text): string => trim(preg_replace('/\s*\R\s*/', ' | ', $text));

$count = 0;

foreach ($xml->xpath('//testcase[failure or error]') as $case) {
    $problem = $case->failure ?? $case->error;

    $name = (string) $case['class'].'::'.(string) $case['name'];
    $file = (string) $case['file'];
    $line = (string) $case['line'];

    // Repository-relative, so the annotation lands on the right file.
    $file = str_replace(getcwd().DIRECTORY_SEPARATOR, '', $file);
    $file = strtr($file, DIRECTORY_SEPARATOR, '/');

    $message = $flatten((string) $problem);

    // 4000 characters is roughly where GitHub stops rendering.
    if (strlen($message) > 3800) {
        $message = substr($message, 0, 3800).' [truncated]';
    }

    printf("::error file=%s,line=%s,title=%s::%s\n", $file, $line ?: '1', $name, $message);

    $count++;
}

fwrite(STDERR, "annotated {$count} failing test(s)\n");
