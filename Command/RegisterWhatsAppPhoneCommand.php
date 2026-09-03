<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Command;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'mautic:meta:phone:register', description: 'Register a configured WhatsApp phone-number asset with the Cloud API.')]
final class RegisterWhatsAppPhoneCommand extends Command
{
    public function __construct(
        private MetaAssetRepository $assets,
        private MetaGraphClientInterface $graph,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('asset-id', InputArgument::REQUIRED, 'Internal Meta asset ID.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $asset = $this->assets->find((int) $input->getArgument('asset-id'));
        if (!$asset instanceof MetaAsset || AssetType::WhatsAppPhoneNumber !== $asset->getType()) {
            $output->writeln('<error>WhatsApp phone-number asset not found.</error>');

            return Command::FAILURE;
        }

        $question = (new Question('Two-step verification PIN: '))->setHidden(true)->setHiddenFallback(false);
        $pin = trim((string) $this->getHelper('question')->ask($input, $output, $question));
        if (!preg_match('/^[0-9]{6}$/', $pin)) {
            $output->writeln('<error>The PIN must contain exactly six digits.</error>');

            return Command::INVALID;
        }

        $response = $this->graph->post($asset->getConnection(), $asset->getExternalId().'/register', [
            'messaging_product' => 'whatsapp',
            'pin'               => $pin,
        ]);
        unset($pin);

        if (true !== ($response['success'] ?? false)) {
            $output->writeln('<error>Meta did not confirm phone registration.</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>WhatsApp phone asset %d registered successfully.</info>', $asset->getId()));

        return Command::SUCCESS;
    }
}
