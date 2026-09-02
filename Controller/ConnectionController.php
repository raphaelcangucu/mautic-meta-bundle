<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticMetaBundle\Application\Connection\AssetManager;
use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionDiagnostic;
use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionManager;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use MauticPlugin\MauticMetaBundle\Form\Type\MetaAssetType;
use MauticPlugin\MauticMetaBundle\Form\Type\MetaConnectionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConnectionController extends AbstractController
{
    public function index(CorePermissions $permissions, MetaConnectionRepository $repository): Response
    {
        if (!$permissions->isGranted('meta:connections:view')) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('@MauticMeta/Connection/index.html.twig', ['connections' => $repository->findBy([], ['name' => 'ASC'])]);
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
                $this->addFlash('notice', 'Meta connection created. Add its WABA, phone numbers, or Instagram accounts next.');

                return $this->redirectToRoute('mautic_meta_connections', [], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException|\JsonException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('@MauticMeta/Connection/form.html.twig', ['form' => $form]);
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
                $this->addFlash('notice', 'Meta connection updated. Run the connection test to confirm the credentials.');

                return $this->redirectToRoute('mautic_meta_connections', [], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException|\JsonException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('@MauticMeta/Connection/form.html.twig', ['form' => $form, 'connection' => $connection]);
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
        $this->addFlash('notice', 'Meta connection and its associated assets were deleted.');

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
        $form = $this->createForm(MetaAssetType::class, ['default_region' => 'BR', 'require_opt_in' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->create($connection, $form->getData());
            $this->addFlash('notice', 'Meta asset added.');

            return $this->redirectToRoute('mautic_meta_connections');
        }

        return $this->render('@MauticMeta/Connection/asset_form.html.twig', ['form' => $form, 'connection' => $connection]);
    }

    public function editAsset(int $assetId, Request $request, CorePermissions $permissions, MetaAssetRepository $assets, AssetManager $manager): Response
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
            'default_region' => $settings['default_region'] ?? 'BR', 'contact_match_field' => $settings['contact_match_field'] ?? null,
            'require_opt_in' => $settings['require_opt_in'] ?? true, 'is_default' => $asset->isDefault(),
            'daily_send_limit' => $settings['daily_send_limit'] ?? null,
            'hourly_send_limit' => $settings['hourly_send_limit'] ?? null,
            'recipient_daily_limit' => $settings['recipient_daily_limit'] ?? null,
            'recipient_cooldown_seconds' => $settings['recipient_cooldown_seconds'] ?? null,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->update($asset, $form->getData());
            $this->addFlash('notice', 'Meta asset updated.');

            return $this->redirectToRoute('mautic_meta_connections');
        }

        return $this->render('@MauticMeta/Connection/asset_form.html.twig', ['form' => $form, 'connection' => $asset->getConnection(), 'asset' => $asset]);
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
        $this->addFlash('notice', 'Meta asset deleted.');

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
        $this->addFlash($result['ok'] ? 'notice' : 'error', $result['ok'] ? sprintf('Meta connection is healthy (%d ms).', $result['latencyMs']) : 'Meta connection failed: '.$result['error']);

        return $this->redirectToRoute('mautic_meta_connections');
    }
}
