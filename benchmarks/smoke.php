<?php

declare(strict_types=1);

use Componenta\Interceptor\Benchmarks\InterceptorBench;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Suite/InterceptorBench.php';

$root = dirname(__DIR__);
$report = tempnam(sys_get_temp_dir(), 'interceptor-bench-');
if ($report === false) {
    throw new RuntimeException('Could not allocate the benchmark smoke report.');
}

try {
    $process = proc_open([
        PHP_BINARY,
        $root . '/vendor/bin/phpbench',
        'run',
        '--config=' . $root . '/phpbench.json.dist',
        '--revs=1',
        '--iterations=1',
        '--report=aggregate',
        '--dump-file=' . $report,
    ], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start PHPBench.');
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('PHPBench smoke execution failed with exit code ' . $exitCode);
    }

    $document = new DOMDocument();
    if (!$document->load($report, LIBXML_NONET)) {
        throw new RuntimeException('PHPBench did not produce a valid XML report.');
    }

    $expected = array_keys(iterator_to_array((new InterceptorBench())->provideScenarios()));
    $subjects = ['benchFirstInvocation' => $expected, 'benchRepeatedInvocation' => $expected];
    $xpath = new DOMXPath($document);
    $variants = 0;

    foreach ($xpath->query('/phpbench/suite/benchmark/subject') as $subject) {
        if (!$subject instanceof DOMElement || !$subject->parentNode instanceof DOMElement
            || ltrim($subject->parentNode->getAttribute('class'), '\\') !== InterceptorBench::class
        ) {
            throw new RuntimeException('Unexpected benchmark subject.');
        }
        $name = $subject->getAttribute('name');
        $remaining = $subjects[$name] ?? throw new RuntimeException('Unexpected or duplicate subject: ' . $name);
        unset($subjects[$name]);

        foreach ($xpath->query('./variant', $subject) as $variant) {
            if (!$variant instanceof DOMElement
                || (int) $variant->getAttribute('revs') !== 1
                || (int) $variant->getAttribute('warmup') !== ($name === 'benchFirstInvocation' ? 0 : 100)
                || $xpath->query('./iteration', $variant)->length !== 1
                || $xpath->query('./errors/error | ./failures/failure', $variant)->length !== 0
            ) {
                throw new RuntimeException('Invalid executed benchmark variant: ' . $name);
            }
            $parameterSet = $xpath->query('./parameter-set', $variant)->item(0);
            $scenario = $parameterSet instanceof DOMElement ? $parameterSet->getAttribute('name') : '';
            $index = array_search($scenario, $remaining, true);
            if ($index === false) {
                throw new RuntimeException('Unexpected or duplicate scenario: ' . $scenario);
            }
            unset($remaining[$index]);
            ++$variants;
        }
        if ($remaining !== []) {
            throw new RuntimeException('Missing scenarios: ' . implode(', ', $remaining));
        }
    }

    if ($subjects !== [] || $variants === 0) {
        throw new RuntimeException('Missing benchmark subjects.');
    }
    printf("Verified %d subjects and %d executed variants.\n", 2, $variants);
} finally {
    unlink($report);
}
