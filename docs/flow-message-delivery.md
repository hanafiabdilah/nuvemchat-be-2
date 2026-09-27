# Bubble node Message: gagal kirim, retry, dan kenapa timernya pernah melar

Ditulis setelah laporan produksi 27 Sep 2026 atas Flow 16 (tenant 14, kanal API
Way): *"timer-nya tidak dihormati, dan karena funnel berisi teks, gambar, dan
audio, urutan yang terkirim kadang jadi kacau."*

## Yang TIDAK terjadi: engine tidak pernah mengacak urutan

Diperiksa lebih dulu, karena perbaikan untuk masalah yang salah lebih mahal
daripada tidak memperbaiki apa pun. Node Message mengirim bubble-nya secara
sekuensial — tiap job men-dispatch job berikutnya **setelah** kirimannya
selesai — dan dari 53 pengiriman di 28 sekuens pada log 3 hari, **nol** kasus
index mundur. Satu run bersih hari itu (conv 27990) cocok sampai detiknya:

| bubble | delay | jarak nyata |
|---|---|---|
| text | 3 s | +5 s |
| image | 5 s | +11 s |
| audio | 15 s | +18 s |
| text | 5 s | +7 s |
| text | 0 s | +0 s |

Media **tidak** menyalip teks. Yang terjadi dua hal lain.

## Sebab 1 — bubble gagal kirim dibuang diam-diam

`FlowExecutor::sendMessageItem()` dulu mencatat kegagalan lalu **melanjutkan ke
bubble berikutnya**. Log conv 27929, 06:33–06:34:

```
06:33:44 ERROR WhatsappApiwayHandler: Failed to send message
         cURL error 28: Connection timed out after 10002 ms … /v1/message/send-text
06:33:44 ERROR FlowExecutor: Error sending message node bubble {"node_id":2640,"index":0}
06:33:44 INFO  FlowExecutor: Moved to next node                 ← lanjut saja
06:34:11 ERROR FlowExecutor: Error sending message node bubble {"node_id":3028,"index":0}
06:34:43 INFO  FlowExecutor: Message sent {"node_id":3028,"index":1}   ← audio, yang PERTAMA sampai
```

Core API Way tak terjangkau ±40 detik. Sapaan dan gambar penawaran **hilang
permanen**, dan **tak ada baris `messages` yang pernah dibuat** untuk keduanya
(id 121288–121290 milik percakapan lain), jadi dashboard pun tak menunjukkan apa
pun. Pelanggan menerima audio lebih dulu — dari kursinya itulah "urutannya
kacau" — lalu funnel lanjut bertanya *"Posso enviar?"* tentang materi yang tak
pernah keluar dari gedung.

### Sekarang

1. **Retry**, tapi **hanya** untuk kegagalan yang terbukti tak pernah sampai ke
   kanal: `App\Support\Errors\TransportFailure::undelivered()` mencocokkan
   sidik jari fase-connect cURL (connect timeout, connection refused, DNS, TLS
   handshake). 3 percobaan, backoff 5 s lalu 20 s.
   ⚠️ **Read timeout sengaja TIDAK di-retry.** "Operation timed out after 30000
   ms with N bytes received" berarti request sudah dikirim dan yang hilang
   jawabannya — kanal bisa saja sudah mengantarkan pesannya. WhatsApp tak punya
   cara memberi tahu "itu sudah pernah kamu kirim", jadi percobaan kedua di
   situ = gelembung kedua di depan pelanggan, dan di funnel itu terbaca spam —
   satu-satunya kegagalan yang harganya sebuah nomor, bukan sebuah pesan.
   ⚠️ Matching-nya pada teks cURL karena itu yang selamat: tiap handler menangkap
   kegagalan transportnya lalu melempar kalimatnya sendiri, dan
   `MessageService::guard()` menerjemahkannya lagi untuk pelanggan. Aslinya
   dipertahankan sebagai `previous` di **45 titik rethrow** di
   `app/Services/Message/Handlers/` (sebelumnya dibuang total), dan rantai itu
   ditelusuri.
2. **Retry di antrean, bukan `sleep()`.** Jalur inline jalan di dalam webhook
   yang mengantar pesan pelanggan, dan webhook yang tidur adalah webhook yang
   di-retry kanal. Jadi jalur inline yang gagal **menyerahkan sisa sekuens ke
   chain, mulai dari bubble yang gagal** — bukan dari awal, yang akan mengirim
   ulang yang sudah sampai.
3. **Habis percobaan: berhenti, jangan lanjut.** `failMessageNode()` melepas
   klaim chain, menulis note `flow_message_failed` / `_reason` ke thread, dan
   men-set flow state `Failed` (bukan `Stopped` — itu berarti manusia mengambil
   percakapan dari bot, dan dua hal itu tak boleh terbaca sama di papan Live).
   Percakapan tetap **Pending**, yaitu antrean yang ditonton semua orang, dan
   note-nya yang menjelaskan ke agen kenapa thread berhenti di tengah kalimat.

⚠️ **Ini perubahan perilaku yang disengaja.** Dulu sebuah funnel kehilangan satu
gambar lalu tetap jalan; sekarang ia berhenti. Kalau kanal sebuah workspace mati,
**semua** percakapannya akan berhenti + dapat note. Itu berisik, dan memang
begitu seharusnya: sebelumnya senyap total.

## Sebab 2 — timer melar karena worker `default` tunggal mati

conv 27851: `delay 3` jadi **63 detik**. Bukan flow yang salah:

```
18:15:26 (app)   Message sequence queued {"node_id":2640}     ← delay 3s
18:15:27 (queue) App\Jobs\SyncContactPhoto ......... RUNNING  ← tak pernah DONE
18:16:28 (queue) INFO Configuration cached successfully       ← WORKER MATI & RESTART
18:16:29 (queue) FlowExecutor: Message sent                   ← 61 detik telat
```

Dari 56 start worker dalam 2 hari, 54 normal (`--max-time=3600`); **2 di luar
jadwal, keduanya persis setelah `SyncContactPhoto` RUNNING tanpa DONE.**

⚠️ **Job yang melewati timeout-nya tidak sekadar gagal.** Laravel tak bisa
melanjutkan proses dengan aman setelah alarm, jadi seluruh `queue:work`
**keluar** — dan semua job yang mengantre di belakangnya menunggu container
hidup lagi, sekitar satu menit. Penyebabnya aritmetika: anggaran HTTP di dalam
job (3 lookup × 20 s + download 30 s = 90 s untuk Telegram) melawan
`$timeout = 60` yang diketik tangan.

### Sekarang

- **`App\Services\Contact\Photo\PhotoHttp`** memegang anggarannya di satu tempat
  (lookup 8 s × maks 3 + download 15 s = 39 s), dan
  `SyncContactPhoto::$timeout` **diturunkan darinya** (`BUDGET + OVERHEAD` = 64).
  Ada tes yang menjaga turunannya **dan** yang menolak `Http::timeout(` baru di
  resolver mana pun — menaikkan timeout tanpa lewat file itu persis cara
  anggarannya menyimpang dulu.
- **`SyncContactPhoto` pindah ke antrean `media`.** Foto profil layak dimiliki
  dan tak urgen sama sekali; `default` adalah tempat bubble flow berikutnya
  menunggu.

  Terukur di produksi tepat setelah pemisahannya terpasang (27 Sep 2026,
  11:04): satu `SyncContactPhoto` untuk kontak API Way nyata selesai dalam
  **59 detik** — satu detik di bawah timeout 60 s-nya. Ia lolos sehelai rambut,
  dan pada 26 Sep dua kali tidak. Angka itu sekaligus dua hal: konfirmasi
  mekanismenya, dan alasan anggaran `PhotoHttp` (39 s) bukan kemewahan. Yang
  penting: ia jalan di `queue-media`, dan `queue` tetap `restarts=0` — 59 detik
  itu tak menyentuh satu pun bubble flow.
- **`RunFlowMessageNode` sekarang punya `$timeout = 120` sendiri.** Job tanpa
  timeout mewarisi milik worker, dan bubble media bisa wajar-wajar memakan waktu
  (URL send gagal → download → re-upload) — cukup dekat ke 60 s untuk tak
  dibiarkan mewarisi.

## Sebab 3 — worker produksi jalan tanpa `--timeout`

Ditemukan saat memeriksa yang di atas, dan **laten tapi parah**: perintah worker
adalah `queue:work --sleep=3 --tries=3 --max-time=3600` — tanpa `--timeout`,
jadi defaultnya 60 s. Tiga job di antrean `default` sudah mendeklarasikan lebih
dari itu (`RunAiAgentTurn` 240, `DownloadInboundMedia` 180,
`PublishInstagramPost` 120). Timeout milik job menang, jadi ketiganya tak pernah
dibunuh — yang dibunuh adalah job yang **tak punya** timeout sendiri.

Sekarang `--timeout=300`, sesuai syarat yang sudah lama tertulis di CLAUDE.md
tapi tak pernah terpasang di produksi.

## Perubahan ops (sudah terpasang 27 Sep 2026)

`/opt/pingly/docker-compose.yml` (hanya ada di VPS; backup bertanggal di
sebelahnya):

- `queue` → `--timeout=300` ditambahkan.
- **`queue-media`** baru → `--queue=media --timeout=300`.
- **`queue-presence`** baru → `--queue=presence --sleep=1 --tries=1 --timeout=60`.

`/opt/pingly/.env`: `MEDIA_QUEUE=media`, `AI_PRESENCE_QUEUE=presence`.
`deploy.sh` sudah menyertakan keduanya di daftar WORKERS.

⚠️ **Urutan wajib**: worker dulu, env belakangan. `MEDIA_QUEUE=media` tanpa
pembaca antrean `media` membuat semua media pesan masuk menggantung `pending`
selamanya — teksnya masuk, gambarnya tidak pernah.

## Verifikasi

```bash
# Worker default benar-benar punya timeout
docker inspect pingly-queue-1 --format '{{json .Config.Cmd}}'

# Antrean baru dikonsumsi
docker compose logs --tail 20 queue-media queue-presence

# Kematian worker di luar jadwal: tiap RESTART yang didahului RUNNING tanpa DONE
docker compose logs --timestamps --no-color queue \
  | sed 's/^queue-1  | //' \
  | awk '/Configuration cached successfully/{print "RESTART "$1"  <= last RUNNING: "last} /RUNNING/{last=$1" "$4}'

# Bubble yang tak terkirim, dan apakah ia di-retry
docker compose logs --tail 500 queue | grep -E 'retrying a message node bubble|message sequence stopped'
```

Restart per jam itu normal. **Restart di luar jadwal yang didahului sebuah job
tanpa DONE adalah bug**, dan jenisnya selalu sama: anggaran HTTP sebuah job
melewati timeout-nya sendiri.

## Rollback

- Ops: `cp docker-compose.yml.bak-<ts> docker-compose.yml`, hapus `MEDIA_QUEUE`
  dan `AI_PRESENCE_QUEUE` dari `.env`, lalu recreate. Job yang terlanjur antre di
  `media`/`presence` tak ikut pindah — kuras sekali dengan
  `php artisan queue:work --queue=media --stop-when-empty`.
- Kode: satu-satunya perubahan perilaku yang terlihat pelanggan adalah flow
  **berhenti** alih-alih melanjutkan setelah bubble yang tak terkirim. Untuk
  kembali ke perilaku lama, `failMessageNode()` harus memanggil
  `finishMessageNode()` — jangan lakukan tanpa mengganti cara lain untuk membuat
  pesan yang hilang terlihat.

## Tes

- `tests/Feature/Flow/MessageDeliveryFailureTest.php`
- `tests/Feature/Contact/ContactPhotoBudgetTest.php`
- `tests/Feature/Flow/MessageSequenceTest.php` (urutan & timer yang sudah ada)
