# Instagram publishing — prasyarat rilis

Fitur *Instagram Posts* (`/instagram` di dashboard) memakai **Instagram API with
Instagram Login** — sama persis dengan koneksi inbox yang sudah ada, tidak ada
perubahan metode login. Yang baru hanya scope, storage publik, dan satu entri
scheduler.

Ada **tiga** hal yang harus benar di server, dan dua di antaranya diam-diam
menggagalkan publish kalau terlewat.

---

## 1. `php artisan storage:link` — WAJIB

Ini yang paling mudah terlewat, dan gejalanya menyesatkan: post tersimpan, job
jalan, lalu Meta menolak dengan pesan tentang media yang tidak bisa diambil.

Alasannya ada di API-nya: **content publishing tidak menerima byte gambar.** Meta
menerima *URL* lalu mengunduh sendiri dari sana. Jadi file yang diunggah user
disimpan di disk `public` (`storage/app/public/instagram/...`) dan harus bisa
dibaca dari internet di `{APP_URL}/storage/instagram/...`.

```bash
php artisan storage:link
```

Konsekuensi yang perlu disadari:

- **Publish tidak bisa jalan dari laptop.** `APP_URL` harus resolve dari luar;
  `localhost` berarti Meta mengunduh dari dirinya sendiri dan gagal.
- Simlink ini **belum ada di `deploy.sh`**. Tambahkan, atau jalankan sekali
  manual di VPS setelah rilis ini.

## 2. Scheduler — sudah terdaftar, tapi harus benar-benar jalan

`routes/console.php` menambahkan:

```
instagram:publish-scheduled  → everyMinute, withoutOverlapping(5)
```

Perintah ini punya dua tugas, dan yang kedua yang penting:

1. Melempar post yang waktunya tiba ke antrean.
2. **Membangkitkan post yang rantai publish-nya mati.** Publish adalah rantai job
   yang men-dispatch dirinya sendiri tanpa retry (retry = post ganda), jadi
   worker yang dibunuh saat Meta sedang transcoding meninggalkan post
   menggantung di status `publishing` — dan dari dashboard itu tidak terlihat
   seperti kegagalan, hanya seperti Instagram yang lambat.

Tidak ada worker baru: job-nya ikut antrean `config('queue.media')` (env
`MEDIA_QUEUE`, default `default`), jadi worker yang sudah ada mengerjakannya.
Kalau Anda sudah memisahkan `queue-media` sesuai `media-worker.md`, publish ikut
ke sana — itu justru yang diinginkan, karena unggah video besar tidak boleh
duduk di depan event realtime.

## 3. Scope Meta + reconnect tenant lama

URL authorize sekarang meminta empat scope:

```
instagram_business_basic
instagram_business_manage_messages
instagram_business_content_publish     ← baru
instagram_business_manage_comments     ← baru
```

⚠️ **Scope menempel di token.** Koneksi Instagram yang sudah ada dibuat sebelum
dua scope terakhir ada, jadi token-nya tidak mencakupnya dan **tidak bisa
di-upgrade di tempat** — tenant harus melewati OAuth lagi.

Itu ditangani, bukan dibiarkan: `InstagramApiException::isPermissionError()`
mengenali jawaban Meta untuk scope yang kurang (code 10 / subcode 2534015, code
200, code 3) dan API menjawab `422` dengan `code: "instagram_permission_required"`.
FE menampilkan layar "conta precisa ser reconectada" + tautan ke Connections,
bukan pesan error mentah.

Kedua permission itu juga butuh **Advanced Access** (App Review), termasuk untuk
menerima webhook `comments`.

---

## Yang API ini tidak bisa — dan kenapa itu permanen di jalur ini

| | Status |
|---|---|
| Publish foto / vídeo / Reels / Carrossel / Stories | ✅ |
| Baca feed, likes, jumlah komentar | ✅ |
| Balas / sembunyikan / hapus komentar | ✅ |
| Aktif-nonaktifkan komentar per post | ✅ |
| **Edit caption post yang sudah live** | ❌ Meta tidak punya endpoint-nya (di kedua flavour) |
| **Hapus post yang sudah live** | ❌ `DELETE /{ig-media-id}` ada, tapi butuh `instagram_manage_contents` yang **hanya ada di Instagram API with Facebook Login** |

Jangan "perbaiki" dua baris terakhir dengan menghapus baris `instagram_posts`
kita — itu hanya menyembunyikan post yang masih tayang. `InstagramPostController`
sengaja menolak `destroy` untuk post berstatus `published`, dan UI mengarahkan
user ke app Instagram.

Pindah ke Facebook Login akan membuka keduanya, tapi harganya: messaging pindah
ke Messenger Platform (tulis ulang handler inbox Instagram), tenant wajib punya
Page yang ter-link, dan **semua koneksi Instagram harus reconnect**. Itu keputusan
produk, bukan refactor.

## Dua jebakan di jalur publish (jangan "optimalkan" balik)

**1. Container SELALU harus dicek statusnya — termasuk foto.**

Godaannya jelas: gambar tidak perlu transcode, jadi kelihatannya bisa langsung
`media_publish` setelah container dibuat. Salah. Meta **tidak menerima byte
gambar** — ia menerima URL lalu pergi mengunduhnya, jadi container foto pun
sempat `IN_PROGRESS`. Melewati pengecekan itu menghasilkan error Meta:

> *A mídia não está pronta para ser publicada. Aguarde um momento.*

`InstagramPostPublisher::attempt()` sekarang memanggil `containerIsReady()`
untuk semua tipe. Biaya nol di jalur bahagia: pengecekan pertama biasanya sudah
`FINISHED`, jadi foto tetap terbit dalam satu pass. Test yang menjaganya:
*"a photo Meta is still fetching is waited for, not published early"*.

**2. Status `queued` ada supaya dashboard tidak berbohong.**

Antara user menekan tombol dan worker mengambil job, dulu baris-nya masih
`draft` — jadi respons yang kembali ke browser bilang "rascunho" padahal
pengiriman berhasil dimulai. Terlihat seperti tombol yang gagal diam-diam.

Sekarang `store(publish_now)`, endpoint `publish`, dan scheduler semuanya
men-*stamp* `PostStatus::Queued` **sebelum** dispatch. Tiga efek:

- Respons langsung jujur ("Na fila"), FE menampilkan spinner.
- Tekanan kedua pada tombol ditolak — `Queued` sengaja **bukan**
  `isPublishable()`, jadi tidak bisa masuk antrean dua kali.
- Scheduler berhenti men-dispatch ulang post yang sama tiap menit saat antrean
  sedang padat (dulu status-nya tetap `Scheduled` + due sampai job jalan).

`instagram:publish-scheduled` menyapu `queued` **dan** `publishing` yang basi
(>5 menit): post yang worker-nya mati sebelum sempat mengklaim sama macetnya
dengan yang ditinggal di tengah publish, dan dari dashboard keduanya identik.

## Grid = jendela ke Instagram, bukan cermin DB

Bagian "published" **tidak pernah** dibaca dari tabel kita — selalu live dari
`GET /me/media`. Konsekuensinya (semua diinginkan):

- Akun yang baru di-connect langsung menampilkan **seluruh arsip lamanya**.
- Post yang dibuat **langsung dari app Instagram** ikut muncul, dan komentarnya
  bisa dimoderasi — endpoint komentar memakai IG media id, tidak terikat record
  kita.
- Post yang dihapus orang dari app langsung hilang dari grid.

`instagram_posts` hanya menyimpan yang Instagram tidak punya konsepnya: draft,
jadwal, dan alasan gagal.

⚠️ **Stories ada di edge terpisah.** `/media` **tidak pernah** mengembalikan
story media — harus `GET /{ig-id}/stories` (hanya 24 jam terakhir; yang lewat
sudah tidak ada di Meta, bukan sekadar difilter). Tanpa panggilan kedua itu,
story yang di-publish dari sini akan *lenyap* dari grid tepat saat tayang: tile
terjadwalnya pergi dan tidak ada penggantinya. Panggilan stories di-skip saat
paging (strip itu milik layar pertama) dan kegagalannya sengaja ditelan — feed
call yang jalan duluan sudah melaporkan token mati / scope kurang, jadi
menggagalkan grid demi strip yang mayoritas hari kosong tidak sepadan.

Grid juga menyegarkan diri saat tab kembali terlihat (throttle 60 dtk) dan punya
tombol Refresh, karena akun ini diedit dari luar dashboard.

## Batas kuota

100 post per 24 jam **rolling, per akun Instagram** — dihitung Meta, bukan kita,
dan dibagi dengan tool lain yang dipakai customer. Sisa kuota dibaca dari
`GET /{ig-id}/content_publishing_limit` dan ditampilkan di header halaman feed,
supaya user tahu sebelum menyusun, bukan sesudah Meta menolak.

## Spesifikasi media (ditegakkan sebelum kirim)

`InstagramMediaPreparer` mengonversi setiap gambar sebelum disimpan, karena Meta
hanya menerima JPEG dan menolak PNG — hal yang paling sering diunggah orang —
dengan pesan yang baru muncul setelah container dibuat.

- Gambar: JPEG, ≤ 8 MB, rasio 4:5 – 1.91:1, sisi 320–1920 px.
  Di luar rasio itu **di-fit**, bukan ditolak: `crop` (default, seperti app
  Instagram) atau `pad` (bar putih), dipilih user di composer.
- Video: MP4/MOV, diteruskan apa adanya (tidak ada ffmpeg di app ini; Meta
  transcode sendiri). Video yang ditolak Meta muncul sebagai `status` container,
  dan teks Meta ditampilkan verbatim di tile yang gagal.
- HEIC/AVIF **tidak** didukung: butuh Imagick dengan delegate yang tidak dijamin
  ada di host, dan diam-diam merusaknya lebih buruk daripada menolak.
