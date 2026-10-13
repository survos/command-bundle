<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        //        __DIR__ . '/assets',
        //        __DIR__ . '/config',
        //        __DIR__ . '/public',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    // uncomment to reach your current PHP version
    ->withPhpVersion(Rector\ValueObject\PhpVersion::PHP_84)
    ->withRules([
        Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector::class,
        Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector::class,
        Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector::class,
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictTypedPropertyRector::class,
    ])
    ->withPhpSets(php84: true)
    ->withTypeCoverageLevel(10)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0);
