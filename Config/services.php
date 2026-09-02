<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass;
use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClient;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use MauticPlugin\MauticMetaBundle\Security\WebhookSignatureVerifier;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = MauticCoreExtension::DEFAULT_EXCLUDES;
    $excludes[] = 'Application/Connection/ConnectionCredentials.php';
    $excludes[] = 'Application/WhatsApp/WhatsAppSendResult.php';
    $excludes[] = 'Infrastructure/MetaGraphApiException.php';

    $services->load('MauticPlugin\\MauticMetaBundle\\', '../')
        ->exclude('../{'.implode(',', $excludes).'}');

    $services->load('MauticPlugin\\MauticMetaBundle\\Entity\\', '../Entity/*Repository.php')
        ->tag(ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);

    $services->set(CredentialVault::class);
    $services->set(WebhookSignatureVerifier::class);
    $services->alias(MetaGraphClientInterface::class, MetaGraphClient::class);
};
