<?php

use Rector\Config\RectorConfig;
use Zenstruck\Foundry\Utils\Rector\FoundrySetList;

return RectorConfig::configure()
    ->withPaths([
        // add all paths where your factories are defined and where Foundry is used
        'src',
        'tests'
    ])
    ->withSets([FoundrySetList::REMOVE_PROXIES])
    ;