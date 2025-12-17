<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Infrastructure\I18n\Localizer;
use App\Infrastructure\Telegram\TelegramApi;
use App\Profile\ProfileRepository;
use App\Telegram\KeyboardFactory;
use App\User\UserRepository;
use App\Shared\AppOptions;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

final class BrowseProfilesHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly Localizer $t,
        private readonly TelegramApi $tg,
        private readonly KeyboardFactory $kb,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly AppOptions $options,
    ) {
    }

    /**
     * Sends next random profile for the user based on preference.
     * Triggered by callbacks: browse_profiles, like_profile, dislike_profile
     * or directly after preference selection.
     */
    public function sendNext(array $update): void
    {
        $cb = $update['callback_query'] ?? [];
        $msg = $cb['message'] ?? ($update['message'] ?? []);
        $chatId = (int)($msg['chat']['id'] ?? ($cb['from']['id'] ?? 0));
        if ($chatId <= 0) {
            return;
        }

        $lang = $this->users->getLanguage($chatId) ?? 'en';
        $lang = $this->t->normalize($lang);
        $pref = $this->users->getPreference($chatId);
        if ($pref !== 'woman' && $pref !== 'man') {
            // ask for preference again
            $text = $this->t->t('find_whom.text', $lang);
            $kb = $this->kb->findWhom($lang);
            $this->tg->sendMessage($chatId, $text, $kb);
            return;
        }

        // Try to get from cached queue first
        $queueKey = $this->queueCacheKey($chatId, $pref);
        $queue = $this->cache->get($queueKey);
        if (!is_array($queue)) {
            $queue = [];
        }

        $profile = null;
        if (!empty($queue)) {
            $profile = array_shift($queue);
            // refresh cache TTL with remaining
            $this->cache->set($queueKey, $queue, 600);
        }

        if ($profile === null) {
            // Refill queue with a batch of unseen profiles
            $batch = $this->profiles->getUnseenBatchByGender($chatId, $pref, 10);
            if (empty($batch)) {
                // reset and try again once
                $this->profiles->clearShownForUser($chatId);
                $batch = $this->profiles->getUnseenBatchByGender($chatId, $pref, 10);
            }
            if (!empty($batch)) {
                $profile = array_shift($batch);
                // store remainder for 10 minutes
                $this->cache->set($queueKey, $batch, 600);
            }
        }

        if ($profile === null) {
            // Nothing to show; silently return
            $this->logger->warning('No profiles to show', ['user' => $chatId, 'pref' => $pref]);
            return;
        }

//        $caption = $this->t->t('profile.text', $lang);
        $caption = '';
        $kb = $this->kb->profileCard($lang, $profile['id']);
        $photoUrl = $this->buildPublicPhotoUrl($profile['gender'], $profile['file']);
        $this->tg->sendPhoto($chatId, $photoUrl, $caption, $kb);

        // mark as shown
        $this->profiles->markShown($chatId, $profile['id']);
    }

    private function queueCacheKey(int $userId, string $gender): string
    {
        return 'profiles_queue_' . $userId . '_' . $gender;
    }

    private function buildPublicPhotoUrl(string $gender, string $file): string
    {
        $base = rtrim($this->options->publicBaseUrl, '/');
        $folder = $gender === 'man' ? 'men' : 'women';
        // keep original file name but encode path segment
        $encoded = rawurlencode($file);
        return $base . '/storage/profiles/' . $folder . '/' . $encoded;
    }
}
