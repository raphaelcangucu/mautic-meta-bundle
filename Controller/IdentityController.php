<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Domain\ConsentStatus;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentityRepository;
use Mautic\CoreBundle\Controller\CommonController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class IdentityController extends CommonController
{
    use MetaViewTrait;

    public function index(CorePermissions $permissions, MetaContactIdentityRepository $identities, MetaAssetRepository $assets, Request $request, int $page = 1): Response
    {
        if (!$permissions->isGranted('meta:messages:view')) { throw $this->createAccessDeniedException(); }

        $page = max(1, $request->query->getInt('page', $page));
        $limit = in_array($request->query->getInt('limit'), [25, 50, 100], true) ? $request->query->getInt('limit') : 25;
        $search = trim((string) $request->query->get('search', ''));
        $assetId = max(0, (int) $request->query->get('asset', 0)) ?: null;
        $channel = in_array($request->query->get('channel'), ['whatsapp', 'instagram', 'facebook'], true) ? (string) $request->query->get('channel') : null;
        $consent = ConsentStatus::tryFrom((string) $request->query->get('consent', ''))?->value;
        $identityPage = $identities->findPage($search, $assetId, $channel, $consent, ($page - 1) * $limit, $limit, $request->query->getString('linked'));
        $lastPage = max(1, (int) ceil($identityPage['total'] / $limit));
        if ($page > $lastPage) {
            return $this->redirectToRoute('mautic_meta_identities', ['page' => $lastPage, 'search' => $search, 'asset' => $assetId, 'consent' => $consent, 'channel' => $channel, 'limit' => $limit, 'linked' => $request->query->getString('linked')]);
        }

        return $this->metaView('@MauticMeta/Identity/index.html.twig', [
            'identities' => $identityPage['items'],
            'listing' => ['total' => $identityPage['total'], 'page' => $page, 'pages' => $lastPage, 'limit' => $limit, 'pageKey' => 'page'],
            'identityTotal' => $identityPage['total'], 'identityPage' => $page, 'identityLimit' => $limit,
            'identityFilters' => ['search' => $search, 'asset' => $assetId, 'channel' => $channel, 'consent' => $consent],
            'allAssets' => $assets->findAll(),
        ]);
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
            $this->addFlash('error', $this->translator->trans('mautic.meta.ui.the_selected_mautic_contact_does_not_exist'));

            return $this->redirectToRoute('mautic_meta_identities');
        }
        $manager->associate($identity, $contact);
        $status = ConsentStatus::tryFrom((string) $request->request->get('consent_status', 'unknown')) ?? ConsentStatus::Unknown;
        $manager->changeConsent($identity, $status, 'mautic_user');
        $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.meta_identity_updated'));

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
        $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.meta_identity_removed_consent_audit_was_preserved'));

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
            $this->addFlash('error', $this->translator->trans('mautic.meta.ui.select_at_least_one_meta_identity'));
        } else {
            $manager->archive($entities);
            $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.identities_removed', ['%count%' => count($entities)]));
        }

        return $this->redirectToRoute('mautic_meta_identities');
    }
}
