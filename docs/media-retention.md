# Retensi media (`media:purge`)

Storage adalah satu-satunya biaya di sini yang **hanya bisa naik**: setiap foto,
vídeo, dan áudio yang pernah masuk tersimpan selamanya di disk, dan group chat
mengalikannya dengan jumlah anggota untuk file yang sering tak pernah dibuka
siapa pun di perusahaan.

`media:purge` menghapus **file**-nya saja setelah lewat jendela retensi:

| Percakapan | Default | Env |
|---|---|---|
| Group (`conversations.type = group`) | 30 hari | `MEDIA_RETENTION_GROUP_DAYS` |
| Private | 90 hari | `MEDIA_RETENTION_PRIVATE_DAYS` |

Baris `messages`, caption, reaction, dan posisinya di thread **tidak disentuh** —
bubble-nya berubah jadi penanda "Mídia expirada". Umur dihitung dari
`messages.created_at` (saat byte-nya mendarat di disk kita), bukan `sent_at`
yang bisa dimundurkan oleh history import.

Matikan seluruhnya dengan `MEDIA_RETENTION_ENABLED=false`. Semua knob ada di
`config/media.php`.

## Kenapa URL-nya ikut berubah

Signed URL media sekarang **kedaluwarsa persis pada tanggal file-nya dihapus**
(dulu: `now()->addMonths(6)` setiap kali di-serialize).

SPA menulis URL itu ke IndexedDB dan **tidak pernah memintanya lagi** — tidak
ada yang menandatangani ulang saat sync berikutnya. Jadi URL yang hidup lebih
lama dari file-nya meninggalkan bubble yang menunjuk 403 tanpa penjelasan, dan
URL yang mati lebih dulu bikin bubble terlihat rusak padahal file-nya masih ada.
Menurunkan keduanya dari `created_at + retensi` (`App\Services\Media\MediaRetention`)
membuat dua ujung itu bertemu, dan FE bisa tahu media sudah kedaluwarsa **tanpa
request apa pun**: parameter `expires` di URL-nya sendiri yang bercerita
(`components/Chat/MediaPlaceholder.tsx`).

Efek samping yang menguntungkan: URL jadi deterministik (pesan yang sama selalu
menghasilkan string yang sama), sehingga re-sync thread tidak lagi membatalkan
cache gambar di browser seperti sebelumnya.

## Yang sengaja TIDAK dilakukan

- **`updated_at` tidak dinaikkan.** Kolom itu kursor delta sync pesan di semua
  klien. Menaikkannya saat purge berarti pass pertama (setahun backlog)
  menyodorkan puluhan ribu baris untuk di-download ulang ke setiap dashboard
  yang terbuka, tanpa satu pun perubahan yang terlihat. Klien tetap konvergen
  sendiri lewat expiry di URL yang sudah mereka simpan.
- **Body HTML e-mail (`meta.email.html_path`) tidak dihapus** — itu isi pesannya,
  ukurannya kilobyte, bukan media yang jadi alasan command ini ada. Lampiran
  e-mail tetap dihapus, entri-nya di `meta.email.attachments` bertahan (nama +
  content type) dengan `expired: true`.
- **Media yang disimpan sebagai URL absolut tidak disentuh** — file-nya di server
  orang lain, tidak makan storage kita.

## Jadwal

```php
Schedule::command('media:purge')->hourlyAt(50)->withoutOverlapping(30);
```

Per pass dibatasi `--limit` (default 1000 pesan **per tipe percakapan**), jadi
backlog besar terkuras bertahap alih-alih menahan worker sejam penuh di malam
pertama. Setelah terkuras, pass yang tak menemukan apa-apa hanya dua query
ber-index. Tidak ada langkah ops: cukup `schedule:run` yang sudah jalan.

## Menjalankan manual

```bash
# Lihat dulu berapa yang akan dibebaskan, tanpa menghapus apa pun
docker compose exec app php artisan media:purge --dry-run

# Kuras backlog lebih cepat dari jadwal per-jam
docker compose exec app php artisan media:purge --limit=20000
```

Command juga menyapu **widget upload yatim**: visitor mengunggah file dulu lalu
mengirimnya di panggilan kedua, dan unggahan yang tak pernah jadi pesan tidak
direferensikan apa pun (`MEDIA_WIDGET_UPLOAD_TTL_HOURS`, default 24 jam).

## Verifikasi

```bash
# Ukuran direktori media
docker compose exec app du -sh storage/app/private/media

# Tidak ada lagi media group yang lewat jendelanya
docker compose exec app php artisan media:purge --dry-run
```
