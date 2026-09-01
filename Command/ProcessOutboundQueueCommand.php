<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Command;

use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mautic:meta:queue:process', description: 'Process queued WhatsApp and Instagram outbound messages.')]
final class ProcessOutboundQueueCommand extends Command
{
    public function __construct(
        private OutboundQueue $queue
    ) { parent::__construct(); }
    protected function configure(): void { $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum jobs to process.', '100'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->queue->work(max(1, (int) $input->getOption('limit')));
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
