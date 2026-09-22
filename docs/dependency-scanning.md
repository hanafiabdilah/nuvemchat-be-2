# Dependensi yang punya advisory

## Masalahnya

Repo ini mengirim 96 paket PHP beserta seluruh pohon transitifnya, dan **tak
pernah ada satu pun langkah yang menanyakan apakah salah satunya punya advisory
yang sudah diterbitkan**. Tidak di CI (`lint.yml` + `tests.yml` tak menyebut
audit apa pun), tidak di `deploy.sh`.

Saat akhirnya ditanyakan (Set 2026), jawabannya **41 advisory di 12 paket**,
dua belas di antaranya `high`. Beberapa langsung mengenai permukaan produk ini:

| Paket | Advisory | Kenapa mengenai kita |
|---|---|---|
| `guzzlehttp/guzzle` | CVE-2026-69246 — *noncanonical host can bypass host-based checks* | Seluruh gerbang SSRF **adalah** pemeriksaan berbasis host yang hasilnya diserahkan ke Guzzle (`docs/ssrf-guard.md`) |
| `guzzlehttp/psr7` | CVE-2026-59882, CVE-2026-48998 — *host confusion* | Kelas yang sama, di lapisan URI-nya |
| `laravel/framework` | PKSA-m5cs-t1y6-qpcs — *temporary signed URL path confusion* | Seluruh media privat, galeri, QR Pix dan PDF nota fiscal disajikan lewat signed URL |
| `laravel/framework` | PKSA-3r5d-mb8f-1qw9 — *CRLF injection in default email rule* | Setiap form yang memvalidasi e-mail |
| `symfony/mime` | CVE-2026-45067 — *SMTP command injection via CRLF* | Kanal e-mail mengirim surat |
| `symfony/http-foundation` | CVE-2026-48736 — `IpUtils::PRIVATE_SUBNETS` melewatkan bentuk transisi IPv6 | Kelas yang sama dengan lubang yang ditemukan di `PublicUrl::isPublicIp()` sendiri |
| `league/commonmark` | XSS di `AttributesExtension` + 7 DoS | Dipakai jalur markdown Laravel |

## Yang dilakukan

### 1. Pemutakhiran (Set 2026)

`composer.lock` dinaikkan pada paket yang punya advisory beserta
dependensinya — **`composer.json` tidak disentuh**, jadi semua batasan versi
tetap sama dan tidak ada major yang dilompati:

- `laravel/framework` v12.39.0 → v12.69.2
- `guzzlehttp/guzzle` 7.10.0 → 7.15.5, `guzzlehttp/psr7` 2.8.0 → 2.13.1
- Symfony 7.3.x → 7.4.x (http-foundation, http-kernel, mime, mailer, routing,
  process, yaml, string, …)
- `league/commonmark` 2.7.1 → 2.10.3
- `phpunit/phpunit`, `pestphp/pest`, `paragonie/sodium_compat` (dev/CLI)

Sesudahnya: **`composer audit` bersih**, dan test suite lengkap hijau (1966
lulus; satu kegagalan yang sudah ada sebelumnya di `RegistrationTest`, rute
starter-kit Inertia, tak berubah).

⚠️ **Ini 60 paket sekaligus.** Meski tetap di dalam major yang sama, ia layak
mendapat jendela deploy sendiri dan pemeriksaan di staging — jangan ditumpuk
dengan perubahan produk di deploy yang sama.

### 2. `.github/workflows/security.yml`

```
composer audit --locked --no-interaction
npm audit --omit=dev --audit-level=high
```

Jalan pada push, pull request, **dan setiap Senin pagi**. Jadwalnya bukan
hiasan: lockfile yang bersih saat di-merge tidak tetap bersih — advisory-nya
terbit belakangan, terhadap kode yang tak disentuh siapa pun sejak itu. Scan
yang hanya jalan saat ada perubahan tak akan pernah melihat itu, padahal justru
itu seluruh kelas masalah yang ia ada untuk menangkapnya.

`--locked` sengaja: **lockfile yang di-deploy**, jadi itulah yang ditanyakan,
dan melewati langkah install membuat job ini cukup cepat untuk tetap ada di
tiap pull request.

`--omit=dev` pada npm: pohon dev di sini adalah build tooling yang tak pernah
sampai ke browser, dan advisory-nya cukup berisik untuk melatih semua orang
mengabaikan job ini.

## Yang belum dikerjakan

- ~~Kedua SPA tidak tercakup.~~ **Sudah** (Set 2026): masing-masing punya
  `.github/workflows/ci.yml` sendiri. Saat akhirnya ditanya, keduanya juga
  merah — `nuvemchat-fe-2` **13 advisory (8 high)** termasuk **axios**
  (seluruh lapisan API SPA, yang membawa token sesi) dan **DOMPurify** (yang
  membersihkan HTML e-mail pelanggan di Webmail); `nuvemchat-bo` 2 high di
  `react-router`. Keduanya dibereskan `npm audit fix` tanpa menyentuh
  `package.json`, dan build keduanya tetap lolos.
  ⚠️ Hanya BO yang dapat langkah **typecheck** — di sanalah `tsc` sudah pernah
  menangkap bug nyata yang lolos ke produksi (`axios.isAxiosError` di 15 catch
  block). `nuvemchat-fe-2` masih punya ~196 error `tsc`, mayoritas dari
  boilerplate Figma tak terpakai di `src/components/ui/` yang meng-import
  modul dengan spesifier ber-versi (`"@radix-ui/react-dialog@1.1.6"`) yang tak
  resolve untuk siapa pun; membersihkannya pekerjaan tersendiri, dan langkah
  typecheck yang merah tiap run hanya melatih semua orang mengabaikan job ini.
- **Tidak ada Dependabot/Renovate.** Scan memberi tahu; ia tidak membuka PR.
  Selama tak ada, pemutakhiran tetap pekerjaan manual dan akan tertunda persis
  seperti yang sudah terjadi.
