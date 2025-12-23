<?php

declare(strict_types=1);

namespace App\Console;

use App\Profile\ProfileRepository;
use App\Shared\BotContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'profiles:import-storage',
    description: 'Scan storage/profiles/{men,women} and create profile records based on file names.',
)]
final class ProfilesImportFromStorageCommand extends Command
{
    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly BotContext $botContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('bot_id', InputArgument::REQUIRED, 'Bot ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $botId = (string)$input->getArgument('bot_id');
        $this->botContext->setBotId($botId);

        $io = new SymfonyStyle($input, $output);

        $root = dirname(__DIR__, 2);
        $base = $root . '/public/storage/profiles';
        $dirs = [
            'men' => 'man',
            'women' => 'woman',
        ];

        $total = 0;
        $created = 0;
        $exists = 0;
        $skipped = 0;

        foreach ($dirs as $folder => $gender) {
            $path = $base . '/' . $folder;
            if (!is_dir($path)) {
                $io->warning("Directory not found: $path (skipping)");
                continue;
            }

            $files = scandir($path) ?: [];
            foreach ($files as $f) {
                if ($f === '.' || $f === '..' || $f === '.gitignore') {
                    continue;
                }
                $full = $path . '/' . $f;
                if (!is_file($full)) {
                    continue;
                }
                $total++;
                $res = $this->profiles->createIfNotExists($f, $gender);
                if ($res['created']) {
                    if ($res['id'] > 0) {
                        $created++;
                    } else {
                        $skipped++;
                    }
                } else {
                    $exists++;
                }
            }
        }

        $io->success("Processed: $total, created: $created, already exists: $exists, skipped: $skipped");
        return ExitCode::OK;
    }
}
