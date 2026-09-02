<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Consent;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\DoNotContact as Dnc;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Domain\ConsentStatus;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentityRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaWhatsAppConsent;
use MauticPlugin\MauticMetaBundle\Entity\MetaWhatsAppConsentRepository;

final class WhatsAppConsentRegistrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private LeadModel $leadModel,
        private MetaAssetRepository $assets,
        private MetaContactIdentityRepository $identities,
        private MetaWhatsAppConsentRepository $consents,
        private PhoneNormalizer $phones,
        private DoNotContact $dnc,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function register(array $input, bool $dryRun = false): array
    {
        try {
            $evidence = $this->validate($input);
        } catch (\InvalidArgumentException $exception) {
            return $this->result('rejected', $exception->getMessage(), dryRun: $dryRun);
        }

        $lockName = 'meta_consent_'.substr(hash('sha256', $evidence['assetId'].':'.$evidence['externalSubmissionId']), 0, 40);
        if (1 !== (int) $this->connection->fetchOne('SELECT GET_LOCK(:lockName, 5)', ['lockName' => $lockName])) {
            return $this->result('conflict', 'Consent registration is already being processed.');
        }

        try {
            return $this->entityManager->wrapInTransaction(
                fn (): array => $this->process($evidence, $dryRun),
            );
        } finally {
            $this->connection->executeQuery('SELECT RELEASE_LOCK(:lockName)', ['lockName' => $lockName]);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        if (true !== ($input['consent'] ?? null)) {
            throw new \InvalidArgumentException('consent must be the boolean true.');
        }

        $required = [
            'assetId',
            'phone',
            'consentAt',
            'business',
            'purpose',
            'source',
            'consentText',
            'consentVersion',
            'externalSubmissionId',
        ];
        foreach ($required as $field) {
            if ('' === trim((string) ($input[$field] ?? ''))) {
                throw new \InvalidArgumentException(sprintf('%s is required.', $field));
            }
        }

        $asset = $this->assets->find((int) $input['assetId']);
        if (!$asset instanceof MetaAsset || AssetType::WhatsAppPhoneNumber !== $asset->getType()) {
            throw new \InvalidArgumentException('assetId must reference a WhatsApp phone-number asset.');
        }

        try {
            $consentAt = new \DateTimeImmutable((string) $input['consentAt']);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('consentAt must be a valid timestamp.', previous: $exception);
        }

        $region = (string) ($asset->getSettings()['default_region'] ?? 'BR');
        $digits = $this->phones->normalize((string) $input['phone'], $region);

        return [
            'asset'                => $asset,
            'assetId'              => (int) $asset->getId(),
            'contactId'            => isset($input['contactId']) ? (int) $input['contactId'] : null,
            'email'                => strtolower(trim((string) ($input['email'] ?? ''))),
            'phone'                => '+'.$digits,
            'phoneDigits'          => $digits,
            'consentAt'            => $consentAt,
            'business'             => trim((string) $input['business']),
            'locale'               => $this->nullable($input['locale'] ?? null),
            'purpose'              => trim((string) $input['purpose']),
            'source'               => trim((string) $input['source']),
            'consentText'          => trim((string) $input['consentText']),
            'consentVersion'       => trim((string) $input['consentVersion']),
            'externalSubmissionId' => trim((string) $input['externalSubmissionId']),
            'pageUrl'              => $this->nullable($input['pageUrl'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     *
     * @return array<string, mixed>
     */
    private function process(array $evidence, bool $dryRun): array
    {
        /** @var MetaAsset $asset */
        $asset = $evidence['asset'];
        $hash = $this->evidenceHash($evidence);
        $existingConsent = $this->consents->findSubmission($asset, $evidence['externalSubmissionId']);
        if ($existingConsent instanceof MetaWhatsAppConsent) {
            if (hash_equals($existingConsent->getEvidenceHash(), $hash)) {
                return $this->result('already_registered', null, $existingConsent->getContact(), $existingConsent->getIdentity(), $existingConsent);
            }

            return $this->result('conflict', 'externalSubmissionId already exists with different evidence.');
        }

        $contactResult = $this->resolveContact($evidence, $dryRun);
        if (isset($contactResult['error'])) {
            return $this->result('conflict', (string) $contactResult['error']);
        }

        /** @var Lead $contact */
        $contact = $contactResult['contact'];
        $identity = $this->identities->findForAssetAndExternalId($asset, $evidence['phoneDigits']);
        if ($identity instanceof MetaContactIdentity && $identity->getContact()?->getId() !== $contact->getId()) {
            return $this->result('conflict', 'The normalized phone is already linked to a different Mautic contact.');
        }

        $identityCreated = !$identity instanceof MetaContactIdentity;
        $identity ??= (new MetaContactIdentity())
            ->setAsset($asset)
            ->setExternalId($evidence['phoneDigits']);

        $identity
            ->setContact($contact)
            ->setPhoneNumber($evidence['phone'])
            ->setConsentSource($evidence['source']);

        $optOutAt = $identity->getOptedOutAt();
        $superseded = $optOutAt instanceof \DateTimeInterface && $optOutAt >= $evidence['consentAt'];
        if ($dryRun) {
            return $this->result(
                $identityCreated || $contactResult['created'] ? 'created' : ($superseded ? 'conflict' : 'updated'),
                $superseded ? 'A later WhatsApp opt-out remains in force.' : null,
                $contact,
                $identity,
                null,
                true,
            );
        }

        if (!$superseded) {
            $identity
                ->setConsentStatus(ConsentStatus::OptedIn)
                ->setConsentedAt($evidence['consentAt']);
            $this->dnc->removeDncForContact($contact, 'whatsapp', false, Dnc::UNSUBSCRIBED);
        }
        $this->entityManager->persist($identity);

        $consent = (new MetaWhatsAppConsent())
            ->setAsset($asset)
            ->setIdentity($identity)
            ->setContact($contact)
            ->setExternalSubmissionId($evidence['externalSubmissionId'])
            ->setPhoneNumber($evidence['phone'])
            ->setConsentAt($evidence['consentAt'])
            ->setEvidence($evidence)
            ->setEvidenceHash($hash)
            ->setStatus($superseded ? 'superseded_by_opt_out' : 'accepted');
        $this->entityManager->persist($consent);
        $this->entityManager->flush();

        $status = $superseded
            ? 'conflict'
            : ($identityCreated || $contactResult['created'] ? 'created' : 'updated');

        return $this->result(
            $status,
            $superseded ? 'A later WhatsApp opt-out remains in force.' : null,
            $contact,
            $identity,
            $consent,
        );
    }

    /**
     * @param array<string, mixed> $evidence
     *
     * @return array{contact?: Lead, created?: bool, error?: string}
     */
    private function resolveContact(array $evidence, bool $dryRun): array
    {
        $ids = [];
        if (is_int($evidence['contactId']) && $evidence['contactId'] > 0) {
            $lead = $this->leadModel->getEntity($evidence['contactId']);
            if (!$lead instanceof Lead) {
                return ['error' => 'contactId does not reference an existing Mautic contact.'];
            }
            $ids['contactId'] = (int) $lead->getId();
        }
        if ('' !== $evidence['email']) {
            $emailId = $this->connection->fetchOne(
                'SELECT id FROM leads WHERE LOWER(email) = :email LIMIT 1',
                ['email' => $evidence['email']],
            );
            if (false !== $emailId) {
                $ids['email'] = (int) $emailId;
            }
        }

        $phoneIds = $this->connection->fetchFirstColumn(
            "SELECT id FROM leads WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') = :phone OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(mobile, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') = :phone LIMIT 2",
            ['phone' => $evidence['phoneDigits']],
        );
        if (count($phoneIds) > 1) {
            return ['error' => 'The normalized phone matches multiple Mautic contacts.'];
        }
        if (1 === count($phoneIds)) {
            $ids['phone'] = (int) $phoneIds[0];
        }

        if (count(array_unique($ids)) > 1) {
            return ['error' => 'contactId, email, and phone resolve to different Mautic contacts.'];
        }

        $contactId = reset($ids);
        if (false !== $contactId) {
            return ['contact' => $this->leadModel->getEntity((int) $contactId), 'created' => false];
        }
        if ('' === $evidence['email']) {
            return ['error' => 'No contact matched; email is required to create a Mautic contact.'];
        }

        $contact = $this->leadModel->getEntity();
        $this->leadModel->setFieldValues($contact, [
            'email' => $evidence['email'],
            'phone' => $evidence['phone'],
        ], true);
        if (!$dryRun) {
            $this->leadModel->saveEntity($contact);
        }

        return ['contact' => $contact, 'created' => true];
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function evidenceHash(array $evidence): string
    {
        $copy = $evidence;
        unset($copy['asset'], $copy['phoneDigits']);
        $copy['consentAt'] = $evidence['consentAt']->format(DATE_ATOM);
        ksort($copy);

        return hash('sha256', json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        string $status,
        ?string $reason = null,
        ?Lead $contact = null,
        ?MetaContactIdentity $identity = null,
        ?MetaWhatsAppConsent $consent = null,
        bool $dryRun = false,
    ): array {
        return [
            'status'      => $status,
            'reason'      => $reason,
            'error'       => $reason,
            'dryRun'      => $dryRun,
            'contactId'   => $contact?->getId(),
            'identityId'  => $identity?->getId(),
            'consentId'   => $consent?->getId(),
            'phone'       => $identity?->getPhoneNumber(),
        ];
    }
}
