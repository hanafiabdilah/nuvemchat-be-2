# Migrating a WhatsApp number from another BSP into Pingly

Moving a live number from another provider (Whaticket, 360dialog, Twilio, …)
without deleting it and starting over. The number, its verification and its
approved templates come across; the old provider keeps the chat history.

## Which Meta flow this is — and which one it is not

Meta documents two ways to move a WABA between providers. They are not
interchangeable, and only one of them is available to us.

**What we implement — [migration via Embedded Signup][es-migration].** The
business runs *our* Embedded Signup and enters the number they already use, as
if it were new. There is no migration screen, no "import" button, and no list
of numbers in use elsewhere: Embedded Signup only ever offers accounts in
business portfolios the person administers.

⚠️ **It only works when the existing WABA is already in the customer's own
Meta business portfolio.** The source and destination WABA have to live under
the same portfolio. Where the losing BSP owns the WABA — the classic reseller
arrangement, and what a business usually has if they never went through a
self-serve signup — this path cannot reach it. Nothing in Embedded Signup can
pull a WABA out of somebody else's portfolio, and no amount of code here
changes that. Those customers need their old provider to release the account
first, or to go through the Solution Partner flow below.

An earlier version of this document claimed the losing provider does nothing
and cannot block the move. That is wrong, and it was wrong in the direction
that wastes the most time: they must at minimum disable two-step verification,
they must de-register data localization if they enabled it, and if they own the
portfolio they must release it.

**What we do not implement — [solution migration via Meta Business Suite][bs-migration],**
the flow behind `POST /<WABA_ID>/set_solution_migration_intent`. Two things rule
it out, and neither is a coding problem:

1. **The source provider has to start it.** That endpoint is called by the
   provider *losing* the number, pointing at the destination's solution ID. A
   migration away from Whaticket would begin with Whaticket making an API call
   for us. The business owner cannot initiate it themselves in Business Suite —
   they can only accept a request that already exists.
2. **It is a Solution Partner flow.** The destination steps assume a
   `solution_id` and an extended credit line shared onto the customer's WABA
   (`POST /<CREDIT_LINE_ID>/whatsapp_credit_sharing_and_attach`). Pingly is a
   Tech Provider: customers attach their own payment method to their own WABA,
   which is why the existing onboarding works with no credit line at all.

If Pingly ever becomes a Solution Partner with a credit line, that flow becomes
worth adding — as a *second* path, for partners who will cooperate. It is not a
replacement for this one.

[es-migration]: https://developers.facebook.com/docs/whatsapp/solution-providers/support/migrating-phone-numbers-among-solution-partners-via-embedded-signup/
[bs-migration]: https://developers.facebook.com/documentation/business-messaging/whatsapp/solution-providers/support/migrating-wabas-among-solutions-via-meta-business-suite

## Before you start

Everything here is done by the **business that owns the number**, at their
current provider. Nothing is done by us, and nothing can be done from Pingly.

These four are the prerequisites Meta's own page states, in its order:

| Prerequisite | Where | Why |
|---|---|---|
| **Two-step verification OFF** | Old provider's WhatsApp Manager → Settings → Two-step verification | **The only thing the losing provider has to do**, and the most common failure. With it on, our `/register` call is answered with a PIN we do not have (`133005`). |
| Meta Business Account **verified** | Meta Business Suite | Stated prerequisite. |
| Existing WABA **approved**, with a valid payment method | Meta Business Suite → Billing | Stated prerequisite; the number stops sending without one, migration or not. |
| Display name already approved, no change pending | WhatsApp Manager | Meta refuses to migrate a number with a name request in flight. |

Also documented: test numbers cannot migrate, and a migrated number can only be
registered for Cloud API.

Third-party BSP guides add two more — the source and destination WABA sharing
one business portfolio, and data localization having to be de-registered first.
Meta's page states neither, so they are listed under troubleshooting rather than
here: treat them as things to check when a run fails, not as gates to clear
before the first attempt.

## How it is implemented: the programmatic route

Meta offers two ways in. We drive the API one, in our own wizard:

| # | Call | Purpose |
|---|---|---|
| 1 | `POST /{WABA_ID}/phone_numbers` — `cc`, `phone_number`, `verified_name`, `migrate_phone_number: true` | Claim the number onto our WABA; returns a **new** phone number id |
| 2 | `POST /{PHONE_NUMBER_ID}/request_code` — `code_method` (SMS/VOICE), `language` | Meta sends a 6-digit code to the number |
| 3 | `POST /{PHONE_NUMBER_ID}/verify_code` — `code` | Prove ownership |
| 4 | `POST /{PHONE_NUMBER_ID}/register` — `messaging_product`, `pin` | Enable Cloud API under our WABA |

`request_code`'s reference names the token types it accepts — User, System User,
**Business Integration System User** — with `whatsapp_business_management`.
That is exactly what Embedded Signup already hands us, which is why this needs
no Solution Partner status and no credit line.

`App\Services\Connection\WhatsApp\WhatsappNumberMigrationService` holds the four
calls; `Api\ConnectionController::{migrateNumber,migrationRequestCode,migrationVerifyCode}`
expose them under `/api/connections/{id}/migration/*` behind
`permission:connections.connect`. Progress lives in
`credentials.pending_migration`, so a refresh between the code being sent and
typed does not strand a number that has already been claimed. Step 4 runs in the
same request as step 3 — between them the number is verified but unusable, and
there is nothing to decide in that gap.

**The other way — the number typed inside Embedded Signup's own window — also
works** and is what Meta calls preferred (steps: ES → capture ids → subscribe →
register, which `handleWhatsAppCallback` still does). It was tried first and
produced nothing usable in practice: no migration screen, nothing listed, and a
failure that looks identical to someone closing the popup. The API route exists
because each of its steps reports its own error and can be retried alone.

⚠️ **One contract still unproven.** Meta's phone-number reference describes
`migrate_phone_number` as the On-Premises → Cloud API flag, while the
solution-migration guide uses it for moving a number between WABAs. Both read as
the same instruction — "this number exists elsewhere, take it over" — so it is
sent, and step 1 logs Meta's response body verbatim on failure
(`grep 'claiming the number failed'`). The first real migration settles it.

## What the customer does in Embedded Signup's window

The step the first live attempt missed, and the only one that makes this a
migration at all:

> In Meta's window, type **the same number already in use at the other
> provider**, with its country code, and the same approved display name.

There is no migration screen, no import button, and nothing listed to pick —
Meta never shows numbers in use elsewhere. A run that stops at "this looks like
an ordinary connect" has not tested anything.

## Doing it

1. Pingly → **Connections → New connection → WhatsApp Official**.
2. Choose **"Migrate from another provider"** (the third card), read the
   prerequisites, then:
   - **Authorize with Meta** — only if this connection has no WABA yet.
     In Meta's window, **create a new WhatsApp Business Account and do not add
     a phone number to it.** The number you are migrating is still live at the
     other provider; it is asked for in the next step, not here. Do not add a
     different number just to get past the screen — that leaves a stray number
     on the account.

     An empty WABA is the expected outcome of this step: the callback stores
     the account and its token and leaves the connection **Pending**. (Outside
     a migration an empty WABA still fails loudly, as it should.)
   - **Which number are you moving?** — country code, number, and the display
     name already approved for it, plus SMS or voice for the code.
   - **Enter the verification code** — the 6-digit code Meta sends to that
     number. Finishing this registers it and the connection goes Active.

   A wrong or expired code only repeats the last step: the number is already
   claimed by then, and re-claiming it would fail.

Old steps, kept for the Embedded Signup route:
3. Read the prerequisites panel, then **Start migration**.
4. In the Meta popup, enter **the same number** the old provider runs, and the
   same display name. Meta detects that it already exists and walks the owner
   through moving it.
5. The popup closes; the connection goes Active on its own.

What the backend does when the popup finishes (`handleWhatsAppCallback`):
reads the phone number, subscribes our app to the new WABA's webhooks,
**registers the number on Cloud API**, and stores the credentials with
`migrated_from_bsp: true`.

## Why registration is the part that needed code

Ordinary onboarding skips `POST /{phone_number_id}/register` when Meta reports
the number as `platform_type: CLOUD_API` **and** `code_verification_status:
VERIFIED` — re-registering a number whose PIN we do not hold fails, so the skip
is protective.

A migrated number defeats that test. It has been live elsewhere for months, so
it arrives verified, and can arrive already reporting `CLOUD_API`. Skipping on
that evidence produces the worst possible outcome: a connection that shows
Active, passes the status check, and cannot send — registration is per-WABA and
this number has never been registered on *ours*.

So a migration always attempts the call. It is safe to attempt: an
already-registered number comes back as `2388023` / "already registered", which
`registerPhoneNumber()` treats as success.

## After the migration

- **Templates.** Meta copies over templates that were `APPROVED` **and** quality
  `GREEN`. They are re-checked against current category rules on the way, so
  some can land `REJECTED` and need resubmitting. Anything not `APPROVED`/`GREEN`
  is not copied and must be recreated. Check **Templates** in Pingly.
- **Quality rating.** Resets to `UNKNOWN` for ~24 hours. Not a fault.
- **Messaging.** Re-registration is instantaneous; there is no send/receive gap.
- **Billing.** Messages delivered before the move are billed to the old
  provider, even if delivered after it. Afterwards the customer's own WABA pays.
- **History.** Past conversations stay at the old provider. Pingly's inbox starts
  from the first message after the move. This is a Meta boundary, not ours.
- **The old provider.** Their side goes dead on its own. Cancel the contract
  separately — nothing in Pingly touches their account.

## When the popup offers nothing

The symptom that ended the first live attempt: the window looked exactly like
an ordinary connect, with no sign of the number already in use elsewhere.

That much is expected — there is no migration screen. What is **not** expected
is the existing WABA failing to appear as something to select. Embedded Signup
does detect a WABA the person administers and offers to share it, keeping its
numbers, quality rating and approved templates. When it does not, work down
this list; every item is Meta-side state that cannot be seen or fixed from
here:

1. **Logged in as the wrong person.** The Facebook account driving the popup
   must be an admin of the portfolio holding the WABA, not merely a user of
   the old provider's dashboard.
2. **We are not a partner on that WABA.** WhatsApp Manager → the WABA →
   **Partners → Add partner**. Several BSPs document this as the fix for
   exactly this symptom; allow a couple of minutes, then re-run the flow.
3. **The WABA is in the old provider's portfolio.** Then it is theirs, and
   only they can release it — see the ownership gate above.
4. **The WABA was created through a developer app** rather than a signup flow.
   Meta states these cannot be selected through Embedded Signup at all.

Since Aug 2026 the browser console records what Meta actually reported:
`[WhatsApp signup] variant: … — events from Meta: [...]`, plus a warning naming
the last step when the popup closes without a WABA id. **Capture that console
output on the next attempt** — it is the difference between guessing at this
list and knowing which line applies.

## When it fails

Errors are surfaced to the operator in plain language; the raw code is in the
log. `grep 'Failed to register phone number on Cloud API'`.

| Meta code | Meaning | Fix |
|---|---|---|
| `133005` | Registered elsewhere under a PIN we don't have | Turn two-step verification off at the old provider, retry. |
| `133006` | Number never completed verification | Verify it in WhatsApp Manager first. |
| `133016` | Too many registration attempts | Wait a few minutes, retry. |
| `2388023` | Already registered | Not an error — treated as success. |

A migration that fails leaves the connection **Pending** with no credentials.
Retrying is safe: nothing was half-written, and re-running Embedded Signup
starts from the same place.

## Testing it (ProxyBR: Whaticket → Pingly)

0. Ask Whaticket to turn **two-step verification off** for the number. That is
   the whole of their involvement.
1. Open the browser console before starting, and keep it open. The wizard logs
   `[WhatsApp signup] variant: … — events from Meta: [...]`; without it a
   failure is indistinguishable from "nothing happened".
2. Create the connection in Pingly, run the migration flow, and **type
   ProxyBR's existing Whaticket number** in Meta's window — see the section
   above. The first attempt stopped here because the window looked ordinary;
   it is supposed to.
3. Assert: connection Active; `credentials.migrated_from_bsp` is true;
   `phone_number_id` is **new** (the old WABA's id is not reused).
4. Send a test message out, and reply to it from another phone — both
   directions have to work before the old provider is cancelled.
5. Check **Templates** for anything that came across `REJECTED`.

⚠️ The one thing no test suite can settle: whether Meta reports the migrated
number as `CLOUD_API` before we register it. Both branches are handled, so the
outcome is correct either way — but the log line
`Phone number already registered on Cloud API; skipping /register` must **not**
appear for a migration. If it does, the skip heuristic leaked and this runbook
is wrong.

Automated coverage: `tests/Feature/Connection/WhatsappNumberMigrationTest.php`
(the four API steps and every error path) and
`tests/Feature/Connection/WhatsappBspMigrationTest.php` (the Embedded Signup
route's registration behaviour).
