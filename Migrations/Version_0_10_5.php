<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

final class Version_0_10_5 extends AbstractMigration
{
    private const TABLE = 'meta_connections';

    protected function isApplicable(Schema $schema): bool
    {
        $tableName = $this->concatPrefix(self::TABLE);
        if (!$schema->hasTable($tableName)) {
            return false;
        }

        $table = $schema->getTable($tableName);

        return !$table->hasColumn('business_id')
            || !$table->hasIndex($this->concatPrefix('meta_connection_app_business'))
            || [] !== $this->legacyUniqueIndexes($table);
    }

    protected function up(): void
    {
        $connection = $this->entityManager->getConnection();
        $tableName = $this->concatPrefix(self::TABLE);
        $table = $connection->createSchemaManager()->introspectTable($tableName);
        $quotedTable = $connection->quoteIdentifier($tableName);

        if (!$table->hasColumn('business_id')) {
            $this->addSql("ALTER TABLE $quotedTable ADD business_id VARCHAR(191) NOT NULL DEFAULT ''");
        }

        $newIndex = $this->concatPrefix('meta_connection_app_business');
        if (!$table->hasIndex($newIndex)) {
            $this->addSql('ALTER TABLE '.$quotedTable.' ADD CONSTRAINT '.$connection->quoteIdentifier($newIndex).' UNIQUE (app_id, business_id)');
        }

        foreach ($this->legacyUniqueIndexes($table) as $indexName) {
            $this->addSql('ALTER TABLE '.$quotedTable.' DROP INDEX '.$connection->quoteIdentifier($indexName));
        }
    }

    /** @return list<string> */
    private function legacyUniqueIndexes(Table $table): array
    {
        $names = [];
        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique() && !$index->isPrimary() && ['app_id'] === $index->getColumns()) {
                $names[] = $index->getName();
            }
        }

        return $names;
    }
}
