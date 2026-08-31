# Rented AI tokens & prepaid credit

A workspace can run its AI agents on a provider key **the platform owns** instead
of pasting one of its own. The key is shared with other workspaces and drawn at
random from a pool; what the customer pays for is the usage, out of a prepaid
balance in BRL.

Nothing here changes workspaces that bring their own key. Those keep spending
their own money at the provider, are never gated on a balance, and are never
charged by us.

---

## The one fact that shapes everything

**A hub provider credential belongs to a hub tenant.** There is no shared record
several workspaces can point at.

So "renting" means: take the platform's secret and register it *again*, inside
the renting workspace's own hub scope. What the workspace ends up holding is an
ordinary `ai_hub_provider_credentials` row — one that carries
`ai_token_pool_key_id`, which is the whole of what makes it rented.

That shape is why the rest of the application did not have to change. Agents,
the trained-agent fork and the flow node's audio settings all select a
credential by local id and neither know nor care who pays for the key behind it.

It is also why **we now store raw provider secrets** (`ai_token_pool_keys.api_key`,
encrypted cast, `$hidden`, in no Resource, in no log). That surface did not
exist before; everywhere else a key is write-only.

---

## Moving parts

| Piece | Where |
|---|---|
| The pool | `ai_token_pool_keys` · `App\Services\AiCredits\AiTokenPool` |
| Renting / releasing / rotating | `App\Services\AiCredits\AiTokenRentalService` |
| Price of a run | `App\Services\AiCredits\AiCreditPricing` |
| Balance + ledger | `ai_credit_wallets`, `ai_credit_transactions` · `AiCreditService` |
| Gate + debit | `AiAgentHubTenantService::{assertCanSpendCredit,chargeRentedRun}` |
| Top-up charge | `BillingService::createAiCreditTopupPixInvoice` (`InvoicePurpose::AiCreditTopup`) |
| Tenant UI | `pages/AiAgents/{AiAgentCredentials,AiCredits}.tsx` |
| Back Office | `pages/{AiTokens,AiCredits}.tsx` · `bo.ai-tokens.manage`, `bo.ai-credits.manage` |

---

## Why the pick is random *once*, not per run

The credential is a property of the **agent** (`AiHubAgent.providerCredentialId`),
not of the run. Re-rolling per run would mean a `PATCH /agents/{id}` in front of
every reply — latency on the hot path, and a race with any other run in flight
on the same agent.

Randomising the *assignment* already spreads workspaces across keys, which is the
load behaviour the pool exists for. Per-run randomness would buy a smoother
distribution for one busy workspace at a price the quiet ones pay too.

A key is re-rolled only when it stops being usable — see rotation below.

**`max_tenants`** is the fence that matters: a provider's rate limit is per
organisation, so an uncapped key means one busy workspace throttling everyone
sharing it. **`weight`** pushes new workspaces toward the keys that can take them.

---

## Pricing

```
cents = ceil( cost_usd × usd_brl_rate × (1 + markup_pct/100) × 100 )
```

`cost_usd` is what the hub reported the provider charged. Rate and markup come
from the `settings` table (Back Office), falling back to `config/ai.php`.

Two things worth knowing:

- **Rounding is guarded.** `0.02 × 5 × 1.5` is `0.15000000000000002` in binary
  floating point; ceiling that gives 16 cents instead of 15. `AiCreditPricing`
  rounds to six places before the ceiling. Without it every price carries an
  extra cent it cannot justify.
- **A run with no reported cost is not free.** `ai_hub_runs.cost_usd` is already
  null for a share of rows — the Back Office AI Usage page reports `costed_runs`
  separately for exactly this reason. Those are priced at
  `ai.credits.fallback_run_cents` and **logged every time**:

  ```
  grep 'run priced from the fallback' storage/logs/laravel.log
  ```

  A statement full of `estimated` badges means the rental is no longer priced on
  anything real. Find out why the hub stopped reporting before touching the
  fallback number.

---

## The overdraft is deliberate

The price of a run is only known once it has run. So the pre-run gate can only
check that the balance is **strictly positive**, and one run can push it below
zero. The next one is refused.

Reserving an estimate up front would be a guess needing reconciliation
afterwards, for a smaller error than it introduces. Refusing to *record* a debit
that was genuinely incurred, to keep a column non-negative, would make our ledger
a worse record than the provider's own invoice.

`balance_cents` is therefore signed, and the Back Office shows a negative balance
as a red badge rather than clamping it to zero.

---

## Idempotency is the database's job

`ai_credit_transactions.ai_hub_run_id` and `.invoice_id` are **unique**.

- A retried job that already persisted the run cannot bill for it twice.
- MercadoPago delivers the same webhook more than once; a credit applied twice
  is money given away.

Both are enforced by the index, not by a check-then-write — webhook deliveries
and queue workers are concurrent by nature. A duplicate insert is swallowed and
logged (`AiCreditService: movement already recorded`), because from the caller's
point of view the movement already happened.

---

## Rotation: what "revoked" actually does

`paused` and `revoked` are different states on purpose:

- **paused** — the key is healthy, it is simply full. No new rentals; the
  workspaces already on it keep working.
- **revoked** — the secret is gone (rotated at the provider, leaked, cancelled).
  Every workspace on it is already broken.

Saving `revoked` runs `AiTokenRentalService::rotateAllFrom()` **inline**, and the
response reports `{moved, failed}`. Inline because the admin needs to know, in
that response, whether the pool had spares — a rotation that silently failed for
want of a second key leaves those workspaces exactly as broken as doing nothing.

Rotation order is the opposite of the obvious one: the replacement is created and
everything re-pointed **before** the old credential is deleted. Deleting first
leaves a window in which a run picks up an agent whose credential no longer
exists.

**Two kinds of reference move, and both must:**

1. Agents — local id *and* a `PATCH` hub-side.
2. AIAgent flow nodes — `data.input_audio.credential_id` and
   `data.response_audio.credential_id` hold the **hub** id as a plain string,
   chosen in the node editor. It does not look like a foreign key, which is
   exactly why forgetting it would leave voice replies failing with no visible
   cause.

⚠️ **Replacing `api_key` on a pool key does not reach the workspaces already
holding it** — the hub stored a copy when each credential was minted. Revoking
is what moves them. The Back Office form says so at the field.

---

## When the balance runs out

`assertCanSpendCredit()` throws `AiCreditExhaustedException` **before** the hub
is called, so an empty wallet costs the platform nothing. It follows the same
`BILLING_ENFORCE` master switch as every other entitlement check.

Consequences, decided by the caller:

- **Flow** → `routeHandoff(reason: 'ai_credit_exhausted')`. The customer is
  handed to a human, never left talking to silence.
- **"Respond with AI"** → `402` with code `ai_credit_exhausted`.

Kept apart from `ai_quota_exceeded` on purpose: both stop the AI for an account
reason, but they are fixed on different screens — one by upgrading the plan, one
by topping up — and a customer pointed at the wrong one loses the afternoon
before they find out.

Charging failures are **swallowed**, not thrown: the reply has already been
generated and is on its way to a customer, and throwing there would hand the
conversation to a human over a bookkeeping problem while losing an answer already
paid for at the provider.

```
grep 'failed to charge a rented run' storage/logs/laravel.log
```

---

## Ops checklist

- `php artisan migrate --force` creates the tables **and** both `bo.*`
  permissions (deploys only run `migrate --force`; `PlatformRbacSeeder` alone
  would ship the pages invisible).
- Set the commercial numbers before selling anything: `AI_CREDITS_MARKUP_PCT`,
  `AI_CREDITS_USD_BRL_RATE`, or the Back Office settings that override them.
- Add at least one pool key per provider you intend to offer. With none, the
  tenant UI simply does not show the rent button — a provider with no capacity
  is never offered, because a workspace that clicks "rent" and is told there is
  nothing has already been let down by the time it finds out.
- `AI_CREDITS_ENABLED=false` closes the door to **new** rentals without breaking
  live ones.
- No new worker or daemon. Top-ups settle through the MercadoPago webhook that
  already exists.

---

## Known gaps

- **Audio cost may be invisible.** Transcription and TTS run inside the same hub
  run, and whether `providerCostUsd` includes them is not verified. If it does
  not, a workspace's voice replies are unbilled. Probe before promising a margin
  on audio-heavy accounts.
- **Card top-ups are not supported.** The card path in this product is a
  MercadoPago Preapproval — a recurring authorisation for a fixed amount — which
  is the wrong instrument for "deposit R$50 whenever". Adding it means the
  one-off `/v1/payments` flow this application does not otherwise use.
- **A shared key's rate limit is shared.** `max_tenants` is a static fence; there
  is no reaction to a `429` yet. The natural next step is rotating a workspace
  off a key that is throttling, reusing `rotate()`.

---

## Tests

`tests/Feature/AiCredits/{AiTokenRentalTest,AiCreditTest}.php`, fixtures in
`tests/Support/AiCreditFixtures.php`.
