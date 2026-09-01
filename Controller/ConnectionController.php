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
            $manager->create($data['name'], $data['app_id'], $data['app_secret'], $data['access_token'], $data['verify_token'], $data['graph_version']);
            $this->addFlash('notice', 'Meta connection created. Add its WABA, phone numbers, or Instagram accounts next.');

            return $this->redirectToRoute('mautic_meta_connections');
        }

        return $this->render('@MauticMeta/Connection/form.html.twig', ['form' => $form]);
    }

    public function edit(int $connectionId, Request $request, CorePermissions $permissions, MetaConnectionRepository $connections, ConnectionManager $manager): Response
    {
        if (!$permissions->isGranted('meta:connections:edit')) { throw $this->createAccessDeniedException(); }
        $connection = $connections->find($connectionId);
        if (!$connection instanceof MetaConnection) { throw $this->createNotFoundException(); }
        $form = $this->createForm(MetaConnectionType::class, [
            'name' => $connection->getName(), 'app_id' => $connection->getAppId(), 'graph_version' => $connection->getGraphVersion(),
        ], ['editing' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->update($connection, $form->getData());
            $this->addFlash('notice', 'Meta connection updated. Run the connection test to confirm the credentials.');

            return $this->redirectToRoute('mautic_meta_connections');
        }

        return $this->render('@MauticMeta/Connection/form.html.twig', ['form' => $form, 'connection' => $connection]);
    }

    public function delete(int $connectionId, Request $request, CorePermissions $permissions, MetaConnectionRepository $connections, ConnectionManager $manager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:connections:delete') || !$this->isCsrfTokenValid('meta_connection_delete_'.$connectionId, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $connection = $connections->find($connectionId);
        if (!$connection instanceof MetaConnection) { throw $this->createNotFoundException(); }
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
        if (!$permissions->isGranted('meta:connections:edit')) { throw $this->createAccessDeniedException(); }
        $asset = $assets->find($assetId);
        if (!$asset instanceof MetaAsset) { throw $this->createNotFoundException(); }
        $settings = $asset->getSettings();
        $form = $this->createForm(MetaAssetType::class, [
            'name' => $asset->getName(), 'type' => $asset->getType()->value, 'external_id' => $asset->getExternalId(),
            'username' => $asset->getUsername(), 'phone_number' => $asset->getPhoneNumber(),
            'default_region' => $settings['default_region'] ?? 'BR', 'contact_match_field' => $settings['contact_match_field'] ?? null,
            'require_opt_in' => $settings['require_opt_in'] ?? true, 'is_default' => $asset->isDefault(),
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
        if (!$permissions->isGranted('meta:connections:delete') || !$this->isCsrfTokenValid('meta_asset_delete_'.$assetId, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $asset = $assets->find($assetId);
        if (!$asset instanceof MetaAsset) { throw $this->createNotFoundException(); }
        $manager->remove($asset);
        $this->addFlash('notice', 'Meta asset deleted.');

        return $this->redirectToRoute('mautic_meta_connections');
    }

    public function test(int $connectionId, Request $request, CorePermissions $permissions, MetaConnectionRepository $connections, ConnectionDiagnostic $diagnostic): RedirectResponse
    {
        if (!$permissions->isGranted('meta:connections:edit') || !$this->isCsrfTokenValid('meta_connection_test_'.$connectionId, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $connection = $connections->find($connectionId);
        if (!$connection instanceof MetaConnection) { throw $this->createNotFoundException(); }
        $result = $diagnostic->test($connection);
        $this->addFlash($result['ok'] ? 'notice' : 'error', $result['ok'] ? sprintf('Meta connection is healthy (%d ms).', $result['latencyMs']) : 'Meta connection failed: '.$result['error']);

        return $this->redirectToRoute('mautic_meta_connections');
    }
}
