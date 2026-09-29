# Catálogo de produtos + "Agente IA com ações" (ai_tools)

Runbook. O que existe, como ligar, e onde olhar quando algo der errado.

## O que é

- **Catálogo** (`products`, `product_variants`, `stock_movements`): os produtos da
  loja, cadastrados em Configurações → Avançado → Produtos ou importados de
  CSV/XLSX. Todo produto tem ao menos uma variante — um produto simples é uma
  variante sem nome.
- **Pedidos** (`orders`, `order_items`): o carrinho que a IA monta numa conversa
  e, depois, a venda. O estoque só sai quando o Pix é pago (`OrderService::markPaid`,
  chamado por `FlowPaymentService::settle()` — uma vez por cobrança).
- **Nó `ai_tools`** ("Agente IA com ações"): um nó de Agente de IA que também age.
  Usa a mesma máquina do `ai_agent` (turno, janela de rajada, digitando, handoff).
  O autor marca capacidades; as definições das ferramentas vivem no código
  (`App\Services\AiAgentHub\Tools\AiToolCatalog`).
- **Endpoint `POST /api/v1/conversations/tools`**: o AI Hub chama no meio do run
  quando o modelo usa uma ferramenta. Contrato do Hub (o que vale):
  `PINGLY-TOOLS-20260928.md` (raiz do monorepo). O PDF que enviamos antes
  (`Especificacao-Ferramentas-IA-Hub-2026-09-28.pdf`) ficou só como histórico.

## Contrato do Hub (PINGLY-TOOLS-20260928.md)

- **Seis ferramentas, e só elas**: `search_products`, `get_product`, `cart_add`,
  `cart_remove`, `cart_view`, `create_payment`. Tags, lead e notas não existem.
- **Catálogo por agente**: `PUT /v1/agents/{id}/pingly-tools` com as seis
  definições (sempre todas). Cada run manda só os **nomes** permitidos pelo nó:
  `tools: ["search_products", …]`. Schemas com `required` sempre presente e
  `additionalProperties: false`; sem `pattern`/`format`/`$ref`.
- **Credencial por agente**: `PUT /v1/agents/{id}/pingly-delivery {apiKey}` com uma
  chave `pk_…` do workspace. Não existe chave global. O Hub chama o Pingly com
  `X-Api-Key`.
- Os dois registros são feitos por `AiToolHubSync` antes do run que precisa deles:
  catálogo reenviado só quando o hash das definições muda
  (`ai_hub_agents.tools_catalog_hash`); a chave é emitida com o nome
  "AI Hub · {agente}" e guardada por id (`ai_hub_agents.tools_api_key_id`) — se
  alguém revogá-la em Desenvolvedor, o próximo turno emite e registra outra.
- **409 do nosso endpoint** → o Hub devolve o run `CANCELLED` com
  `output.responseSuppressed: true` → o nó fica em silêncio (sem resposta, sem handoff).
- **Falha com ferramentas não é repetida** (a repetição seria um run novo, que
  poderia chamar `cart_add` de novo sob outro run id). O nó faz o handoff de erro.
- Agentes **GEMINI** com ferramentas: 400 `tools_provider_unsupported` → handoff de
  erro. Use OpenAI ou Anthropic no agente do nó.
- Limites do Hub: 8 chamadas por run, 20 s por chamada, 60 s por loop.

## Regras que não mudam

1. **Nenhum valor vem do modelo.** `cart_add` recebe variante + quantidade; o preço
   é lido do catálogo. `create_payment` não tem argumentos: cobra o total do
   carrinho. Mudou preço/estoque desde que o carrinho foi montado → a ferramenta
   devolve `cart_changed` e o modelo confirma o novo total antes.
2. **Referência válida = permissão para pedir, não para agir.** Cada chamada
   reconfere: conversa ainda no nó, com a IA, com o mesmo agente.
3. **Retentativa nunca age duas vezes.** `ai_tool_calls` único por
   `(tenant_id, idempotency_key)`, gravado antes de executar.
4. **Sem reserva de estoque na v1.** Dois clientes pagando a última unidade: os dois
   ficam pagos, o estoque fica negativo e aparece "Vendido além do estoque" em
   Produtos. Recusar dinheiro que já entrou seria pior.

## Ligar em produção

Ordem obrigatória — **o Hub primeiro**. O `/v1/runs` rejeita o run inteiro por um
campo desconhecido (incidente `inputAudio`, 28/08/2026).

1. Anderson confirma que o Hub aceita o campo `tools` no run e chama o endpoint.
2. Sondagem gratuita (agente inexistente): `POST /v1/runs` com `tools: []` deve
   responder **404 Agent not found**, não 400.
3. `.env`:
   ```
   AI_TOOLS_ENABLED=true
   # opcionais
   AI_TOOLS_RUN_FIELD=tools
   AI_TOOLS_MAX_CALLS_PER_CONVERSATION_PER_HOUR=120
   AI_TOOLS_SEARCH_LIMIT=8
   ```
   e `up -d --force-recreate` dos containers PHP.
4. Dar o recurso de plano **`catalog`** aos planos que vão vender (BO → Plans).
   Sem ele, Produtos/Pedidos somem e um nó `ai_tools` roda como agente comum.

Desligar: `AI_TOOLS_ENABLED=false`. Nós já salvos continuam respondendo como
agente de IA comum, sem nenhum campo novo no run.

## Autenticação do endpoint

`X-Api-Key` do workspace, como o resto do `/v1` — a chave que `AiToolHubSync`
registrou no agente. A conversa vem do `callback_ref`, assinado com HMAC e
preso a uma conversa, um nó e um agente; chave de um workspace com ref de outro
responde 404.

## Saídas do nó

| Saída | Quando |
|---|---|
| `handoff` | Sempre existe. É a saída única do `ai_agent` (modo `always_ai`, ou sem gente no horário). |
| `paid` | "Cobrar o carrinho" marcado; o Pix do carrinho foi pago. Variáveis `order_*` + `payment_*`. |
| `payment_failed` | Idem; expirou ou foi recusado. |

Saída sem conexão = a IA continua atendendo. O desvio por pagamento roda em
`RunAiToolsPaymentBranch`, que espera a mesma trava do turno da IA
(`ai-turn:{conversa}`) para não cruzar com uma resposta sendo escrita.

## Diagnóstico

- **"Por que a IA cobrou esse valor?"** → `ai_tool_calls` da conversa (argumentos,
  resultado, duração) + `orders`/`order_items` + `flow_payments.order_id`.
  Produção não guarda logs entre deploys: a tabela é a fonte.
- **"Estoque não baixou"** → `stock_movements` com `reason=order_paid`. Variante
  com `stock` nulo não é contada (proposital).
- **"O Hub não chama as ferramentas"** → o run tem o campo `tools`?
  (`ai_hub_runs.metadata.toolCount`; as chamadas feitas ficam em
  `metadata.toolCalls`). `AI_TOOLS_ENABLED` ligado? Plano tem `catalog`? O agente
  tem `tools_api_key_id` ativo e `tools_catalog_hash` preenchido?
- **Run `FAILED` com `pingly_tool_unauthorized`** → a chave registrada no Hub foi
  revogada fora do fluxo normal; zere `ai_hub_agents.tools_api_key_id` do agente e o
  próximo turno registra outra.
- **Hub recusando o run** → `docker compose logs queue | grep 'Validation failed to run agent'`.
  O nó cai no handoff com motivo `error`; desligue `AI_TOOLS_ENABLED`.

## Importação de planilha

`POST /api/products/import/preview` (arquivo → mapeamento sugerido + primeiras
linhas, guardado 30 min no cache) e `POST /api/products/import` (token + mapeamento).
Uma linha = uma variante; mesmo nome de produto agrupa variações; SKU existente
atualiza. CSV em UTF-8 ou Windows-1252, `;` ou `,`; XLSX lido nativamente
(extensões zip + xmlreader, presentes na imagem de produção). Até 5.000 linhas.

## Testes

`tests/Feature/Catalog/ProductCatalogTest.php`, `tests/Feature/PublicApi/ToolCallTest.php`,
`tests/Feature/Flow/AiToolsNodeTest.php` (inclui a regressão: payload do `ai_agent`
idêntico com as ferramentas ligadas). Fixture: `tests/Support/CatalogFixtures.php`.
