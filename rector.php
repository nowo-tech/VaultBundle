<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/demo',
        __DIR__ . '/tests/App/var',
        __DIR__ . '/tests/Fixtures/OptionalBundles',
        // Optional packages are probed by class-name strings: a ::class constant of a class that may be absent is
        // what these files avoid (the test stand-ins in tests/Fixtures would otherwise make Rector rewrite them).
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/src/Integration',
            __DIR__ . '/tests/Fixtures/OptionalBundles',
        ],
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
