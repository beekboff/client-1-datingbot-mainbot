<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Infrastructure\Telegram\TelegramApi;
use Psr\Log\LogLevel;
use Yiisoft\Log\Target;

/**
 * TelegramLogTarget sends error-level log messages to a Telegram chat.
 *
 * It is intended for production alerting. By default it:
 * - listens to levels: error, critical, alert, emergency
 * - exports immediately (exportInterval = 1)
 */
final class TelegramLogTarget extends Target
{
    public function __construct(
        private readonly TelegramApi $telegram,
        private readonly int|string $chatId,
        private readonly string $environment = 'prod'
    ) {
        parent::__construct();

        // Listen only to high-severity levels
        $this->setLevels([
            LogLevel::ERROR,
            LogLevel::CRITICAL,
            LogLevel::ALERT,
            LogLevel::EMERGENCY,
            LogLevel::WARNING,
            LogLevel::NOTICE
        ]);

        // Export immediately to avoid buffering in long-running consumers
        $this->setExportInterval(1);

        // Disable in non-prod environments
        $this->setEnabled(fn () =>  !empty($this->chatId)); // $this->environment === 'prod' &&
    }

    protected function export(): void
    {
        // Build a concise message capped by Telegram's 4096 char limit
        $formatted = $this->getFormattedMessages();
        if ($formatted === []) {
            return;
        }

        // Join messages with double newline for readability
        $text = "\u{26A0}\u{FE0F} Application error\n\n" . implode("\n\n", $formatted);

        // Ensure we don't exceed Telegram message length
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 4000) . "\n…";
        }

        try {
            $this->telegram->sendMessage($this->chatId, $text);
        } catch (\Throwable) {
            // Swallow any exceptions to not break the app during logging
        }
    }
}
