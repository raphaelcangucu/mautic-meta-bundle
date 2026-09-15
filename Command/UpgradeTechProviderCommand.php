<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mautic:meta:provider:upgrade', description: 'Preview/apply only the Tech Provider connection-key migration. Back up the database first.')]
final class UpgradeTechProviderCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->em->getConnection();
        $name = $this->em->getClassMetadata(MetaConnection::class)->getTableName();
        $schema = $db->createSchemaManager();
        $table = $schema->introspectTable($name);
        $quoted = $db->quoteIdentifier($name);
        $indexName = substr($name, 0, -strlen('meta_connections')).'meta_connection_app_business';
        $sql = [];
        if (!$table->hasColumn('business_id')) {
            $sql[] = "ALTER TABLE $quoted ADD business_id VARCHAR(191) NOT NULL DEFAULT ''";
        }
        // Install the replacement constraint before removing the legacy unique key.
        if (!$table->hasIndex($indexName)) {
            $sql[] = 'ALTER TABLE '.$quoted.' ADD CONSTRAINT '.$db->quoteIdentifier($indexName).' UNIQUE (app_id, business_id)';
        }
        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique() && !$index->isPrimary() && ['app_id'] === $index->getColumns()) {
                $sql[] = 'ALTER TABLE '.$quoted.' DROP INDEX '.$db->quoteIdentifier($index->getName());
            }
        }
        foreach ($sql as $statement) {
            $output->writeln($statement);
            if ($input->getOption('apply')) {
                $db->executeStatement($statement);
            }
        }
        $output->writeln($input->getOption('apply') ? 'Migration applied; existing records retained.' : 'Preview only. Use --apply after backup.');

        return Command::SUCCESS;
    }
}
