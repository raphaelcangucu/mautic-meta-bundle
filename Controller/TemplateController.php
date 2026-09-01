<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppTemplateManager;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplateRepository;
use MauticPlugin\MauticMetaBundle\Form\Type\WhatsAppTemplateType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TemplateController extends AbstractController
{
    public function index(CorePermissions $permissions, WhatsAppTemplateRepository $templates, MetaAssetRepository $assets): Response
    {
        if (!$permissions->isGranted('meta:templates:view')) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('@MauticMeta/Template/index.html.twig', [
            'templates' => $templates->findBy([], ['name' => 'ASC', 'language' => 'ASC']),
            'businessAccounts' => $assets->findBy(['type' => AssetType::WhatsAppBusinessAccount->value, 'isPublished' => true], ['name' => 'ASC']),
        ]);
    }

    public function synchronize(int $assetId, Request $request, CorePermissions $permissions, MetaAssetRepository $assets, WhatsAppTemplateManager $manager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:templates:edit') || !$this->isCsrfTokenValid('meta_template_sync_'.$assetId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $asset = $assets->find($assetId);
        if (!$asset instanceof MetaAsset) {
            throw $this->createNotFoundException();
        }
        $result = $manager->synchronize($asset);
        $this->addFlash('notice', sprintf('Templates synchronized: %d created, %d updated.', $result['created'], $result['updated']));

        return $this->redirectToRoute('mautic_meta_templates');
    }

    public function new(Request $request, CorePermissions $permissions, MetaAssetRepository $assets, WhatsAppTemplateManager $manager): Response
    {
        if (!$permissions->isGranted('meta:templates:create')) { throw $this->createAccessDeniedException(); }
        $accounts = $this->accounts($assets);
        $form = $this->createForm(WhatsAppTemplateType::class, ['language' => 'pt_BR', 'category' => 'UTILITY', 'components_json' => $this->exampleComponents()], ['business_accounts' => $accounts]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $data = $form->getData();
                $account = $assets->find((int) $data['business_account_id']);
                if (!$account instanceof MetaAsset) { throw new \InvalidArgumentException('WhatsApp Business Account was not found.'); }
                $manager->create($account, (string) $data['name'], (string) $data['language'], (string) $data['category'], $this->components((string) $data['components_json']));
                $this->addFlash('notice', 'WhatsApp template submitted to Meta.');

                return $this->redirectToRoute('mautic_meta_templates');
            } catch (\Throwable $exception) { $this->addFlash('error', $exception->getMessage()); }
        }

        return $this->render('@MauticMeta/Template/form.html.twig', ['form' => $form, 'editing' => false]);
    }

    public function edit(int $templateId, Request $request, CorePermissions $permissions, WhatsAppTemplateRepository $templates, MetaAssetRepository $assets, WhatsAppTemplateManager $manager): Response
    {
        if (!$permissions->isGranted('meta:templates:edit')) { throw $this->createAccessDeniedException(); }
        $template = $templates->find($templateId);
        if (!$template instanceof WhatsAppTemplate) { throw $this->createNotFoundException(); }
        $data = ['business_account_id' => $template->getBusinessAccount()->getId(), 'name' => $template->getName(), 'language' => $template->getLanguage(), 'category' => $template->getCategory(), 'components_json' => json_encode($template->getComponents(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        $form = $this->createForm(WhatsAppTemplateType::class, $data, ['editing' => true, 'business_accounts' => $this->accounts($assets)]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $submitted = $form->getData();
                $manager->update($template, (string) $submitted['category'], $this->components((string) $submitted['components_json']));
                $this->addFlash('notice', 'WhatsApp template update submitted to Meta.');

                return $this->redirectToRoute('mautic_meta_templates');
            } catch (\Throwable $exception) { $this->addFlash('error', $exception->getMessage()); }
        }

        return $this->render('@MauticMeta/Template/form.html.twig', ['form' => $form, 'editing' => true]);
    }

    public function delete(int $templateId, Request $request, CorePermissions $permissions, WhatsAppTemplateRepository $templates, WhatsAppTemplateManager $manager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:templates:delete') || !$this->isCsrfTokenValid('meta_template_delete_'.$templateId, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $template = $templates->find($templateId);
        if (!$template instanceof WhatsAppTemplate) { throw $this->createNotFoundException(); }
        try { $manager->delete($template); $this->addFlash('notice', 'WhatsApp template deleted from Meta.'); } catch (\Throwable $exception) { $this->addFlash('error', $exception->getMessage()); }

        return $this->redirectToRoute('mautic_meta_templates');
    }

    /**
     * @return array<string, int>
     */
    private function accounts(MetaAssetRepository $assets): array
    {
        $choices = [];
        foreach ($assets->findBy(['type' => AssetType::WhatsAppBusinessAccount->value, 'isPublished' => true], ['name' => 'ASC']) as $asset) { $choices[$asset->getName()] = (int) $asset->getId(); }

        return $choices;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function components(string $json): array
    {
        $components = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($components) || !array_is_list($components)) { throw new \InvalidArgumentException('Components JSON must be an array.'); }

        return $components;
    }

    private function exampleComponents(): string
    {
        return "[\n  {\n    \"type\": \"BODY\",\n    \"text\": \"Olá {{1}}, sua atualização está pronta.\"\n  }\n]";
    }
}
