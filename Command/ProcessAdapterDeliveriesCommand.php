<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Command;

use MauticPlugin\MauticMetaBundle\Application\Adapter\WebhookDeliveryQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mautic:meta:adapters:process', description: 'Deliver queued omnichannel webhook events.')]
final class ProcessAdapterDeliveriesCommand extends Command
{
    public function __construct(private WebhookDeliveryQueue $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum deliveries.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode($this->queue->work(max(1, (int) $input->getOption('limit'))), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
