<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\EventListener;

use Mautic\ApiBundle\Helper\RequestHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\Event\ListChangeEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticMetaBundle\Application\Consent\TrustedApiWaitlistConsentService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class TrustedApiWaitlistSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RequestStack $requests,
        private Security $security,
        private TrustedApiWaitlistConsentService $consents,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_POST_SAVE => ['onLeadPostSave', -50],
            LeadEvents::LEAD_LIST_CHANGE => ['onListChange', -50],
        ];
    }

    public function onLeadPostSave(LeadEvent $event): void
    {
        $contact = $event->getLead();
        $request = $this->requests->getCurrentRequest();
        if ($event->isNew() && $request instanceof Request && $this->isAuthenticatedContactCreateApiRequest($request)) {
            $this->consents->markApiImport($contact, $this->externalSubmissionId($request));
        }

        if ($this->consents->wasApiImported($contact) && $this->consents->isWaitlist($contact)) {
            $this->register($contact);
        }
    }

    public function onListChange(ListChangeEvent $event): void
    {
        if (!$event->wasAdded() || 0 !== strcasecmp($event->getList()->getName(), 'Waitlist')) {
            return;
        }
        $contacts = $event->getLead() instanceof Lead ? [$event->getLead()] : ($event->getLeads() ?? []);
        foreach ($contacts as $contact) {
            if ($contact instanceof Lead && $this->consents->wasApiImported($contact)) {
                $this->register($contact);
            }
        }
    }

    private function register(Lead $contact): void
    {
        $asset = $this->consents->defaultAsset();
        if (null === $asset) {
            $this->logger->warning('Trusted Waitlist consent was not registered because no active default WhatsApp asset is configured.', ['contactId' => $contact->getId()]);
            return;
        }
        $import = $this->consents->apiImport($contact);
        if (null === $import) {
            return;
        }
        $result = $this->consents->register(
            $contact,
            $asset,
            'trusted_mautic_api_import',
            $import->getReceivedAt(),
            externalSubmissionId: $import->getExternalSubmissionId(),
        );
        if (in_array($result['status'], ['rejected', 'conflict'], true)) {
            $this->logger->warning('Trusted Waitlist consent registration was safely skipped.', ['contactId' => $contact->getId(), 'status' => $result['status'], 'reason' => $result['reason']]);
        }
    }

    private function isAuthenticatedContactCreateApiRequest(Request $request): bool
    {
        if ('POST' !== $request->getMethod() || !RequestHelper::isApiRequest($request)) {
            return false;
        }
        $route = (string) $request->attributes->get('_route');
        $contactCreateRoute = 'mautic_api_contacts_new' === $route
            || '_api_/contacts_post' === $route
            || 'mautic_api_contacts_newbatch' === $route;

        return $contactCreateRoute && null !== $this->security->getUser();
    }

    private function externalSubmissionId(Request $request): ?string
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            $payload = $request->request->all();
        }
        foreach (['external_submission_id', 'externalSubmissionId', 'submission_id'] as $field) {
            if ('' !== trim((string) ($payload[$field] ?? ''))) {
                return trim((string) $payload[$field]);
            }
        }

        return null;
    }
}
