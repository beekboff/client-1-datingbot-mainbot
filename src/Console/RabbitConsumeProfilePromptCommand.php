<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\I18n\Localizer;
use App\Infrastructure\RabbitMQ\RabbitMqService;
use App\Infrastructure\Telegram\TelegramApi;
use App\Shared\AppOptions;
use App\Shared\BotContext;
use App\Telegram\KeyboardFactory;
use App\User\UserRepository;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'rabbit:consume-profile-prompt',
    description: 'Consume delayed profile prompt messages and send Telegram notifications',
)]
final class RabbitConsumeProfilePromptCommand extends BaseRabbitConsumeCommand
{
    public function __construct(
        private readonly RabbitMqService $mq,
        private readonly TelegramApi $tg,
        private readonly AppOptions $opts,
        private readonly Localizer $t,
        private readonly KeyboardFactory $kb,
        private readonly UserRepository $users,
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

        $output->writeln("<info>Consuming delayed profile prompts for bot {$botId}...</info>");
        $this->mq->ensureTopology();

        $this->mq->consumeProfilePrompt(
            function (array $payload): void {
                $this->db->close();

                $action = (string)($payload['action'] ?? '');
                if ($action !== 'send_create_profile') {
                    return;
                }
                $chatId = (int)($payload['chat_id'] ?? 0);
                if ($chatId <= 0) {
                    return;
                }
                $lang = $this->users->getLanguage($chatId) ?? 'en';
                $text = $this->t->t('create_profile.text', $lang);
                $markup = $this->kb->createProfile($lang, $chatId);
                $this->tg->sendMessage($chatId, $text, $markup);
                $this->users->updateLastPush($chatId, new DateTimeImmutable());
            },
            $this->getMemoryLimit($input),
            $this->getMessagesLimit($input)
        );

        return ExitCode::OK;
    }
}
