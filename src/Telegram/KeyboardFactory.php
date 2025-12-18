<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Infrastructure\I18n\Localizer;
use App\Infrastructure\Telegram\TelegramApi;
use App\Shared\AppOptions;

final class KeyboardFactory
{
    public function __construct(private readonly Localizer $t, private readonly AppOptions $opts)
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

    public function createProfile(string $lang, ?int $userId = null): array
    {
        $base = $this->opts->profileCreateUrl;
        $urlWithId =  'https://tlin.cc/i/xw26z1/url_create_profile/' . $userId. '?url=' . urlencode($base);

//            $userId
//            ? $base . (str_contains($base, '?') ? '&' : '?') . 'uid=' . urlencode((string)$userId)
//            : $base;
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

    public function profileCard(string $lang, int $profileId, int $userId): array
    {
        $btnLike = TelegramApi::callbackButton($this->t->t('profile.buttons.like', $lang), [
            'action' => 'like_profile',
            'data' => ['profile_id' => $profileId],
        ]);
        $btnDislike = TelegramApi::callbackButton($this->t->t('profile.buttons.dislike', $lang), [
            'action' => 'dislike_profile',
            'data' => ['profile_id' => $profileId],
        ]);
        $base = $this->opts->profileCreateUrl;
        $connectUrl =  'https://tlin.cc/i/xw26z1/url_dating/' . $userId. '?url=' . urlencode($base); ;// . (str_contains($base, '?') ? '&' : '?') . 'pid=' . urlencode((string)$profileId);
        $btnConnect = TelegramApi::urlButton($this->t->t('profile.buttons.connect', $lang), $connectUrl);
        return TelegramApi::inlineKeyboard([
            [$btnLike, $btnDislike],
            [$btnConnect],
        ]);
    }
}
