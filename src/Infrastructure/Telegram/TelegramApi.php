<?php

declare(strict_types=1);

namespace App\Infrastructure\Telegram;

use App\Shared\BotContext;
use GuzzleHttp\Client as HttpClient;
use Psr\Log\LoggerInterface;

final class TelegramApi
{

    public bool $log = false;

    /**
     * @param array<string, string> $botTokens Map of bot_id => token
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly BotContext $botContext,
        private readonly array $botTokens = [],
        private readonly string $defaultToken = '',
    ) {
    }

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }
        $this->call('sendMessage', $payload);
    }

    public function sendPhoto(int|string $chatId, string $photoUrlOrId, ?string $caption = null, ?array $replyMarkup = null, ?string $parseMode = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'photo' => $photoUrlOrId,
        ];
        if ($caption !== null) {
            $payload['caption'] = $caption;
        }
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }
        $this->call('sendPhoto', $payload);
    }

    public static function inlineKeyboard(array $rows): array
    {
        // $rows is array of rows; each row is array of button arrays
        return ['inline_keyboard' => $rows];
    }

    public static function callbackButton(string $text, array $payload): array
    {
        return [
            'text' => $text,
            'callback_data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    public static function urlButton(string $text, string $url): array
    {
        return [
            'text' => $text,
            'url' => $url,
        ];
    }

    private function getToken(): string
    {
        if ($this->log) {
            return $this->defaultToken;
        }
        $botId = $this->botContext->getBotId();
        if ($botId !== null && isset($this->botTokens[$botId])) {
            return $this->botTokens[$botId];
        }
        return $this->defaultToken;
    }

    private function call(string $method, array $payload): void
    {
        $token = $this->getToken();
        if ($token === '') {
            $this->logger->warning('Telegram token is empty for bot {bot_id}, skipping call {method}', [
                'bot_id' => $this->botContext->getBotId(),
                'method' => $method
            ]);
            return;
        }
        $url = sprintf('%s/bot%s/%s', $this->baseUrl, $token, $method);
        try {
            $resp = $this->http->post($url, [
                'json' => $payload,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            $body = (string) $resp->getBody();
            $data = json_decode($body, true);
            if (!is_array($data) || !($data['ok'] ?? false)) {
                $this->logger->error('Telegram API error on {method}: {body}', ['method' => $method, 'body' => $body]);
            }
        } catch (\Throwable $e) {
            if (isset($resp) && $resp->getStatusCode() === 403) {
                return;
            }
            $this->logger->error('Telegram API exception on {method}: {error}; {payload}', ['method' => $method, 'error' => $e->getMessage(), 'payload' => $payload, 'code' => $e->getCode()]);
        }
    }
}
