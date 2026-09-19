<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJobRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphApiException;
use MauticPlugin\MauticMetaBundle\Application\Exception\ChannelTemporarilyUnavailable;
use MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface;

final class OutboundQueue
{
    /**
     * Teto do Graph. Continua valendo para os tres canais oficiais: uma falha de
     * plataforma que passe de uma hora deixou de ser um soluco e vira caso de suporte.
     */
    private const GRAPH_BACKOFF_CAP_SECONDS = 3600;

    /**
     * Teto de um canal que caiu e volta sozinho. Uma resposta parada expira em duas
     * horas e vira "nao saiu" com o motivo escrito, entao esperar mais que a janela em
     * que ela ainda pode sair nao serve para nada; duas horas ainda deixam o job ser
     * tentado umas oito vezes dentro dela.
     */
    private const TEMPORARY_CHANNEL_BACKOFF_CAP_SECONDS = 7200;

    public function __construct(
        private MetaOutboundJobRepository $jobs,
        private EntityManagerInterface $entityManager,
        private OutboundOperationExecutor $executor,
        private Connection $connection,
        private InboxIntegrationInterface $inboxIntegration,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(MetaAsset $asset, string $operation, array $payload, ?Lead $contact = null, int $maxAttempts = 5, ?string $idempotencyKey = null): MetaOutboundJob
    {
        if (!in_array($operation, ['whatsapp_text', 'whatsapp_template', 'whatsapp_media', 'whatsapp_interactive', 'instagram_private_reply', 'instagram_public_reply', 'instagram_direct_message', 'facebook_public_reply', 'facebook_direct_message'], true)) {
            throw new \InvalidArgumentException('Unsupported Meta queue operation.');
        }
        if (null !== $idempotencyKey && $this->jobs->findOneBy(['idempotencyKey' => $idempotencyKey]) instanceof MetaOutboundJob) {
            return $this->jobs->findOneBy(['idempotencyKey' => $idempotencyKey]);
        }
        $job = (new MetaOutboundJob())->setAsset($asset)->setContact($contact)->setOperation($operation)->setPayload($payload)->setMaxAttempts($maxAttempts)->setIdempotencyKey($idempotencyKey);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    /**
     * @param list<string>         $messages
     * @param array<string, mixed> $payload
     */
    public function enqueueRotating(MetaAsset $asset, string $operation, array $messages, array $payload, ?Lead $contact = null, int $maxAttempts = 5, ?string $idempotencyKey = null): MetaOutboundJob
    {
        $messages = array_values(array_filter(array_map(static fn (string $message): string => trim($message), $messages), static fn (string $message): bool => '' !== $message));
        if ([] === $messages) {
            throw new \InvalidArgumentException('At least one rotating message is required.');
        }

        $assetId = (int) $asset->getId();
        $lockName = 'mautic_meta_rotation_'.substr(hash('sha256', $assetId.':'.$operation), 0, 32);
        if (1 !== (int) $this->connection->fetchOne('SELECT GET_LOCK(:name, 5)', ['name' => $lockName])) {
            throw new \RuntimeException('Could not reserve the next outbound message variation.');
        }

        try {
            if (null !== $idempotencyKey && $this->jobs->findOneBy(['idempotencyKey' => $idempotencyKey]) instanceof MetaOutboundJob) {
                return $this->jobs->findOneBy(['idempotencyKey' => $idempotencyKey]);
            }

            $table = (defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '').'meta_outbound_jobs';
            $position = (int) $this->connection->fetchOne(
                'SELECT COUNT(id) FROM '.$table.' WHERE asset_id = :asset AND operation = :operation',
                ['asset' => $assetId, 'operation' => $operation],
            );
            $payload['text'] = $messages[$position % count($messages)];

            return $this->enqueue($asset, $operation, $payload, $contact, $maxAttempts, $idempotencyKey);
        } finally {
            $this->connection->executeQuery('SELECT RELEASE_LOCK(:name)', ['name' => $lockName]);
        }
    }

    /**
     * @return array{processed:int,succeeded:int,retried:int,failed:int,recovered:int}
     */
    public function work(int $limit = 100): array
    {
        if (1 !== (int) $this->connection->fetchOne("SELECT GET_LOCK('mautic_meta_outbound_queue', 0)")) {
            return ['processed' => 0, 'succeeded' => 0, 'retried' => 0, 'failed' => 0, 'recovered' => 0];
        }
        try {
            return $this->processDue($limit);
        } finally {
            $this->connection->executeQuery("SELECT RELEASE_LOCK('mautic_meta_outbound_queue')");
        }
    }

    /**
     * Executes an already-persisted job in the current request.
     *
     * Atomic claiming prevents the minute worker and an immediate dispatcher from
     * sending the same job. A false result means another process already claimed it
     * or the job had reached a terminal state.
     */
    public function dispatchNow(MetaOutboundJob $job): bool
    {
        return null !== $this->processJob($job, new \DateTimeImmutable());
    }

    /**
     * @return array{processed:int,succeeded:int,retried:int,failed:int,recovered:int}
     */
    private function processDue(int $limit): array
    {
        $now = new \DateTimeImmutable();
        $recovered = $this->recoverStalled($now->modify('-15 minutes'));
        $processed = $succeeded = $retried = $failed = 0;
        foreach ($this->jobs->findDue($limit, $now) as $job) {
            $outcome = $this->processJob($job, $now);
            if (null === $outcome) {
                continue;
            }

            ++$processed;
            if ('succeeded' === $outcome) {
                ++$succeeded;
            } elseif ('retried' === $outcome) {
                ++$retried;
            } else {
                ++$failed;
            }
        }

        return compact('processed', 'succeeded', 'retried', 'failed', 'recovered');
    }

    /** @return 'succeeded'|'retried'|'failed'|null */
    private function processJob(MetaOutboundJob $job, \DateTimeImmutable $now): ?string
    {
        if (!$this->jobs->claim($job, $now)) {
            return null;
        }

        $this->notifyInbox($job);
        $outcome = 'succeeded';
        try {
            $result = $this->executor->execute($job);
            $messageLogId = $result instanceof MetaMessage ? (int) $result->getId() : $result->logId;
            $inboxConversationId = (int) ($job->getPayload()['_inbox_conversation_id'] ?? 0);
            if (0 === $inboxConversationId && 'instagram_private_reply' === $job->getOperation()) {
                $commentConversation = $this->entityManager->getRepository(MetaConversation::class)->findOneBy([
                    'asset'     => $job->getAsset(),
                    'channel'   => 'instagram',
                    'recipient' => 'comment:'.(string) ($job->getPayload()['recipient'] ?? ''),
                ]);
                $inboxConversationId = $commentConversation instanceof MetaConversation ? (int) $commentConversation->getId() : 0;
            }
            if ($inboxConversationId > 0) {
                $inboxConversation = $this->entityManager->find(MetaConversation::class, $inboxConversationId);
                $messageLog = $result instanceof MetaMessage ? $result : $this->entityManager->find(MetaMessage::class, $messageLogId);
                if ($inboxConversation instanceof MetaConversation && $messageLog instanceof MetaMessage && $messageLog->getAsset()->getId() === $inboxConversation->getAsset()->getId()) {
                    $messageLog->setConversation($inboxConversation);
                    $inboxConversation->setLastMessageAt(new \DateTimeImmutable());
                    $this->entityManager->persist($messageLog);
                    $this->entityManager->persist($inboxConversation);
                }
            }
            if ('whatsapp_' === substr($job->getOperation(), 0, 9)) {
                if (!$result instanceof \MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppSendResult || '' === trim($result->messageId)) {
                    throw new \RuntimeException('WhatsApp delivery cannot complete without response.messages[0].id.');
                }
                $persisted = $this->connection->fetchAssociative('SELECT external_id,status FROM meta_messages WHERE id=:id', ['id' => $result->logId]);
                if (!is_array($persisted) || trim((string) ($persisted['external_id'] ?? '')) !== $result->messageId) {
                    $this->connection->update('meta_messages', [
                        'external_id'    => $result->messageId,
                        'status'         => $result->status,
                        'response'       => json_encode($result->response, JSON_THROW_ON_ERROR),
                        'date_modified'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ], ['id' => $result->logId]);
                }
            }
            $job->setStatus('completed')->setCompletedAt(new \DateTimeImmutable())->setLockedAt(null)->setLastError(null)->setMessageLogId($messageLogId);
        } catch (\Throwable $exception) {
            $error = $exception instanceof MetaGraphApiException ? $exception->details() : ['message' => $exception->getMessage()];
            $job->setLastError(json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->setLockedAt(null);
            $permanentGraphFailure = $exception instanceof MetaGraphApiException && !$exception->isRetryable();
            // Reconhecido antes dos bracos herdados porque a taxonomia abaixo e do Graph:
            // sem esta excecao, um canal que so caiu seria lido como desfecho desconhecido
            // e o atendente veria "nao saiu" em vez de "na fila".
            $temporaryChannelFailure = $exception instanceof ChannelTemporarilyUnavailable;
            if ($exception instanceof \DomainException && str_contains($exception->getMessage(), 'Automation paused')) {
                $job->setStatus('blocked');
                $outcome = 'failed';
            } elseif (!$temporaryChannelFailure && !$exception instanceof MetaGraphApiException && !$exception instanceof \InvalidArgumentException && !$exception instanceof \DomainException) {
                // A transport interruption can happen after Meta accepted the request. Never retry blindly.
                $job->setStatus('uncertain');
                $outcome = 'failed';
            } elseif (
                $exception instanceof \InvalidArgumentException
                || $exception instanceof \DomainException
                || $permanentGraphFailure
                || $job->getAttempts() >= $job->getMaxAttempts()
            ) {
                $job->setStatus('failed');
                $outcome = 'failed';
            } else {
                // So o teto muda entre os dois casos: a curva de espera e a mesma, para
                // que o reagendamento dos canais oficiais fique como estava.
                $cap = $temporaryChannelFailure ? self::TEMPORARY_CHANNEL_BACKOFF_CAP_SECONDS : self::GRAPH_BACKOFF_CAP_SECONDS;
                $delay = min($cap, 2 ** max(0, $job->getAttempts() - 1) * 30);
                $job->setStatus('retry')->setAvailableAt((new \DateTimeImmutable())->modify(sprintf('+%d seconds', $delay)));
                $outcome = 'retried';
            }
        }
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        $this->notifyInbox($job);

        return $outcome;
    }

    private function recoverStalled(\DateTimeInterface $before): int
    {
        $stalled = $this->jobs->findStalled($before);
        foreach ($stalled as $job) {
            $job->setStatus('uncertain')->setLockedAt(null)->setAvailableAt(null)->setLastError('Outbound outcome is uncertain after worker timeout; verify before any manual action.');
            $this->entityManager->persist($job);
        }
        if ([] !== $stalled) {
            $this->entityManager->flush();
            foreach ($stalled as $job) {
                $this->notifyInbox($job);
            }
        }

        return count($stalled);
    }

    private function notifyInbox(MetaOutboundJob $job): void
    {
        try {
            $this->inboxIntegration->outboundJobChanged($job);
        } catch (\Throwable) {
            // A support projection must never change the outcome of an external operation.
        }
    }
}
