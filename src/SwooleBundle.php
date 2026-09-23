<?php

namespace Cesurapp\SwooleBundle;

use Cesurapp\SwooleBundle\Client\ClientDataCollector;
use Cesurapp\SwooleBundle\Client\SwooleBridge;
use Cesurapp\SwooleBundle\Cron\CronDataCollector;
use Cesurapp\SwooleBundle\Cron\CronInterface;
use Cesurapp\SwooleBundle\Cron\CronScheduler;
use Cesurapp\SwooleBundle\Cron\CronWorker;
use Cesurapp\SwooleBundle\Process\ProcessInterface;
use Cesurapp\SwooleBundle\Process\ProcessWorker;
use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;
use Cesurapp\SwooleBundle\Task\TaskHandler;
use Cesurapp\SwooleBundle\Task\TaskInterface;
use Cesurapp\SwooleBundle\Task\TaskWorker;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SwooleBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
        ->children()
            ->scalarNode('entrypoint')->defaultValue('public/index.php')->end()
            ->scalarNode('watch_dir')->defaultValue('/config,/src,/templates')->end()
            ->scalarNode('watch_extension')->defaultValue('*.php,*.yaml,*.yml,*.twig')->end()
            ->booleanNode('replace_http_client')->defaultTrue()->end()
            ->booleanNode('cron_worker')->defaultTrue()->end()
            ->booleanNode('task_worker')->defaultFalse()->end()
            ->booleanNode('task_sync_mode')->defaultFalse()->end()
            ->booleanNode('process_worker')->defaultTrue()->end()
            // Seconds before each retry of a failed task: one entry per retry, so [60, 300] runs a
            // task at most three times. FailedTaskCron looks every minute, hence the 60-second floor.
            ->arrayNode('task_retry')
                ->integerPrototype()->min(60)->end()
                ->defaultValue([600])
            ->end()
            // Seconds an attempt of a stored task may run before it is taken as lost and run again.
            // Keep it above the longest durable task.
            ->integerNode('task_redeliver_timeout')->defaultValue(3600)->min(60)->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('Cesurapp\\SwooleBundle\\Command\\', './Command/Server*.*');
        foreach ($config as $key => $value) {
            $builder->setParameter('swoole.'.$key, $value);
        }

        // Register Swoole Http Client
        if ($builder->getParameter('swoole.replace_http_client')) {
            $def = $builder
                ->register(SwooleBridge::class, SwooleBridge::class)
                ->setArguments([new Reference('event_dispatcher')])
                ->setDecoratedService('http_client', invalidBehavior: ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
            if ('test' === $container->env()) {
                $def->setPublic(true);
            }

            if (in_array($container->env(), ['test', 'dev'])) {
                $def->addMethodCall('enableTrace');
                $services->set(ClientDataCollector::class);
            }
        }

        // Register Task Service
        if ($builder->getParameter('swoole.task_worker')) {
            $builder->registerForAutoconfiguration(TaskInterface::class)
                ->addTag('tasks')
                ->setLazy(true);

            // The worker is always injected: besides sync mode, it runs a task inline when Swoole
            // refuses to queue it (inside a task worker, or outside the server's own processes).
            // The store keeps durable tasks.
            $builder->register(TaskHandler::class, TaskHandler::class)->setArguments([
                '$worker' => new Reference(TaskWorker::class),
                '$sync' => 'test' === $container->env() || (bool) $builder->getParameter('swoole.task_sync_mode'),
                '$store' => new Reference(FailedTaskRepository::class),
            ]);

            $services->load('Cesurapp\\SwooleBundle\\Command\\', './Command/Task*.*');
            $services->load('Cesurapp\\SwooleBundle\\Repository\\', './Repository');
            $services->load('Cesurapp\\SwooleBundle\\Entity\\', './Entity');

            // Failed Task Cron: tagged here, so it runs even with cron_worker off
            $services->load('Cesurapp\\SwooleBundle\\Task\\', './Task/*Cron.php')->tag('crons');
        }

        // Register Cron Service
        if ($builder->getParameter('swoole.cron_worker')) {
            $builder->registerForAutoconfiguration(CronInterface::class)
                ->addTag('crons')
                ->setLazy(true);

            if (in_array($container->env(), ['test', 'dev'])) {
                $services->set(CronDataCollector::class);
            }

            $services->load('Cesurapp\\SwooleBundle\\Command\\', './Command/Cron*.*');
        }

        // Register Process Service
        if ($builder->getParameter('swoole.process_worker')) {
            $builder->registerForAutoconfiguration(ProcessInterface::class)
                ->addTag('processes')
                ->setLazy(true);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class () implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                // Init Task Worker
                if ($container->getParameter('swoole.task_worker')) {
                    $tasks = $container->findTaggedServiceIds('tasks');
                    array_walk($tasks, static fn (&$val, $id) => $val = new Reference($id));
                    $container
                        ->register(TaskWorker::class, TaskWorker::class)
                        ->addArgument(ServiceLocatorTagPass::register($container, $tasks))
                        ->setArgument('$retry', '%swoole.task_retry%')
                        ->setAutowired(true)
                        ->setPublic(true);
                }

                // Init Cron Worker, for FailedTaskCron alone when only the task worker is on
                if ($container->getParameter('swoole.cron_worker') || $container->getParameter('swoole.task_worker')) {
                    $crons = $container->findTaggedServiceIds('crons');
                    array_walk($crons, static fn (&$val, $id) => $val = new Reference($id));
                    $container
                        ->register(CronWorker::class, CronWorker::class)
                        ->addArgument(ServiceLocatorTagPass::register($container, $crons))
                        ->setAutowired(true)
                        ->setPublic(true);
                    $container
                        ->register(CronScheduler::class, CronScheduler::class)
                        ->setAutowired(true)
                        ->setPublic(true);
                }

                // Init Process Worker
                if ($container->getParameter('swoole.process_worker')) {
                    $processes = $container->findTaggedServiceIds('processes');
                    array_walk($processes, static fn (&$val, $id) => $val = new Reference($id));
                    $container
                        ->register(ProcessWorker::class, ProcessWorker::class)
                        ->addArgument(ServiceLocatorTagPass::register($container, $processes))
                        ->setAutowired(true)
                        ->setPublic(true);
                }
            }
        });

        parent::build($container);
    }
}
