<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\CodingStyle\Rector\FuncCall\ConsistentPregDelimiterRector;
use Rector\CodingStyle\Rector\ClassConst\VarConstantCommentRector;
use Rector\Config\RectorConfig;
use Rector\Doctrine\Rector\Property\ImproveDoctrineCollectionDocTypeInEntityRector;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\SetList;
use Rector\Symfony\Rector\Class_\EventListenerToEventSubscriberRector;
use Rector\Symfony\Set\SymfonySetList;
use Rector\Transform\Rector\Attribute\AttributeKeyToClassConstFetchRector;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;
use Rector\Php80\Rector\FuncCall\ClassOnObjectRector;
use Rector\Php80\Rector\NotIdentical\StrContainsRector;
use Rector\Php80\Rector\Identical\StrEndsWithRector;
use Rector\Php80\Rector\Identical\StrStartsWithRector;
use Rector\Php80\Rector\Class_\StringableForToStringRector;
use Rector\Php81\Rector\ClassMethod\NewInInitializerRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php74\Rector\Property\RestoreDefaultNullToNullableTypePropertyRector;
use Rector\Php74\Rector\Assign\NullCoalescingOperatorRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnTypeDeclarationRector;
use Rector\Php71\Rector\List_\ListToArrayDestructRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureReturnTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Renaming\Rector\ClassConstFetch\RenameClassConstFetchRector;


return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/public/upload.php'
    ]);

    $rectorConfig->symfonyContainerXml(__DIR__ . '/var/cache/dev/App_KernelDevDebugContainer.xml');

    $rectorConfig->rules([
        // PHP 7.1
        ListToArrayDestructRector::class,

        // PHP 7.4
        RestoreDefaultNullToNullableTypePropertyRector::class,
        NullCoalescingOperatorRector::class,
        AddReturnTypeDeclarationRector::class,

        // PHP 8.0
        ChangeSwitchToMatchRector::class,
        ClassOnObjectRector::class,
        StrContainsRector::class,
        StrEndsWithRector::class,
        StrStartsWithRector::class,
        StringableForToStringRector::class,

        // PHP 8.1
        NewInInitializerRector::class,
        ReadOnlyPropertyRector::class,

        // PHP 8.2
        ReadOnlyClassRector::class,

        //Type declaration
        AddClosureReturnTypeRector::class,
        AddVoidReturnTypeWhereNoReturnRector::class,
    ]);

    $rectorConfig->ruleWithConfiguration(ConsistentPregDelimiterRector::class, [
        ConsistentPregDelimiterRector::DELIMITER => '/',
    ]);

    $rectorConfig->sets([
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::DEAD_CODE,

        SymfonySetList::SYMFONY_62,
        SymfonySetList::SYMFONY_CODE_QUALITY,
        SymfonySetList::SYMFONY_CONSTRUCTOR_INJECTION,

        PHPUnitSetList::PHPUNIT_91,
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,

        DoctrineSetList::DOCTRINE_CODE_QUALITY,
    ]);

    $rectorConfig->skip([
        EventListenerToEventSubscriberRector::class,
        AttributeKeyToClassConstFetchRector::class,
        ImproveDoctrineCollectionDocTypeInEntityRector::class,
        EncapsedStringsToSprintfRector::class,
        VarConstantCommentRector::class,
        ExplicitBoolCompareRector::class,
        RenameClassRector::class,
        RenameClassConstFetchRector::class
    ]);
};
