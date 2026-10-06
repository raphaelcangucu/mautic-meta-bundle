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

    /**
     * Um asset do Graph tem no proprio na Meta e escopo de permissao correspondente: da para
     * consultar e diagnosticar. Uma sessao por QR vive fora do Graph. Quem fala com o Graph
     * pergunta aqui em vez de manter a propria lista de tipos, que envelhece calada e so
     * estoura em runtime quando alguem acrescenta o proximo canal.
     */
    public function isGraphAsset(): bool
    {
        return match ($this) {
            self::WhatsAppBusinessAccount, self::WhatsAppPhoneNumber, self::InstagramAccount, self::FacebookPage => true,
            self::WhatsAppQrSession => false,
        };
    }
}
