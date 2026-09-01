<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\WhatsApp;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplateRepository;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;

final class WhatsAppTemplateManager
{
    public function __construct(
        private MetaGraphClientInterface $graph,
        private WhatsAppTemplateRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {}

    public function synchronize(MetaAsset $businessAccount): array
    {
        $this->assertBusinessAccount($businessAccount);
        $seen = [];
        $created = 0;
        $updated = 0;
        $after = null;
        do {
            $query = ['fields' => 'id,name,language,category,status,quality_score,components,rejected_reason', 'limit' => 100];
            if (null !== $after) {
                $query['after'] = $after;
            }
            $response = $this->graph->get($businessAccount->getConnection(), $businessAccount->getExternalId().'/message_templates', $query);
            foreach ($response['data'] ?? [] as $remote) {
                if (!is_array($remote) || empty($remote['name']) || empty($remote['language'])) {
                    continue;
                }
                $template = $this->repository->findOneBy(['businessAccount' => $businessAccount, 'name' => $remote['name'], 'language' => $remote['language']]);
                $isNew = !$template instanceof WhatsAppTemplate;
                $template ??= (new WhatsAppTemplate())->setBusinessAccount($businessAccount);
                $this->hydrate($template, $remote);
                $this->entityManager->persist($template);
                $seen[] = $template->getName().'|'.$template->getLanguage();
                $isNew ? ++$created : ++$updated;
            }
            $after = $response['paging']['cursors']['after'] ?? null;
            $hasNext = !empty($response['paging']['next']) && is_string($after) && '' !== $after;
        } while ($hasNext);
        foreach ($this->repository->findBy(['businessAccount' => $businessAccount]) as $local) {
            if (!in_array($local->getName().'|'.$local->getLanguage(), $seen, true)) {
                $local->setStatus('REMOTE_MISSING')->touch();
            }
        }
        $this->entityManager->flush();

        return ['created' => $created, 'updated' => $updated, 'total' => count($seen)];
    }

    public function create(MetaAsset $businessAccount, string $name, string $language, string $category, array $components): WhatsAppTemplate
    {
        $this->assertBusinessAccount($businessAccount);
        $this->validate($name, $language, $category, $components);
        $response = $this->graph->post($businessAccount->getConnection(), $businessAccount->getExternalId().'/message_templates', [
            'name' => $name, 'language' => $language, 'category' => $category, 'components' => $components,
        ]);
        $template = (new WhatsAppTemplate())
            ->setBusinessAccount($businessAccount)
            ->setName($name)
            ->setLanguage($language)
            ->setCategory($category)
            ->setComponents($components)
            ->setExternalId(isset($response['id']) ? (string) $response['id'] : null)
            ->setStatus((string) ($response['status'] ?? 'PENDING'));
        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $template;
    }

    public function update(WhatsAppTemplate $template, string $category, array $components): WhatsAppTemplate
    {
        if (null === $template->getExternalId()) {
            throw new \InvalidArgumentException('Template must have a Meta ID before it can be updated.');
        }
        $this->validate($template->getName(), $template->getLanguage(), $category, $components);
        $this->graph->post($template->getBusinessAccount()->getConnection(), $template->getExternalId(), ['category' => $category, 'components' => $components]);
        $template->setCategory($category)->setComponents($components)->setStatus('PENDING')->touch();
        $this->entityManager->flush();

        return $template;
    }

    public function delete(WhatsAppTemplate $template): void
    {
        $asset = $template->getBusinessAccount();
        $query = ['name' => $template->getName()];
        if (null !== $template->getExternalId()) {
            $query['hsm_id'] = $template->getExternalId();
        }
        $this->graph->delete($asset->getConnection(), $asset->getExternalId().'/message_templates', $query);
        $this->entityManager->remove($template);
        $this->entityManager->flush();
    }

    private function hydrate(WhatsAppTemplate $template, array $remote): void
    {
        $template
            ->setExternalId(isset($remote['id']) ? (string) $remote['id'] : null)
            ->setName((string) $remote['name'])
            ->setLanguage((string) $remote['language'])
            ->setCategory((string) ($remote['category'] ?? 'UNKNOWN'))
            ->setStatus((string) ($remote['status'] ?? 'UNKNOWN'))
            ->setQualityScore(isset($remote['quality_score']['score']) ? (string) $remote['quality_score']['score'] : null)
            ->setComponents(is_array($remote['components'] ?? null) ? $remote['components'] : [])
            ->setRejectedReason(isset($remote['rejected_reason']) ? (string) $remote['rejected_reason'] : null)
            ->touch();
    }

    private function assertBusinessAccount(MetaAsset $asset): void
    {
        if (AssetType::WhatsAppBusinessAccount !== $asset->getType() || !$asset->isPublished()) {
            throw new \InvalidArgumentException('A published WhatsApp Business Account asset is required.');
        }
    }

    private function validate(string $name, string $language, string $category, array $components): void
    {
        if (1 !== preg_match('/^[a-z0-9_]{1,512}$/', $name)) {
            throw new \InvalidArgumentException('Template name must use lowercase letters, numbers, and underscores.');
        }
        if ('' === trim($language) || !in_array($category, ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true) || [] === $components) {
            throw new \InvalidArgumentException('Template language, valid category, and components are required.');
        }
    }
}
