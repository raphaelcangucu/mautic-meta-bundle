<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticMetaBundle\Application\Connection\TechProviderManager;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TechProviderController extends CommonController
{
    use MetaViewTrait;

    public function index(Request $request, MetaConnectionRepository $connections, MetaAssetRepository $assets, EntityManagerInterface $em, TechProviderManager $manager, \MauticPlugin\MauticMetaBundle\Application\Connection\WabaResolver $resolver, \MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppTemplateManager $templateManager, \MauticPlugin\MauticMetaBundle\Application\Connection\ProviderTestSender $testSender): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \Mautic\UserBundle\Entity\User || !$user->isAdmin()) {
            throw $this->createAccessDeniedException();
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('meta_provider', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                $action = $request->request->getString('action');
                if ('configure' === $action) {
                    $provider = $connections->find($request->request->getInt('provider'));
                    $config = $request->request->getString('config_id');
                    if (!$provider || $provider->isCustomer() || !preg_match('/^[0-9]{5,32}$/', $config)) {
                        throw new \DomainException('Aplicativo ou configuração inválidos.');
                    }
                    $provider->setSettings(array_replace($provider->getSettings(), ['embedded_signup_config_id' => $config]));
                    $em->persist($provider);
                    $em->flush();
                } elseif ('pause' === $action) {
                    $customer = $connections->find($request->request->getInt('provider'));
                    if (!$customer || !$customer->isCustomer()) {
                        throw new \DomainException('Selecione uma conexão de cliente.');
                    }
                    $customer->setStatus('paused');
                    $em->persist($customer);
                    $em->flush();
                } elseif ('connect' === $action) {
                    $session = $request->getSession();
                    $pending = $session->get('meta_provider_signup', []);
                    // Consume before exchanging a single-use code; an uncertain result is never automatically resent.
                    $session->remove('meta_provider_signup');
                    if (($pending['expires'] ?? 0) < time() || !hash_equals($pending['nonce'] ?? '', $request->request->getString('nonce'))) {
                        throw new \DomainException('Autorização expirada. Confira a lista antes de iniciar outra autorização.');
                    }
                    $provider = $connections->find($pending['provider']);
                    if (!$provider) {
                        throw new \DomainException('Aplicativo não encontrado.');
                    }
                    $manager->connect($provider, $request->request->getString('code'), $request->request->getString('waba_id'), $request->request->getString('phone_id'), '', $request->request->getString('name'));
                } else {
                    $phone = $assets->find($request->request->getInt('asset'));
                    if (!$phone || AssetType::WhatsAppPhoneNumber !== $phone->getType()) {
                        throw new \DomainException('Número não encontrado.');
                    }
                    if ('test' === $action) {
                        $attempt = $request->request->getString('attempt');
                        $allowed = $request->getSession()->get('meta_provider_test', '');
                        if (!$allowed || !hash_equals($allowed, $attempt) || !$request->request->getBoolean('confirm')) {
                            throw new \DomainException('Confirme um novo teste nesta tela.');
                        }
                        $request->getSession()->remove('meta_provider_test');
                        $template = $em->find(\MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate::class, $request->request->getInt('template'));
                        if (!$template) {
                            throw new \DomainException('Template não encontrado.');
                        }
                        $components = json_decode($request->request->getString('components', '[]'), true, 32, JSON_THROW_ON_ERROR);
                        if (!is_array($components) || !array_is_list($components)) {
                            throw new \DomainException('Os componentes devem ser uma lista JSON.');
                        }
                        $job = $testSender->enqueue($phone, $template, $request->request->getString('recipient'), $components, $attempt);
                        $this->addFlash('notice', 'Teste enfileirado: job '.$job->getId().'. Aguarde o webhook; accepted não confirma entrega.');
                    } else {
                        match ($action) {
                            'check' => $manager->check($phone),
                            'sync' => $templateManager->synchronize($resolver->forPhone($phone)),
                            'subscribe' => $manager->subscribe($phone),
                            'register' => $manager->register($phone, $request->request->getString('pin')),
                            default => throw new \DomainException('Ação inválida.'),
                        };
                    }
                }
                $this->addFlash('notice', match ($action) {
                    'configure' => 'Configuração do Embedded Signup salva.',
                    'pause' => 'Envios desta empresa pausados.',
                    'connect' => 'Autorização recebida. Confira a empresa e valide o número antes de enviar.',
                    'check' => 'Diagnóstico atualizado. Conexão não confirma entrega.',
                    'subscribe' => 'Assinatura de webhooks conferida. Valide o diagnóstico antes de enviar.',
                    'sync' => 'Sincronização de templates concluída.',
                    'register' => 'Registro conferido. Valide o diagnóstico antes de enviar.',
                    'test' => 'O teste foi apenas enfileirado; acompanhe o status real em Operações.',
                    default => 'Etapa concluída.',
                });
            } catch (\Throwable $error) {
                $this->addFlash('error', $error instanceof \DomainException ? $error->getMessage() : 'Não foi possível concluir. Confira os diagnósticos antes de tentar novamente.');
            }

            $returnAsset = $request->request->getInt('return_asset');
            if ($returnAsset > 0 && $returnAsset === $request->request->getInt('asset')) {
                return $this->redirectToRoute('mautic_meta_asset_edit', ['assetId' => $returnAsset], 303);
            }

            return $this->redirectToRoute('mautic_meta_provider', [], 303);
        }
        $provider = $connections->find($request->query->getInt('provider'));
        $nonce = '';
        if ($provider && !$provider->isCustomer() && $provider->isPublished() && !empty($provider->getSettings()['embedded_signup_config_id'])) {
            $nonce = bin2hex(random_bytes(32));
            $request->getSession()->set('meta_provider_signup', ['nonce' => $nonce, 'provider' => $provider->getId(), 'expires' => time() + 900]);
        }
        $attempt = bin2hex(random_bytes(32));
        $request->getSession()->set('meta_provider_test', $attempt);

        return $this->metaView('@MauticMeta/Connection/provider.html.twig', ['connections' => $connections->findAll(), 'phones' => $assets->findBy(['type' => AssetType::WhatsAppPhoneNumber->value]), 'provider' => $provider, 'nonce' => $nonce, 'attempt' => $attempt, 'templates' => $em->getRepository(\MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate::class)->findBy(['status' => 'APPROVED'])]);
    }
}
