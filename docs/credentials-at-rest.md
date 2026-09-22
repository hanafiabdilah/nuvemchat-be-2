# Kredensial kanal terenkripsi di basis data

## Masalahnya

`connections.credentials` menyimpan hal paling berharga yang platform ini
simpan atas nama pelanggan:

| Kanal | Isinya |
|---|---|
| WhatsApp Official | `access_token` WABA — mengirim pesan atas nama bisnis itu, membaca seluruh WABA |
| Telegram / Discord | `token` bot — **kendali penuh** atas bot tersebut |
| Messenger / Instagram | page/user access token — membaca seluruh inbox |
| API Way | `token` instance — mengotorisasi seluruh core API |
| Semua kanal chat | `webhook_secret` — yang membuktikan sebuah delivery asli |

Semuanya **plaintext JSON**. Artinya *dump* basis data — artefak yang justru
paling sering disalin: ke laptop, ke staging, ke lampiran tiket support —
membawa setiap kredensial kanal di platform dalam keadaan terbaca.

## ⚠️⚠️ Kenapa `encrypted:array` adalah jawaban yang salah

Ini kesalahan yang paling mudah dibuat, dan gejalanya tak terlihat.

**22 query membaca ke dalam JSON ini** untuk merutekan lalu lintas masuk:

```
credentials->page_id              → webhook Messenger menemukan koneksinya
credentials->business_account_id  → webhook WhatsApp
credentials->user_id              → webhook Instagram
credentials->business_id          → webhook TikTok
credentials->app_id               → widget me-resolve dirinya
credentials->phone_number_id, ->fb_user_id, ->bot_user_id, ->instagram_account_id
```

Mengenkripsi seluruh kolom membuat **setiap** query itu tak cocok apa pun. Dan
kegagalannya bukan error: ia **pesan yang diam-diam tak pernah sampai**.

## Perbaikannya: per nilai, bukan per kolom

`App\Services\Connection\ConnectionCredentials` + cast
`App\Casts\EncryptedCredentials`:

| | |
|---|---|
| **kunci identitas** (`page_id`, `app_id`, `business_account_id`, …) | tetap plaintext — basis data harus bisa mencocokkannya |
| **nilai rahasia** (`access_token`, `token`, `refresh_token`, `webhook_secret`, …) | terenkripsi — tak ada yang pernah mencarinya |

Daftar rahasianya **satu**, dipakai bersama `ConnectionResource` (yang memakainya
untuk memutuskan apa yang tak boleh sampai ke browser). "Tak boleh terbaca di
basis data" dan "tak boleh terbaca di browser" belum pernah sekali pun berbeda
di sini, dan salinan kedua akan menyimpang pada kanal berikutnya.

### ⚠️ Baca digerakkan PENANDA, tulis digerakkan DAFTAR KUNCI

Ini yang membuatnya bisa di-deploy tanpa migration:

- **menulis** bertanya "apakah kunci ini rahasia?" → enkripsi
- **membaca** bertanya "apakah nilai ini sudah terenkripsi?" (`pingly:enc:v1:`)
  → dekripsi

Baris yang ditulis sebelum ini ada tak membawa penanda, jadi ia dikembalikan apa
adanya. **Tak ada yang rusak selama backfill belum jalan.**

### ⚠️ Rekursif

Rahasia tidak selalu di tingkat atas: `pending_pages` adalah daftar Page yang
masing-masing membawa access token-nya sendiri, dan `released_instance`
menyimpan token instance API Way yang dilepas. Pass satu tingkat meninggalkan
keduanya terbuka — perbaikan separuh yang terbaca seperti perbaikan utuh.

### ⚠️ Kunci dicocokkan PERSIS, tak pernah substring

`token_expires_at` dan `token_type` duduk tepat di sebelah `token` di payload
yang sama dan **bukan** rahasia. Mencocokkan substring akan mengenkripsinya dan
membuat perbandingan kedaluwarsa jadi perbandingan ciphertext.

### ⚠️ Gagal dekripsi → `null`, tak pernah ciphertext-nya

Pemanggil yang menerima string terenkripsi akan menyerahkannya ke WhatsApp
sebagai token dan membaca penolakannya sebagai "token pelanggan kedaluwarsa".
`null` adalah jawaban jujur dan gagal di tempat yang bisa didiagnosis. Dalam
praktiknya ini berarti `APP_KEY` berubah tanpa kolomnya ikut dienkripsi
ulang — itu insiden, bukan bug: **setiap kredensial di platform tak terbaca
sampai kunci lama kembali**.

## Satu query yang harus berubah

`TelegramChannel::connect()` dulu mencegah token bot dipakai dua kali dengan
`where('credentials->token', $data['token'])` — satu-satunya query yang mencari
sebuah **nilai rahasia**. Ciphertext berbeda per baris, jadi query itu akan
diam-diam berhenti mencocokkan apa pun dan penjagaannya mati tanpa suara.

Sekarang dicocokkan pada **bot id** (`credentials->id`, dari `getMe()` yang tetap
harus dipanggil). Lebih benar juga: ia ikut menangkap bot yang sama ditambahkan
lagi dengan token yang di-regenerate di BotFather — yang tak pernah tertangkap
perbandingan string.

## Urutan deploy

1. **Deploy.** Baris baru langsung terlindungi; baris lama tetap bekerja.
2. **Lihat dulu:** `php artisan connections:encrypt-credentials --dry-run`
3. **Jalankan:** `php artisan connections:encrypt-credentials`
4. **Verifikasi:** BO → Health → **"Channel secrets at rest"** hijau.

Aman diulang: baris yang sudah terlindungi dilewati, bukan dienkripsi ulang.

⚠️ Perintahnya **tidak** menaikkan `updated_at` — melindungi rahasia tersimpan
bukan suntingan yang dilakukan siapa pun pada koneksi itu.

## Batas yang jujur

Kunci enkripsinya `APP_KEY`, di `.env` di server yang sama. Jadi ini melindungi
terhadap **basis data yang bocor sendirian** (backup yang disalin, kredensial DB
yang dicuri, SQL injection) dan **tidak** terhadap server yang sepenuhnya
dikuasai. Itu tetap perbedaan yang berarti: backup jauh lebih banyak berpindah
tangan daripada server aplikasi.

Tes: `tests/Feature/Security/CredentialsAtRestTest.php`.
