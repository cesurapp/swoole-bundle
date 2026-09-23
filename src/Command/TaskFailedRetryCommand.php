<?php

namespace Cesurapp\SwooleBundle\Command;

use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'task:failed:retry', description: 'Retry every failed task on the next FailedTaskCron run.')]
class TaskFailedRetryCommand extends Command
{
    public function __construct(private readonly FailedTaskRepository $failedTaskRepo)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The rows get their attempts back and stay put: FailedTaskCron claims and queues them like
        // any retry, and each one leaves the store only when its task succeeds.
        $count = $this->failedTaskRepo->retryFailed();

        new SymfonyStyle($input, $output)->success(sprintf('%d failed task(s) will be retried on the next FailedTaskCron run.', $count));

        return Command::SUCCESS;
    }
}
