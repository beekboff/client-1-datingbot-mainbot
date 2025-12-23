<?php

declare(strict_types=1);

namespace App\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseRabbitConsumeCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('memoryLimit', null, InputOption::VALUE_OPTIONAL, 'Memory limit in MB', 350);
        $this->addOption('messagesLimit', null, InputOption::VALUE_OPTIONAL, 'Messages limit', 100);
    }

    protected function getMemoryLimit(InputInterface $input): int
    {
        return (int)$input->getOption('memoryLimit');
    }

    protected function getMessagesLimit(InputInterface $input): int
    {
        return (int)$input->getOption('messagesLimit');
    }
}
