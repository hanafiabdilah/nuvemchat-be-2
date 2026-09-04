# Media gallery — runbook

The tenant's own media library: files uploaded once and sent many times, plus
the storage they are kept in — some included with the plan, the rest rented by
the gigabyte from the prepaid balance.

## The one sentence that explains the design

**A gallery file is referenced, not copied.** Sending it puts its permanent
signed URL on the message; nothing is duplicated per send. That is the saving
the feature exists for, and it is also where every consequence comes from:

- the URL has **no expiry** (a message from last year still points at it);
- **deleting an asset breaks every message that used it** — said out loud in the
  confirmation dialog, because there is no undo;
- `media:purge` **never touches it**. Gallery paths live under `gallery/`, the
  sweep only looks at `messages` rows and `widget-uploads/`.

## Storage: two allowances, one meter

| Source | Where it comes from |
|---|---|
| Plan | `Quota::GalleryStorageGb` (`gallery_storage_gb`), edited in BO → Plans |
| Rented | `gallery_storage_rentals.gb`, bought by the tenant |

`App\Services\Gallery\GalleryStorage` adds them up. Every screen and every write
asks that one object — a second copy of the sum is a second chance to disagree
with the meter the customer is looking at.

⚠️ **Absent `gallery_storage_gb` means ZERO, not unlimited.** Same reading as
`included_trained_agents`, and for a stronger reason: stored bytes are the one
cost that only rises and never stops being paid. Every plan that predates this
feature therefore includes **no** gallery storage until somebody edits it in
BO → Plans. That is deliberate, and the feature is still alive on day one
because renting is not gated on the plan at all.

Only gallery files count toward the quota. Message attachments share the disk
but are not the tenant's to manage — they arrive unbidden and are purged on
their own schedule.

### Over the limit

Read-only. Uploads are refused; **nothing is ever deleted**, on any timer, by
any command. Existing files stay readable and sendable. This is the whole
difference from a lapsed virtual number, where the platform keeps getting billed
and cancelling is the only way to stop it.

## Renting

One row per tenant (`gallery_storage_rentals`). `PUT /api/gallery/storage {gb}`
does all four things a customer can mean by changing that number:

| From → to | What happens |
|---|---|
| nothing → N | full month charged now, `renews_at = now + 1 month` |
| N → more | **prorated** charge for the rest of the cycle, effective immediately |
| N → less | `pending_gb` set; applies at the renewal. Nothing charged, nothing refunded |
| N → 0 | same as above with a target of zero: the rental ends at the renewal |

Going up is immediate and going down is not, because the month is already paid:
applying a reduction today would either take back storage the customer owns or
require a refund, and this balance refunds nothing except purchases the platform
failed to deliver.

`POST /api/gallery/storage/quote {gb}` returns exactly what the change would
cost, so the dialog states it **before** the button. Do not re-derive it in the
frontend — the three branches are one rule and it lives on the server.

### Renewal (`gallery:renew`, daily 08:50 BRT)

Two windows, like `apiway:renew` and `numbers:renew`:

- **D-7**: warn if the balance will not cover it (`GalleryStorageRenewalNoCredit`,
  repeated daily — one message a week earlier, read while somebody was away, is
  not a warning);
- **D-3**: charge (`CreditTransactionType::Renewal`, reference
  `gallery:renew:{id}:{YYYY-MM-DD}` — one charge per cycle, so a pass that runs
  twice cannot bill twice);
- **at `renews_at`, still unpaid**: the rental ends
  (`GalleryStorageCancelledNoCredit`). The allowance goes; not one byte does.

A scheduled reduction is applied **before** the price is worked out, so the
customer is never charged at last month's size.

⚠️ If the scheduler was down for months, the renewal **catches up from today**
rather than replaying every missed cycle. Those months were never warned about
and never charged; billing three of them in three days would be the customer
paying for our outage.

## Pricing

`App\Services\Gallery\GalleryPricing` — `settings` table is the authority,
`config/gallery.php` is the fallback. Edited in **BO → Storage → Pricing**
(`bo.settings.manage`). Read through the class, never `config()` directly: the
price quoted on the customer's screen and the price charged to their balance
have to be the same number.

Changes are **not retroactive** — each rental keeps `price_per_gb_cents` until
its own renewal.

## Sending from the gallery

The gallery has no sender of its own. A picked file resolves to the same
`send-image` / `send-video` / `send-audio` / `send-document` route the composer
always called, with `gallery_asset_id` instead of a file:

```
POST /api/conversations/{id}/send-image   { "gallery_asset_id": 12, "message": "..." }
```

`App\Services\Gallery\GalleryMediaResolver` swaps it for `media_url` **on the
server**. That is the point of taking an id: `media_url` is a free-form URL, so
a client that built it itself would be choosing what this server fetches. The
resolver also checks the asset belongs to the workspace and that its type
matches the endpoint.

Everything downstream is unchanged — every channel handler already sends media
by URL (`OutboundMedia::fromData`).

⚠️ The public URL ends in a real filename (`/gallery/{uuid}/{slug}.pdf`) and
that is **not** cosmetic: `OutboundMedia` reads the MIME type off the last path
segment, and WhatsApp shows it as the document's name. `public_filename` is
signed into the URL, so it is immutable — renaming an asset changes `name` only.

## Permissions

| Permission | What it covers |
|---|---|
| `gallery.view` | browse the library, pick a file to send, read the meter |
| `gallery.manage` | upload, rename, delete, rent storage |

Both are given to the `owner` role by migration (deploys only run
`migrate --force`). Routes are **exempt from `subscription.active`**: rented
space keeps billing monthly, so the screen that stops it has to stay reachable.

No plan feature gates the pages — the storage is sold to anyone with the
permission and the balance, and the workspaces whose plan includes none of it
are exactly the audience for the offer.

## Operations

- Scheduler entry: `gallery:renew --days-before=3`, daily 08:50 America/Sao_Paulo.
- Heartbeat: `gallery:renew` (Health page). If it goes quiet, rented storage is
  free — nobody is charged and nothing warns.
- Files live on the `local` disk under `gallery/{tenant_id}/{uuid}.{ext}`.
  `GALLERY_DISK` moves them; nothing else needs to change.
- Per-file ceiling: `GALLERY_MAX_UPLOAD_MB` (default 64), well above what any
  channel accepts — the library is a library first.
- `config('gallery.blocked_extensions')` refuses `.html`, `.svg`, executables
  and archives-that-install. Not because they could run here (private disk,
  explicit Content-Type) but because a gallery URL is handed to customers over
  WhatsApp, and a workspace must not be able to use the platform's domain to
  host an installer.

## Tests

`tests/Feature/Gallery/` — uploads and the quota line
(`GalleryUploadTest`), the rental lifecycle (`GalleryStorageRentalTest`), and
resolving a pick into a send (`GallerySendTest`). Shared fixtures in
`tests/Support/GalleryFixtures.php`.
