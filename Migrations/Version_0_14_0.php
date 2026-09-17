<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

final class Version_0_14_0 extends AbstractMigration
{
    /** @var list<string> */
    private const TABLES = [
        'meta_whatsapp_consents',
        'meta_consent_jobs',
        'meta_consent_sync_runs',
    ];

    protected function isApplicable(Schema $schema): bool
    {
        foreach (self::TABLES as $table) {
            if ($schema->hasTable($this->concatPrefix($table))) {
                return true;
            }
        }

        return false;
    }

    protected function up(): void
    {
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();

        foreach (self::TABLES as $table) {
            $tableName = $this->concatPrefix($table);
            if (!$schemaManager->tablesExist([$tableName])) {
                continue;
            }
            $this->addSql('DROP TABLE '.$connection->quoteIdentifier($tableName));
        }
    }
}
