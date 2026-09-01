<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Domain;

enum ConsentStatus: string
{
    case Unknown = 'unknown';
    case OptedIn = 'opted_in';
    case OptedOut = 'opted_out';
}
