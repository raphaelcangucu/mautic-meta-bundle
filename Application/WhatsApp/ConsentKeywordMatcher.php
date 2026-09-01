<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\WhatsApp;

final class ConsentKeywordMatcher
{
    public function match(string $text): ?string
    {
        $normalized = mb_strtoupper(trim($text));
        if (in_array($normalized, ['START', 'INICIAR', 'SIM', 'ACEITO', 'ASSINAR'], true)) { return 'opt_in'; }
        if (in_array($normalized, ['STOP', 'PARAR', 'SAIR', 'CANCELAR', 'DESCADASTRAR'], true)) { return 'opt_out'; }

        return null;
    }
}
