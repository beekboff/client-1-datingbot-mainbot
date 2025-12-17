<?php

declare(strict_types=1);

use App\Console;

return [
    'hello' => Console\HelloCommand::class,
    'app:setup' => Console\AppSetupCommand::class,
    'rabbit:consume-updates' => Console\RabbitConsumeUpdatesCommand::class,
    'rabbit:consume-profile-prompt' => Console\RabbitConsumeProfilePromptCommand::class,
    'rabbit:consume-pushes' => Console\RabbitConsumePushesCommand::class,
    'push:enqueue-due' => Console\PushEnqueueDueCommand::class,
    'push:reset-daily-counter' => Console\PushResetDailyCounterCommand::class,
    'profiles:import-storage' => Console\ProfilesImportFromStorageCommand::class,
    'tg:cleanup-processed-updates' => Console\TelegramProcessedUpdatesCleanupCommand::class,
];
