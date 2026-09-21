# Widget de chat: kode embed (`/widget.js`)

Sampai Set 2026 satu-satunya cara memasang Live Chat Widget adalah **npm**
(`@multichat-adslogin/chat-widget`) — artinya pelanggan harus punya aplikasi
React dan proses build. Mayoritas situs pelanggan tidak punya keduanya, dan
manual pun menyatakannya terang-terangan: *"Esta tela não mostra código para
colar no site."*

Sekarang ada jalur kedua, dan dashboard menawarkan **keduanya** (tab `Embed code` / `npm (React)` di laci koneksi). Yang baru:

```html
<script src="https://chat.pingly.com.br/widget.js" data-app-id="APP_ID" async></script>
```

Satu baris, tanpa unduhan, tanpa plugin. Parameternya cuma `app_id` milik
connection — sisanya (warna, sambutan, template, realtime) sudah dibaca widget
dari `GET /widget-api/config/{appId}` seperti sebelumnya.

## Dua file, dan kenapa harus dua

| Disajikan di | Isi | Cache |
|---|---|---|
| `/widget.js` | **loader** ±1 KB gzip | `max-age=300` |
| `/widget/pingly-chat.<hash>.js` | widget + React, ±65 KB gzip | `max-age=31536000, immutable` |

Alamat pertama ditulis ke dalam HTML **milik orang lain** dan tak pernah boleh
berubah; alamat kedua membawa hash isi, jadi aman di-cache selamanya. Rilis
widget baru karena itu cuma seharga satu revalidasi kecil per pengunjung, dan
tak ada satu pun situs yang perlu menyentuh snippet yang sudah ia tempel.

Keduanya file statis di `public/` repo ini — disajikan Caddy, tanpa PHP:

```
public/widget.js
public/widget/pingly-chat.<hash>.js
```

⚠️ **Blok Caddy-nya wajib ada.** Tanpa `@widget` di `deploy/Caddyfile`,
`/widget.js` jatuh ke SPA dan dijawab `index.html` **dengan status 200** —
situs pelanggan memuat HTML sebagai JavaScript. Karena itu urutannya:
`./deploy.sh caddy` **dulu**, baru `./deploy.sh backend`. Terbalik pun tak
fatal (file ada tapi belum dirutekan), sedangkan Caddy lebih dulu cuma
menghasilkan 404 sampai file-nya menyusul — dan 404 di bawah `/widget/*`
sengaja dikirim `no-store` supaya tak ada browser yang mengingatnya setahun.

## Sumbernya di repo lain

Kode widget hidup di repo **`chat-widget`** (di monorepo ini:
`../chat-widget`, remote `hanafiabdilah/chat-widget`). Repo itu **tidak ikut
deploy**; yang di-deploy adalah hasil build-nya yang di-commit ke sini.

```bash
cd ../chat-widget
npm run release:embed          # build + salin ke ../nuvemchat-be-2/public
cd ../nuvemchat-be-2
git add public/widget.js public/widget && git commit && git push
./deploy.sh backend
```

`release:embed` = `build:embed` (bundel → hash → loader yang menyebut hash itu)
+ `publish:embed` (salin + buang bundel lama, **menyisakan 3**). Bundel lama
sengaja dipertahankan: browser yang masih memegang loader versi sebelumnya
akan meminta bundel yang disebut loader itu, dan permintaan tsb harus tetap
dijawab selama beberapa menit sampai cache loader-nya kedaluwarsa.

## Yang dipakai widget untuk menemukan API

Loader membaca URL-nya sendiri (`document.currentScript.src`) dan menyerahkan
origin itu ke bundel sebagai base URL API. Jadi widget selalu bicara ke host
yang **menyajikannya**, bukan ke alamat yang kebetulan ter-compile di dalamnya.
Override manual: `data-api-url="…"` di tag script.

Konsekuensinya `/widget.js` dan `/widget/*` ikut diblokir di domain negara
(`@platform_only`), sebab `/widget-api` memang 404 di sana — membagikan widget
yang tak bisa menjangkau backend-nya lebih buruk daripada tidak membagikannya.

## Verifikasi setelah deploy

```bash
curl -sI https://chat.pingly.com.br/widget.js | grep -i 'HTTP\|cache-control\|content-type'
# HTTP/2 200 · cache-control: public, max-age=300 · content-type: text/javascript

curl -s https://chat.pingly.com.br/widget.js | grep -o 'widget/pingly-chat\.[a-f0-9]*\.js'
curl -sI https://chat.pingly.com.br/widget/pingly-chat.<hash>.js | grep -i 'HTTP\|cache-control'
# HTTP/2 200 · cache-control: public, max-age=31536000, immutable

curl -sI https://chat.pingly.id/widget.js | head -1     # HTTP/2 404 — memang
```

Uji visual tanpa menyentuh situs siapa pun: `chat-widget/embed-demo.html`
(halaman yang sengaja "bermusuhan" — root font 62.5%, sticky header z-index
999999, `button { … !important }`).

## Percakapan baru lahir saat pengunjung menulis

Jalur embed memakai `deferSession: true` pada adapter: boot **memulihkan**
session yang sudah ada di browser pengunjung, tapi `POST /widget-api/session`
baru dipanggil pada pesan pertama.

Alasannya operasional. `initSession` membuat baris `contacts` + `conversations`,
dan boot lama memanggilnya di **setiap page view** — satu workspace produksi
mengumpulkan 4.591 percakapan kosong (lihat catatan Live monitor & funil di
CLAUDE.md). Dengan snippet yang bisa dipasang siapa saja di situs bertrafik
tinggi, angka itu hanya akan naik. Paket npm tidak berubah (`deferSession`
default `false`).

Efek yang terlihat: **tidak ada**, karena inbox, Live monitor, dan funil sudah
menyaring percakapan tanpa pesan. Yang berubah cuma jumlah baris yang tak
pernah dibaca siapa pun.
