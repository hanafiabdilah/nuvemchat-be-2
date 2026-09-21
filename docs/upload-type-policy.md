# Tipe berkas yang boleh diunggah pelanggan

## Masalahnya

Dua jalur unggah menerima **apa pun**:

| Endpoint | Aturan lama | Disajikan di |
|---|---|---|
| `POST /api/uploads` (lampiran flow, kartu carousel, media campanha) | `required\|file\|max:10240` | `https://chat.pingly.com.br/storage/uploads/…` — langsung oleh Caddy, tanpa PHP |
| `POST /api/gallery` | `['required','file',"max:{$maxKb}"]` | `https://chat.pingly.com.br/gallery/{uuid}/…` |

Laravel menamai berkas unggahan dengan ekstensi yang **ditebak dari isinya**
(`UploadedFile::hashName()` → `guessExtension()`). Jadi berkas HTML yang diunggah
dengan nama `invoice.png` tersimpan sebagai `<acak>.html` dan disajikan dengan
`Content-Type: text/html`.

Origin itu juga yang melayani **dashboard tenant** di `/` dan **Back Office** di
`/webmin`. Token sesi kedua SPA ada di `localStorage`, dan tidak ada
`Content-Security-Policy`. Satu tautan yang dibuka administrator platform =
token Back Office tercuri = pengambilalihan seluruh platform.

Galeri bahkan sudah punya `gallery.blocked_extensions` yang menyebut
`html`/`svg`/`xhtml` — tapi ia memeriksa `getClientOriginalExtension()`, yaitu
**nama yang dikirim browser**. Mengganti nama berkas melewatinya sepenuhnya.

## Perbaikannya

Satu daftar putih terpusat: `App\Services\Media\UploadPolicy`.

```php
$request->validate([
    'file' => UploadPolicy::rules($maxKb),
], [
    'file.mimes' => UploadPolicy::message(),
]);
```

Isi daftarnya **bukan tebakan**: ia gabungan dari apa yang sudah diterima
seluruh handler pengiriman kanal (WhatsApp, Telegram, Discord, Instagram,
Messenger, e-mail, widget) ditambah container audio yang disebut jalur
transkripsi AI. Apa pun di luar itu bisa tersimpan tapi tak akan pernah benar-benar
terkirim — jadi tidak ada yang berfungsi hari ini yang dicabut.

Sengaja **tidak** masuk daftar: `svg`, `html`, `htm`, `xhtml`, `xml`, `js`,
`php`, `phtml`, `htaccess`, `swf`.

### ⚠️ Daftar putih saja tidak cukup

Aturan `mimes:` Laravel membandingkan ekstensi yang ditebak dari **isi** berkas.
Sebuah *polyglot* — berkas yang sekaligus GIF sah dan HTML sah — lolos sebagai
`gif` dan tersimpan `.gif`. Ia jadi tidak berbahaya hanya karena disajikan
dengan `X-Content-Type-Options: nosniff`, yang menghentikan browser menebak ulang
`image/gif`.

**Unggah dan penyajian harus diperbaiki bersamaan.** Karena itu ada tiga bagian:

1. `UploadPolicy::rules()` pada kedua endpoint;
2. `UploadPolicy::safeContentType()` di `GalleryFileController` — tipe yang akan
   dirender browser diturunkan jadi `application/octet-stream`, plus `nosniff`
   dan CSP `sandbox` (pasangan yang sama dengan yang sudah dipakai
   `MediaFileController` untuk media privat);
3. blok `header` pada `handle_path /storage*` di `deploy/Caddyfile` — jalur ini
   tidak melewati PHP sama sekali, jadi header harus dipasang di Caddy.

Keduanya inert bagi pengambil yang penting di jalur itu (Meta, Telegram, Discord
menarik media untuk dikirim): CSP pada respons subresource tidak diterapkan ke
subresource tersebut.

### Instagram

`POST /instagram/accounts/{connection}/uploads` **sudah aman** dan tidak diubah:
`InstagramMediaPreparer::store()` menolak apa pun di luar `IMAGE_MIMES` /
`VIDEO_MIMES` berdasarkan `getMimeType()` (isi berkas) dengan 422.

## Urutan penerapan

### 1. Deploy Caddyfile lebih dulu

```bash
./deploy.sh caddy      # dari root monorepo
```

Ini memasang `nosniff` + CSP pada `/storage/*`. Mendahulukannya berarti berkas
yang sudah ada langsung berhenti bisa dirender, bahkan sebelum backend naik.

### 2. Deploy backend

```bash
./deploy.sh backend
```

### 3. Periksa apa yang sudah terlanjur tersimpan

Validasi memperbaiki unggahan berikutnya; ia tidak bisa berbuat apa-apa terhadap
yang sudah ada di disk. Selama ini belum dijalankan, jawaban jujur atas
"apakah kita sudah dieksploitasi" adalah "kita belum melihat".

```bash
docker compose exec -T app php artisan media:scan-unsafe-uploads
```

Perintah ini **hanya melaporkan**. Ia tidak menghapus apa pun, karena alamat
yang sama bisa tertulis di node flow, kartu carousel, atau campanha terjadwal —
menghapusnya diam-diam merusak otomasi pelanggan, dan itu keputusan orang yang
bisa melihat otomasi tersebut.

**Kalau ada hit:**

1. Lihat isinya sebelum menyimpulkan. Sebagian besar `.svg` adalah logo yang
   diunggah dengan niat baik, bukan serangan.
2. Cari siapa yang mengunggah (`tenant_id` pada baris galeri; untuk disk,
   `uploads/` tidak menyimpan pemilik — telusuri lewat URL di `flow_nodes.data`
   dan `broadcasts.payload`).
3. Kalau isinya memuat `<script>` atau memanggil `localStorage`: perlakukan
   sebagai insiden — cabut token, periksa `audit_logs` untuk aktivitas Back
   Office di sekitar waktu itu, lalu hapus berkasnya.
4. Kalau tidak berbahaya: minta pelanggan mengunggah ulang dalam format yang
   didukung (SVG → PNG), baru hapus.

## Menambah tipe baru

Tambahkan ke `UploadPolicy::EXTENSIONS`. Sebelum itu, pastikan tipe tersebut
benar-benar bisa dikirim oleh setidaknya satu handler kanal — kalau tidak,
pelanggan hanya bisa menyimpan berkas yang tak akan pernah terkirim.

**Jangan pernah** menambahkan tipe yang dirender browser sebagai dokumen.
Kalau suatu fitur benar-benar membutuhkannya, jawabannya adalah menyajikan media
pelanggan dari origin terpisah (mis. `media.pingly.com.br` atau langsung dari
bucket), bukan melonggarkan daftar ini.

Tes: `tests/Feature/Media/UploadPolicyTest.php`.
