# Tech Provider — implementação e homologação

## Estado

Disponível no MetaBundle 0.13.0 como console administrativo interno. A implantação continua exigindo backup, migração direcionada e homologação do Embedded Signup por empresa. Nenhum envio real é feito pelos testes automatizados.

## Funcionalidades

- Entrada administrativa em `/s/meta/tech-provider`, também acessível em Conexões.
- Configuration ID por aplicativo; SDK oficial; autorização da empresa e troca de código no servidor.
- Confirmação do aplicativo, validade e escopos do token, empresa proprietária, WABA e associação do número.
- Credenciais do cliente cifradas separadamente; app secret/verify token herdados do provedor sem duplicar segredos.
- Conexões manuais preservadas com business_id vazio; novos clientes únicos por app_id + business_id.
- Reautorização idempotente por empresa e ativo, sem duplicar WABA ou número. Ativos já vinculados a outra conexão são recusados; não há migração implícita.
- Diagnóstico, assinatura de webhooks, registro de números novos após verificação de posse na Meta. Número CONNECTED não é registrado novamente. Coexistência exige fluxo específico, não é convertida automaticamente.
- Sincronização de templates do WABA e envio de teste opcional com consentimento existente, proteção CSRF, idempotência e máximo de uma tentativa.
- Operações existente continua sendo a fonte de job, wamid, resposta e status de entrega. Accepted não significa delivered.
- Pausa de envios do cliente sem desregistrar o número; recibos de entrega continuam sendo processados.
- Roteamento por WABA/empresa e conferência do número nos recibos; templates vinculados ao WABA exato.

## Limite de escopo importante

Esta é uma ferramenta **administrativa interna**. Não fornece login de clientes no Mautic nem transforma a base CRM global em uma aplicação multi-tenant. Não conceder acesso Mautic a empresas clientes por esta implementação. O matching automático de contatos foi desabilitado para conexões de clientes; associações e consentimentos precisam ser explícitos por ativo.

Coexistência, migração entre WABAs, onboarding público self-service, cobrança compartilhada, submissão automática de App Review e atendimento de revisão de restrições não estão implementados. Aprovação como Tech Provider não elimina restrições da Meta.

## Instalação controlada

1. Guardar backup do banco e plugin atual; preservar a configuração/segredos fora deste pacote. Pausar o processamento durante a troca de schema/código.
2. Em staging com o runtime PHP do projeto, instalar o código e reconstruir o container/cache.
3. Executar `mautic:meta:provider:upgrade` sem `--apply` para conferir o SQL. O comando altera somente `meta_connections` respeitando o prefixo.
4. Executar `mautic:meta:provider:upgrade --apply`. Adiciona business_id vazio aos registros existentes, cria a chave composta antes de remover a chave antiga e não apaga registros. Repetir o preview deve produzir zero SQL.
5. Reconstruir cache e assets pelo procedimento de deploy do Mautic. Validar Conexões, Inbox, Operações, webhooks e workers existentes antes de promover o release.
6. Rollback: antes de qualquer cliente novo, restaurar o plugin anterior mantendo a coluna adicional. Depois de clientes compartilharem app_id, não restaurar a chave única antiga sem plano de reconciliação. Não apagar clientes para forçar rollback.

Os comandos PHP devem ser executados pelo wrapper/runtime aprovado do ambiente; não pressupõem PHP direto no host.

Quando o rollout usar **Configurações → Plugins → Install/Upgrade Plugins** em vez do comando CLI, a migração `Migrations/Version_0_10_5.php` faz a mesma alteração de `meta_connections`. A tela só deve ser acionada depois do backup e da instalação do código 0.10.5 no release, pois atualiza o banco e o registro do plugin. Não executar o comando `--apply` depois de uma atualização pela UI sem confirmar que o schema já está correto. Validar na interface a versão nova e a rota `/s/meta/tech-provider`, e em leitura do schema a coluna `business_id`, a chave composta e a ausência da chave única antiga em `app_id`.

## Configuração externa obrigatória na Meta

- Aplicativo empresarial do provedor com os acessos necessários aprovados e configuração de Facebook Login for Business / Embedded Signup adequada ao caso de uso.
- Adicionar o domínio HTTPS do Mautic às configurações permitidas do SDK/login e conferir políticas/URLs obrigatórias do aplicativo.
- Salvar o Configuration ID real na tela Tech Provider. Ele não é inventado nem incluído neste pacote.
- Configurar o callback assinado já existente da conexão raiz em Meta e assinar os campos WhatsApp aplicáveis. As empresas clientes são encaminhadas pelo mesmo callback com base no WABA.
- A empresa piloto autoriza seus ativos no popup. Validar proprietário e IDs retornados antes de prosseguir.
- Completar cobrança, verificação empresarial/telefone/nome e revisão de eventuais restrições diretamente na Meta. O diagnóstico indica cobrança como manual_check_required; não promete que ela esteja liberada.

## Homologação real antes de considerar ativo

1. Empresa piloto autorizada; token do cliente validado e cifrado; conexão antiga inalterada.
2. Número correto no WABA correto, status CONNECTED, aplicativo assinado.
3. Template aprovado sincronizado do WABA; identidade consentida para o remetente exato.
4. Um teste explicitamente confirmado; um job e um wamid; acompanhar webhook sent/delivered/failed e confirmação do destinatário.
5. Receber resposta e conferir Inbox e vínculo corretos. Repetir diagnóstico com um número legado, sem novo register.
6. Cancelamento do popup, autorização expirada, duplicação de callback, falha parcial e pausa não devem causar novo envio automático.
7. Em 130497 ou outra restrição, parar testes e seguir revisão da Meta; não migrar contas como suposta garantia de liberação.

## Testes

- PHPUnit: suíte do bundle, incluindo autorização cifrada/reauth, isolamento, tela/CSRF/schema e fila de teste/consentimento.
- Node: `node --test Tests/tech-provider.test.cjs` (ordem dos callbacks, idempotência, domínios falsos e cancelamento).
- Lint Twig, sintaxe PHP/JS, PHP-CS-Fixer nos PHP alterados e PHPStan no núcleo novo.
- Ambiente de testes possui depreciações preexistentes de libphonenumber sob PHP 8.5; o lint:container global possui incompatibilidade preexistente em fos_oauth_server.controller.authorize. Não confundir estes avisos com homologação de produção.

Referência comparada: exemplo oficial Meta em `/tmp/meta-provider-review-20260914`, commit `14703a3e1fdba9bcf75b2360b00817b6fcc9f79b`. A revisão de documentação e decisões está em `tech-provider-comparacao.md` no workspace principal.
