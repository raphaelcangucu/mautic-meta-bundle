<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Exception;

/**
 * Sinaliza que o canal esta fora do ar agora, mas volta sozinho: sessao de QR Code
 * derrubada, aparelho sem internet, alguem desconectou pelo celular.
 *
 * Existe porque a fila classifica falha por tipo de excecao, e a taxonomia herdada
 * e toda do Graph: o que nao e uma MetaGraphApiException retryable vira "uncertain"
 * ou "failed", e nenhum dos dois tenta de novo. Um canal que cai e volta precisa de
 * uma classe propria para que a resposta do atendente fique na fila em vez de ser
 * descartada com o cliente esperando do outro lado.
 *
 * Estende \RuntimeException de proposito: \DomainException e \InvalidArgumentException
 * ja sao capturadas pelo braco do permanente, entao herdar delas faria a excecao ser
 * lida como definitiva antes de chegar ao braco que a reconhece.
 */
final class ChannelTemporarilyUnavailable extends \RuntimeException
{
}
