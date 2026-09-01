<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Domain\ConsentStatus;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class IdentityController extends AbstractController
{
    public function index(CorePermissions $permissions, MetaContactIdentityRepository $identities): Response
    {
        if (!$permissions->isGranted('meta:messages:view')) { throw $this->createAccessDeniedException(); }

        return $this->render('@MauticMeta/Identity/index.html.twig', ['identities' => $identities->findBy([], ['lastInteractionAt' => 'DESC'], 250)]);
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
}
