# Login: pembatasan percobaan dan faktor kedua

## Masalahnya

`POST /api/auth/login` dan `POST /api/admin/auth/login` tidak punya
`throttle` sama sekali, tidak punya penguncian akun, tidak menunda apa pun, dan
tidak mencatat kegagalan. Sebuah serangan *credential stuffing* terhadap akun
paling berkuasa di platform akan berjalan dengan kecepatan penuh dan **tidak
meninggalkan jejak apa pun**.

Selain itu `POST /api/auth/login` mengembalikan token **tanpa pernah membaca
`two_factor_confirmed_at`** — jadi akun tenant yang sudah menyalakan faktor
kedua tetap bisa dimasuki dengan kata sandi saja lewat endpoint ini.

Dan `admins` — tabel operator Back Office — sama sekali **tidak punya kolom
2FA**. Ia dipisah dari `users` belakangan dan tak pernah mendapatkannya, jadi
justru akun paling berkuasa adalah satu-satunya yang *tidak bisa* punya faktor
kedua.

## Perbaikannya

### 1. Pembatasan percobaan (`App\Services\Auth\LoginThrottle`)

⚠️ **Dihitung pada KEGAGALAN, bukan per permintaan.** Middleware `throttle`
menghitung setiap panggilan, jadi satu kantor di belakang satu alamat IP akan
mengunci dirinya sendiri hanya dengan berhasil login — dan kontrol yang
menghukum pemakaian normal adalah kontrol yang akan dimatikan.

Dua penghitung, karena keduanya menjawab pertanyaan berbeda:

| Penghitung | Batas | Menjawab |
|---|---|---|
| akun + alamat | 5 / 15 mnt | apakah ada yang menggarap satu akun ini? |
| alamat | 30 / 15 mnt | apakah ada yang menyemprot satu sandi ke banyak akun? |

⚠️ Kuncinya **akun + alamat**, bukan akun saja. Kalau hanya akun, siapa pun
bisa mengunci pemilik workspace pesaing dari akunnya sendiri cukup dengan gagal
lima kali — penolakan layanan yang dibagikan gratis. Dipasangkan dengan alamat,
penyerang hanya bisa mengunci dirinya sendiri.

Keduanya luruh sendiri; tidak ada "buka kunci" manual. Akun yang tak bisa
dimasuki pelanggannya adalah gangguan tersendiri, dan support yang membuka
kunci satu per satu adalah cara kebijakan penguncian akhirnya dimatikan.

Di atasnya ada `throttle:sign-in` (20/mnt per alamat) sebagai penahan banjir
yang jalan sebelum controller bekerja sama sekali.

⚠️ Limiter-nya **tidak** bernama `login`: `FortifyServiceProvider` sudah
mendaftarkan nama itu untuk rute Inertia starter-kit, dan pendaftaran kedua akan
diam-diam menggantikan yang pertama.

### 2. Jejak audit

Kegagalan login Back Office kini menulis `auth.login_failed` ke `audit_logs`
walau tidak ada aktor untuk diatribusikan — rentetan baris itu adalah tanda
paling awal yang platform punya bahwa ada serangan. Alamat e-mail di **log**
di-mask (`ma••••••@example.com`); yang menonton serangan tidak butuh alamatnya.

### 3. Faktor kedua (`App\Services\Auth\TwoFactor`)

Ditulis terhadap sebuah model, bukan terhadap `User`, karena dua akun yang
membutuhkannya ada di tabel berbeda.

⚠️ **Pendaftaran dua langkah, dan langkah kedua bukan upacara.** Secret yang
langsung ditulis sebagai `confirmed` mengunci akun ke authenticator yang mungkin
gagal scan, salah scan, atau dipasang di ponsel yang jamnya melenceng — dan
orangnya baru tahu di login berikutnya, dari luar. Tidak ada yang ditegakkan
sampai sebuah kode dari secret itu kembali.

⚠️ **Kode pemulihan di-hash seperti kata sandi.** Ditampilkan sekali, saat
dibuat, dan tidak pernah lagi: salinan terbaca di basis data adalah daftar kata
sandi kedua, dan basis data justru yang sudah dipegang penyerang yang sampai
sejauh itu.

⚠️ **Tiket tantangan sengaja TIDAK hangus karena kode salah.** Salah ketik satu
digit itu kasus biasa; membuatnya berbiaya "ketik sandi lagi" akan mendorong
orang mematikan faktor keduanya. Yang membatasi tebakan di situ adalah rate
limit pada endpoint tantangan, bukan tiketnya.

⚠️ **Tenant memakai format penyimpanan Fortify**, dibaca lewat helper Fortify
sendiri (`Fortify::currentEncrypter()`, `recoveryCodes()`, `replaceRecoveryCode()`).
Fortify yang memiliki cara kolom itu ditulis; pembaca kedua dengan gagasan
format sendiri adalah cara seseorang terkunci dari akunnya oleh sebuah deploy.

⚠️ **`UserFactory` dulu mengisi kolom 2FA secara default** dengan sepuluh
karakter acak plus `two_factor_confirmed_at` — artinya setiap user hasil factory
tampak terdaftar sambil memegang secret yang tak bisa didekripsi apa pun. Tidak
berbahaya selama API mengabaikan kolom itu; begitu login mulai menghormatinya,
semua akun tersebut jadi tak terjangkau. Defaultnya kini kosong, dan
`withTwoFactor()` menyatakannya secara eksplisit bila sebuah tes memang butuh.

## Urutan penerapan

### 1. Deploy

Tidak ada yang berubah untuk operator yang sudah ada: pembatasan percobaan
langsung aktif (dan tak terasa oleh pemakaian normal), 2FA masih opsional.

```bash
./deploy.sh backend      # menjalankan migrate --force
```

### 2. Semua operator mendaftar

Back Office → menu avatar → **Account** → *Two-factor authentication*.

### 3. Cek Health

Baris **"Back Office two-factor"** harus hijau. Selama kuning, `meta.rows`
menyebut akun mana yang tersisa.

### 4. Nyalakan penegakan

Di `/opt/pingly/.env`:

```
ADMIN_MFA_REQUIRED=true
```

lalu `up -d --force-recreate` **semua** container PHP.

⚠️ **Jangan menyalakannya sebelum langkah 3 hijau.** Menyalakan lebih awal
mengunci setiap operator sekaligus — termasuk orang yang harus mematikannya
lagi. Akun yang belum mendaftar tetap bisa menjangkau tiga hal
(`auth/me`, `auth/logout`, `account/two-factor*`), jadi pintunya masih bisa
dibuka dari sisi tempat mereka berada, tapi jangan bergantung pada itu.

## Kalau seorang operator kehilangan ponsel dan kode pemulihannya

Tidak ada tombol "reset 2FA" di UI, dan itu disengaja — tombol semacam itu
adalah jalan pintas yang persis dicari penyerang. Pulihkan lewat tinker, dengan
identitas orangnya diverifikasi di luar jalur:

```php
App\Models\Admin::where('email', '…')->first()->forceFill([
    'two_factor_secret' => null,
    'two_factor_recovery_codes' => null,
    'two_factor_confirmed_at' => null,
])->save();
```

Catat tindakannya. Orang itu lalu mendaftar ulang di langkah 2.

## Verifikasi cepat

```bash
# keenam kali harus 429
for i in $(seq 1 6); do
  curl -s -o /dev/null -w '%{http_code} ' -X POST https://chat.pingly.com.br/api/admin/auth/login \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d '{"email":"tidak-ada@pingly.test","password":"salah"}'
done; echo
```

Di log: `grep 'Sign-in failed'` dan `grep 'Sign-in blocked by throttle'`.
Di Back Office: Audit → aksi `auth.login_failed`.

Tes: `tests/Feature/Auth/SignInHardeningTest.php`.
