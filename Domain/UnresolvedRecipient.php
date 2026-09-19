<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Domain;

/**
 * A marca de um destinatario que nao e telefone.
 *
 * Nem todo canal de WhatsApp entrega numero. O canal por QR Code recebe, em alguns casos,
 * um identificador opaco de privacidade (`220518514233310@lid`) cujos digitos PARECEM
 * telefone e nao sao: discar aquilo nao chega em ninguem. Quem recebe esse identificador
 * marca o destinatario com este prefixo, e o resto do bundle usa a marca para nao tratar
 * como numero o que nunca foi numero -- canonizar ou casar contato por telefone a partir
 * dela inventaria um destinatario, e quem paga por isso e o cliente que nunca recebe a
 * resposta.
 *
 * A convencao vive aqui, e nao em quem produz a marca, porque quem precisa reconhece-la
 * esta deste lado: a conversa e o casamento de contato.
 */
final class UnresolvedRecipient
{
    public const PREFIX = 'jid:';

    public static function marks(string $recipient): bool
    {
        return str_starts_with($recipient, self::PREFIX);
    }
}
