# Migrating a WhatsApp number from another BSP into Pingly

Moving a live number from another provider (Whaticket, 360dialog, Twilio, …)
without deleting it and starting over. The number, its verification and its
approved templates come across; the old provider keeps the chat history.

## Which Meta flow this is — and which one it is not

Meta documents two ways to move a WABA between providers. They are not
interchangeable, and only one of them is available to us.

**What we implement — [migration via Embedded Signup][es-migration].** The
business runs *our* Embedded Signup and enters the number they already own.
Meta recognises it, creates a WABA under our app, and moves the number into it.
**The losing provider does nothing and cannot block it.**

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

| Prerequisite | Where | Why |
|---|---|---|
| **Two-step verification OFF** | Old provider's WhatsApp Manager → Settings → Two-step verification | The single most common failure. With it on, our `/register` call is answered with a PIN we do not have (`133005`). |
| Display name already approved, no change pending | WhatsApp Manager | Meta refuses to migrate a number with a name request in flight. |
| Valid payment method on the WABA | Meta Business Suite → Billing | The number stops sending without one, migration or not. |
| Admin on the business portfolio | Meta Business Suite | Needed to authorise Embedded Signup. |
| Not a test number | — | Test numbers cannot migrate. |

## Doing it

1. Pingly → **Connections → New connection → WhatsApp Official**.
2. Choose **"Migrate from another provider"** (the third card).
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

1. Confirm the number is on Whaticket and two-step verification is **off**.
2. Create the connection in Pingly and run the migration flow above.
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

Automated coverage: `tests/Feature/Connection/WhatsappBspMigrationTest.php`.
