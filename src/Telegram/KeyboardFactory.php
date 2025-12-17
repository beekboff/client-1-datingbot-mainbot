<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Infrastructure\I18n\Localizer;
use App\Infrastructure\Telegram\TelegramApi;

final class KeyboardFactory
{
    public function __construct(private readonly Localizer $t)
    {
    }

    public function findWhom(string $lang): array
    {
        $btnWoman = TelegramApi::callbackButton($this->t->t('find_whom.buttons.woman', $lang), [
            'action' => 'set_preference',
            'data' => ['looking_for' => 'woman'],
        ]);
        $btnMan = TelegramApi::callbackButton($this->t->t('find_whom.buttons.man', $lang), [
            'action' => 'set_preference',
            'data' => ['looking_for' => 'man'],
        ]);
        return TelegramApi::inlineKeyboard([
            [$btnWoman],
            [$btnMan]
        ]);
    }

    public function createProfile(string $lang, string $url, ?int $userId = null): array
    {
        $urlWithId = $userId ? $url . (str_contains($url, '?') ? '&' : '?') . 'uid=' . urlencode((string)$userId) : $url;
        $btnCreate = TelegramApi::urlButton($this->t->t('create_profile.buttons.create_profile', $lang), $urlWithId);
        $btnBrowse = TelegramApi::callbackButton($this->t->t('create_profile.buttons.browse_profiles', $lang), [
            'action' => 'browse_profiles',
            'data' => new \stdClass(),
        ]);
        return TelegramApi::inlineKeyboard([
            [$btnCreate],
            [$btnBrowse]
        ]);
    }

    public function profileCard(string $lang, int $profileId): array
    {
        $btnLike = TelegramApi::callbackButton($this->t->t('profile.buttons.like', $lang), [
            'action' => 'like_profile',
            'data' => ['profile_id' => $profileId],
        ]);
        $btnDislike = TelegramApi::callbackButton($this->t->t('profile.buttons.dislike', $lang), [
            'action' => 'dislike_profile',
            'data' => ['profile_id' => $profileId],
        ]);
        $btnConnect = TelegramApi::urlButton($this->t->t('profile.buttons.connect', $lang), 'https://example.com/connect?pid=' . $profileId);
        return TelegramApi::inlineKeyboard([
            [$btnLike, $btnDislike],
            [$btnConnect],
        ]);
    }
}
