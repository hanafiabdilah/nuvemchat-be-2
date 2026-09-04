# Virtual numbers (API Way) — runbook

Renting Brazilian phone numbers from API Way and reselling them to tenants for
receiving SMS and verification codes.

> ⚠️ **Two products share the name "API Way".** *Instances* are bought through
> the **ProxyBR partner API** (`ApiwayConfig`, `proxyhub.*` settings). *Numbers*
> are bought **straight from API Way** with an account of Pingly's own
> (`ApiwayNumbersConfig`, `apiway_numbers.*` settings). Neither credential works
> on the other's endpoints, and nothing is shared but the brand and the wallet.

---

## The three facts the design comes from

1. **A number is a monthly subscription, not one OTP.** API Way bills the
   platform every month until the number is deleted. A customer who gets their
   code in ten seconds still paid for the month, and so did we — which is why
   cancelling refunds nothing, and why the UI says so before the click.
2. **There is no upstream "renew" call.** The subscription renews itself and
   charges us on `renews_at`. So that date is a deadline on *our* side: by then
   the tenant has been charged for the next month, or `numbers:renew` deletes the
   number. Missing it does not lose the number — it buys another month of it
   with the platform's money.
3. **One account, one cap, one webhook.** Every tenant's numbers live in the
   same API Way account. `422` with a `cap` body means the platform is full
   (not the customer's fault, and not fixable on their form → surfaced as 409
   "out of stock"), and the single registered webhook carries every workspace's
   codes, routed locally by `number_id`.

---

## Setup (once, in the Back Office)

**Integrations → API Way**, which is split the way the credential is: the
account on one sub-tab, what is sold with it on the other. Numbers are the first
feature built on this account, not the only one it can hold.

**→ General** (account-level, shared by every API Way feature)

1. **Base URL** — `https://portal.apiway.com.br/api` (seeded).
2. **API token** — generated in the API Way portal and pasted here. Stored
   encrypted in `settings`, same as every other integration credential. The
   portal also exposes `POST /login`, but we do not use it: storing an e-mail
   and a password that open the whole account, to mint a token the portal hands
   over with a copy button, buys nothing.
3. **Test connection** — reads the numbers catalog with that token. It is the
   cheapest call that proves the token is accepted and sales are enabled, and it
   brings back the cost per number that the pricing table needs.

⚠️ Not the **Proxy BR** tab beside it. That token buys WhatsApp *instances*
through ProxyBR's partner API; this one talks to API Way directly.

**→ Numbers** (this feature)

4. **Resale pricing** — default markup (%) plus an optional fixed price per app.
   A fixed price is a *price, not a floor*: below cost the margin shows red and
   nothing stops the sale. The catalog is probed when the tab opens, so the cost
   and margin columns are filled in without pressing anything.
5. **Register webhook** — points API Way at `POST /webhook/apiway-numbers` and
   stores the signing secret. ⚠️ The raw secret is returned **only** by this
   call; the GET returns a preview forever after. Without a stored secret every
   push is rejected at our door and codes only arrive when somebody presses
   refresh.

---

## Money

Paid from the prepaid balance (`credit_wallets`), same as API Way instances and
trained agent hires.

| Event | Ledger reference | Type |
|---|---|---|
| Rental (first month) | `numbers:buy:{id}` | `purchase` |
| Renewal | `numbers:renew:{id}:{renews_at date}` | `renewal` |
| Undelivered → refund | `reversal:numbers:buy:{id}` | `reversal` |

The renewal reference carries the date it pays to move past — that is what makes
the daily pass safe to run twice, and what stops next month's charge being
refused as a duplicate of this month's.

**Charged before the number exists**, deliberately, because every failure path
gives it straight back:

- upstream refusal (cap reached, sales disabled, bad request) → immediate
  reversal, row `failed`, `VirtualNumberRefunded` notification;
- **5xx / timeout → the account inventory is read once, on the spot.** A number
  matching the purchase means it was created and the answer was lost: it is
  adopted and the purchase stands. Nothing matching means nothing was bought:
  the charge is reversed immediately and the customer is told so
  (`code: purchase_reversed`);
- only when that inventory read *also* fails is there nothing to conclude — the
  row stays `pending` with `meta.unconfirmed` (which records the HTTP status)
  and `numbers:sync` resolves it within the hour.

⚠️ **Cancelling a `pending` row refunds it.** "Cancelling refunds nothing" is
true only because the month is already owed to API Way — a purchase that never
produced a number owes them nothing. It also used to strand the money for good:
`cancelled` is terminal, so `numbers:sync` stopped looking. Two production
purchases were lost that way before `cancelUndelivered()` existed.

---

## Scheduler

| Command | When | What |
|---|---|---|
| `numbers:renew` | daily 08:45 (America/Sao_Paulo) | Warns from D-7, charges from D-3, and **cancels** an unpaid number within 24h of `renews_at` — before API Way bills us again. |
| `numbers:sync` | hourly at :35 | Refreshes `renews_at` and statuses; adopts numbers that exist upstream into unconfirmed purchases; refunds purchases stalled >30 min with nothing to adopt; logs numbers nobody owns. |

Both ping `Heartbeat`, so the Back Office → Health page shows them.

**Orphans are never deleted automatically.** A number upstream with no local
owner is logged as an error (`API Way numbers with no local owner`) because one
of them may be a purchase whose row was lost — deleting it would take a number a
customer is using. Deciding is a human's job.

---

## Receiving codes

Two routes, and the same message may arrive by both:

- **Webhook** (`POST /webhook/apiway-numbers`) — HMAC-SHA256 over the raw body,
  header `X-ApiWay-Signature: sha256=<hex>`, event `X-ApiWay-Event: sms.received`.
  Handled inline (not queued): somebody is watching a verification form.
- **Poll** — `GET /api/numbers/{id}?refresh=1` pulls `/numbers/{id}/sms`.

Deduplicated on a content hash (sender + text + timestamp) per number, because
neither route carries a usable id. Broadcast to the tenant channel as
`virtual-number-sms` (`ShouldBroadcastNow`), relayed by the SPA to a window
event and shown as a toast with the code in it.

---

## Diagnosing

⚠️ **Production keeps no application log.** `storage/logs` lives inside the
container (only `storage/app` is a volume), so a deploy takes the day's lines
with it, and the fpm container's stdout carries access lines only. Until that is
fixed, the durable record of a failed purchase is the row itself:
`meta.unconfirmed` / `meta.failure` carry the upstream status and code.

```bash
# Is the token working at all?
#   BO → Integrations → API Way → General → Test connection
#   A 401 there means the stored token was refused — paste a fresh one from the
#   API Way portal. There is no session to refresh and nothing to retry.

# Codes not arriving:
grep 'API Way SMS webhook rejected' storage/logs/laravel.log      # signature / no secret
grep 'SMS webhook for an unknown API Way number' storage/logs/laravel.log

# Purchases:
grep 'Virtual number purchase failed' storage/logs/laravel.log
grep 'Virtual number purchase left unconfirmed' storage/logs/laravel.log
grep 'Adopted an API Way number' storage/logs/laravel.log

# Money leaking upstream:
grep 'Could not cancel an unpaid virtual number' storage/logs/laravel.log
grep 'API Way numbers with no local owner' storage/logs/laravel.log

php artisan numbers:sync            # safe to run by hand, idempotent
php artisan numbers:renew           # same; charges are reference-deduped
```

---

## Permissions

| Permission | Who | What |
|---|---|---|
| `numbers.view` | tenant | See numbers and the codes on them (an OTP is a credential). |
| `numbers.manage` | tenant | Rent (spends the balance) and cancel. |
| `bo.numbers.view` | platform | The ledger of every rented number, with cost and margin. |
| `bo.settings.manage` | platform | Credentials, pricing and the webhook. |

Tenant permissions are granted to existing `owner` roles by migration
(`2026_09_04_000300`), because deploys only run `migrate --force`.

---

## Tests

- `tests/Feature/Numbers/VirtualNumberPurchaseTest.php` — charging, refusals,
  refunds, pricing, and that cost is never exposed to a tenant.
- `tests/Feature/Numbers/VirtualNumberSmsTest.php` — webhook signature, routing,
  dedupe across both routes, tenant isolation.
- `tests/Feature/Numbers/VirtualNumberRenewalTest.php` — the renewal window, the
  cancel-before-billing rule, adoption and stalled-purchase refunds.
