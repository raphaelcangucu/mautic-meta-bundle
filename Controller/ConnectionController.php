<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticMetaBundle\Application\Connection\AssetManager;
use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionDiagnostic;
use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionManager;
use MauticPlugin\MauticMetaBundle\Application\Connection\WhatsAppOperationalHealth;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppBusinessProfileManager;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate;
use MauticPlugin\MauticMetaBundle\Form\Type\MetaAssetType;
use MauticPlugin\MauticMetaBundle\Form\Type\MetaConnectionType;
use MauticPlugin\MauticMetaBundle\Form\Type\WhatsAppBusinessProfileType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConnectionController extends CommonController
{
    use MetaViewTrait;

    public function index(CorePermissions $permissions, MetaConnectionRepository $repository, MetaAssetRepository $assets, Request $request, \MauticPlugin\MauticMetaBundle\Application\Ui\ListPage $paging, WhatsAppOperationalHealth $operationalHealth): Response
    {
        if (!$permissions->isGranted('meta:connections:view')) {
            throw $this->createAccessDeniedException();
        }

        $query = $assets->createQueryBuilder('a')->join('a.connection', 'c')->addSelect('c')->orderBy('c.name', 'ASC')->addOrderBy('a.name', 'ASC')->addOrderBy('a.id', 'ASC');
        if ($search = trim($request->query->getString('search'))) {
            $query->andWhere('LOWER(a.name) LIKE :search OR LOWER(a.username) LIKE :search OR a.phoneNumber LIKE :search OR LOWER(c.name) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }
        foreach (['type' => 'a.type', 'status' => 'a.status', 'connection' => 'c.id'] as $key => $field) {
            if ('' !== $request->query->getString($key)) {
                $query->andWhere($field.' = :'.$key)->setParameter($key, $request->query->getString($key));
            }
        }
        $listing = $paging->paginate($query, $request);

        return $this->metaView('@MauticMeta/Connection/index.html.twig', [
            'connections'       => $repository->findBy([], ['name' => 'ASC']),
            'listing'           => $listing,
            'whatsAppHealth'    => $operationalHealth->forAssets($listing['items']),
        ]);
    }

    public function new(Request $request, CorePermissions $permissions, ConnectionManager $manager): Response
    {
        if (!$permissions->isGranted('meta:connections:create')) {
            throw $this->createAccessDeniedException();
        }
        $form = $this->createForm(MetaConnectionType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $manager->create($data['name'], $data['app_id'], $data['app_secret'], $data['access_token'], $data['verify_token'], $data['graph_version'], (string) ($data['webhook_adapters_json'] ?? ''), (string) ($data['consent_source_url'] ?? ''), (string) ($data['consent_source_secret'] ?? ''));
                $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.meta_connection_created_add_its_waba_phone_numbers_or_instagram_accounts_next'));

                return $this->redirectToRoute('mautic_meta_connections', [], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException|\JsonException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->metaView('@MauticMeta/Connection/form.html.twig', ['form' => $form]);
    }

    public function edit(int $connectionId, Request $request, CorePermissions $permissions, MetaConnectionRepository $connections, ConnectionManager $manager): Response
    {
        if (!$permissions->isGranted('meta:connections:edit')) {
            throw $this->createAccessDeniedException();
        }
        $connection = $connections->find($connectionId);
        if (!$connection instanceof MetaConnection) {
            throw $this->createNotFoundException();
        }
        $form = $this->createForm(MetaConnectionType::class, [
            'name' => $connection->getName(), 'app_id' => $connection->getAppId(), 'graph_version' => $connection->getGraphVersion(), 'webhook_adapters_json' => $this->adapterJson($connection), 'consent_source_url' => $connection->getSettings()['consent_source_url'] ?? '',
        ], ['editing' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $manager->update($connection, $form->getData());
                $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.meta_connection_updated_run_the_connection_test_to_confirm_the_credentials'));

                return $this->redirectToRoute('mautic_meta_connections', [], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException|\JsonException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->metaView('@MauticMeta/Connection/form.html.twig', ['form' => $form, 'connection' => $connection]);
    }

    private function adapterJson(MetaConnection $connection): string
    {
        $items = $connection->getSettings()['webhook_adapters'] ?? [];

        return json_encode(array_map(static fn (array $item): array => ['name' => $item['name'], 'url' => $item['url'], 'secret' => '***', 'enabled' => $item['enabled'], 'allowReplies' => $item['allow_replies'] ?? false, 'events' => $item['events'], 'channels' => $item['channels'], 'timeout' => $item['timeout'], 'maxAttempts' => $item['maxAttempts'] ?? 5], is_array($items) ? $items : []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    public function delete(int $connectionId, Request $request, CorePermissions $permissions, MetaConnectionRepository $connections, ConnectionManager $manager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:connections:delete') || !$this->isCsrfTokenValid('meta_connection_delete_'.$connectionId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $connection = $connections->find($connectionId);
        if (!$connection instanceof MetaConnection) {
            throw $this->createNotFoundException();
        }
        $manager->remove($connection);
        $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.meta_connection_and_its_associated_assets_were_deleted'));

        return $this->redirectToRoute('mautic_meta_connections');
    }

    public function newAsset(int $connectionId, Request $request, CorePermissions $permissions, MetaConnectionRepository $connections, AssetManager $manager): Response
    {
        if (!$permissions->isGranted('meta:connections:edit')) {
            throw $this->createAccessDeniedException();
        }
        $connection = $connections->find($connectionId);
        if (!$connection instanceof MetaConnection) {
            throw $this->createNotFoundException();
        }
        $form = $this->createForm(MetaAssetType::class, ['default_region' => 'BR', 'trusted_import_default_region' => 'BR', 'trusted_import_convert_legacy_br_mobile' => true, 'require_opt_in' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->create($connection, $form->getData());
            $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.meta_asset_added'));

            return $this->redirectToRoute('mautic_meta_connections');
        }

        return $this->metaView('@MauticMeta/Connection/asset_form.html.twig', ['form' => $form, 'connection' => $connection]);
    }

    public function editAsset(int $assetId, Request $request, CorePermissions $permissions, MetaAssetRepository $assets, AssetManager $manager, EntityManagerInterface $em, WhatsAppBusinessProfileManager $businessProfiles): Response
    {
        if (!$permissions->isGranted('meta:connections:edit')) {
            throw $this->createAccessDeniedException();
        }
        $asset = $assets->find($assetId);
        if (!$asset instanceof MetaAsset) {
            throw $this->createNotFoundException();
        }
        $settings = $asset->getSettings();
        $form = $this->createForm(MetaAssetType::class, [
            'name' => $asset->getName(), 'type' => $asset->getType()->value, 'external_id' => $asset->getExternalId(),
            'username' => $asset->getUsername(), 'phone_number' => $asset->getPhoneNumber(),
            'default_region' => $settings['default_region'] ?? 'BR', 'trusted_import_default_region' => $settings['trusted_import_default_region'] ?? $settings['default_region'] ?? 'BR', 'trusted_import_convert_legacy_br_mobile' => $settings['trusted_import_convert_legacy_br_mobile'] ?? true, 'contact_match_field' => $settings['contact_match_field'] ?? null,
            'require_opt_in' => $settings['require_opt_in'] ?? true, 'is_default' => $asset->isDefault(),
            'daily_send_limit' => $settings['daily_send_limit'] ?? null,
            'hourly_send_limit' => $settings['hourly_send_limit'] ?? null,
            'recipient_daily_limit' => $settings['recipient_daily_limit'] ?? null,
            'recipient_cooldown_seconds' => $settings['recipient_cooldown_seconds'] ?? null,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->update($asset, $form->getData());
            $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.meta_asset_updated'));

            return $this->redirectToRoute('mautic_meta_connections');
        }

        $businessProfile = null;
        $businessProfileError = null;
        $businessProfileForm = null;
        if ('whatsapp_phone_number' === $asset->getType()->value) {
            $cached = $settings['whatsapp_business_profile'] ?? [];
            $businessProfile = is_array($cached) ? $cached : [];
            $profileData = $this->businessProfileFormData($businessProfile);
            $isProfileSubmit = $request->isMethod('POST') && $request->request->has('whatsapp_business_profile');

            if (!$isProfileSubmit) {
                try {
                    $businessProfile = $businessProfiles->profile($asset);
                    $profileData = $this->businessProfileFormData($businessProfile);
                } catch (\Throwable $exception) {
                    $businessProfileError = $exception->getMessage();
                }
            }

            $businessProfileForm = $this->createForm(WhatsAppBusinessProfileType::class, $profileData);
            $businessProfileForm->handleRequest($request);
            if ($businessProfileForm->isSubmitted() && $businessProfileForm->isValid()) {
                try {
                    $picture = $businessProfileForm->get('profile_picture')->getData();
                    $businessProfiles->update(
                        $asset,
                        $businessProfileForm->getData(),
                        $picture instanceof UploadedFile ? $picture : null,
                    );
                    $this->addFlash('notice', 'Perfil público atualizado diretamente no WhatsApp. A propagação da foto pode levar alguns minutos.');

                    return $this->redirectToRoute('mautic_meta_asset_edit', ['assetId' => $assetId], Response::HTTP_SEE_OTHER);
                } catch (\Throwable $exception) {
                    $businessProfileForm->addError(new FormError($exception->getMessage()));
                }
            }
        }

        $attempt = '';
        $templates = [];
        if ('whatsapp_phone_number' === $asset->getType()->value && $this->getUser()?->isAdmin()) {
            $attempt = bin2hex(random_bytes(32));
            $request->getSession()->set('meta_provider_test', $attempt);
            $templates = $em->getRepository(WhatsAppTemplate::class)->findBy(['status' => 'APPROVED']);
        }

        return $this->metaView('@MauticMeta/Connection/asset_form.html.twig', [
            'form' => $form,
            'connection' => $asset->getConnection(),
            'asset' => $asset,
            'attempt' => $attempt,
            'templates' => $templates,
            'businessProfile' => $businessProfile,
            'businessProfileError' => $businessProfileError,
            'businessProfileForm' => $businessProfileForm?->createView(),
        ]);
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private function businessProfileFormData(array $profile): array
    {
        $websites = is_array($profile['websites'] ?? null) ? $profile['websites'] : [];

        return [
            'about' => (string) ($profile['about'] ?? ''),
            'description' => (string) ($profile['description'] ?? ''),
            'address' => (string) ($profile['address'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'website_1' => (string) ($websites[0] ?? ''),
            'website_2' => (string) ($websites[1] ?? ''),
            'vertical' => (string) ($profile['vertical'] ?? ''),
        ];
    }

    public function deleteAsset(int $assetId, Request $request, CorePermissions $permissions, MetaAssetRepository $assets, AssetManager $manager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:connections:delete') || !$this->isCsrfTokenValid('meta_asset_delete_'.$assetId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $asset = $assets->find($assetId);
        if (!$asset instanceof MetaAsset) {
            throw $this->createNotFoundException();
        }
        $manager->remove($asset);
        $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.meta_asset_deleted'));

        return $this->redirectToRoute('mautic_meta_connections');
    }

    public function test(int $connectionId, Request $request, CorePermissions $permissions, MetaConnectionRepository $connections, ConnectionDiagnostic $diagnostic): RedirectResponse
    {
        if (!$permissions->isGranted('meta:connections:edit') || !$this->isCsrfTokenValid('meta_connection_test_'.$connectionId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $connection = $connections->find($connectionId);
        if (!$connection instanceof MetaConnection) {
            throw $this->createNotFoundException();
        }
        $result = $diagnostic->test($connection);
        $this->addFlash($result['ok'] ? 'notice' : 'error', $result['ok'] ? $this->translator->trans('mautic.meta.ui.connection_healthy', ['%latency%' => $result['latencyMs']]) : $this->translator->trans('mautic.meta.ui.connection_failed', ['%error%' => $result['error']]));

        return $this->redirectToRoute('mautic_meta_connections');
    }
}
