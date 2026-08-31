# Trained agents (catálogo de agentes prontos)

Agentes de IA que a plataforma escreve uma vez, por segmento (contabilidade,
consultório médico, academia…), e que o cliente contrata em vez de montar do
zero.

## O modelo, em uma frase

**Contratar é copiar.** O blueprint da plataforma vira um `AiHubAgent` comum
dentro do workspace do cliente — prompt, perfil, conhecimento, habilidades e
exemplos — e a partir daí é dele: editável, retreinável, deletável. Editar o
blueprint depois **não alcança ninguém** que já contratou; só muda o que o
*próximo* comprador recebe.

Três consequências que explicam quase todo o código:

1. **A cobrança é única.** Não existe renovação, porque não há nada rodando do
   nosso lado para renovar.
2. **O token do modelo é do cliente.** O fork roda numa credencial de provedor
   dele, igual a um agente feito à mão. O preço do blueprint paga o *trabalho de
   treinamento*, não os tokens.
3. **A cópia é lenta.** São o agente + perfil + N conhecimentos + M habilidades
   + K exemplos, cada um uma chamada HTTP ao hub. Por isso o fork vive num job.

## Fluxo

```
catálogo  →  POST /api/trained-agents/{blueprint}/hire
                │
                ├─ cota do plano disponível  → hire(source=included, status=provisioning)
                │                              → FulfillTrainedAgentHire
                │
                └─ cota esgotada             → hire(source=purchased, status=pending_payment)
                                               + fatura Pix (purpose=trained_agent_purchase)
                                               → webhook MP aprovado
                                                 → BillingService::applyAssetPaymentUpdate
                                                 → TrainedAgentService::handleInvoicePaid
                                                 → FulfillTrainedAgentHire
```

`FulfillTrainedAgentHire` → `TrainedAgentService::fulfill()` → status `active`,
evento `trained-agent-updated` no canal `tenant-channel.{id}`.

**Quem decide se é grátis é o servidor**, nunca o cliente: se o modo fosse
parâmetro da requisição, "incluído" viraria algo que se pede.

## Cota

`Quota::IncludedTrainedAgents` (`included_trained_agents`) no plano.

⚠️ **Ausência aqui significa ZERO, não ilimitado** — ao contrário de todas as
outras cotas. É uma franquia que o plano concede; um plano que não a menciona
não concedeu nenhuma. Ler ausência como ilimitado entregaria o catálogo inteiro
de graça em todo plano anterior à feature. Está em
`SubscriptionGate::canHireIncludedTrainedAgent()`.

Contam contra a franquia apenas hires `included` em `provisioning`/`active`.
Um fork **falhado libera a vaga**: o cliente não recebeu nada, e segurar a
franquia dele por causa de uma falha nossa transforma nossa indisponibilidade em
direito perdido.

Downgrade de plano **não remove** agentes já copiados (são agentes dele agora);
`trainedAgentsRemaining()` só nunca fica negativo.

## Resiliência do fork

`fulfill()` é **retomável**. Cada passo grava `meta.progress` e é pulado se o
contador disser que já foi feito, então uma falha na metade continua de onde
parou em vez de criar um segundo agente. Há teste para isso
(`a resumed fulfilment continues instead of creating a second agent`).

Esgotadas as tentativas, `FulfillTrainedAgentHire::failed()` chama
`markFailed()`:

- hire pago → `meta.needs_refund` (mesma bandeira do API Way, lida no Back
  Office em **Trained Agents → Hires**, com botão "Marcar reembolsado");
- hire incluído → sem bandeira, vaga devolvida.

O tenant vê um botão **Tentar novamente** (`POST /trained-agents/hires/{id}/retry`).

## Privacidade do catálogo

`TrainedAgentCatalogResource` **não** envia `system_prompt`, o corpo dos
conhecimentos nem os exemplos. Esse texto *é* o produto: um catálogo que o
entrega antes do pagamento já o entregou, e o endpoint de contratação vira o
jeito mais lento de obter o que já está na aba de rede. Vão apenas contagens e
títulos. Há teste (`the catalog never ships the prompt or knowledge bodies`).

Depois da contratação nada disso vale: é um agente dele, legível e editável na
aba AI Agents.

## Permissões

| Onde | Permissão |
|---|---|
| Ver catálogo | `ai-agents.view` (+ feature `ai_agent_hub`) |
| Contratar (dentro da franquia) | `ai-agents.create` |
| Contratar (compra paga) | `ai-agents.create` **e** `billing.manage` |
| Back Office | `bo.trained-agents.manage` |

A checagem de `billing.manage` está no controller, não na rota: se a contratação
custa dinheiro depende da franquia restante do plano, não do endpoint.

## Operação

```bash
# catálogo inicial de exemplo (3 segmentos, 3 agentes) — idempotente por slug
php artisan db:seed --class=TrainedAgentSeeder
```

Não está no `DatabaseSeeder`: publicar conteúdo com preço num catálogo em
produção é decisão de negócio, não efeito colateral de migration.

⚠️ **Depende da fila `default`.** Sem worker, contratações ficam presas em
`provisioning` para sempre. `FulfillTrainedAgentHire` tem `timeout = 300`;
o `--timeout` do worker precisa ser ≥ isso.

## Arquivos

| Camada | Arquivo |
|---|---|
| Regras | `app/Services/TrainedAgent/TrainedAgentService.php` |
| Job | `app/Jobs/TrainedAgent/FulfillTrainedAgentHire.php` |
| Cobrança | `BillingService::{createTrainedAgentPixInvoice,applyAssetPaymentUpdate}` |
| Cota | `SubscriptionGate::{trainedAgentsUsed,canHireIncludedTrainedAgent}` |
| API tenant | `Api/TrainedAgent/TrainedAgentController.php` |
| API admin | `Api/Admin/AdminTrainedAgentController.php` |
| SPA tenant | `pages/AiAgents/TrainedAgentCatalog.tsx` |
| SPA BO | `pages/TrainedAgents.tsx` |
| Testes | `tests/Feature/TrainedAgent/TrainedAgentHireTest.php` |
