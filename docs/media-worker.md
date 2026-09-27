# Worker antrean `media` (TERPASANG di produksi, 27 Sep 2026)

Media pesan masuk **tidak lagi** di-download di dalam request webhook. Alurnya
sekarang: simpan teks → `broadcast(MessageReceived)` → job
`App\Jobs\DownloadInboundMedia` mengambil file-nya → `broadcast(MessageUpdated)`.
Agen melihat bubble + caption seketika, gambar/vídeo/áudio menyusul.

Secara default job itu jatuh ke antrean `default`, jadi rilis media aslinya tak
butuh langkah ops apa pun — worker `queue` yang sudah ada langsung
mengerjakannya. **Sejak 27 Sep 2026 pemisahannya sudah terpasang**: service
`queue-media` ada di `/opt/pingly/docker-compose.yml` dan `MEDIA_QUEUE=media`
ada di `/opt/pingly/.env`. Bagian di bawah ini tetap ada sebagai catatan cara
memasangnya (mis. di environment baru) dan cara rollback.

## Apa saja yang naik antrean ini

- `App\Jobs\DownloadInboundMedia` — unduhan media pesan masuk (16 MB itu wajar).
- `App\Jobs\PublishInstagramPost` — post terjadwal.
- `App\Jobs\SyncContactPhoto` — foto profil kontak.

Yang ketiga ditambahkan setelah ia membunuh worker `default` dua kali pada 26 Sep
2026 dan membuat jeda 3 detik sebuah node Message mendarat 63 detik terlambat.
Lihat `docs/flow-message-delivery.md`; ringkasnya: foto profil layak dimiliki dan
tak urgen sama sekali, sedangkan `default` adalah tempat bubble flow berikutnya
menunggu.

## Kenapa tetap sebaiknya dipisah

Broadcast Laravel (`ShouldBroadcast`) juga job. Selama masih satu antrean,
download vídeo 16 MB duduk **di depan** event `message-received` pesan
berikutnya — persis keterlambatan yang ingin dihilangkan dengan memindahkan
download keluar dari webhook. Memisahkannya menjaga jalur realtime tetap
kosong.

## 1. Tambahkan service di `/opt/pingly/docker-compose.yml`

`docker-compose.yml` hidup di VPS, bukan di repo ini. Salin blok `queue-email`,
ganti nama dan antreannya:

```yaml
  queue-media:
    <<: *app-base          # atau: samakan build/image/env/volumes dengan `queue`
    container_name: pingly-queue-media
    restart: unless-stopped
    command: php artisan queue:work --queue=media --tries=3 --timeout=300 --sleep=1
    depends_on:
      - db
```

`--timeout=300` harus ≥ `$timeout` job terpanjang di antrean ini
(`DownloadInboundMedia` 180). `--tries` di sini hanya batas atas; jumlah
percobaan sebenarnya diambil dari `$tries` job.

⚠️ Angka itu bukan tuning. Job yang melewati timeout-nya tidak sekadar gagal:
Laravel tak bisa melanjutkan proses dengan aman setelah alarm, jadi seluruh
`queue:work` keluar dan semua job di belakangnya menunggu container hidup lagi.
Worker tanpa `--timeout` memakai default 60 s, yang lebih kecil dari `$timeout`
tiga job di `default` — itu yang dibiarkan selama berbulan-bulan sampai 27 Sep
2026.

## 2. Arahkan job ke antrean itu

⚠️ **Urutannya wajib begini**: container dulu, `.env` belakangan. Kalau
`MEDIA_QUEUE=media` diset sementara belum ada worker yang membaca antrean
`media`, semua media pesan masuk menggantung di status `pending` selamanya —
teksnya tetap masuk, tapi gambarnya tidak pernah muncul.

```bash
ssh pingly
cd /opt/pingly
docker compose up -d queue-media
docker compose logs -f queue-media

# baru setelah worker hidup:
echo "MEDIA_QUEUE=media" >> .env
docker compose exec app php artisan config:clear
docker compose restart app queue
```

## 3. Verifikasi

```bash
# Antrean tidak menumpuk
docker compose exec app php artisan queue:monitor media

# Tidak ada pesan yang menggantung menunggu file-nya
docker compose exec app php artisan tinker --execute="echo App\Models\Message::where('attachment_status','pending')->where('created_at','<',now()->subMinutes(10))->count();"
```

Hasil `0` di perintah terakhir artinya sehat. Angka yang terus naik = worker
antrean `media` mati atau `MEDIA_QUEUE` menunjuk antrean yang tak ada
pembacanya.

## Rollback

Hapus `MEDIA_QUEUE` dari `.env` (atau set `MEDIA_QUEUE=default`) lalu
`config:clear`. Job **baru** kembali ke worker `queue` biasa; tidak ada
perubahan kode.

Job yang terlanjur antre di `media` tidak ikut pindah — antrean itu tetap berisi
baris di tabel `jobs` yang tak ada pembacanya. Kuras sekali:

```bash
docker compose exec app php artisan queue:work --queue=media --stop-when-empty
```
