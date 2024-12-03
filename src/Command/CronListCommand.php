<?php

namespace Cesurapp\SwooleBundle\Command;

use Cesurapp\SwooleBundle\Cron\AbstractCronJob;
use Cesurapp\SwooleBundle\Cron\CronWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'cron:list', description: 'List Crons')]
class CronListCommand extends Command
{
    public function __construct(private readonly CronWorker $cronWorker)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new SymfonyStyle($input, $output);

        if (iterator_count($this->cronWorker->getAll())) {
            $output->table(['Cron Services', 'Enable', 'Time', 'Next'], array_map(static fn (AbstractCronJob $cron) => [
                get_class($cron),
                $cron->ENABLE ? 'True' : 'False',
                is_numeric($cron->TIME) ? $cron->TIME.' second' : $cron->TIME,
                $cron->next ? $cron->next->format('d/m/Y H:i:s') : 'N/A',
            ], [...$this->cronWorker->getAll()]));
        } else {
            $output->warning('Cron job not found!');
        }

        return Command::SUCCESS;
    }
}
