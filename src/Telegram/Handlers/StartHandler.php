<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Infrastructure\I18n\Localizer;
use App\Infrastructure\Telegram\TelegramApi;
use App\Telegram\KeyboardFactory;
use App\User\UserRepository;

final class StartHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Localizer $t,
        private readonly TelegramApi $tg,
        private readonly KeyboardFactory $kb,
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
        $this->tg->sendMessage($chatId, $text, $kb);
    }
}
