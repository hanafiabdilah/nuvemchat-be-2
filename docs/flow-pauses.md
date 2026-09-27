# Jeda di dalam flow: "digitando…" / "gravando áudio…" + node Intervalo

Ditulis untuk permintaan klien (Set 2026), yang menyebut dua hal sekaligus
setelah melihat platform lain:

> *"aqui no Leona tem esse 'delay' dai fica como se tivesse realmente gravando o
> audio visivel pro lead, e interessante colocar tambem, e adiciona tambem um
> novo bloco de 'intervalo' usa bastante no meio do fluxo para dar tempo entre
> as mensagens em algumas situações."*

Dua fitur, dan **keduanya memang belum ada** sebelum ini. Yang sudah ada dan
mudah tertukar dengan permintaan pertama adalah `delay` per-bubble di node
Message — jedanya sudah lama ada, tapi **senyap total**: pelanggan tak melihat
apa pun selama jeda itu.

---

## 1. Jeda yang terlihat

### Apa yang berubah

`delay` sebuah bubble sekarang mengisi dirinya sendiri. Selama jeda itu
pelanggan melihat indikator kanal — **"digitando…"** untuk teks,
**"gravando áudio…"** sebelum bubble audio, **"enviando arquivo…"** sebelum
gambar/vídeo/dokumen — lalu pesannya mendarat.

⚠️ **Menyala untuk flow yang sudah ada** (`presence` absen = `true`), dan itu
keputusan yang disengaja: tidak ada jeda yang tak sengaja. Tiap jeda di produksi
adalah angka yang diketik seseorang ke field berlabel *"aguardar antes de
enviar"* — permintaan atas sekuens yang terbaca seperti orang menulis, yang
persis inilah yang selama ini tertahan. Membaca ketiadaan key sebagai "mati"
akan merilis fitur ini dalam keadaan mati justru bagi flow yang paling
menginginkannya.

Dua jalan untuk mematikannya:

| Cakupan | Caranya |
|---|---|
| Satu bubble | Lepas centang **Mostrar "digitando…" durante a espera** di kartu bubble itu (`messages[].presence = false`) |
| Seluruh platform | `FLOW_PRESENCE_ENABLED=false` di `.env` — tanpa menyentuh satu flow pun |

### Indikator menempel di **ekor** jeda, bukan kepalanya

`FLOW_PRESENCE_MAX_SECONDS` (default **30**) membatasi panjang episodenya, dan
sisa jedanya dibiarkan senyap **di depan**, bukan di belakang:

```
delay 120 s, max 30 s   →  [ 90 s senyap ][ 30 s "gravando áudio…" ] pesan
delay 8 s,   max 30 s   →  [ 8 s "digitando…" ] pesan
```

Alasannya satu kalimat: tak ada orang merekam voice note selama dua menit, dan
indikator yang menyala di paruh pertama lalu padam terbaca seperti bot yang
menyerah. Senyap → mengetik → pesan adalah urutan yang dihasilkan manusia.
Karena `MessageNodes::MAX_DELAY_SECONDS` = 300 sementara plafonnya 30, jeda yang
lazim (satu digit detik) tertutup penuh dari ujung ke ujung.

### Apa yang benar-benar terkirim per kanal

`PresenceKind` adalah **permintaan, bukan keharusan**. Kanal yang tak bisa
membedakan tetap menampilkan "digitando…" — poin indikatornya adalah *"ada yang
sedang menyiapkan balasan"*, dan kata kerjanya cuma penyempurnaan.

| Kanal | Teks | Audio | Berkas |
|---|---|---|---|
| Telegram | `typing` | `record_voice` | `upload_document` |
| API Way | `composing` + `media: text` | `composing` + **`media: audio`** | `composing` + `media: text` |
| WA Official | `typing_indicator: {type: text}` | sama | sama |
| Instagram / Messenger | `typing_on` | sama | sama |
| Discord | `POST /channels/{id}/typing` | sama | sama |
| Live Chat Widget | event `widget-typing` (membawa `kind`) | sama | sama |
| TikTok, E-mail | — (tak punya API-nya / tak punya konsepnya) | | |

⚠️ **API Way belum terverifikasi terhadap dokumen core-nya.** whatsmeow memang
membawa jenisnya sebagai atribut `media` pada state `composing` (bukan state
terpisah), dan field itu memang sudah lama kita kirim berisi `text` — jadi kasus
terburuknya sebuah dekorasi yang diabaikan core. Cara memeriksanya: jalankan
flow ber-bubble audio ber-jeda ke nomor produksi dan lihat apakah HP menulis
"gravando áudio".

⚠️ **WA Official tak punya indikator merekam sama sekali.** `typing_indicator`
Meta hanya menerima `{"type":"text"}`, dan panggilannya menumpang mark-as-read —
jadi ia **butuh satu pesan masuk ber-`external_id`** untuk ditempeli. Flow yang
dijalankan tanpa pesan masuk (mis. dari kampanye) tak akan menampilkan apa pun
di kanal ini; itu bukan bug, itu batas API-nya.

### Mekanismenya

`App\Services\Flow\FlowPresence` + job `RefreshFlowPresence`. Sengaja **jauh
lebih sederhana** daripada `AiTypingPresence`, karena masalahnya lebih mudah:
giliran AI panjangnya tak diketahui (butuh token cache + loop sampai dibatalkan),
sedangkan jeda flow panjangnya **diketahui persis**.

Konsekuensinya: **tak ada cache key baru dan tak ada gagasan kepemilikan baru.**
Tiap beat memvalidasi klaim yang sudah dipegang node yang sedang menjeda —
`_message_chain_{id}` atau `_interval_{id}` — jadi sekuens yang diambil alih
orang, ditinggalkan, atau digantikan mematikan indikatornya sendiri tanpa
pembukuan tambahan.

Yang menghentikan sebuah episode:

1. **Deadline** — saat pesannya jatuh tempo. Beat lewat deadline berhenti dan
   **tidak** mengirim `paused`: bubble-nya mendarat di detik yang sama dan tiap
   kanal membersihkan indikatornya sendiri saat pesan tiba.
2. **Token basi** — sekuens lain mengambil node itu.
3. **Thread diambil orang** → `FlowPresence::stop()` mengirim `paused`.
   ⚠️ Di API Way ini satu-satunya yang berdiri antara pelanggan dan bot yang
   tampak mengetik selamanya: indikator di sana **tak punya timeout**.
4. `MAX_BEATS` = 100, untuk jam yang beku.

⚠️ **Tidak pernah selama retry backoff.** `startMessageChain` hanya mengisi jeda
saat `attempt === 0`. Backoff terjadi karena kanalnya tak terjangkau, dan
mengatakan "kami sedang mengetik" sementara yang sebenarnya terjadi adalah gagal
menghubungi WhatsApp adalah satu-satunya hal yang lebih buruk daripada senyap.

### Ops

Beat masuk antrean **`FLOW_PRESENCE_QUEUE`**, yang default-nya mengikuti
`AI_PRESENCE_QUEUE` — jadi di produksi (yang sudah menjalankan worker
`queue-presence`, lihat `docs/ai-turn-delay.md`) **tak ada langkah ops baru**, dan
di mana pun langkah itu belum diambil ia jatuh ke `default`. Tak ada service
baru; antrean yang tertahan hanya membuat indikatornya tersendat.

Grep saat mendiagnosis: `'FlowPresence: could not start the indicator for a pause'`.

---

## 2. Node Intervalo

### Kenapa `delay` bubble tidak cukup

`delay` melekat pada sebuah bubble, jadi ia hanya bisa memberi jarak **di dalam**
satu node Message. Yang diminta klien adalah menunggu **di antara node** — setelah
menempelkan tag, sebelum memanggil API, atau supaya pesan panjang sempat dibaca.
Sebelum ini satu-satunya cara adalah mengarang node Message kosong berisi delay,
dan itu **tidak bekerja**: bubble tanpa isi dilewati saat kirim.

### Bentuknya

```json
{ "type": "interval", "data": { "seconds": 30, "unit": "seconds", "presence": false } }
```

| Field | Arti |
|---|---|
| `seconds` | 0–86400. `0` = node dilewati begitu saja (bukan stall) |
| `unit` | tampilan saja — engine hanya membaca `seconds` |
| `presence` | tampilkan "digitando…" selama menunggu. **Default mati** |

Satu keluaran, tak mengirim apa pun, tak menyimpan apa pun.

### ⚠️ Intervalo ≠ Aguardar resposta

Ini kesalahan yang paling mungkin dibuat penulis flow, dan keduanya terbaca
hampir sama dalam satu kalimat:

| | Intervalo | Aguardar resposta |
|---|---|---|
| Yang mengakhiri | **jam** | **pelanggan**, dengan menulis |
| Pesan pelanggan selama menunggu | tak mengubah apa pun | itulah jawabannya |
| Keluaran | satu | `replied` + (bila ada limit) `timeout` |

`resumeFlow()` karena itu punya gerbang eksplisit: pelanggan yang menulis saat
interval berjalan **tidak** menggerakkan flow **dan tidak me-restart jedanya** —
tanpa bagian kedua itu, orang yang menulis dua kali tak akan pernah sampai ke
ujung penantiannya.

### ⚠️ Kenapa plafonnya 24 jam

Bukan angka bulat yang dipilih karena enak dibaca. WhatsApp Official menolak
konten bebas lebih dari 24 jam setelah pesan terakhir pelanggan, jadi flow yang
bangun setelah itu akan melanjutkan ke sebuah pengiriman yang **ditolak
platform** — fitur yang tampak bekerja sampai diam-diam tidak. Tindak lanjut
sehari kemudian adalah wilayah kampanye, yang punya mesinnya sendiri.

### Kenapa `presence` default-nya **mati** di sini tapi **hidup** di bubble

Dua default yang bertentangan, sengaja. Jeda sebuah bubble ada untuk mengatur
tempo sekuens yang **sebentar lagi mendarat**, jadi "digitando…" justru
intinya. Interval biasanya kebalikannya: ruang bagi pelanggan untuk membaca,
mengecek sesuatu, atau pergi mencari nomor pesanannya. Mengetik ke arahnya
selama itu bukan mengatur tempo, itu **memburu-burunya** — dan lebih buruk lagi,
sebuah janji atas pesan yang mungkin masih semenit lagi.

### Ops

Job `RunFlowIntervalNode` (`$tries = 1`, `$timeout = 180`) masuk antrean
`default`. ⚠️ **Tanpa worker, flow yang parkir di node ini tidak akan pernah
lanjut** — tak ada jalur lain yang menggerakkannya. Klaimnya
(`_interval_{nodeId}`) kedaluwarsa `seconds + 300` detik, jadi job yang tak
pernah jalan akhirnya berhenti memblokir node-nya.

Grep: `'FlowExecutor: Interval started'`,
`'RunFlowIntervalNode: the flow never came back from its interval'`.

---

## Berkas

| Sisi | Berkas |
|---|---|
| Presence | `app/Enums/Message/PresenceKind.php`, `app/Services/Flow/FlowPresence.php`, `app/Jobs/RefreshFlowPresence.php`, `SendsTypingIndicator` + 7 handler |
| Intervalo | `app/Services/Flow/IntervalNodes.php`, `app/Jobs/RunFlowIntervalNode.php`, `FlowExecutor::{executeIntervalNode,runIntervalElapsed}` |
| Config | `config/flow.php` → `presence.{enabled,max_seconds,queue}` |
| FE | `lib/intervalNodes.ts`, `components/Flow/IntervalNode.tsx`, `renderIntervalForm` di `NodeEditForm`, checkbox di `MessageItemsEditor`, `lib/messageNodeItems.ts` |
| Tes | `tests/Feature/Flow/{FlowPresenceTest,IntervalNodeTest}.php`, `tests/Feature/Message/TypingIndicatorTest.php` |
