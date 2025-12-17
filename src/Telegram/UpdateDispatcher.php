<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Infrastructure\I18n\Localizer;
use App\Infrastructure\Telegram\TelegramApi;
use App\User\UserRepository;
use App\Telegram\Handlers\PreferenceHandler;
use App\Telegram\Handlers\BrowseProfilesHandler;
use App\Telegram\Handlers\StartHandler;
use Psr\Log\LoggerInterface;
use DateTimeImmutable;
use App\Telegram\ProcessedUpdatesRepository;

final class UpdateDispatcher
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Localizer $t,
        private readonly TelegramApi $tg,
        private readonly KeyboardFactory $kb,
        private readonly UserRepository $users,
        private readonly ProcessedUpdatesRepository $processedUpdates,
        private readonly StartHandler $start,
        private readonly PreferenceHandler $preference,
        private readonly BrowseProfilesHandler $browse,
    ) {
    }

    /**
     * Dispatch a raw Telegram update as received from RabbitMQ body.
     *
     * Note: message publish time (if needed) is available in AMQP headers under key "unix_time".
     * Currently we don't use it in handlers; it's used only in logging context.
     */
    public function dispatch(array $update): void
    {
        // Accept either raw Telegram update or wrapped as {date, data}
        $tgUpdate = isset($update['data']) && is_array($update['data']) ? $update['data'] : $update;

        // Deduplicate by Telegram update_id
        $updateId = $tgUpdate['update_id'] ?? null;
        if (is_int($updateId) || (is_string($updateId) && ctype_digit($updateId))) {
            $uid = (int)$updateId;
            $inserted = $this->processedUpdates->tryInsert($uid);
            if (!$inserted) {
                $this->logger->debug('Skip duplicate Telegram update', ['update_id' => $uid]);
                return;
            }
        } else {
            $this->logger->warning('Telegram update without update_id, cannot deduplicate');
        }

        if (!isset($tgUpdate['message']) && !isset($tgUpdate['callback_query']) && !isset($tgUpdate['my_chat_member'])) {
            $this->logger->warning('Invalid update payload: missing message/callback_query/my_chat_member');
            return;
        }

        // Handle membership changes in private chat (my_chat_member)
        if (isset($tgUpdate['my_chat_member']) && is_array($tgUpdate['my_chat_member'])) {
            $mcm = $tgUpdate['my_chat_member'];
            $chatId = isset($mcm['chat']['id']) ? (int)$mcm['chat']['id'] : 0;
            $status = $mcm['new_chat_member']['status'] ?? '';
            if ($chatId > 0 && is_string($status)) {
                if ($status === 'kicked' || $status === 'left') {
                    $this->users->deactivate($chatId);
                    $this->logger->info('User deactivated due to block/left', [
                        'user_id' => $chatId,
                        'status' => $status,
                    ]);
                    return;
                }
                if ($status === 'member') {
                    $this->users->activate($chatId);
                    $this->logger->info('User activated on membership update', [
                        'user_id' => $chatId,
                        'status' => $status,
                    ]);
                    return;
                }
            }
        }

        // Update last_push on any interaction
        $chatId = 0;
        if (isset($tgUpdate['message']['chat']['id'])) {
            $chatId = (int)$tgUpdate['message']['chat']['id'];
        } elseif (isset($tgUpdate['callback_query']['from']['id'])) {
            $chatId = (int)$tgUpdate['callback_query']['from']['id'];
        } elseif (isset($tgUpdate['callback_query']['message']['chat']['id'])) {
            $chatId = (int)$tgUpdate['callback_query']['message']['chat']['id'];
        }
        if ($chatId > 0) {
            $this->users->updateLastPush($chatId, new DateTimeImmutable());
        }

        // /start command
        $text = $tgUpdate['message']['text'] ?? null;
        if (is_string($text) && str_starts_with($text, '/start')) {
            $this->start->handle($tgUpdate);
            return;
        }

        // callback queries
        if (isset($tgUpdate['callback_query'])) {
            $cb = $tgUpdate['callback_query'];
            $data = $cb['data'] ?? '';
            if (is_string($data)) {
                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    $action = $decoded['action'] ?? '';
                    $payload = $decoded['data'] ?? [];
                    if ($action === 'set_preference') {
                        $this->preference->handle($tgUpdate, $payload);
                        return;
                    }
                    if ($action === 'browse_profiles' || $action === 'like_profile' || $action === 'dislike_profile') {
                        $this->browse->sendNext($tgUpdate);
                        return;
                    }
                }
            }
        }
    }
}
