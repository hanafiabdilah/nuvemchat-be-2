# Ke mana server ini boleh mengirim permintaan

## Masalahnya

Tiga tempat menerima URL dari pelanggan lalu mengambilnya. **Hanya satu yang
memeriksa.**

| Tempat | Dulu |
|---|---|
| Webhook keluar (`WebhookUrl`) | ✅ diperiksa, dua kali (saat simpan + sebelum tiap kirim) |
| Node `http_request` di Flow | ❌ tanpa pemeriksaan apa pun |
| `media_url` di seluruh endpoint kirim | ❌ hanya aturan `url` Laravel |

Yang lolos aturan `url` Laravel (diuji langsung pada versi terpasang):

```
file:///etc/passwd               → ditolak
gopher://x/                     → ditolak
http://127.0.0.1:6379/          → DITERIMA
http://169.254.169.254/v1.json  → DITERIMA   (metadata instance cloud)
http://app:9000                 → DITERIMA   (nama layanan Docker internal)
dict://127.0.0.1:11211/         → DITERIMA
```

Keduanya **mengembalikan isi respons**:

- node Flow memetakan `raw_body` ke variabel, yang bisa dikirim sebagai pesan;
- `media_url` mengunduh byte-nya lalu mengirimkannya sebagai berkas media ke
  percakapan penyerang sendiri.

Jadi ini bukan SSRF buta — ini **akses baca ke jaringan platform dengan
jawabannya diantar ke chat penyerang**.

## Perbaikannya

Aturannya **sudah ditulis dan sudah benar** di
`App\Services\Webhooks\WebhookUrl`. Yang dilakukan di sini adalah
memindahkannya ke `App\Support\PublicUrl` supaya ketiganya bisa membacanya;
`WebhookUrl` mendelegasikan ke sana dan **mempertahankan kalimatnya sendiri**
(pesannya tentang webhook, dan kalimat bersama harus berhenti menjadi tentang
apa pun).

### `PublicUrl::isFetchable()`

```
isOwnOrigin(url)                        → boleh
isPublic(url) && !resolvesToPrivate(url) → boleh
selain itu                               → tolak
```

⚠️ **Pengecualian origin sendiri dibandingkan sebagai origin utuh** — skema,
host **dan** port. Kalau dibandingkan per host saja, `http://localhost:6379`
menjadi "alamat kita sendiri" di setiap mesin yang `APP_URL`-nya localhost —
yaitu persis permintaan yang gerbang ini ada untuk menghentikannya.

⚠️ Cabang origin-sendiri **juga melewati pemeriksaan DNS**. Host kita sendiri
resolve ke 127.0.0.1 adalah keadaan normal di mesin developer dan di test
suite; menolaknya di sana berarti setiap tautan yang platform ini cetak untuk
dirinya sendiri — QR Pix, berkas galeri, PDF nota fiscal — tak bisa diambil.

⚠️ **Tak bisa di-resolve BUKAN berarti privat.** Nama yang sedang mati cukup
gagal saja, dan memperlakukan gangguan DNS sebagai serangan mengubah setiap
outage di endpoint pelanggan jadi penolakan yang tak bisa mereka diagnosis.

### `OutboundHttp::guard()` — separuh yang soal redirect

Memeriksa URL yang diketik pelanggan itu perlu dan **tidak cukup**: host yang
jelas-jelas publik bisa menjawab `302 Location: http://169.254.169.254/`, dan
Guzzle mengikutinya tanpa bertanya siapa pun (bawaan: lima hop). Jadi URL yang
sudah divalidasi hanyalah *saran*, bukan batasan.

⚠️ Redirect **dijaga, bukan dimatikan**. Banyak endpoint sah menjawab 301 —
domain telanjang ke www, http ke https, URL S3 ke host regionalnya — dan
menolak semuanya akan merusak flow yang bekerja demi menutup lubang yang bisa
ditutup dengan memeriksa ulang tiap hop.

Callback-nya melempar, yang oleh Guzzle dimunculkan sebagai kegagalan
permintaan. Semua pemanggil sudah memperlakukan permintaan gagal sebagai
permintaan gagal, jadi tak ada yang baru untuk ditangani di call site.

### Batas ukuran unduhan

`OutboundMedia::toUploadedFile()` dulu melakukan
`file_put_contents($tmp, $response->body())` — **seluruh respons di memori PHP
lebih dulu**. `media_url` yang menunjuk berkas besar berarti kehabisan memori
dalam satu permintaan, dan menunjuk berkas besar itu gratis.

Sekarang: `->sink($temp)` (streaming ke disk) + `on_headers` menolak
`Content-Length` di atas plafon + pemeriksaan `filesize()` sesudahnya untuk
server yang tak mengirim `Content-Length`. Plafon 110 MB — tepat di atas yang
terbesar benar-benar diterima kanal (100 MB lampiran e-mail), sehingga tak
pernah menolak kiriman yang tadinya berhasil.

## Perilaku saat ditolak

| Permukaan | Jawaban |
|---|---|
| Node `http_request` | Ambil cabang **`error`** + `Log::warning` (host saja, bukan URL penuh — query string penulis flow membawa token dan identitas pelanggannya). Flow harus tetap jalan; `error` adalah cabang yang penulisnya memang sudah gambar untuk "panggilan ini gagal". |
| `media_url` | `ValidationException` pada field `media_url` → **422**. Dilempar dari `OutboundMedia::fromData()`, satu tempat yang dilewati ~20 handler kanal — dua puluh string aturan berarti dua puluh kesempatan untuk terlewat satu. |

⚠️ `ValidationException` sengaja diteruskan oleh `MessageService::guard()`
(`catch (ValidationException|HasUserSafeMessage)`), jadi ia tidak diratakan jadi
kegagalan upstream generik.

## Catatan untuk penulis tes

Pemeriksaan DNS berjalan walaupun `Http::fake()` aktif — fake mencegat HTTP,
bukan resolusi nama. Gunakan `https://cdn.example.com/...` untuk fixture media
(konvensi yang sudah dipakai 10 tes). **Jangan** pakai TLD `.test`: banyak mesin
developer memetakannya ke 127.0.0.1 lewat dnsmasq/Valet, dan gerbang ini akan
menolaknya — dengan benar.

## Yang belum dikerjakan

- **Tidak ada proksi keluar khusus.** Kontrolnya ada di kode, bukan di jaringan.
  Lapisan yang lebih kuat adalah memblokir `169.254.169.254` dan rentang privat
  di tingkat jaringan kontainer aplikasi, sehingga sebuah bug di sini tidak
  cukup untuk menjangkau apa pun.
- **Jendela DNS rebinding masih ada** (P2-13): `resolvesToPrivate()` melakukan
  resolusi, lalu Guzzle melakukan resolusinya sendiri. Perbaikan yang benar
  adalah resolve sekali lalu menyambung ke IP yang sudah diverifikasi lewat
  `CURLOPT_RESOLVE`.

Tes: `tests/Feature/Security/SsrfGuardTest.php`.
