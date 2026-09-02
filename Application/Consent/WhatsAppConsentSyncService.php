<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Consent;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConsentSyncRun;
use MauticPlugin\MauticMetaBundle\Entity\MetaConsentSyncRunRepository;
use Symfony\Bundle\SecurityBundle\Security;

final class WhatsAppConsentSyncService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private MetaAssetRepository $assets,
        private MetaConsentSyncRunRepository $runs,
        private LandingConsentSourceClient $sourceClient,
        private WhatsAppConsentRegistrationService $registration,
        private TrustedApiWaitlistConsentService $trustedWaitlist,
        private Security $security,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(int $assetId, string $source, string $version, int $batchSize = 100, bool $onlyUnsynced = true): array
    {
        if ('mautic_api_waitlist' === $source) {
            return $this->previewMauticWaitlist($assetId, '' === $version ? 'Waitlist' : $version, $batchSize, $onlyUnsynced);
        }
        $asset = $this->asset($assetId);
        $checkpoint = 0;
        $counts = $this->emptyCounts();
        $rejections = [];

        do {
            $page = $this->sourceClient->fetch($asset, $source, $version, $checkpoint, $batchSize);
            foreach ($page['items'] as $item) {
                ++$counts['analyzed'];
                $result = $this->businessMatches($asset, (string) ($item['business'] ?? ''))
                    ? $this->registration->register($item + ['assetId' => $assetId], true)
                    : ['status' => 'conflict', 'error' => 'Consent business does not match the selected WhatsApp asset.'];
                $this->countResult($counts, $rejections, $item, $result, $onlyUnsynced);
            }
            $checkpoint = $page['nextCheckpoint'];
        } while ($page['hasMore']);

        return [
            'status' => 'ready_to_confirm',
            'asset' => ['id' => $assetId, 'name' => $asset->getName(), 'phone' => $asset->getPhoneNumber()],
            'criteria' => ['source' => $source, 'consentVersion' => $version, 'batchSize' => $batchSize, 'onlyUnsynced' => $onlyUnsynced],
            'counts' => $counts,
            'rejections' => $rejections,
            'readOnly' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function previewMauticWaitlist(int $assetId, string $stage = 'Waitlist', int $batchSize = 100, bool $onlyUnsynced = true): array
    {
        $asset = $this->asset($assetId);
        $checkpoint = 0;
        $counts = $this->emptyCounts();
        $items = [];
        $rejections = [];
        do {
            $page = $this->trustedWaitlist->findWaitlistPage($stage, $checkpoint, $batchSize);
            foreach ($page['items'] as $candidate) {
                ++$counts['analyzed'];
                ++$counts['waitlist_analyzed'];
                $result = $this->trustedWaitlist->register($candidate['contact'], $asset, 'admin_attested_trusted_api_import', new \DateTimeImmutable(), dryRun: true);
                $items[] = $result + ['apiOriginPreserved' => $candidate['apiImported']];
                $this->countWaitlistResult($counts, $rejections, $result);
            }
            $checkpoint = $page['nextCheckpoint'];
        } while ($page['hasMore']);

        return [
            'status' => 'ready_to_confirm',
            'sourceMode' => 'mautic_api_waitlist',
            'asset' => ['id' => $assetId, 'name' => $asset->getName(), 'phone' => $asset->getPhoneNumber()],
            'criteria' => ['sourceMode' => 'mautic_api_waitlist', 'stage' => $stage, 'batchSize' => $batchSize, 'onlyUnsynced' => $onlyUnsynced],
            'counts' => $counts,
            'items' => $items,
            'rejections' => $rejections,
            'readOnly' => true,
        ];
    }

    public function start(int $assetId, string $source, string $version, int $batchSize, bool $onlyUnsynced, string $idempotencyKey, ?User $operator = null): MetaConsentSyncRun
    {
        $existing = $this->runs->findOneBy(['idempotencyKey' => $idempotencyKey]);
        if ($existing instanceof MetaConsentSyncRun) {
            return $existing;
        }

        $authenticatedUser = $this->security->getUser();
        if (!$operator instanceof User && $authenticatedUser instanceof User) {
            $operator = $authenticatedUser;
        }
        $run = (new MetaConsentSyncRun())
            ->setAsset($this->asset($assetId))
            ->setOperator($operator)
            ->setCriteria('mautic_api_waitlist' === $source
                ? ['sourceMode' => $source, 'stage' => '' === $version ? 'Waitlist' : $version, 'onlyUnsynced' => $onlyUnsynced]
                : ['sourceMode' => 'explicit_consent_fields', 'source' => $source, 'consentVersion' => $version, 'onlyUnsynced' => $onlyUnsynced])
            ->setBatchSize($batchSize)
            ->setCounts($this->emptyCounts())
            ->setIdempotencyKey($idempotencyKey)
            ->setStatus('syncing');
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function processNext(): ?array
    {
        $run = $this->runs->nextQueued();
        if (!$run instanceof MetaConsentSyncRun) {
            return null;
        }
        $lock = 'meta_consent_sync_'.$run->getId();
        if (1 !== (int) $this->connection->fetchOne('SELECT GET_LOCK(:lockName, 0)', ['lockName' => $lock])) {
            return ['status' => 'locked', 'runId' => $run->getId()];
        }

        try {
            if ('failed' === $run->getStatus()) {
                $run->setStatus('syncing');
                $this->entityManager->flush();
            }
            $criteria = $run->getCriteria();
            if ('mautic_api_waitlist' === ($criteria['sourceMode'] ?? null)) {
                return $this->processWaitlistPage($run, $criteria);
            }
            $page = $this->sourceClient->fetch($run->getAsset(), (string) $criteria['source'], (string) $criteria['consentVersion'], $run->getCheckpoint(), $run->getBatchSize());
            $counts = $run->getCounts() + $this->emptyCounts();
            $rejections = $run->getRejections();
            foreach ($page['items'] as $item) {
                if ('cancelled' === $run->getStatus()) {
                    break;
                }
                ++$counts['analyzed'];
                $result = $this->businessMatches($run->getAsset(), (string) ($item['business'] ?? ''))
                    ? $this->registration->register($item + ['assetId' => $run->getAsset()->getId()])
                    : ['status' => 'conflict', 'error' => 'Consent business does not match the selected WhatsApp asset.'];
                $this->countResult($counts, $rejections, $item, $result, (bool) ($criteria['onlyUnsynced'] ?? true));
            }
            $run->setCheckpoint($page['nextCheckpoint'])->setCounts($counts)->setRejections($rejections);
            if (!$page['hasMore']) {
                $run->setStatus($counts['rejected'] + $counts['conflicts'] > 0 ? 'completed_with_rejections' : 'completed')
                    ->setCompletedAt(new \DateTimeImmutable());
            }
            $this->entityManager->persist($run);
            $this->entityManager->flush();

            return $this->serialize($run);
        } catch (\Throwable $exception) {
            $rejections = $run->getRejections();
            $rejections[] = ['reason' => 'Temporary source or processing failure; the checkpoint was preserved.', 'retryable' => true];
            if ($this->entityManager->isOpen()) {
                $run->setStatus('failed')->setRejections($rejections);
                $this->entityManager->flush();
            } else {
                $this->connection->update('meta_consent_sync_runs', [
                    'status' => 'failed',
                    'rejections' => json_encode($rejections, JSON_THROW_ON_ERROR),
                    'date_modified' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ], ['id' => $run->getId()]);
            }
            throw $exception;
        } finally {
            $this->connection->executeQuery('SELECT RELEASE_LOCK(:lockName)', ['lockName' => $lock]);
        }
    }

    public function cancel(MetaConsentSyncRun $run): void
    {
        if (in_array($run->getStatus(), ['waiting', 'ready_to_confirm', 'syncing', 'failed'], true)) {
            $run->setStatus('cancelled')->setCompletedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(MetaConsentSyncRun $run): array
    {
        return [
            'id' => $run->getId(),
            'status' => $run->getStatus(),
            'assetId' => $run->getAsset()->getId(),
            'assetName' => $run->getAsset()->getName(),
            'criteria' => $run->getCriteria(),
            'counts' => $run->getCounts(),
            'checkpoint' => $run->getCheckpoint(),
            'operator' => $run->getOperator()?->getName(),
            'dateAdded' => $run->getDateAdded()->format(DATE_ATOM),
            'dateModified' => $run->getDateModified()->format(DATE_ATOM),
            'completedAt' => $run->getCompletedAt()?->format(DATE_ATOM),
        ];
    }

    private function asset(int $id): MetaAsset
    {
        $asset = $this->assets->find($id);
        if (!$asset instanceof MetaAsset || AssetType::WhatsAppPhoneNumber !== $asset->getType()) {
            throw new \InvalidArgumentException('assetId must reference a WhatsApp phone-number asset.');
        }

        return $asset;
    }

    /**
     * @return array<string, int>
     */
    private function emptyCounts(): array
    {
        return ['analyzed' => 0, 'waitlist_analyzed' => 0, 'valid_phones' => 0, 'eligible' => 0, 'identities_to_create' => 0, 'identities_existing' => 0, 'created' => 0, 'updated' => 0, 'already_synced' => 0, 'missing_phone' => 0, 'invalid_phone' => 0, 'missing_or_invalid_phone' => 0, 'incomplete_consent' => 0, 'opted_out' => 0, 'conflicts' => 0, 'duplicates' => 0, 'rejected' => 0];
    }

    private function countResult(array &$counts, array &$rejections, array $item, array $result, bool $onlyUnsynced): void
    {
        $status = (string) ($result['status'] ?? 'rejected');
        if ('already_registered' === $status) {
            ++$counts['already_synced'];
            return;
        }
        if (in_array($status, ['created', 'updated'], true)) {
            ++$counts['eligible'];
            ++$counts[$status];
            return;
        }

        $message = (string) ($result['error'] ?? 'Consent evidence was rejected.');
        $key = str_contains(strtolower($message), 'phone') ? 'missing_or_invalid_phone' : (str_contains(strtolower($message), 'opt-out') ? 'opted_out' : ('conflict' === $status ? 'conflicts' : 'incomplete_consent'));
        ++$counts[$key];
        ++$counts['rejected'];
        $rejections[] = ['externalSubmissionId' => $item['externalSubmissionId'] ?? null, 'email' => $item['email'] ?? null, 'reason' => $message, 'status' => $status];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    private function processWaitlistPage(MetaConsentSyncRun $run, array $criteria): array
    {
        $page = $this->trustedWaitlist->findWaitlistPage((string) ($criteria['stage'] ?? 'Waitlist'), $run->getCheckpoint(), $run->getBatchSize());
        $counts = $run->getCounts() + $this->emptyCounts();
        $rejections = $run->getRejections();
        foreach ($page['items'] as $candidate) {
            if ('cancelled' === $run->getStatus()) {
                break;
            }
            ++$counts['analyzed'];
            ++$counts['waitlist_analyzed'];
            $result = $this->trustedWaitlist->register(
                $candidate['contact'],
                $run->getAsset(),
                'admin_attested_trusted_api_import',
                new \DateTimeImmutable(),
                $run->getOperator(),
                $run->getId(),
            );
            $this->countWaitlistResult($counts, $rejections, $result);
        }
        $run->setCheckpoint($page['nextCheckpoint'])->setCounts($counts)->setRejections($rejections);
        if (!$page['hasMore']) {
            $run->setStatus($counts['rejected'] + $counts['conflicts'] + $counts['opted_out'] > 0 ? 'completed_with_rejections' : 'completed')
                ->setCompletedAt(new \DateTimeImmutable());
        }
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $this->serialize($run);
    }

    private function countWaitlistResult(array &$counts, array &$rejections, array $result): void
    {
        $status = (string) $result['status'];
        if ('already_registered' === $status) {
            ++$counts['already_synced'];
            ++$counts['valid_phones'];
            ++$counts['identities_existing'];
            return;
        }
        if (in_array($status, ['created', 'updated'], true)) {
            ++$counts['eligible'];
            ++$counts['valid_phones'];
            ++$counts['identities_'.('created' === $status ? 'to_create' : 'existing')];
            ++$counts[$status];
            return;
        }
        if (in_array($status, ['conflict', 'opted_out'], true) && null !== ($result['phone'] ?? null)) {
            ++$counts['valid_phones'];
        }
        $counter = match ($status) {
            'opted_out' => 'opted_out',
            'conflict' => 'conflicts',
            default => str_contains(strtolower((string) $result['reason']), 'no phone') ? 'missing_phone' : 'invalid_phone',
        };
        ++$counts[$counter];
        ++$counts['rejected'];
        $rejections[] = $result;
    }

    private function businessMatches(MetaAsset $asset, string $business): bool
    {
        $normalize = static fn (string $value): string => preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
        $expected = $normalize($business);
        if ('' === $expected) {
            return false;
        }

        return str_contains($normalize($asset->getName()), $expected)
            || str_contains($normalize($asset->getConnection()->getName()), $expected);
    }
}
