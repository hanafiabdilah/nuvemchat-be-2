# Header keamanan

## Masalahnya

Pencarian terhadap `Content-Security-Policy`, `X-Frame-Options`,
`Strict-Transport-Security`, `X-Content-Type-Options`, dan `Referrer-Policy` di
Caddyfile produksi, middleware backend, dan kedua `index.html` **tidak
menghasilkan satu pun kecocokan**. Satu-satunya CSP di seluruh sistem terpasang
di `MediaFileController` untuk media privat — jadi kontrolnya dipahami, hanya
belum dipasang secara global.

Origin `chat.pingly.com.br` melayani tiga hal sekaligus:

| Jalur | Isi |
|---|---|
| `/` | dashboard tenant |
| `/webmin` | **Back Office** |
| `/storage/*` | berkas yang diunggah pelanggan |

Token sesi kedua SPA ada di `localStorage`. Kombinasi itulah yang mengubah
unggahan tanpa validasi menjadi **pengambilalihan platform**, bukan sekadar
halaman yang dirusak. Dan tanpa `X-Frame-Options`, `/webmin` bisa disematkan di
iframe transparan lalu administratornya dipancing mengklik tindakan destruktif.

## Yang dipasang

Snippet `(security_headers)` di `deploy/Caddyfile`, di-`import` oleh host
platform **dan** setiap domain negara.

| Header | Nilai | Kenapa |
|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Kunjungan pertama lewat HTTP rentan *SSL stripping* |
| `X-Content-Type-Options` | `nosniff` | Yang menahan *polyglot* — berkas yang sekaligus GIF sah dan HTML sah |
| `X-Frame-Options` | `DENY` | *Clickjacking* terhadap `/webmin` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | URL bertanda tangan (media, galeri, nota) bocor lewat `Referer` |
| `Permissions-Policy` | `geolocation=(), camera=(), microphone=(self), payment=()` | ⚠️ `microphone=(self)` **bukan hiasan** — composer merekam voice note |
| `Content-Security-Policy-Report-Only` | lihat berkasnya | **Diamati, belum ditegakkan** |

## ⚠️ Kenapa CSP-nya report-only

Dua skrip yang dimuat aplikasi ini **dipilih saat runtime** dan tidak bisa
dibaca dari kode:

1. **SDK tokenisasi kartu** — host-nya tergantung gateway mana yang mencetak
   sesi pembayaran (`lib/paymentSdk.ts` memuatnya secara dinamis).
2. **SDK Meta** (`connect.facebook.net`) — hanya disuntikkan saat seseorang
   membuka popup Embedded Signup WhatsApp.

Menegakkan kebijakan yang belum pernah melihat keduanya akan **merusak checkout
dan onboarding kanal** — diam-diam, dan pertama-tama pada pelanggan yang
kebetulan mencoba lebih dulu. Karena itu ia diamati dulu.

Sumber yang sudah diketahui dan sudah masuk kebijakan:

```
script-src   'self' sdk.mercadopago.com connect.facebook.net
style-src    'self' 'unsafe-inline' fonts.googleapis.com
font-src     'self' data: fonts.gstatic.com
connect-src  'self' wss://ws.pingly.com.br ewr1.vultrobjects.com …
frame-src    connect.facebook.net www.facebook.com
```

`'unsafe-inline'` pada `style-src` masih dibutuhkan: Tailwind dan beberapa
komponen menulis gaya inline (mis. host widget, `chart.tsx`). Menghapusnya
adalah pekerjaan tersendiri, bukan bagian dari perbaikan ini.

## Cara mempromosikannya jadi penegakan

1. **Deploy** `./deploy.sh caddy`.
2. **Amati.** Tanpa endpoint pelaporan, pelanggarannya muncul di console browser
   sebagai `[Report Only] Refused to load …`. Lewati jalur yang jarang dipakai
   dengan sengaja: checkout kartu, popup Embedded Signup WhatsApp, unggah media,
   perekaman audio, halaman Statistik (grafik), Webmail (HTML e-mail),
   `/manual`.
3. **Tambahkan** sumber yang sah ke kebijakan, jalankan `./deploy.sh caddy` lagi.
4. Setelah beberapa hari tanpa pelanggaran baru, ganti nama headernya dari
   `Content-Security-Policy-Report-Only` menjadi `Content-Security-Policy`.

⚠️ **Jangan lompat ke langkah 4.** Pelanggaran yang belum terlihat akan muncul
sebagai fitur yang mati tanpa pesan galat — dan yang paling mungkin mati adalah
pembayaran.

## Yang tidak dipasang, sadar

- **Tidak ada endpoint `report-uri`/`report-to`.** Menerima laporan CSP berarti
  satu endpoint publik lagi yang menulis ke basis data — yaitu kelas masalah
  yang baru saja ditutup di tempat lain (lihat `docs/chat-webhook-secrets.md`).
  Console browser cukup untuk satu periode pengamatan; endpoint pelaporan layak
  dibangun hanya kalau CSP akan dirawat terus-menerus.
- **Token masih di `localStorage`.** CSP mempersulit pencuriannya, tidak
  mencegahnya. Memindahkannya ke cookie `HttpOnly` adalah perbaikan yang
  sebenarnya — dan **wajib didahului** mempersempit `config/cors.php`, yang saat
  ini `allowed_origins: ['*']`. Menggabungkan keduanya tanpa itu justru
  menciptakan kerentanan CSRF yang hari ini tidak ada.
