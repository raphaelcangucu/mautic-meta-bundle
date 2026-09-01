<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Command;

use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\MauticMetaBundle\Application\Connection\AssetManager;
use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionManager;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mautic:meta:migrate-whatsapp', description: 'Migrate the legacy single-account WhatsApp Cloud integration into MauticMetaBundle.')]
final class MigrateLegacyWhatsAppCommand extends Command
{
    public function __construct(
        private IntegrationsHelper $integrations,
        private ConnectionManager $connections,
        private MetaConnectionRepository $connectionRepository,
        private AssetManager $assets,
        private MetaAssetRepository $assetRepository
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('app-id', null, InputOption::VALUE_REQUIRED, 'Meta App ID missing from the legacy integration.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and show the migration without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try { $legacy = $this->legacyConfiguration(); } catch (\Throwable $exception) { $output->writeln('<error>'.$exception->getMessage().'</error>'); return Command::FAILURE; }
        $appId = trim((string) $input->getOption('app-id'));
        if ('' === $appId) { $output->writeln('<error>--app-id is required because the legacy plugin did not store it.</error>'); return Command::INVALID; }
        $plan = ['connection' => 'Migrated WhatsApp Cloud', 'appId' => $appId, 'graphVersion' => $legacy['graph_version'], 'wabaId' => $legacy['business_account_id'], 'phoneNumberId' => $legacy['phone_number_id'], 'defaultRegion' => $legacy['default_region']];
        $output->writeln(json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if ((bool) $input->getOption('dry-run')) { return Command::SUCCESS; }

        $connection = $this->connectionRepository->findOneBy(['appId' => $appId]);
        if (!$connection instanceof MetaConnection) { $connection = $this->connections->create('Migrated WhatsApp Cloud', $appId, $legacy['app_secret'], $legacy['access_token'], $legacy['verify_token'], $legacy['graph_version']); }
        $createdAssets = 0;
        $createdAssets += $this->createAssetIfMissing($connection, AssetType::WhatsAppBusinessAccount, $legacy['business_account_id'], 'Migrated WABA', []);
        $createdAssets += $this->createAssetIfMissing($connection, AssetType::WhatsAppPhoneNumber, $legacy['phone_number_id'], 'Migrated WhatsApp number', ['default_region' => $legacy['default_region'], 'contact_match_field' => $legacy['phone_field'], 'require_opt_in' => true]);
        $output->writeln(sprintf('<info>Migration complete. Connection #%d; %d assets created.</info>', $connection->getId(), $createdAssets));

        return Command::SUCCESS;
    }

    /**
     * @return array{access_token:string,app_secret:string,verify_token:string,phone_number_id:string,business_account_id:string,graph_version:string,phone_field:string,default_region:string}
     */
    private function legacyConfiguration(): array
    {
        $integration = $this->integrations->getIntegration('WhatsAppCloud');
        $settings = $integration->getIntegrationConfiguration();
        if (!$settings->getIsPublished()) { throw new \RuntimeException('WhatsApp Cloud integration is not enabled.'); }
        $keys = $settings->getApiKeys();
        $features = $settings->getFeatureSettings()['integration'] ?? [];
        foreach (['access_token', 'app_secret', 'verify_token', 'phone_number_id', 'business_account_id'] as $key) {
            if (empty($keys[$key])) { throw new \RuntimeException(sprintf('Missing WhatsApp configuration key: %s.', $key)); }
        }

        return [
            'access_token' => (string) $keys['access_token'], 'app_secret' => (string) $keys['app_secret'],
            'verify_token' => (string) $keys['verify_token'], 'phone_number_id' => (string) $keys['phone_number_id'],
            'business_account_id' => (string) $keys['business_account_id'], 'graph_version' => (string) ($features['graph_version'] ?? 'v26.0'),
            'phone_field' => (string) ($features['phone_field'] ?? 'mobile'), 'default_region' => strtoupper((string) ($features['default_region'] ?? 'BR')),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createAssetIfMissing(MetaConnection $connection, AssetType $type, string $externalId, string $name, array $settings): int
    {
        if ($this->assetRepository->findOneBy(['connection' => $connection, 'type' => $type->value, 'externalId' => $externalId]) instanceof MetaAsset) { return 0; }
        $this->assets->create($connection, ['type' => $type->value, 'external_id' => $externalId, 'name' => $name, 'is_default' => true, 'default_region' => $settings['default_region'] ?? 'BR', 'contact_match_field' => $settings['contact_match_field'] ?? null, 'require_opt_in' => $settings['require_opt_in'] ?? true]);

        return 1;
    }
}
