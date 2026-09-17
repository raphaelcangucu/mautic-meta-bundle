<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;

final class TechProviderManager
{
    public function __construct(private EntityManagerInterface $em, private MetaConnectionRepository $connections,
        private MetaAssetRepository $assets, private EmbeddedSignupApi $signup, private CredentialVault $vault,
        private MetaGraphClientInterface $graph, private WabaResolver $resolver)
    {
    }

    public function connect(MetaConnection $provider, string $code, string $wabaId, string $phoneId, string $redirect, string $name): MetaConnection
    {
        if ($provider->isCustomer() || !$provider->isPublished()) {
            throw new \DomainException('Aplicativo Tech Provider indisponível.');
        }
        foreach ([$wabaId, $phoneId] as $id) {
            if (!preg_match('/^[0-9]{5,32}$/', $id)) {
                throw new \InvalidArgumentException('Identificador Meta inválido.');
            }
        }
        $auth = $this->signup->exchange($provider, $code, $redirect);
        // Only the temporary request context is changed; no provider credential is overwritten.
        $probe = clone $provider;
        $probe->setEncryptedAccessToken($this->vault->seal($auth['token']));
        $waba = $this->graph->get($probe, $wabaId, ['fields' => 'id,name,owner_business_info']);
        $business = (string) ($waba['owner_business_info']['id'] ?? '');
        if (($waba['id'] ?? '') !== $wabaId || !preg_match('/^[0-9]{5,32}$/', $business)) {
            throw new \DomainException('Não foi possível confirmar a empresa proprietária do WABA. Revise os acessos na Meta.');
        }
        $phone = $this->graph->get($probe, $phoneId, ['fields' => 'id,display_phone_number,verified_name,status,is_on_biz_app']);
        $found = false;
        $after = null;
        for ($page = 0; $page < 100; ++$page) {
            $phones = $this->graph->get($probe, $wabaId.'/phone_numbers', ['fields' => 'id', 'limit' => 100] + ($after ? ['after' => $after] : []));
            foreach ($phones['data'] ?? [] as $row) {
                if (($row['id'] ?? '') === $phoneId) {
                    $found = true;
                    break;
                }
            }
            if ($found || empty($phones['paging']['next'])) {
                break;
            }
            $next = $phones['paging']['cursors']['after'] ?? null;
            if (!$next || $next === $after) {
                break;
            } $after = $next;
        }
        if (!$found || ($phone['id'] ?? '') !== $phoneId) {
            throw new \DomainException('O número selecionado não pertence ao WABA autorizado.');
        }
        $db = $this->em->getConnection();
        $lock = 'meta_tp_'.hash('sha1', $provider->getAppId().':'.$business);
        if (1 !== (int) $db->fetchOne('SELECT GET_LOCK(:name, 5)', ['name' => $lock])) {
            throw new \DomainException('Cadastro em andamento. Atualize a lista antes de tentar novamente.');
        }
        try {
            return $this->em->wrapInTransaction(function () use ($provider, $probe, $business, $waba, $wabaId, $phone, $phoneId, $name, $auth): MetaConnection {
                $customer = $this->connections->findOneBy(['appId' => $provider->getAppId(), 'businessId' => $business]);
                foreach ([$wabaId, $phoneId] as $id) {
                    foreach ($this->assets->findBy(['externalId' => $id]) as $existing) {
                        if (!$customer || $existing->getConnection()->getId() !== $customer->getId()) {
                            throw new \DomainException('Este ativo já está conectado. Use o teste da conexão existente; não será duplicado ou migrado.');
                        }
                    }
                }
                $customer ??= new MetaConnection();
                $customer->setName(trim($name) ?: (string) $waba['name'])->setAppId($provider->getAppId())->setBusinessId($business)
                    ->setEncryptedAccessToken($probe->getEncryptedAccessToken())->setGraphVersion($provider->getGraphVersion())
                    ->setIsPublished(true)->setStatus('pending')->setTokenExpiresAt($auth['expires'] ? new \DateTime('@'.$auth['expires']) : null)
                    ->setSettings(array_replace($customer->getSettings(), ['provider_connection_id' => $provider->getId(), 'authorization_at' => gmdate(DATE_ATOM)]));
                $this->em->persist($customer);
                $this->em->flush();
                $this->asset($customer, $wabaId, AssetType::WhatsAppBusinessAccount, (string) $waba['name']);
                $number = $this->asset($customer, $phoneId, AssetType::WhatsAppPhoneNumber, (string) ($phone['verified_name'] ?? $name));
                $number->setPhoneNumber((string) ($phone['display_phone_number'] ?? ''))->setSettings(array_replace($number->getSettings(), ['waba_id' => $wabaId]));
                $this->em->flush();

                return $customer;
            });
        } finally {
            $db->executeQuery('SELECT RELEASE_LOCK(:name)', ['name' => $lock]);
        }
    }

    private function asset(MetaConnection $connection, string $id, AssetType $type, string $name): MetaAsset
    {
        $asset = $this->assets->findOneBy(['connection' => $connection, 'externalId' => $id, 'type' => $type->value]);
        $asset ??= (new MetaAsset())->setConnection($connection)->setExternalId($id)->setType($type)->setSettings(['default_region' => 'BR']);
        $asset->setName($name)->setIsPublished(true)->setStatus('pending');
        $this->em->persist($asset);

        return $asset;
    }

    public function check(MetaAsset $phone): array
    {
        $waba = $this->resolver->forPhone($phone);
        $c = $phone->getConnection();
        $data = $this->graph->get($c, $phone->getExternalId(), ['fields' => 'id,status,code_verification_status,is_on_biz_app,quality_rating,display_phone_number']);
        $subs = $this->graph->get($c, $waba->getExternalId().'/subscribed_apps');
        $subscribed = false;
        foreach ($subs['data'] ?? [] as $app) {
            if (($app['whatsapp_business_api_data']['id'] ?? $app['id'] ?? '') === $c->getAppId()) {
                $subscribed = true;
            }
        }
        $result = ['checked_at' => gmdate(DATE_ATOM), 'waba_id' => $waba->getExternalId(), 'phone_status' => $data['status'] ?? 'UNKNOWN',
            'verified' => 'VERIFIED' === ($data['code_verification_status'] ?? ''), 'coexistence' => (bool) ($data['is_on_biz_app'] ?? false),
            'subscribed' => $subscribed, 'billing' => 'manual_check_required', 'delivery' => 'not_tested',
            'ready' => 'CONNECTED' === ($data['status'] ?? '') && $subscribed];
        $phone->setSettings(array_replace($phone->getSettings(), ['waba_id' => $waba->getExternalId(), 'provider_readiness' => $result]));
        if ($result['ready']) {
            $phone->setStatus('active');
            $waba->setStatus('active');
            if ('paused' !== $c->getStatus()) {
                $c->setStatus('active');
            }
        } else {
            $phone->setStatus('pending');
        }
        $this->em->persist($phone);
        $this->em->persist($waba);
        $this->em->persist($c);
        $this->em->flush();

        return $result;
    }

    public function subscribe(MetaAsset $phone): void
    {
        $waba = $this->resolver->forPhone($phone);
        $r = $this->graph->post($phone->getConnection(), $waba->getExternalId().'/subscribed_apps', []);
        if (true !== ($r['success'] ?? false)) {
            throw new \DomainException('A Meta não confirmou a inscrição de eventos.');
        }
        $this->check($phone);
    }

    public function register(MetaAsset $phone, string $pin): void
    {
        $this->resolver->forPhone($phone);
        $current = $this->graph->get($phone->getConnection(), $phone->getExternalId(), ['fields' => 'status,is_on_biz_app,code_verification_status']);
        if ('CONNECTED' === ($current['status'] ?? '')) {
            $this->check($phone);

            return;
        }
        if (!empty($current['is_on_biz_app'])) {
            throw new \DomainException('Este número usa coexistência. Conclua o fluxo específico da Meta; registro genérico não será executado.');
        }
        if ('VERIFIED' !== ($current['code_verification_status'] ?? '')) {
            throw new \DomainException('Confirme primeiro a posse do número na Meta.');
        }
        if (!preg_match('/^[0-9]{6}$/', $pin)) {
            throw new \InvalidArgumentException('Informe o PIN de duas etapas de seis dígitos.');
        }
        $r = $this->graph->post($phone->getConnection(), $phone->getExternalId().'/register', ['messaging_product' => 'whatsapp', 'pin' => $pin]);
        if (true !== ($r['success'] ?? false)) {
            throw new \DomainException('Registro não confirmado pela Meta.');
        }
        $this->check($phone);
    }
}
