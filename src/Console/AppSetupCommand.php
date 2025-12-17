<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\RabbitMQ\RabbitMqService;
use App\User\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'app:setup',
    description: 'Ensure RabbitMQ topology (run DB migrations separately)',
)]
final class AppSetupCommand extends Command
{
    public function __construct(
        private readonly RabbitMqService $mq,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->mq->ensureTopology();
        $output->writeln('<info>RabbitMQ topology ensured.</info>');
        $output->writeln('<comment>Note: run DB migrations via ./yii migrate:up</comment>');
        return ExitCode::OK;
    }
}
