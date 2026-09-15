<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller\Api;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramMediaResolveException;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramMediaResolver;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class InstagramMediaApiController
{
    public function __construct(
        private CorePermissions $permissions,
        private MetaAssetRepository $assets,
        private InstagramMediaResolver $resolver,
    ) {
    }

    public function resolve(int $assetId, Request $request): JsonResponse
    {
        if (!$this->permissions->isGranted('meta:connections:view')) {
            return $this->error('access_denied', Response::HTTP_FORBIDDEN, false);
        }

        $asset = $this->assets->find($assetId);
        if (!$asset instanceof MetaAsset) {
            return $this->error('instagram_asset_not_found', Response::HTTP_NOT_FOUND, false);
        }

        try {
            $media = $this->resolver->resolve($asset, $request->query->getString('permalink'));
        } catch (\InvalidArgumentException) {
            return $this->error('invalid_instagram_permalink', Response::HTTP_BAD_REQUEST, false);
        } catch (InstagramMediaResolveException $exception) {
            return $this->error($exception->publicCode(), $exception->httpStatus(), $exception->isRetryable());
        } catch (MetaGraphApiException $exception) {
            return $this->graphError($exception);
        }

        return new JsonResponse([
            'success'    => true,
            'asset_id'   => $assetId,
            'account'    => $media['account'],
            'media_id'   => $media['media_id'],
            'permalink'  => $media['permalink'],
            'media_type' => $media['media_type'],
            'timestamp'  => $media['timestamp'],
        ]);
    }

    private function graphError(MetaGraphApiException $exception): JsonResponse
    {
        $details = $exception->details();
        $code = (int) ($details['code'] ?? 0);
        $status = (int) ($details['http_status'] ?? 0);
        if (190 === $code) {
            return $this->error('meta_token_expired', Response::HTTP_UNPROCESSABLE_ENTITY, false);
        }
        if (Response::HTTP_TOO_MANY_REQUESTS === $status || in_array($code, [4, 17, 32, 613], true)) {
            return $this->error('meta_rate_limited', Response::HTTP_SERVICE_UNAVAILABLE, true);
        }
        if ($exception->isRetryable()) {
            return $this->error('meta_graph_unavailable', Response::HTTP_SERVICE_UNAVAILABLE, true);
        }

        return $this->error('meta_graph_error', Response::HTTP_BAD_GATEWAY, false);
    }

    private function error(string $code, int $status, bool $retryable): JsonResponse
    {
        return new JsonResponse([
            'success'   => false,
            'code'      => $code,
            'retryable' => $retryable,
        ], $status);
    }
}
