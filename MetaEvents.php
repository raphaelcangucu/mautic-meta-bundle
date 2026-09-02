<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle;

final class MetaEvents
{
    public const CAMPAIGN_WHATSAPP_SEND = 'mautic.meta.campaign.whatsapp.send';
    public const CAMPAIGN_WHATSAPP_REGISTER_OPT_IN = 'mautic.meta.campaign.whatsapp.register_opt_in';
    public const CAMPAIGN_INSTAGRAM_SEND = 'mautic.meta.campaign.instagram.send';
    public const CAMPAIGN_MESSAGE_DECISION = 'mautic.meta.campaign.message.decision';
    public const CAMPAIGN_MESSAGE_TYPE = 'meta.message.event';
}
