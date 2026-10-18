<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(array_filter([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/examples',
        __DIR__ . '/tools',
    ], is_dir(...)))
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2x0' => true,
        '@PHP8x4Migration' => true,
        'declare_strict_types' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        // Call compiler-optimized special functions/constants from the global
        // namespace (leading backslash) for better opcache optimization.
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        // The rule qualifies the constants the running PHP defines and removes
        // the backslash from all others, so the constants of an extension that
        // a PHP may lack are excluded: they stay unqualified everywhere. The
        // signal constants need ext-pcntl, which e.g. the php:8.4-cli image
        // lacks.
        'native_constant_invocation' => [
            'include' => ['@compiler_optimized'],
            'exclude' => ['null', 'false', 'true', 'SIGALRM', 'SIGUSR1', 'SIG_IGN'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
    ])
    ->setFinder($finder);
