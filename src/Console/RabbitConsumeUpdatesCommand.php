<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\RabbitMQ\RabbitMqService;
use App\Shared\BotContext;
use App\Telegram\UpdateDispatcher;
use Yiisoft\Db\Connection\ConnectionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'rabbit:consume-updates',
    description: 'Consume Telegram updates from RabbitMQ queue tg_got_data',
)]
final class RabbitConsumeUpdatesCommand extends BaseRabbitConsumeCommand
{
    public function __construct(
        private readonly RabbitMqService $mq,
        private readonly UpdateDispatcher $dispatcher,
        private readonly BotContext $botContext,
        private readonly ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('bot_id', InputArgument::REQUIRED, 'Bot ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(30);
        $botId = (string)$input->getArgument('bot_id');
        $this->botContext->setBotId($botId);

        $output->writeln("<info>Consuming updates from queue tg_got_data for bot {$botId}...</info>");
        $this->mq->ensureTopology();
        $this->mq->consumeUpdates(
            function (array $payload): void {
                $this->db->close();
                $this->dispatcher->dispatch($payload);
            },
            $this->getMemoryLimit($input),
            $this->getMessagesLimit($input)
        );
        return ExitCode::OK;
    }
}
