<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Security\Permissions;

use Mautic\CoreBundle\Security\Permissions\AbstractPermissions;
use Symfony\Component\Form\FormBuilderInterface;

final class MetaPermissions extends AbstractPermissions
{
    public function __construct(array $params)
    {
        parent::__construct($params);
        $this->addStandardPermissions(['connections', 'messages', 'templates', 'webhooks', 'analytics'], false);
    }

    public function getName(): string { return 'meta'; }

    public function buildForm(FormBuilderInterface &$builder, array $options, array $data): void
    {
        foreach (['connections', 'messages', 'templates', 'webhooks', 'analytics'] as $resource) {
            $this->addStandardFormFields('meta', $resource, $builder, $data);
        }
    }
}
