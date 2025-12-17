<?php

declare(strict_types=1);

namespace App\Console;

use App\Telegram\ProcessedUpdatesRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'tg:cleanup-processed-updates',
    description: 'Cleanup old processed Telegram update ids (keep last N days, default: 2)'
)]
final class TelegramProcessedUpdatesCleanupCommand extends Command
{
    public function __construct(private readonly ProcessedUpdatesRepository $repo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_OPTIONAL, 'How many days to keep', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $daysOpt = (string)$input->getOption('days');
        $days = (int)$daysOpt;
        if ($days <= 0) {
            $days = 2;
        }

        $deleted = $this->repo->deleteOlderThanDays($days);
        $output->writeln(sprintf('<info>Deleted %d processed update ids older than %d days.</info>', $deleted, $days));
        return ExitCode::OK;
    }
}
