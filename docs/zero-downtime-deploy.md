# Deploy backend tanpa downtime

Deploy backend dulu **menolak pesan masuk**. Bukan melambatkannya — menolaknya,
dengan 502, dan untuk sebagian kanal itu berarti pesannya hilang selamanya.

`docker compose up -d --force-recreate app` **menghapus** container `app` sebelum
membuat penggantinya, dan begitu container itu hilang namanya ikut hilang dari
DNS internal Docker. Caddy (`php_fastcgi app:9000`) lalu tidak gagal dengan
"connection refused" seperti yang diduga, melainkan dengan **`lookup app: no
such host`** — 267 dari 336 kegagalan.

Terukur dari log Caddy (2 minggu, sebelum perbaikan ini):

| | |
|---|---|
| Jendela deploy tercatat | **38** |
| Durasi per jendela | **2–14 detik** |
| `POST /webhook/*` ditolak | **336** |
| Terbanyak | 2 koneksi API Way aktif, WA Official, Instagram, Telegram |

Yang menentukan apakah sebuah pesan benar-benar hilang adalah pengirimnya. Meta
(WhatsApp Official, Instagram, Messenger) dan Telegram me-retry, jadi di sana
502 hanya berarti tertunda. **Core API Way (whatsmeow, ProxyBR) tidak** — dan
itu kanal terbesar di sini, jadi setiap 502 di `/webhook/chat/{id}` adalah satu
pesan pelanggan yang tak pernah sampai ke inbox.

## Hasil

Diukur dengan menembak `/up` dan `/webhook/*` ≈6,7×/detik sambil menukar **kedua**
container, memakai urutan rolling yang sekarang dipakai `deploy.sh`:

| | sebelum | sesudah |
|---|---|---|
| `POST /webhook/*` gagal | ±79 (jendela 11,8 dtk × laju itu) | **2** |
| `GET` gagal | ±79 | **0** |
| Request tertahan | — | tak ada (terlambat 0,37 dtk) |

Dua yang tersisa bukan sisa yang bisa dihapus dari sini; alasannya di bagian
*Batas yang tidak bisa dilewati* di bawah.

---

## Apa yang berubah

Tiga hal, di tiga tempat. Dua pertama ter-versioned di repo ini; yang ketiga
hidup di VPS dan **harus dipasang manual** (bagian berikutnya).

**1. `deploy/Caddyfile` — snippet `(laravel)`: dua upstream.**

```
php_fastcgi app:9000 app-2:9000 {
    lb_policy round_robin
    fail_duration 3s
    lb_try_duration 5s
    lb_try_interval 150ms
    lb_retry_match {
        method GET HEAD OPTIONS
    }
}
```

Empat keputusan di situ, semuanya hasil pengukuran, bukan selera:

- **`round_robin`, bukan `least_conn`.** Saat retry, Caddy memilih upstream lagi
  lewat policy yang sama, dan container yang mati memegang **nol** koneksi —
  jadi `least_conn` terus memilih yang mati dan retry-nya tak pernah keluar.
- **`fail_duration` pendek (3s).** Ia memarkir container setelah satu dial
  gagal, sehingga request berikutnya langsung ke saudaranya.
- ⚠️ **`lb_try_duration` pendek (5s), dan ini penting.** `errNoUpstream` — semua
  upstream terparkir sekaligus — di-retry **tanpa pagar method sama sekali**,
  jadi try_duration yang panjang mengubah gangguan sekejap menjadi *setiap*
  request menggantung selama itu. Pada 20s hal ini sudah terjadi di produksi:
  request ditahan 20,08 detik lalu 503. Itu jauh lebih buruk daripada satu 502
  cepat. Jendela yang perlu ditutup adalah restart container, dan container
  kembali dalam ~2 detik.
- **`lb_retry_match { method GET HEAD OPTIONS }`** memulihkan perilaku default
  Caddy. Mendefinisikan `lb_retry_match` **menggantikan** default, bukan
  menambahnya: tanpa set ini, sebuah GET pun berhenti di-retry.

**2. `deploy.sh` — urutan baru.** Urutannya bukan selera: tiap langkah memilih
versi mana yang melayani sementara langkah itu berjalan.

```
1. build image                     ← tak ada trafik terpengaruh
2. migrate (image BARU, one-off)   ← kode LAMA masih melayani
3. recreate worker                 ← worker tak pernah lebih tua dari yang men-dispatch
4. recreate app, tunggu FPM, jeda 5s
   recreate app-2, tunggu FPM, jeda 5s
5. optimize:clear + config:cache
6. verifikasi /up == 200
```

Tiga hal yang mudah dibalik dan ketiganya salah kalau dibalik:

- **Migrate sebelum tukar kode**, dari container sekali-pakai yang sudah memakai
  image baru. Kebalikannya — migrate setelah kode ditukar, seperti sebelumnya —
  membuat kode **baru** melayani di atas schema **lama** selama migration
  berjalan, dan jendela itu selama migration-nya: bisa jauh lebih lama dari 14
  detik. Container `run` itu sengaja tanpa `--use-aliases`: tanpa flag itu ia
  tidak mendapat alias DNS `app`, jadi Caddy tak akan pernah mengirim request ke
  container yang sedang menjalankan artisan dan tak punya PHP-FPM.
- **Worker sebelum FPM.** Satu invariant yang harus selalu benar: kode baru
  wajib bisa memproses job yang di-enqueue kode lama, karena job memang
  mengantre melintasi deploy. Kebalikannya tak bisa dijamin — worker lama tak
  punya job class yang baru ditambahkan, dan job ber-`$tries=1` (giliran AI,
  batch campanha) hilang di percobaan pertama.
- ⚠️ **Jeda 5 detik setelah sebuah container siap, sebelum menyentuh yang
  berikutnya.** Caddy masih memarkir container itu selama `fail_duration`, jadi
  tepat setelah ia kembali ia masih di luar rotasi. Melanjutkan sekarang
  membuat **keduanya** di luar rotasi sekaligus, dan gejalanya bukan satu
  request gagal melainkan semua request menggantung lalu 503.

Setelah tiap container FPM di-recreate, deploy **menunggu FPM benar-benar
menerima koneksi** (probe `fsockopen` ke 127.0.0.1:9000 dari dalam container itu
— `php` selalu ada di image ini, jadi tak menambah dependensi).

`config:cache` di langkah 5 memperbaiki regresi yang sudah lama ada:
`optimize:clear` membuang config cache yang dibuat entrypoint saat container
start, jadi sebelum ini produksi jalan **tanpa** config cache sampai deploy
berikutnya. `optimize:clear` tetap dipanggil karena itu yang membersihkan cache
aplikasi di Redis (setting platform, entitlement) — itu memang gunanya.

**3. `/opt/pingly/docker-compose.yml` — container FPM kedua + grace period.**

---

## Batas yang tidak bisa dilewati

⚠️⚠️ **Caddy tidak dapat me-retry request non-GET lewat transport FastCGI.**
Ditulis di sini supaya tak ada yang menghabiskan satu malam lagi untuk itu.
Bukan dengan `lb_try_duration`, bukan dengan `lb_retry_match` (matcher apa pun,
termasuk yang cocok dengan segalanya), bukan dengan `request_buffers`. Ketiganya
diukur di lab terisolasi, satu upstream dihentikan, 40 POST tiap varian: **3
dari 40 tetap 502 dan yang paling lambat 0,005 detik** — tak satu pun retry
pernah dicoba.

Aturan yang didokumentasikan Caddy ("kegagalan dial selalu di-retry, apa pun
method-nya") benar untuk transport **HTTP**, yang membungkus kegagalan dial-nya
dalam `DialError`. Transport **FastCGI** men-dial di dalam `RoundTrip`-nya
sendiri dan mengembalikan error biasa, jadi `tryAgain` jatuh ke pagar yang hanya
me-retry GET.

Akibatnya kecil dan jujur: ketika sebuah container di-recreate, request
**pertama** yang kebetulan memilihnya gagal. `fail_duration` lalu memarkirnya
dan semua request setelahnya ke saudaranya — jadi kira-kira **satu POST gagal
per container yang diganti, sekitar dua per deploy**. GET/HEAD/OPTIONS tertutup,
karena yang itu memang di-retry Caddy.

**Menutup sisa itu adalah pekerjaan pengirim, bukan pekerjaan di sini.** Meta dan
Telegram sudah me-retry 502 sendiri. Untuk core API Way yang tidak, perbaikannya
satu retry di sisi ProxyBR — layak diminta, karena mereka juga yang kehilangan
pesan saat jaringan mereka sendiri tersendat, bukan hanya saat kita deploy.

### Yang juga masih bukan zero-downtime

- **`discord-gateway`**: inbound Discord bukan webhook melainkan daemon Gateway.
  Selama container itu di-recreate, DM yang tiba **hilang** — Gateway tidak
  memutar ulang event tanpa RESUME, dan implementasi di sini tidak memakainya.
- **`reverb`**: recreate memutus semua WebSocket. Itu bukan kehilangan pesan —
  klien reconnect dan SPA menarik delta yang terlewat (`runCatchUp()`) — tapi
  realtime terputus beberapa detik.
- **Migration**: lihat aturannya di bawah.
- **`rsync --delete` ke `src/`**: Caddy mem-bind-mount `./src` untuk file statis
  dan `try_files`. Selama rsync ada jendela sangat singkat di mana ia melihat
  pohon file setengah. Tak menyentuh `/api` maupun `/webhook` (keduanya jatuh ke
  `index.php`), tapi satu aset statis bisa 404 sekejap.

---

## Memasang di VPS

`docker-compose.yml` hidup di VPS, bukan di repo ini, jadi langkah ini manual.
`deploy.sh` sudah mengecek keberadaan `app-2`: selama belum ada, deploy tetap
jalan seperti sebelumnya dan mencetak peringatan bahwa jendela 502 itu masih
ada — ia tidak diam-diam berperilaku beda dari namanya.

**Urutannya penting**: `app-2` harus ada *sebelum* Caddyfile menyebutnya.

### 1. Backup

```bash
cd /opt/pingly
cp docker-compose.yml docker-compose.yml.bak-zerodowntime-$(date +%Y%m%d-%H%M%S)
```

### 2. Tambahkan `app-2`, tepat setelah service `app`

```yaml
  # Second PHP-FPM container -- identical to `app`, behind the same Caddy load
  # balancer (the (laravel) snippet in Caddyfile). It exists so a deploy can
  # recreate the two one at a time: while one is being replaced the other keeps
  # accepting FastCGI.
  #
  # No `build:` on purpose: it runs the image that `app` builds, the same way
  # queue/reverb/scheduler already do, so a deploy still produces exactly one
  # image. And `app` keeps its name so every runbook and every one-off
  # `docker compose exec app php artisan ...` keeps working.
  app-2:
    <<: [*app-common, *app-broadcaster]
    expose:
      - "9000"
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
```

`app` **tetap bernama `app`** — bukan `app-a` — supaya setiap runbook dan setiap
`docker compose exec app php artisan …` di dokumentasi ini tetap bekerja.

### 3. Beri tahu caddy soal upstream baru

```yaml
    depends_on:
      app:
        condition: service_started
      app-2:              # ← tambahkan
        condition: service_started
      reverb:
        condition: service_started
```

### 4. Beri worker waktu menyelesaikan job-nya

Tambahkan ke `queue`, `queue-email`, `queue-broadcast`, dan `scheduler`:

```yaml
    stop_grace_period: 60s
```

Default Docker 10 detik, dan `queue:work` hanya berhenti **di antara** job: saat
SIGTERM ia menyelesaikan job yang dipegangnya lalu keluar. Jadi apa pun yang
lebih lama dari 10 detik dibunuh di tengah jalan pada setiap deploy — satu
giliran AI (`RunAiAgentTurn`, `$tries=1`) mati tanpa pernah menjawab pelanggan.
60 detik adalah kompromi yang disengaja: ia menutup mayoritas besar job tanpa
membuat deploy menunggu timeout terpanjang yang mungkin (240 detik untuk giliran
AI, 300 untuk batch campanha). Deploy hanya menunggu kalau memang ada job yang
sedang jalan, dan Compose menghentikan service-service ini paralel.

### 5. Validasi, naikkan, lalu pasang Caddyfile

```bash
cd /opt/pingly
docker compose config -q && echo "compose VALID"   # jangan `config` tanpa -q: ia mencetak seluruh .env
docker compose up -d --no-deps app-2                # aditif — tak menyentuh container lain
docker compose ps
```

Lalu dari monorepo, di mesin sendiri:

```bash
./deploy.sh caddy     # validasi di container → backup → pasang → reload → cek /up
```

---

## Aturan yang ikut: migration wajib backward-compatible

Ini harga dari zero-downtime, dan satu-satunya bagian yang tak bisa dijamin oleh
skrip. Karena `migrate` sekarang jalan **sementara kode lama masih melayani**,
setiap migration harus aman bagi kode yang belum tahu tentangnya:

- **Boleh**: tambah tabel, tambah kolom nullable (atau ber-default), tambah
  index, tambah enum value.
- **Jangan dalam satu deploy**: `drop`/`rename` kolom atau tabel yang masih
  dibaca kode lama, menyempitkan tipe, menambah kolom `NOT NULL` tanpa default.
  Pecah jadi dua rilis — tambahkan yang baru sekarang, pindahkan pembacanya,
  buang yang lama di deploy berikutnya. Contoh nyata di repo ini: rename
  `usd_brl_rate` → `usd_rate` akan mematahkan kode lama selama migration
  berjalan kalau dikirim sebagai satu langkah.
- **Tetap berbahaya kapan pun, terlepas dari urutan deploy**: migration yang
  **mengunci tulis** pada tabel besar. FULLTEXT pertama di `messages`
  (`2026_09_07_000400`) memaksa rebuild tabel InnoDB dan memblokir tulis selama
  menit-menit — tiap webhook masuk menunggu. Jalankan yang seperti itu manual,
  di jendela pemeliharaan, **sebelum** deploy.

Kalau sebuah rilis memang menuntut migration yang tak backward-compatible dan
tak bisa dipecah, jalankan deploy dengan `--no-migrate` lalu urus migration-nya
sendiri dengan mata terbuka.

---

## Verifikasi

Bahwa jendelanya benar-benar hilang, bukan hanya menyempit. Dari VPS, tembak
kedua jalur sambil menukar kedua container — persis urutan `deploy.sh`:

```bash
cd /opt/pingly
R="--resolve chat.pingly.com.br:443:127.0.0.1"
B=https://chat.pingly.com.br
: > /tmp/wh.txt; : > /tmp/get.txt

# terminal 1 — /webhook/zdt-probe tidak ada route-nya: 404 dari Laravel,
# jadi ia membuktikan request sampai ke PHP tanpa menulis apa pun ke DB.
while :; do
  curl -s -o /dev/null -w '%{http_code}\n' -m 20 -X POST $R $B/webhook/zdt-probe >> /tmp/wh.txt
  curl -s -o /dev/null -w '%{http_code}\n' -m 20 $R $B/up >> /tmp/get.txt
  sleep 0.15
done

# terminal 2
for svc in app app-2; do
  docker compose up -d --no-deps --force-recreate $svc
  sleep 8
done
```

Lalu `sort /tmp/get.txt | uniq -c` harus **hanya `200`**, dan
`sort /tmp/wh.txt | uniq -c` harus 404 dengan **paling banyak dua** 502 (satu per
container — lihat *Batas yang tidak bisa dilewati*). Sebelum perbaikan ini,
langkah yang sama menghasilkan beberapa detik penuh kegagalan di kedua jalur.

Dan setelah deploy sungguhan, hitung apa yang Caddy catat:

```bash
docker compose logs --since 10m caddy 2>&1 | grep -cE "no such host|connection refused"
```

---

## Rollback

Semua reversibel, dan tak satu pun butuh downtime:

```bash
cd /opt/pingly

# Caddyfile (kembali ke satu upstream)
cp Caddyfile.bak-deploy-<ts> Caddyfile
docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile

# compose (hapus app-2 + grace period)
cp docker-compose.yml.bak-zerodowntime-<ts> docker-compose.yml
docker compose up -d --remove-orphans
```

`deploy.sh` versi baru tetap bekerja setelah rollback compose: ia mendeteksi
bahwa `app-2` tak ada, jatuh ke cara lama, dan mengatakannya.
