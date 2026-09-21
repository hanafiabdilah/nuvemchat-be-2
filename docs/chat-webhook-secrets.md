# Rahasia webhook percakapan (`/webhook/chat/{id}`)

`POST /webhook/chat/{id}` adalah jalur masuk pesan untuk **Telegram** dan
**WhatsApp API Way**. Sampai rilis ini endpoint tersebut **tidak memverifikasi
apa pun**: tanpa tanda tangan, tanpa token, dan `{id}`-nya adalah primary key
`auto_increment` — siapa pun yang bisa menghitung dari 1 dapat menyuntikkan
pesan ke kotak masuk workspace mana pun, memalsukan identitas kontak yang sudah
ada di sana, dan menjalankan mesin alur dengan saldo pra-bayar tenant.

Sekarang setiap koneksi punya rahasianya sendiri
(`connections.credentials.webhook_secret`, 48 karakter acak) dan pengirim harus
menyertakannya:

| Kanal | Cara membawa rahasia | Alasan |
|---|---|---|
| Telegram | Header `X-Telegram-Bot-Api-Secret-Token`, disetel lewat parameter `secret_token` pada `setWebhook` | Header, jadi rahasianya tidak pernah masuk access log proksi |
| WhatsApp API Way | Segmen terakhir URL: `/webhook/chat/{id}/{secret}` | Core hanya menyimpan sebuah alamat — tak ada header yang bisa ditandatangani |

Keduanya diterima untuk kanal mana pun: **buktinya adalah mengetahui rahasia**,
sedangkan di mana ia dititipkan adalah keterbatasan pengirim, bukan aturan kita.

Kode: `App\Services\Webhook\ChatWebhookSecret`.

---

## ⚠️ Kenapa ada jalur yang masih dibiarkan lewat

Setiap koneksi yang hidup hari ini sudah mendaftarkan webhook-nya ke Telegram /
core **tanpa** rahasia. Menolak mereka begitu kode ini naik berarti **pesan
pelanggan sungguhan berhenti masuk untuk seluruh workspace Telegram dan API Way
sekaligus**.

Karena itu aturannya per-koneksi, bukan global:

- koneksi yang **sudah punya** rahasia → wajib menyertakannya, kalau tidak `401`;
- koneksi yang **belum punya** → tetap dilayani, dan setiap kali dicatat
  `Log::warning('Chat webhook accepted without a secret — run webhooks:secure-chat')`.

Jadi celahnya tertutup satu koneksi demi satu seiring perintah di bawah
dijalankan, bukan menunggu seseorang ingat menekan saklar.

---

## Urutan penerapan di produksi

### 1. Deploy kode

Tidak mengubah apa pun untuk lalu lintas yang sedang berjalan. Koneksi baru yang
dibuat setelah ini langsung punya rahasia (diset saat `connect()`).

### 2. Amankan koneksi yang sudah ada

```bash
docker compose exec -T app php artisan webhooks:secure-chat --dry-run
docker compose exec -T app php artisan webhooks:secure-chat
```

Untuk tiap koneksi aktif: daftarkan rahasia ke **hulu dulu**, baru simpan.
Urutan ini tidak boleh dibalik — rahasia yang tersimpan sementara pengirimnya
tidak pernah diberi tahu adalah satu-satunya keadaan yang menghilangkan pesan
sungguhan.

Idempoten: koneksi yang sudah aman dilewati. Jalankan ulang kapan saja.

**Keluaran `fail` itu normal dan bukan alasan menunda:** instance API Way yang
tidak ter-pair tidak menjawab apa pun, jadi tidak bisa diamankan sampai
seseorang mem-pair-nya — dan jalur masuknya memang sedang mati. `connect()`
akan mengamankannya begitu di-pair.

### 3. Cek Back Office → Health

Baris **"Chat webhook secrets"** harus hijau (`All secured`). Selama masih
kuning, `meta.rows` menyebut koneksi mana yang tersisa beserta tenant-nya.

### 4. Nyalakan mode ketat

Di `/opt/pingly/.env`:

```
WEBHOOK_CHAT_STRICT=true
```

lalu `up -d --force-recreate` **semua** container PHP (env_file hanya dibaca
saat container start).

Setelah ini, koneksi tanpa rahasia ditolak — dan baris Health yang sama berubah
jadi **merah** bila masih ada, karena pada titik itu koneksi tak teramankan
memang berarti kotak masuk yang mati.

---

## Rotasi

```bash
php artisan webhooks:secure-chat --connection=42 --rotate
```

Dipakai bila sebuah rahasia dicurigai bocor. Untuk API Way, rahasia ada di URL
sehingga ikut tercatat di access log Caddy — itulah alasan nilainya per-koneksi
dan bisa dirotasi, bukan satu nilai platform.

Menukar instance (`switchInstance`) **selalu** merotasi dengan sendirinya:
`linkInstance()` tidak mempertahankan `webhook_secret`, jadi instance yang
ditinggalkan tidak lagi bisa menulis ke kotak masuk ini dengan URL terakhirnya.

---

## Pembatasan laju

Rute ini kini `throttle:webhook-chat` — **600/menit per `connection_id`**,
bukan per IP. Telegram dan core API Way masing-masing mengirim lalu lintas
seluruh workspace dari segelintir alamat, jadi jatah per-IP justru akan mulai
membuang pesan pelanggan sungguhan tepat saat platform sedang ramai. Sepuluh
pesan per detik jauh di atas laju nyata satu kotak masuk, dan tetap membatasi
berapa banyak yang bisa dipaksakan ke satu koneksi sebelum pemeriksaan rahasia
dijalankan.

---

## Verifikasi cepat

```bash
# harus 401
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
  https://chat.pingly.com.br/webhook/chat/<ID_KONEKSI_TELEGRAM> \
  -H 'Content-Type: application/json' -d '{"message":{"text":"tes"}}'
```

Di log: `grep 'Chat webhook rejected'` (ditolak) dan
`grep 'Chat webhook accepted without a secret'` (masih menunggu diamankan).

Tes: `tests/Feature/Webhook/ChatWebhookAuthTest.php`.
