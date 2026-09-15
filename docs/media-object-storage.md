# Media on object storage (Vultr)

Media leaves the VPS disk for a Vultr Object Storage bucket: `pingly-media`
on `ewr1.vultrobjects.com` (New Jersey). The code for it is in place and inert
until the settings below are changed; the defaults keep everything on the local
disks.

## How it fits together

| Role (`App\Services\Media\MediaStorage`) | Setting | Local disk (today) | Object storage | Address handed out |
|---|---|---|---|---|
| Private media — attachments, avatars, contact photos, widget uploads, e-mail | `MEDIA_DISK` | `local` | `media` → `pingly-media/private/…` | Our signed link `/storage/{path}?expires&signature` → **302** to a presigned URL |
| Outbound copies Meta/API Way fetch while sending | `MEDIA_OUTBOUND_DISK` | `public` | `media_public` → `pingly-media/public/…` | Presigned URL, 2 h |
| Published — `/api/uploads` (flows, carousel, campaigns), Instagram post media | `MEDIA_PUBLISHED_DISK` | `public` | `media_public` → `pingly-media/public/…` | Permanent public URL (`MEDIA_S3_PUBLIC_URL`) |
| Gallery | `GALLERY_DISK` | `local` | `media` | Unchanged signed gallery route (streams) |

Why each piece exists:

- **The signed link is ours, not the bucket's.** Dashboards cache media links
  in IndexedDB for up to 7 months and never ask again; S3 presigned URLs die
  after at most 7 days. `MediaFileController` answers at the same address and
  with the same signature Laravel's `storage.local` route used, so every link
  already cached keeps working, and on object storage it redirects to a
  presigned URL rounded to the hour (browsers keep hitting their cache).
- **Old public URLs keep working.** Flows and campaigns stored
  `https://chat.pingly.com.br/storage/uploads/…` addresses. Caddy serves
  `/storage/*` from the local public disk while the file is there, and from
  `pingly-media/public/` once it is not (`deploy/Caddyfile`).
- **CORS on the bucket** lets "download" and "copy image" in the chat fetch a
  file as a blob after the redirect (`media:configure-bucket`).
- **Latency.** The server is in São Paulo, the bucket in New Jersey: 120–150 ms
  per round trip for anything the *server* reads or writes. Browsers fetch
  media from the bucket directly. The code avoids per-file checks where it can
  (attachment size is one call, contact photos compare by ETag, `media:purge`
  deletes without checking first).

## Moving to the bucket

Every step up to 7 can be undone by putting the old settings back.

1. **Deploy the code** (`./deploy.sh backend`, then `./deploy.sh caddy`).
   Nothing changes yet.

2. **Credentials** — in `/opt/pingly/.env` (never in git, never in chat):

   ```
   MEDIA_S3_KEY=…
   MEDIA_S3_SECRET=…
   MEDIA_S3_REGION=us-east-1
   MEDIA_S3_BUCKET=pingly-media
   MEDIA_S3_ENDPOINT=https://ewr1.vultrobjects.com
   MEDIA_S3_PUBLIC_URL=https://ewr1.vultrobjects.com/pingly-media
   ```

   Then recreate every PHP container:
   `docker compose up -d --force-recreate app queue queue-email queue-broadcast scheduler discord-gateway`.

3. **Check the bucket answers**:

   ```bash
   docker compose exec -T app php artisan tinker --execute="Storage::disk('media')->put('check.txt','ok'); echo Storage::disk('media')->get('check.txt'); Storage::disk('media')->delete('check.txt');" </dev/null
   ```

   It must print `ok`.

4. **CORS**: `docker compose exec -T app php artisan media:configure-bucket media </dev/null`.

5. **First copy**, with the app still on the local disks. Safe to stop and
   re-run: files already copied are skipped.

   ```bash
   docker compose exec -T app php artisan media:migrate local media --limit=20 </dev/null   # smoke test
   nohup docker compose exec -T app php artisan media:migrate local media </dev/null > migrate-private.log 2>&1 &
   nohup docker compose exec -T app php artisan media:migrate public media_public </dev/null > migrate-public.log 2>&1 &
   ```

   `local` includes the gallery (`gallery/`), so no separate gallery copy.

6. **Switch** — in `.env`:

   ```
   MEDIA_DISK=media
   MEDIA_OUTBOUND_DISK=media_public
   MEDIA_PUBLISHED_DISK=media_public
   GALLERY_DISK=media
   MEDIA_LEGACY_DISK=local
   ```

   Recreate the PHP containers. `MEDIA_LEGACY_DISK` makes the signed link fall
   back to the local disk for any file not copied yet.

7. **Catch-up copy** — what arrived between step 5 and 6:

   ```bash
   docker compose exec -T app php artisan media:migrate local media </dev/null
   docker compose exec -T app php artisan media:migrate public media_public </dev/null
   ```

8. **Verify**:
   - both commands with `--dry-run` end with *Nothing left to copy*;
   - an old image in a conversation opens (network tab: 302 to `ewr1.vultrobjects.com`);
   - "download" and "copy image" on a chat image work;
   - an image sent to Instagram or Messenger arrives;
   - an old flow attachment URL (`…/storage/uploads/…`) still opens;
   - a new upload in a flow node returns an `ewr1.vultrobjects.com/pingly-media/public/…` URL that opens without signing;
   - a gallery file can be sent; an avatar upload shows.

9. **After a week without problems**: remove `MEDIA_LEGACY_DISK` and recreate
   the PHP containers. Then free the disk — move the local files aside first,
   delete them a few days later:

   ```bash
   cd /var/lib/docker/volumes/pingly_storage/_data
   du -sh private public
   mv private private.moved-to-bucket && mkdir private && chown 33:33 private
   mv public public.moved-to-bucket && mkdir public && chown 33:33 public
   ```

   Local `public/` files missing is fine: Caddy proxies `/storage/*` to the
   bucket's public prefix.

## Rolling back (before step 9)

Put `MEDIA_DISK=local`, `MEDIA_OUTBOUND_DISK=public`,
`MEDIA_PUBLISHED_DISK=public`, `GALLERY_DISK=local` back, remove
`MEDIA_LEGACY_DISK`, recreate the PHP containers, and copy back what was written
to the bucket meanwhile:

```bash
docker compose exec -T app php artisan media:migrate media local </dev/null
docker compose exec -T app php artisan media:migrate media_public public </dev/null
```
