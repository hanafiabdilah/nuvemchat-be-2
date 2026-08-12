# Worker antrean `broadcasts`

Kampanye broadcast berjalan di antrean sendiri (`RunBroadcastJob::onQueue('broadcasts')`)
dan **butuh container worker sendiri**. Tanpa itu job-nya jatuh ke worker `queue`
biasa: pesannya tetap terkirim, tapi satu kampanye 5.000 penerima akan menahan
kiriman interaktif — balasan agen, OTP login, notifikasi — di belakangnya.

`docker-compose.yml` hidup di VPS (`/opt/pingly`), bukan di repo ini, jadi langkah
ini manual dan **harus dilakukan sebelum rilis**. `deploy.sh` sudah mengecek
keberadaan service ini; selama belum ada, deploy tetap jalan seperti biasa dan
mencetak peringatan.

## 1. Tambahkan service di `/opt/pingly/docker-compose.yml`

Salin persis blok `queue-email` yang sudah ada, ganti nama dan antreannya:

```yaml
  queue-broadcast:
    <<: *app-base          # atau: samakan build/image/env/volumes dengan `queue`
    container_name: pingly-queue-broadcast
    restart: unless-stopped
    command: php artisan queue:work --queue=broadcasts --tries=1 --timeout=300 --sleep=1
    depends_on:
      - db
```

Dua flag yang **tidak boleh diubah**:

- `--queue=broadcasts` — seluruh gunanya memisahkan antrean.
- `--tries=1` — job pompa mendorong dirinya sendiri dan tidak idempoten.
  Job yang di-retry akan **mengirim ulang pesan yang sudah diterima orang**.
  Pemulihan dari worker yang mati ditangani `broadcasts:tick` (scheduler), yang
  hanya membangkitkan penerima yang belum pernah ditandai terkirim.

`--timeout=300` harus ≥ `RunBroadcastJob::$timeout`.

## 2. Naikkan

```bash
ssh pingly
cd /opt/pingly
docker compose up -d queue-broadcast
docker compose logs -f queue-broadcast
```

Deploy berikutnya (`./deploy.sh backend`) akan otomatis menyertakannya.

## 3. Verifikasi

```bash
# Scheduler sudah menjalankan tick tiap menit (lihat routes/console.php)
docker compose exec app php artisan broadcasts:tick

# Antrean broadcast tidak menumpuk
docker compose exec app php artisan queue:monitor broadcasts
```

## Skala worker

Satu worker sudah cukup: pacing kampanye ditentukan `rate_per_minute`, bukan
jumlah worker, dan pompa mengambil batch secara atomik (`lockForUpdate`) sehingga
dua worker tidak akan mengirim ke orang yang sama dua kali — mereka hanya akan
membuat kampanye berjalan lebih cepat dari ritme yang diminta operator. Tambah
replika hanya kalau banyak kampanye berjalan bersamaan.
