<?php

declare(strict_types=1);

namespace App\Console;

use App\User\UserRepository;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'push:reset-daily-counter',
    description: 'Reset users.daily_push_count to 0 (run nightly with a lock)'
)]
final class PushResetDailyCounterCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CacheInterface $cache,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lockKey = 'push_reset_daily_counter_lock';
        if ($this->cache->get($lockKey)) {
            $output->writeln('<comment>Reset already running. Skipping.</comment>');
            return ExitCode::OK;
        }
        $this->cache->set($lockKey, 1, 300);
        try {
            $affected = $this->users->resetDailyPushCounters();
            $output->writeln(sprintf('<info>Reset daily_push_count for %d users.</info>', $affected));
            return ExitCode::OK;
        } finally {
            $this->cache->delete($lockKey);
        }
    }
}
