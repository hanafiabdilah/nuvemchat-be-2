# Country domains

One install, one server, one deploy serve every country. Each country gets a
domain of its own for the dashboard; everything a system outside the platform
calls stays on the platform host, `chat.pingly.com.br` (`PLATFORM_URL`).

| Served on | What |
|---|---|
| Platform host only | Back Office (`/webmin`, `/api/admin`), webhooks, OAuth callbacks, widget API, signed links (`/gallery`, `/flow-payments`, `/flow-invoices`), media (`/storage`) |
| Platform host **and** country domains | Dashboard (SPA, `/manual`), dashboard API (`/api`), `/up` |

On a country domain the platform-only paths answer **404** twice over: Caddy
(`deploy/Caddyfile`, snippet `country_site`) never passes them to PHP, and
Laravel's `EnsurePlatformHost` answers the same if one slips through.

## Prerequisites

- `PLATFORM_URL=https://chat.pingly.com.br` is set in `/opt/pingly/.env` and
  BO → Health → "Endereço da plataforma" is green. Without it, a webhook
  registered from a country dashboard points at the country domain, where
  `/webhook` is blocked.
- The market exists: **BO → Markets → Open a market**. Pick the country; its
  currency, calling code and timezones are filled in. The currency can't be
  changed once the first workspace joins, so check it before anyone signs up.

## Adding a country domain

Order matters: Caddy asks for the TLS certificate on reload, which fails until
DNS resolves to the server.

1. **DNS** — an `A` record for the domain pointing at the VPS (the same address
   `chat.pingly.com.br` resolves to). Wait until `dig +short <domain>` returns it.
2. **Caddy** — add a block at the end of `nuvemchat-be-2/deploy/Caddyfile`:

   ```
   app.pingly.id {
       import country_site
   }
   ```

   Commit, push, then from the monorepo root: `./deploy.sh caddy`. It validates
   inside the container before touching the live file, keeps a backup
   (`Caddyfile.bak-deploy-*`), reloads, and checks `/up`.
3. **Market domain** — BO → Markets → the market → *Add domain*. From then on
   signups there join the market and the 404 guard treats it as a country
   domain. The first domain becomes the market's primary. The platform address
   and a domain another market holds are refused.

   (Outside the Back Office, use the `MarketDomain` model, never the query
   builder: saving the model flushes the cached domain map. With the query
   builder, call `App\Services\Market\MarketResolver::flush()` afterwards.)
4. **Meta app** — add the domain to *App Domains* and to *Allowed Domains for
   the JavaScript SDK*. WhatsApp Embedded Signup runs `FB.login` on the page the
   customer is on, and Meta refuses it on an unlisted domain.

## Checking it

BO → Markets → the market → *Check* next to the domain asks it
`/api/public/bootstrap` over the public internet and names the missing step:
unreachable (DNS or the Caddy block), an HTTP error (the API isn't served), or
answering as another market. The same by hand:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://app.pingly.id/                        # 200
curl -s https://app.pingly.id/api/public/bootstrap | jq .data.market.code              # "ID"
curl -s -o /dev/null -w '%{http_code}\n' https://app.pingly.id/webhook/facebook        # 404
curl -s -o /dev/null -w '%{http_code}\n' https://app.pingly.id/webmin/                 # 404
curl -s -o /dev/null -w '%{http_code}\n' https://chat.pingly.com.br/webhook/facebook   # 403 (reaches PHP)
```

## Removing a country domain

Remove the Caddy block and deploy it, remove the domain in BO → Markets, and
remove the domain from the Meta app. Workspaces in that market are untouched —
they keep their market and can still use the platform host.

## Rolling back a bad Caddyfile

```bash
ssh pingly
cd /opt/pingly
ls -t Caddyfile.bak-deploy-* | head -1          # the backup the last deploy made
cp Caddyfile.bak-deploy-<ts> Caddyfile          # cp, never mv: same inode
docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
```

Then fix `deploy/Caddyfile` in the repo, or the next `./deploy.sh caddy` puts
the broken version back.
