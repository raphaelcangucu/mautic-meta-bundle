<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mautic:meta:consent-source:configure', description: 'Configure the signed landing evidence source without exposing its secret in command arguments.')]
final class ConfigureConsentSourceCommand extends Command
{
    public function __construct(
        private MetaConnectionRepository $connections,
        private EntityManagerInterface $entityManager,
        private CredentialVault $vault
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('connectionId', InputArgument::REQUIRED)->addArgument('url', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = trim((string) $input->getArgument('url'));
        $secret = trim((string) getenv('MAUTIC_META_CONSENT_SOURCE_SECRET'));
        $connection = $this->connections->find((int) $input->getArgument('connectionId'));
        if (!$connection instanceof MetaConnection || !filter_var($url, FILTER_VALIDATE_URL) || 'https' !== parse_url($url, PHP_URL_SCHEME) || '' === $secret) {
            $output->writeln('<error>Valid connection, HTTPS URL, and MAUTIC_META_CONSENT_SOURCE_SECRET are required.</error>');
            return Command::INVALID;
        }
        $settings = $connection->getSettings();
        $settings['consent_source_url'] = $url;
        $settings['consent_source_secret'] = $this->vault->seal($secret);
        $connection->setSettings($settings);
        $this->entityManager->persist($connection);
        $this->entityManager->flush();
        $output->writeln('<info>Landing consent evidence source configured; secret not displayed.</info>');

        return Command::SUCCESS;
    }
}
