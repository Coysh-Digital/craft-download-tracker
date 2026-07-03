<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symfony\Component\DependencyInjection\Loader\Configurator\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __FILE__,
    ]);
    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};
