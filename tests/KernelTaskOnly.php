<?php

namespace Cesurapp\SwooleBundle\Tests;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The test app with its crons off and the task worker on.
 */
class KernelTaskOnly extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->extension('swoole', [
            'cron_worker' => false,
        ]);
    }
}
