<?php

declare(strict_types=1);

// Since 1.21.0: SearchKit (PHP >= 8.3) is optional. Without it (the PHP 8.2 floor, the dependency-floor job) the
// SearchKit group cannot be analysed: it extends SearchKit's EntitySearchDefinition. It is analysed wherever SearchKit
// is installed (the PHPStan job runs .github/ci/search-kit.sh first).
return is_file(__DIR__ . '/vendor/nowo-tech/search-kit-bundle/src/Provider/Entity/EntitySearchDefinition.php')
    ? []
    : ['parameters' => ['excludePaths' => ['analyseAndScan' => [__DIR__ . '/src/Bridge/SearchKit/VaultItemSearchDefinition.php']]]];
