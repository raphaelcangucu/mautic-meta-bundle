<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Domain;

enum AssetType: string
{
    case WhatsAppBusinessAccount = 'whatsapp_business_account';
    case WhatsAppPhoneNumber = 'whatsapp_phone_number';
    case InstagramAccount = 'instagram_account';
    case FacebookPage = 'facebook_page';
    case WhatsAppQrSession = 'whatsapp_qr_session';

    public function channel(): Channel
    {
        return match ($this) {
            self::WhatsAppBusinessAccount, self::WhatsAppPhoneNumber, self::WhatsAppQrSession => Channel::WhatsApp,
            self::InstagramAccount => Channel::Instagram,
            self::FacebookPage => Channel::Facebook,
        };
    }
}
