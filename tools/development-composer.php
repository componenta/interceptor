<?php

declare(strict_types=1);

// Generate an isolated root manifest for the exact development dependency lines.
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$dependencies = [
    'componenta/di' => ['.dependencies/di', 'dev-fix/callback-dependency-baseline-2026-11-26 as 6.0.x-dev'],
];
$repositories = [];
$argument = 1;

foreach ($dependencies as $package => [$defaultPath, $version]) {
    $path = $argv[$argument++] ?? $root . '/' . $defaultPath;
    $resolved = realpath($path);
    if ($resolved === false || !is_file($resolved . '/composer.json')) {
        throw new RuntimeException('Missing development checkout for ' . $package . ': ' . $path);
    }
    $dependency = json_decode(file_get_contents($resolved . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($dependency['name'] ?? null) !== $package) {
        throw new RuntimeException('Unexpected package in development checkout: ' . $path);
    }

    $repositories[] = ['type' => 'path', 'url' => $resolved, 'options' => ['symlink' => true]];
    $section = isset($manifest['require'][$package]) ? 'require' : 'require-dev';
    $manifest[$section][$package] = $version;
}

$manifest['repositories'] = [...$repositories, ...($manifest['repositories'] ?? [])];
$target = $root . '/.composer-development.json';
if (file_put_contents($target, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
    throw new RuntimeException('Could not write the development Composer manifest.');
}
fwrite(STDOUT, "Created .composer-development.json; use COMPOSER=.composer-development.json composer update.\n");
