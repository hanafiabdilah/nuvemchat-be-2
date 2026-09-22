# Health page & heartbeats

Back Office → **Health** menjawab satu pertanyaan yang sebelumnya tak bisa
dijawab dari dalam produk: *apakah proses latar masih hidup?*

Sebagian besar yang menjalankan platform ini tak pernah melayani satu pun
request HTTP — daemon `discord:gateway`, dua worker antrean, dan scheduler yang
memperbarui langganan API Way, menghapus media, dan memompa kampanye. Saat salah
satunya mati, produk tetap **terlihat** sehat: percakapan cuma berhenti
berdatangan, atau jendela renewal lewat dan ProxyBR mencabut instance secara
permanen (tanpa grace). Gejalanya muncul berhari-hari kemudian sebagai tiket
support.

## Cara kerjanya

Setiap proses menulis satu baris ke `system_heartbeats` (`name` unik, ditimpa di
tempat) lewat `App\Support\Heartbeat::ping()`. Halaman Health membaca baris itu
dan membandingkannya dengan **interval yang diharapkan** — yang didaftarkan di
`Heartbeat::PROCESSES`.

Interval itulah yang mengubah timestamp jadi vonis. Tanpanya pembaca tak bisa
tahu apakah "terakhir jalan 40 menit lalu" itu normal (job per jam) atau bencana
(daemon 30 detik).

| Vonis | Arti |
|---|---|
| `ok` | Terakhir ping ≤ 1× interval |
| `late` (warn) | ≤ 3× interval — jitter di bawah beban, bukan alarm |
| `down` | > 3× interval — tak ada proses sehat yang sampai ke sini |
| `unknown` | **Belum pernah** ping |

`unknown` sengaja bukan `down`: pada deploy baru belum ada yang check-in, dan
meneriakkan "outage" di menit pertama tiap instalasi adalah cara tercepat
membuat orang mengabaikan halaman ini.

## Menambah proses baru

1. Panggil `Heartbeat::ping('nama')` di awal `handle()` (bukan di akhir — sebuah
   pass yang tak menemukan pekerjaan adalah kasus **sehat**, dan tetap
   membuktikan prosesnya jalan).
2. Daftarkan di `Heartbeat::PROCESSES` dengan label, interval, dan **kenapa itu
   penting** — kalimat terakhir itu yang dibaca operator saat memutuskan apakah
   perlu bangun jam 3 pagi.

Proses yang ping tanpa terdaftar tetap tersimpan, hanya tampil tanpa vonis.

Worker antrean tak dipanggil per-job (satu worker yang mengeruk backlog akan
mengubah satu baris status jadi ribuan write per menit) — mereka memakai
`Heartbeat::throttledPing()` dari listener `JobProcessed` di `AppServiceProvider`,
maksimal sekali per menit, **di-key per antrean**: `default` dan `broadcasts`
jalan sebagai service terpisah dan mati terpisah.

## Yang dipantau hari ini

| Nama | Sumber | Interval |
|---|---|---|
| `scheduler` | closure `Schedule::call` di `routes/console.php` | 120 s |
| `queue:default`, `queue:broadcasts` | listener `JobProcessed` | 300 s |
| `discord:gateway` | loop reconcile daemon | 90 s |
| `broadcasts:tick` | command watchdog | 180 s |
| `media:purge` | command | 2 j |
| `apiway:renew`, `apiway:sync` | command | 2 j |
| `emails:fetch` | command | 15 mnt |

Selain heartbeat, halaman ini juga memeriksa hal-hal yang bisa dibaca langsung
dari DB: kedalaman antrean, `failed_jobs` 24 jam terakhir, kampanye yang macet
(`last_tick_at` basi), langganan API Way yang mendekati/melewati expiry, mailbox
yang berhenti disinkronkan, dan koneksi yang memegang kredensial tapi tidak
aktif (biasanya token dicabut).

Ditambah empat pemeriksaan **konfigurasi** — bukan proses, melainkan setelan
yang tak punya gejala sampai seseorang memanfaatkannya:

| Pemeriksaan | Kapan merah |
|---|---|
| `webhook:chat-secrets` | koneksi Telegram/API Way yang webhook-nya masih bisa dipanggil siapa pun |
| `webhook:meta-secrets` | app secret Meta kosong → verifier fail-closed akan menghentikan webhook |
| `admin:two-factor` | akun Back Office tanpa faktor kedua |
| `platform:debug` | **lihat di bawah** |

### ⚠️ `platform:debug`

Dua setelan yang tak berbahaya di mana pun kecuali di produksi:

- **`APP_DEBUG=true` → `down`.** Ini bukan setelan verbositas, ini penyingkapan:
  halaman debug Laravel mencetak stack, query yang gagal beserta bindings-nya,
  dan **seluruh environment** — kata sandi database, `APP_KEY`, tiap kredensial
  kanal, kunci payment service. Siapa pun yang bisa membuat aplikasi ini
  melempar bisa membacanya, dan membuat aplikasi web melempar itu tidak sulit.
- **`LOG_LEVEL=debug` → `warn`.** Versi yang lebih kecil dari masalah yang sama,
  dan mudah tercapai tanpa sengaja karena **default di `config/logging.php`
  memang `debug`**: jalur pesan masuk mencatat payload webhook lengkap di level
  itu, jadi isi pesan dan nomor telepon pelanggan mendarat di berkas yang panel
  ini bisa mengunduhnya.

Keduanya dibaca dari config yang **sedang hidup**, bukan dari berkas `.env` di
disk: yang penting adalah dengan apa proses ini boot, dan `.env` diedit di
server tanpa ada yang di sini menyadarinya.

⚠️ Di luar produksi (`APP_ENV` bukan `production`) pemeriksaan ini **diam
hijau**, bukan kuning. Instalasi lokal dan staging memang seharusnya jalan
dengan debug menyala, dan peringatan permanen di semuanya persis cara halaman
ini mulai diabaikan di kotak yang benar-benar penting — alasan yang sama dengan
`unknown` vs `down` pada proses.

## Prasyarat ops

Tak ada yang baru. Halaman ini hanya **membaca** — kalau supervisor sudah
menjalankan `discord:gateway`, `queue`, `queue-broadcast` dan `schedule:run`
seperti sebelumnya, semuanya langsung hijau dalam satu interval. Yang berubah:
kalau salah satunya **tidak** jalan, sekarang ada tempat yang mengatakannya.
