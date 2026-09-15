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
    public const CAMPAIGN_INSTAGRAM_COMMENT_TYPE = 'meta.instagram.comment';
    public const CAMPAIGN_INSTAGRAM_COMMENT_DECISION = 'mautic.meta.campaign.instagram.comment.decision';
    public const CAMPAIGN_INSTAGRAM_COMMENT_PRIVATE_REPLY = 'mautic.meta.campaign.instagram.comment.private_reply';
    public const CAMPAIGN_INSTAGRAM_COMMENT_PUBLIC_REPLY = 'mautic.meta.campaign.instagram.comment.public_reply';
}
