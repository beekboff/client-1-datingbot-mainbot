<?php

declare(strict_types=1);

namespace App\Shared;

final class BotContext
{
    private ?string $botId = null;

    public function setBotId(string $botId): void
    {
        $this->botId = $botId;
    }

    public function getBotId(): ?string
    {
        if ($this->botId === null) {
            $this->botId = $_ENV['BOT_ID'] ?? $_SERVER['BOT_ID'] ?? $this->detectFromArgv();
        }
        return $this->botId;
    }

    private function detectFromArgv(): ?string
    {
        $commands = [
            'rabbit:consume-updates',
            'rabbit:consume-pushes',
            'rabbit:consume-profile-prompt',
            'app:setup',
            'push:enqueue-due',
            'profiles:import-storage',
            'tg:cleanup-processed-updates',
            'push:reset-daily-counter'
        ];
        foreach ($_SERVER['argv'] ?? [] as $i => $arg) {
            foreach ($commands as $cmd) {
                if ($arg === $cmd || str_ends_with($arg, $cmd)) {
                    if (isset($_SERVER['argv'][$i + 1]) && !str_starts_with($_SERVER['argv'][$i + 1], '-')) {
                        return $_SERVER['argv'][$i + 1];
                    }
                }
            }
        }
        return null;
    }
}
