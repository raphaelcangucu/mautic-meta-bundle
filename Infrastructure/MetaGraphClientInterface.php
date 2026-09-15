<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Infrastructure;

use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;

interface MetaGraphClientInterface
{
    public function get(MetaConnection $connection, string $path, array $query = []): array;

    public function post(MetaConnection $connection, string $path, array $payload): array;

    public function delete(MetaConnection $connection, string $path, array $query = []): array;

    /**
     * Download WhatsApp media without exposing the connection credential to the browser.
     *
     * @return array{contents:string,mimeType:string,fileSize:int}
     */
    public function downloadWhatsAppMedia(MetaConnection $connection, string $mediaId, int $maximumBytes = 26214400): array;

    /**
     * Upload a complete file through Meta's resumable-upload API and return its handle.
     */
    public function upload(MetaConnection $connection, string $fileName, string $contents, string $mimeType): string;
}
