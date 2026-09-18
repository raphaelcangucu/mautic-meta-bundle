<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Entity;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\Driver\StaticPHPDriver;
use Doctrine\Persistence\Proxy;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJobRepository;
use PHPUnit\Framework\TestCase;

/**
 * findDue() e uma consulta, entao mock nao prova nada aqui: a ordem errada so aparece
 * quando o banco responde. Nao ha aplicacao Mautic neste repositorio para rodar os
 * testes funcionais (MauticMysqlTestCase), entao o teste monta um EntityManager sobre
 * SQLite em memoria e cria apenas a tabela meta_outbound_jobs. Asset e contato entram
 * como referencias: o job so guarda a chave estrangeira, e as tabelas leads e
 * meta_assets nunca sao lidas.
 */
final class MetaOutboundJobRepositoryTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private MetaOutboundJobRepository $repository;

    protected function setUp(): void
    {
        $configuration = new Configuration();
        // A cadeia e o que o Mautic monta em producao, e nao e detalhe: sem ela um
        // parametro DateTimeImmutable seria lido como entidade e o driver estatico
        // morreria em vez de admitir que a classe nao e mapeada.
        $driver = new MappingDriverChain();
        $driver->addDriver(new StaticPHPDriver([]), 'MauticPlugin\\MauticMetaBundle\\Entity');
        $driver->addDriver(new StaticPHPDriver([]), 'Mautic');
        $configuration->setMetadataDriverImpl($driver);
        $configuration->setProxyDir(sys_get_temp_dir().'/meta-outbound-job-repository-test');
        $configuration->setProxyNamespace('MetaOutboundJobRepositoryTestProxies');
        $configuration->setAutoGenerateProxyClasses(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->entityManager = new EntityManager($connection, $configuration);
        (new SchemaTool($this->entityManager))->createSchema([$this->entityManager->getClassMetadata(MetaOutboundJob::class)]);

        $registry = new class($this->entityManager) extends AbstractManagerRegistry {
            public function __construct(private EntityManagerInterface $entityManager)
            {
                parent::__construct('meta-test', ['default' => 'default'], ['default' => 'default'], 'default', 'default', Proxy::class);
            }

            protected function getService(string $name): EntityManagerInterface
            {
                return $this->entityManager;
            }

            protected function resetService(string $name): void
            {
            }
        };

        $this->repository = new MetaOutboundJobRepository($registry);
    }

    /**
     * O atendente manda "Bom dia, Dona Marta" e, quinze segundos depois, "seu pedido
     * saiu hoje". A primeira tropeca e volta para a fila com availableAt trinta
     * segundos a frente; a segunda esta pronta agora. Ordenado so por availableAt, o
     * segundo texto sai primeiro e a cliente le a conversa ao contrario. A regra
     * existe para isso: enquanto a primeira mensagem da conversa nao terminar, a
     * seguinte nao sai, nem que ja esteja vencida.
     */
    public function testAJobWaitsForAnEarlierJobOfTheSameConversation(): void
    {
        $marta = $this->entityManager->getReference(Lead::class, 10);
        $greeting = $this->job(1, $marta, 'retry', '2026-01-01 12:00:30');
        $followUp = $this->job(1, $marta, 'pending', '2026-01-01 12:00:15');

        self::assertSame([], $this->dueIds('2026-01-01 12:00:20'), 'A segunda mensagem nao pode sair enquanto a primeira nao terminar.');
        self::assertSame([$greeting->getId()], $this->dueIds('2026-01-01 12:00:35'), 'A primeira mensagem sai sozinha assim que vence.');

        // Persist explicito porque a entidade e DEFERRED_EXPLICIT, igual ao que a
        // OutboundQueue faz depois de cada desfecho.
        $this->entityManager->persist($greeting->setStatus('completed'));
        $this->entityManager->flush();

        self::assertSame([$followUp->getId()], $this->dueIds('2026-01-01 12:00:35'), 'A segunda mensagem sai depois que a primeira saiu.');
    }

    /**
     * A serializacao e por conversa, nao pela fila inteira. Uma conversa travada num
     * numero de WhatsApp nao pode segurar os outros contatos nem os outros canais, ou
     * a correcao vira uma fila global de um por vez e estrangula os tres canais que ja
     * estao em producao.
     */
    public function testJobsOfDifferentConversationsDoNotBlockEachOther(): void
    {
        $marta = $this->entityManager->getReference(Lead::class, 10);
        $joao = $this->entityManager->getReference(Lead::class, 11);

        $this->job(1, $marta, 'retry', '2026-01-01 12:00:30');
        $otherContact = $this->job(1, $joao, 'pending', '2026-01-01 12:00:15');
        $otherAsset = $this->job(2, $marta, 'pending', '2026-01-01 12:00:15');

        self::assertSame(
            [$otherContact->getId(), $otherAsset->getId()],
            $this->dueIds('2026-01-01 12:00:20'),
            'Outro contato e outro canal do mesmo contato sao conversas distintas.',
        );
    }

    /**
     * Job de campanha nao tem conversa: o contato pode vir nulo. Sem tratar o nulo, a
     * condicao le todos eles como uma unica conversa gigante e a campanha inteira
     * passa a sair um por varredura, ou fica presa para sempre atras de um job nulo
     * que nunca termina.
     */
    public function testAJobWithNoConversationIsNeverBlocked(): void
    {
        $inFlight = $this->job(1, null, 'processing', '2026-01-01 12:00:00');
        $first = $this->job(1, null, 'pending', '2026-01-01 12:00:05');
        $second = $this->job(1, null, 'pending', '2026-01-01 12:00:10');

        self::assertSame(
            [$first->getId(), $second->getId()],
            $this->dueIds('2026-01-01 12:00:20'),
            'Jobs sem conversa saem juntos, mesmo com um job anterior sem conversa em voo.',
        );
        self::assertNotContains($inFlight->getId(), $this->dueIds('2026-01-01 12:00:20'));
    }

    private function job(int $assetId, ?Lead $contact, string $status, string $availableAt): MetaOutboundJob
    {
        $job = (new MetaOutboundJob())
            ->setAsset($this->entityManager->getReference(MetaAsset::class, $assetId))
            ->setContact($contact)
            ->setOperation('whatsapp_text')
            ->setStatus($status)
            ->setAvailableAt(new \DateTimeImmutable($availableAt));
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    /**
     * @return list<int>
     */
    private function dueIds(string $now): array
    {
        return array_map(
            static fn (MetaOutboundJob $job): int => (int) $job->getId(),
            $this->repository->findDue(500, new \DateTimeImmutable($now)),
        );
    }
}
