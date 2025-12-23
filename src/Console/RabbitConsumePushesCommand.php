<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\RabbitMQ\RabbitMqService;
use App\Infrastructure\Telegram\TelegramApi;
use App\Shared\BotContext;
use App\User\UserRepository;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'rabbit:consume-pushes',
    description: 'Consume prepared push messages from RabbitMQ and send via Telegram',
)]
final class RabbitConsumePushesCommand extends Command
{
    public function __construct(
        private readonly RabbitMqService $mq,
        private readonly TelegramApi $tg,
        private readonly UserRepository $users,
        private readonly BotContext $botContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('bot_id', InputArgument::REQUIRED, 'Bot ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $botId = (string)$input->getArgument('bot_id');
        $this->botContext->setBotId($botId);

        $output->writeln("<info>Consuming pushes from queue tg.pushes for bot {$botId}...</info>");
        $this->mq->ensureTopology();

        $this->mq->consumePushes(function (array $payload): void {
            $method = (string)($payload['method'] ?? '');
            $args = (array)($payload['args'] ?? []);
            $chatId = (int)($args['chat_id'] ?? 0);
            if ($chatId <= 0 || $method === '') {
                return;
            }

            switch ($method) {
                case 'sendMessage':
                    $text = (string)($args['text'] ?? '');
                    $replyMarkup = $args['reply_markup'] ?? null;
                    $parseMode = $args['parse_mode'] ?? null;
                    $this->tg->sendMessage($chatId, $text, is_array($replyMarkup) ? $replyMarkup : null, is_string($parseMode) ? $parseMode : null);
                    break;
                case 'sendPhoto':
                    $photo = (string)($args['photo'] ?? '');
                    $caption = $args['caption'] ?? null;
                    $replyMarkup = $args['reply_markup'] ?? null;
                    $parseMode = $args['parse_mode'] ?? null;
                    $this->tg->sendPhoto($chatId, $photo, is_string($caption) ? $caption : null, is_array($replyMarkup) ? $replyMarkup : null, is_string($parseMode) ? $parseMode : null);
                    break;
                default:
                    // Unknown method, ignore
                    return;
            }

            // Mark last_push after sending
            $this->users->updateLastPush($chatId, new DateTimeImmutable());
        });

        return ExitCode::OK;
    }
}
