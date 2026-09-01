<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Domain;

enum Channel: string
{
    case WhatsApp = 'whatsapp';
    case Instagram = 'instagram';
}
