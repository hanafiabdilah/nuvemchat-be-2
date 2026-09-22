# Kebijakan kata sandi

## Masalahnya

Setiap tempat kata sandi disetel menuliskan aturannya sendiri, dan semuanya
menuliskan aturan yang sama: **`min:8`**. Enam tempat, enam controller:

| Berkas | Apa yang disetel |
|---|---|
| `Api/AuthController` | pendaftaran tenant |
| `Api/PasswordResetController` | reset via OTP WhatsApp |
| `Api/UserController` | ganti sandi sendiri |
| `Api/AgentController` (×2) | sandi atendente, dibuat & diubah owner |
| `Api/Admin/AdminController` | akun Back Office baru |
| `Api/Admin/AccountController` | ganti sandi Back Office |

Dua akibatnya:

1. **`min:8` menerima `12345678`** — yang ada di puncak setiap korpus
   kebocoran yang pernah ada. Akun-akun ini menjangkau seluruh isi percakapan
   pelanggan sebuah workspace; akun Back Office menjangkau semua workspace.
2. **Aturannya tak bisa diubah tanpa menemukan keenamnya.** Yang ketujuh akan
   ditulis `min:8` juga, karena itu yang terlihat di sebelahnya.

## Perbaikannya

Satu definisi, di `AppServiceProvider::registerPasswordPolicy()`:

```php
Password::defaults(fn () => app()->isProduction()
    ? Password::min(10)->uncompromised()
    : Password::min(8));
```

Keenam tempat kini memanggil `Password::defaults()`. `Actions/Fortify/
PasswordValidationRules` dan `Settings/PasswordController` sudah memakai
`Password::default()`/`defaults()` sejak dulu, jadi keduanya ikut sendiri.

### ⚠️ Panjang + korpus kebocoran, sengaja TANPA aturan komposisi

Tidak ada `->mixedCase()->symbols()->numbers()`. Aturan komposisi tidak
menghasilkan kata sandi yang lebih kuat, ia menghasilkan `Senha@123` — dan ia
**menolak** frasa panjang huruf-kecil-semua yang justru kuat. Panduan NIST saat
ini mengatakan hal yang sama.

### ⚠️ `uncompromised()` hanya di produksi

Ia memanggil `api.pwnedpasswords.com` sungguhan (k-anonymity: hanya 5 karakter
pertama hash SHA-1 yang dikirim, bukan sandinya). Menyalakannya di test suite
akan membuat ratusan tes bergantung pada jaringan.

**Gagalnya ke arah aman-bagi-pengguna, bukan aman-bagi-kita**: Laravel
melaporkan exception-nya lalu memperlakukan sandi itu sebagai bersih. Jadi
gangguan di Have I Been Pwned **memperlambat** pendaftaran, bukan memblokirnya
— dan timeout-nya diturunkan ke **4 detik** (bawaan framework 30 detik akan
terasa seperti form yang rusak):

```php
$this->app->bind(UncompromisedVerifier::class,
    fn ($app) => new NotPwnedVerifier($app[HttpFactory::class], 4));
```

### Apa yang berubah bagi pengguna yang sudah ada

**Tidak ada.** Validasi hanya berjalan saat sandi disetel. Akun lama tetap
masuk dengan sandi lamanya; aturan baru berlaku saat mereka menggantinya.

⚠️ Akibatnya sandi 8–9 karakter yang lemah tetap hidup di produksi tanpa batas.
Kalau itu perlu dibereskan, jalannya adalah kampanye rotasi yang disengaja
(atau `password_changed_at` + pemaksaan), bukan menaikkan `min` dan berharap.
