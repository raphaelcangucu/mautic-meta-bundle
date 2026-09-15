<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\IntegrationsBundle\Migration\Engine;

final class TechProviderMigrationTest extends MauticMysqlTestCase
{
    public function testPluginUpgradePreservesLegacyConnectionAndAllowsSeparateBusinesses(): void
    {
        $connection = $this->em->getConnection();
        $prefix = 'test_tp_';
        $tableName = $prefix.'meta_connections';
        $quotedTable = $connection->quoteIdentifier($tableName);

        $connection->executeStatement('CREATE TABLE '.$quotedTable.' (id INT AUTO_INCREMENT PRIMARY KEY, app_id VARCHAR(191) NOT NULL, UNIQUE KEY test_tp_meta_connection_app_id (app_id))');

        try {
            $connection->insert($tableName, ['app_id' => '1437146078305405']);
            $engine = new Engine($this->em, $prefix, dirname(__DIR__, 2), 'MauticMetaBundle');
            $engine->up();

            $table = $connection->createSchemaManager()->introspectTable($tableName);
            self::assertTrue($table->hasColumn('business_id'));
            self::assertTrue($table->hasIndex($prefix.'meta_connection_app_business'));
            self::assertSame('', $connection->fetchOne('SELECT business_id FROM '.$quotedTable.' WHERE id = 1'));

            $connection->insert($tableName, ['app_id' => '1437146078305405', 'business_id' => '846359018709148']);
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$quotedTable));

            $engine->up();
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$quotedTable));
        } finally {
            $connection->executeStatement('DROP TABLE '.$quotedTable);
        }
    }
}
