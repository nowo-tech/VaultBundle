<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/demo',
        __DIR__ . '/tests/App/var',
        __DIR__ . '/tests/Fixtures/OptionalBundles',
        // 2.0: application classes that still implement a removed port (they cannot be loaded; the build must fail).
        __DIR__ . '/tests/Support/Removed',
        // is_callable([$connection, 'getServerVersion']) probes a method that doctrine/dbal 3 keeps private: the first-class
        // callable form would throw instead of answering false.
        ArrayToFirstClassCallableRector::class => [__DIR__ . '/src/Doctrine/ExactCollation.php'],
        // Optional packages are probed by class-name strings: a ::class constant of a class that may be absent is
        // what these files avoid (the test stand-ins in tests/Fixtures would otherwise make Rector rewrite them).
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/src/Integration',
            __DIR__ . '/src/DependencyInjection/VaultExtension.php',
            __DIR__ . '/tests/Fixtures/OptionalBundles',
        ],
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
