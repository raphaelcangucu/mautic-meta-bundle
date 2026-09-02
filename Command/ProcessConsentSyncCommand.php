<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Command;

use MauticPlugin\MauticMetaBundle\Application\Consent\WhatsAppConsentSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mautic:meta:consent-sync:process', description: 'Process one queued WhatsApp consent synchronization batch.')]
final class ProcessConsentSyncCommand extends Command
{
    public function __construct(
        private WhatsAppConsentSyncService $sync
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->sync->processNext();
        $output->writeln(json_encode($result ?? ['status' => 'idle'], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
