<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Infrastructure\I18n\Localizer;
use App\Infrastructure\RabbitMQ\RabbitMqService;
use App\Infrastructure\Telegram\TelegramApi;
use App\Telegram\Handlers\BrowseProfilesHandler;
use App\Telegram\KeyboardFactory;
use App\User\UserRepository;
use Psr\Log\LoggerInterface;

final class PreferenceHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RabbitMqService $mq,
        private readonly Localizer $t,
        private readonly TelegramApi $tg,
        private readonly BrowseProfilesHandler $browse,
        private readonly LoggerInterface $logger,
        private readonly KeyboardFactory $kb,
    ) {
    }

    /**
     * @param array $update Full Telegram update
     * @param array $payload {looking_for: 'woman'|'man'}
     */
    public function handle(array $update, array $payload): void
    {
        $cb = $update['callback_query'] ?? [];
        $msg = $cb['message'] ?? [];
        $chatId = (int)($msg['chat']['id'] ?? ($cb['from']['id'] ?? 0));
        if ($chatId <= 0) {
            return;
        }
        $lookingFor = (string)($payload['looking_for'] ?? '');
        if ($lookingFor !== 'woman' && $lookingFor !== 'man') {
            return;
        }

        // Persist preference
        $this->users->setPreference($chatId, $lookingFor);

        // Schedule delayed profile prompt (15 minutes)
        $this->mq->publishProfilePromptDelayed([
            'action' => 'send_create_profile',
            'chat_id' => $chatId,
        ], 15 * 60 * 1000);

        $lang = $this->users->getLanguage($chatId) ?? 'en';
        $ageText = $this->t->t('age_selection.text', $lang);
        $ageKb = $this->kb->ageSelection($lang);
        $this->tg->sendMessage($chatId, $ageText, $ageKb);

        // Immediately send a profile card according to the new preference
//        $this->browse->sendNext($update);

        $this->logger->info('Set preference and scheduled profile prompt', ['user' => $chatId, 'pref' => $lookingFor]);
    }
}
