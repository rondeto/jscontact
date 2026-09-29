<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\Config\Level\CodeQualityLevel;
use Rector\Config\Level\CodingStyleLevel;
use Rector\Config\Level\DeadCodeLevel;
use Rector\Config\Level\TypeDeclarationLevel;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets()
    ->withComposerBased(phpunit: true)
    ->withTypeCoverageLevel(count(TypeDeclarationLevel::RULES))
    ->withDeadCodeLevel(count(DeadCodeLevel::RULES))
    ->withCodeQualityLevel(count(CodeQualityLevel::RULES))
    ->withCodingStyleLevel(count(CodingStyleLevel::RULES))
    ->withSkip([
        // "$e" is an acceptable name for a caught exception.
        CatchExceptionNameMatchingTypeRector::class,

        // Comparing to null reads better than instanceof checks.
        FlipTypeControlToUseExclusiveTypeRector::class,
    ])
;
