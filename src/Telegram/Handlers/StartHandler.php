<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Infrastructure\I18n\Localizer;
use App\Infrastructure\RabbitMQ\RabbitMqService;
use App\Infrastructure\Telegram\TelegramApi;
use App\Shared\AppOptions;
use App\Telegram\KeyboardFactory;
use App\User\UserRepository;

final class StartHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Localizer $t,
        private readonly TelegramApi $tg,
        private readonly KeyboardFactory $kb,
        private readonly AppOptions $opts,
        private readonly RabbitMqService $mq
    ) {
    }

    /**
     * @param array $update Telegram update array
     */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? [];
        $chatId = (int)($message['chat']['id'] ?? 0);
        $from = $message['from'] ?? [];
        $langCode = is_string($from['language_code'] ?? null) ? $from['language_code'] : 'en';
        $lang = $this->t->normalize($langCode);

        if ($chatId <= 0) {
            return;
        }

        if (!$this->users->isRegistered($chatId)) {
            $this->users->register($chatId, $lang);
        }

        $text = $this->t->t('find_whom.text', $lang);
        $kb = $this->kb->findWhom($lang);
        $photoUrl = rtrim($this->opts->publicBaseUrl, '/') . '/storage/find_whom_ru.jpg';
//        $this->tg->sendPhoto($chatId, $photoUrl, $text, $kb);
        $this->tg->sendMessage($chatId, $text, $kb);

        // Schedule delayed profile prompt (15 minutes)
        $this->mq->publishProfilePromptDelayed([
            'action' => 'send_create_profile',
            'chat_id' => $chatId,
        ], 15 * 60 * 1000);
    }
}
