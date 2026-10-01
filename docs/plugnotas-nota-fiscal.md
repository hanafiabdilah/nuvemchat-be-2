# Nota fiscal das faturas da Pingly (Plugnotas)

Every invoice a workspace **in the BR market** pays **in BRL** gets one NFS-e,
issued by Pingly (prestador) to the workspace (tomador) through Plugnotas. A
refunded invoice gets its nota cancelled. Nothing is issued for any other market.

Not to be confused with the flow node *Invoice* (Spedy, `flow_invoices`): that
is a workspace issuing notas to its own customers.

## Moving parts

| Piece | Where |
|---|---|
| Trigger | `App\Observers\InvoiceFiscalObserver` (after commit) — `paid` → queue, `refunded` → cancel |
| Rules + state machine | `App\Services\Billing\Fiscal\FiscalInvoiceService` |
| HTTP | `App\Services\Billing\Fiscal\PlugnotasClient` (contract: `https://docs.plugnotas.com.br/api.json`) |
| Settings | `App\Services\Billing\Fiscal\PlugnotasConfig` → `settings` table, keys `plugnotas.*` |
| Submit job | `App\Jobs\IssueFiscalInvoice` (retries transport failures only) |
| Sweep | `fiscal-invoices:sync`, every 5 min, heartbeat |
| Webhook | `POST /webhook/plugnotas`, token in header `X-Pingly-Token` |
| Table | `fiscal_invoices` (unique `invoice_id`, unique `reference`) |
| BO | Integrations → Nota fiscal; Invoices → Notas fiscais; Health row *Notas fiscais (Plugnotas)* |
| Tenant | Billing → Faturas (column **Nota fiscal**, PDF/XML); Settings → Company (address) |

## States

`pending → processing → issued`, with `rejected` (prefeitura said no) and
`failed` (never reached it) needing an operator; `issued → cancelling →
cancelled` on refund. A refund before the nota exists is remembered
(`meta.cancel_requested_at`) and carried out as soon as it is authorized.

## Exactly one nota

- One row per invoice (unique index) — observer, job, sweep and operator converge.
- `idIntegracao = pingly-inv-{invoice}` (`-{attempt}` from the second attempt).
  Plugnotas answers **409** to a reference it already holds; the service adopts
  it and reads it back. That is what makes a timed-out submission safe to retry.
- A retry after a rejection uses a **new** reference — Plugnotas keeps the
  rejected nota under the old one.

## First setup

1. Plugnotas panel: register Pingly's company (CNPJ + A1 certificate + municipal
   registration) and enable NFS-e for it. Generate a token.
2. BO → Integrations → Nota fiscal: API key, CNPJ, service code (LC 116), ISS
   rate (+ municipal tax code / CNAE / ISS types if the city asks). **Test
   connection** must show the company.
3. **Register at Plugnotas** (webhook). Then tick **Issue notas fiscais**, Save.
4. Sandbox: tick *Sandbox*. Plugnotas' public sandbox key is
   `2da392a6-79d2-4304-a8b7-959572c7e44d` with CNPJ `08187168000160`.

Invoices paid **before** issuing was switched on get no nota automatically.
Issue them one by one: `POST /api/admin/invoices/{id}/fiscal-invoice`
(`bo.subscriptions.manage`).

## When something goes wrong

- Health *Notas fiscais (Plugnotas)* red → BO → Invoices → Notas fiscais →
  filter *Needs attention*. The message is Plugnotas'/the prefeitura's, verbatim.
  Fix the setting (or the customer's CPF/CNPJ) and **Send again**.
- Stuck in *With the prefeitura* → **Check now**; the sweep already asks every
  3 minutes. Warns after 24 h.
- Logs: `grep 'Nota fiscal'` (`submitted`, `moved`, `not issued`, `cancellation failed`).

## Tomador address

Optional, Brazil only, sent only when complete (`tenants.billing_address`,
`PUT /api/billing/address`, CEP lookup via ViaCEP `GET /api/billing/cep/{cep}`).
A partial address is never sent — prefeituras reject a CEP without a city code
but most accept a tomador without an address.

## Not verified yet

- The exact webhook body Plugnotas sends for NFS-e. The controller reads
  `idIntegracao` (fallback `id`) and **always** reads the nota back, so the
  shape only matters for finding the row.
- What `GET /nfse/consultar/{idIntegracao}/{cnpj}` answers while a nota is still
  processing (documented examples only show terminal states). Anything that is
  not CONCLUIDO/REJEITADO/DENEGADO/CANCELADO is treated as still processing.
