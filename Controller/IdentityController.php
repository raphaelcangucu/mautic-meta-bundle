<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Application\Consent\WhatsAppConsentSyncService;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Domain\ConsentStatus;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConsentSyncRun;
use MauticPlugin\MauticMetaBundle\Entity\MetaConsentSyncRunRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class IdentityController extends AbstractController
{
    public function index(CorePermissions $permissions, MetaContactIdentityRepository $identities, MetaAssetRepository $assets, MetaConsentSyncRunRepository $runs, Request $request, int $page = 1): Response
    {
        if (!$permissions->isGranted('meta:messages:view')) { throw $this->createAccessDeniedException(); }

        $page = max(1, $page);
        $limit = max(5, min(100, (int) $request->getSession()->get('mautic.metaIdentities.limit', 30)));
        $search = trim((string) $request->query->get('search', ''));
        $assetId = max(0, (int) $request->query->get('asset', 0)) ?: null;
        $channel = in_array($request->query->get('channel'), ['whatsapp', 'instagram'], true) ? (string) $request->query->get('channel') : null;
        $consent = ConsentStatus::tryFrom((string) $request->query->get('consent', ''))?->value;
        $identityPage = $identities->findPage($search, $assetId, $channel, $consent, ($page - 1) * $limit, $limit);
        $lastPage = max(1, (int) ceil($identityPage['total'] / $limit));
        if ($page > $lastPage) {
            return $this->redirectToRoute('mautic_meta_identities', ['page' => $lastPage, 'search' => $search, 'asset' => $assetId, 'consent' => $consent]);
        }
        $allAssets = $assets->findAll();

        return $this->render('@MauticMeta/Identity/index.html.twig', [
            'identities' => $identityPage['items'],
            'identityTotal' => $identityPage['total'], 'identityPage' => $page, 'identityLimit' => $limit,
            'identityFilters' => ['search' => $search, 'asset' => $assetId, 'channel' => $channel, 'consent' => $consent],
            'allAssets' => $allAssets,
            'whatsappAssets' => array_values(array_filter($allAssets, static fn ($asset): bool => AssetType::WhatsAppPhoneNumber === $asset->getType())),
            'syncRuns' => $runs->findBy([], ['id' => 'DESC'], 20),
            'syncPreview' => $request->getSession()->get('meta_consent_sync_preview'),
        ]);
    }

    public function previewSync(Request $request, CorePermissions $permissions, WhatsAppConsentSyncService $sync): RedirectResponse
    {
        $this->assertSyncAccess($request, $permissions, 'meta_consent_sync_preview');
        try {
            $sourceMode = (string) $request->request->get('source_mode', 'explicit_consent_fields');
            $preview = 'mautic_api_waitlist' === $sourceMode
                ? $sync->previewMauticWaitlist((int) $request->request->get('asset_id'), (string) $request->request->get('stage', 'Waitlist'), (int) $request->request->get('batch_size', 100))
                : $sync->preview((int) $request->request->get('asset_id'), (string) $request->request->get('source'), (string) $request->request->get('consent_version'), (int) $request->request->get('batch_size', 100));
            $request->getSession()->set('meta_consent_sync_preview', $preview);
            $this->addFlash('notice', 'Analysis completed. Review the counters before confirming synchronization.');
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('mautic_meta_identities');
    }

    public function startSync(Request $request, CorePermissions $permissions, WhatsAppConsentSyncService $sync): RedirectResponse
    {
        $this->assertSyncAccess($request, $permissions, 'meta_consent_sync_start');
        $preview = $request->getSession()->get('meta_consent_sync_preview');
        if (!is_array($preview) || (int) ($preview['asset']['id'] ?? 0) !== (int) $request->request->get('asset_id')) {
            $this->addFlash('error', 'A matching read-only analysis is required before synchronization.');
            return $this->redirectToRoute('mautic_meta_identities');
        }
        if ('mautic_api_waitlist' === ($preview['sourceMode'] ?? null) && '1' !== (string) $request->request->get('trusted_waitlist_attestation')) {
            $this->addFlash('error', 'The trusted API Waitlist consent attestation must be explicitly confirmed.');
            return $this->redirectToRoute('mautic_meta_identities');
        }
        $user = $this->getUser();
        $waitlistMode = 'mautic_api_waitlist' === ($preview['sourceMode'] ?? null);
        $run = $sync->start(
            (int) $preview['asset']['id'],
            $waitlistMode ? 'mautic_api_waitlist' : (string) $preview['criteria']['source'],
            $waitlistMode ? (string) $preview['criteria']['stage'] : (string) $preview['criteria']['consentVersion'],
            (int) $preview['criteria']['batchSize'],
            true,
            (string) $request->request->get('idempotency_key'),
            $user instanceof User ? $user : null,
        );
        $request->getSession()->remove('meta_consent_sync_preview');
        $this->addFlash('notice', 'Synchronization queued as run #'.$run->getId().'.');

        return $this->redirectToRoute('mautic_meta_identities');
    }

    public function cancelSync(int $runId, Request $request, CorePermissions $permissions, MetaConsentSyncRunRepository $runs, WhatsAppConsentSyncService $sync): RedirectResponse
    {
        $this->assertSyncAccess($request, $permissions, 'meta_consent_sync_cancel_'.$runId);
        $run = $runs->find($runId);
        if (!$run instanceof MetaConsentSyncRun) {
            throw $this->createNotFoundException();
        }
        $sync->cancel($run);
        $this->addFlash('notice', 'Synchronization cancelled safely at its last checkpoint.');

        return $this->redirectToRoute('mautic_meta_identities');
    }

    public function rejections(int $runId, CorePermissions $permissions, MetaConsentSyncRunRepository $runs): Response
    {
        if (!$permissions->isGranted('meta:messages:view')) {
            throw $this->createAccessDeniedException();
        }
        $run = $runs->find($runId);
        if (!$run instanceof MetaConsentSyncRun) {
            throw $this->createNotFoundException();
        }

        return $this->json(['runId' => $runId, 'rejections' => $run->getRejections()], 200, ['Content-Disposition' => 'attachment; filename="meta-consent-sync-'.$runId.'-rejections.json"']);
    }

    public function update(int $identityId, Request $request, CorePermissions $permissions, MetaContactIdentityRepository $identities, IdentityManager $manager, LeadModel $leads): RedirectResponse
    {
        if (!$permissions->isGranted('meta:messages:edit') || !$this->isCsrfTokenValid('meta_identity_'.$identityId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $identity = $identities->find($identityId);
        if (!$identity instanceof MetaContactIdentity) { throw $this->createNotFoundException(); }

        $contactId = max(0, (int) $request->request->get('contact_id', 0));
        $contact = 0 === $contactId ? null : $leads->getEntity($contactId);
        if (0 !== $contactId && !$contact instanceof Lead) {
            $this->addFlash('error', 'The selected Mautic contact does not exist.');

            return $this->redirectToRoute('mautic_meta_identities');
        }
        $manager->associate($identity, $contact);
        $status = ConsentStatus::tryFrom((string) $request->request->get('consent_status', 'unknown')) ?? ConsentStatus::Unknown;
        $manager->changeConsent($identity, $status, 'mautic_user');
        $this->addFlash('notice', 'Meta identity updated.');

        return $this->redirectToRoute('mautic_meta_identities');
    }

    public function remove(int $identityId, Request $request, CorePermissions $permissions, MetaContactIdentityRepository $identities, IdentityManager $manager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:messages:delete') || !$this->isCsrfTokenValid('meta_identity_remove_'.$identityId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $identity = $identities->find($identityId);
        if (!$identity instanceof MetaContactIdentity) { throw $this->createNotFoundException(); }
        $manager->archive([$identity]);
        $this->addFlash('notice', 'Meta identity removed. Consent audit was preserved.');

        return $this->redirectToRoute('mautic_meta_identities');
    }

    public function removeBatch(Request $request, CorePermissions $permissions, MetaContactIdentityRepository $identities, IdentityManager $manager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:messages:delete') || !$this->isCsrfTokenValid('meta_identity_remove_batch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->request->all('ids')), static fn (int $id): bool => $id > 0)));
        $entities = array_values(array_filter($identities->findBy(['id' => $ids]), static fn ($identity): bool => $identity instanceof MetaContactIdentity && null === $identity->getArchivedAt()));
        if ([] === $entities) {
            $this->addFlash('error', 'Select at least one Meta identity.');
        } else {
            $manager->archive($entities);
            $this->addFlash('notice', count($entities).' Meta identities removed. Consent audits were preserved.');
        }

        return $this->redirectToRoute('mautic_meta_identities');
    }

    private function assertSyncAccess(Request $request, CorePermissions $permissions, string $csrfId): void
    {
        if (!$permissions->isGranted('meta:messages:edit') || !$this->isCsrfTokenValid($csrfId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
