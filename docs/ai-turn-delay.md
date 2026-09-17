# Jeda balasan AI (jendela "boom chat")

Node AIAgent **tidak lagi** menjawab setiap pesan masuk begitu ia mendarat. Ia
menunggu pelanggan berhenti mengetik dulu, lalu menjawab semua yang datang
sebagai **satu giliran**.

## Kenapa

Orang tidak menulis satu pesan per pikiran:

```
19:04:01  oi
19:04:03  tenho uma dúvida
19:04:07  sobre o pedido 123
```

Sebelumnya tiap baris memanggil hub sendiri-sendiri: **tiga run, tiga balasan**,
dua di antaranya menjawab pertanyaan yang belum selesai ditanyakan — dan
ketiganya ditagih. Yang dilihat pelanggan: bot yang memotong pembicaraannya.

Lebih buruk lagi, watermark (`_ai_last_processed_message_id_*`) dulu baru
disimpan **setelah** hub menjawab, jadi pesan yang datang selama model berpikir
terbaca sebagai belum terjawab dan ikut dirakit ulang di giliran berikutnya —
konteks yang sama dikirim dua kali.

## Mekanismenya

1. Tiap pesan masuk memanggil `FlowExecutor::scheduleAIAgentTurn()`, yang
   menulis **token acak baru** ke `flow_states.state_data._ai_debounce_token_{nodeId}`
   dan men-dispatch `App\Jobs\RunAiAgentTurn` dengan `delay`.
2. Pesan berikutnya menimpa token itu dan men-dispatch job baru — jadi jendelanya
   **mundur**, bukan bertambah.
3. Saat sebuah job bangun, ia hanya lanjut kalau token di flow state **masih
   miliknya**. Job yang terlanjur antre di belakang pesan yang lebih baru
   menemukan token orang lain dan mundur diam-diam.
4. Job yang menang mengambil kunci per-percakapan (`ai-turn:{conversationId}`,
   `Cache::lock`), menghapus token, lalu menjalankan giliran. Giliran itu
   merakit **semua** pesan sejak watermark (maks `AI_MAX_INPUT_MESSAGES` = 10,
   yang terbaru menang), jadi tak ada yang hilang karena menunggu.
5. Watermark ditulis **sebelum** hub dipanggil. Pesan yang datang saat model
   berpikir jadi milik giliran berikutnya, bukan diulang di giliran ini.

Kalau kunci sedang dipegang giliran lain, job **`release(10)`** — bukan gagal:
pesan yang ia bawa memang belum dijawab siapa pun.

### Media

Giliran yang ditahan menunggu download gambar (lihat `AI_MEDIA_WAIT_SECONDS`)
tetap bekerja seperti sebelumnya. Yang berubah: `resumeAfterMedia()` sekarang
**tidak jadi** menjalankan giliran bila masih ada token terpasang — artinya
pelanggan masih mengetik, dan job yang sudah terpasang itu akan mengambil
file-nya sekalian. Kalau tak ada token (job-nya sudah bangun lalu ditahan
menunggu file), media yang mendarat langsung memicu giliran tanpa jeda
tambahan — penantiannya sudah dibayar oleh download.

## Setelan

| Tempat | Kunci | Default |
|---|---|---|
| `.env` / `config/ai.php` | `AI_TURN_DELAY_SECONDS` | **8** detik |
| `.env` / `config/ai.php` | `AI_MAX_TURN_DELAY_SECONDS` | 300 detik (batas atas nilai per-node) |
| Flow builder → node AIAgent | `response_delay_seconds` | kosong = pakai default platform |

`0` = jawab begitu pesan datang (perilaku sebelum fitur ini ada). Nilai
per-node selalu menang, dan selalu di-clamp ke `[0, AI_MAX_TURN_DELAY_SECONDS]`.

Menaikkan angkanya = lebih sedikit balasan terpotong, tapi jawaban pertama
terasa lebih lambat bagi pelanggan yang memang cuma mengirim satu pesan.

## ⚠️ Ops: butuh queue worker

Giliran AI sekarang **dijalankan dari antrean `default`**, bukan dari request
webhook. Worker `queue` yang sudah ada di VPS langsung mengerjakannya — tak ada
service baru yang perlu dibuat — tapi konsekuensinya nyata:

- **Worker mati = AI berhenti membalas.** Sebelumnya AI tetap jalan tanpa worker.
- `--timeout` worker sebaiknya **≥ 240** (nilai `RunAiAgentTurn::$timeout`).
  Kalau lebih rendah, worker membunuh giliran di tengah panggilan hub. Tidak
  merusak data — watermark sudah tersimpan, jadi percobaan ulang tak mengirim
  balasan kedua — tapi pelanggan kehilangan jawabannya.

Bonusnya: request webhook tak lagi menahan seluruh round-trip hub. Meta dan
Telegram sama-sama mengulang webhook yang dianggap lambat.

## Mengisi penantiannya

Jendela di atas **ditambah** round-trip hub adalah satu rentang senyap di layar
pelanggan — dan dari kursinya, senyap tak bisa dibedakan dari nomor yang mati.
Agen di panel juga salah membacanya, lalu mengambil alih thread di tengah
giliran yang sedang jalan. Dua jawaban, sengaja berbeda sifatnya.

### 1. "Digitando…" (otomatis, semua kanal yang punya)

`App\Services\AiAgentHub\AiTypingPresence`. Menyala **saat giliran diarmed**,
bukan saat hub dipanggil — jendela debounce adalah paruh pertama penantian, dan
di node yang jedanya panjang ia justru mayoritasnya.

Tiap indikator typing di tiap kanal adalah *dead man's switch* (Telegram padam
4 dtk, Meta & API Way ~10 dtk, API Way **tak punya timeout sama sekali**), jadi
satu episode = satu token di cache (`ai-typing:{conversationId}`) + job
`RefreshAiTypingIndicator` yang men-dispatch dirinya tiap
`Channel::typingRefreshSeconds()` sampai tokennya hilang.

Yang menghentikannya: giliran selesai (`finally` di `runAIAgentTurn`), flow
berhenti (`stopFlow`, termasuk handoff dan "Assumir da IA"), conversation tak
lagi milik flow, atau `AI_TYPING_MAX_SECONDS` habis. Penarikannya mengirim
`paused` ke kanal yang menerimanya — di API Way itu **satu-satunya** yang
membersihkan indikator, tanpa itu pelanggan menonton agen hantu mengetik.

> ⚠️ **Koneksi queue `sync` hanya dapat satu beat.** Tak ada "nanti" di sana,
> jadi job yang menjadwalkan dirinya akan memanggil dirinya seketika, selamanya.
> Ini bukan akomodasi tes: deployment sync memang tak punya penjadwal.

### 2. Pesan tunggu (per node, default mati)

`App\Services\AiAgentHub\AiHoldingMessage` + job `SendAiHoldingMessage`.
Teksnya **selalu ditulis penulis flow**, di kartu "Avisar enquanto a resposta é
preparada" pada node AIAgent — engine tak tahu bahasa percakapannya, jadi tak
ada kalimat bawaan yang aman untuk dikarang. Node tanpa daftar tetap diam, yang
juga alasan fitur ini aman di-deploy: tiap flow produksi berperilaku sama persis
seperti sebelumnya.

- ⚠️ **Dikirim inline oleh giliran itu sendiri, bukan oleh job — dan itu wajib.**
  `runAIAgentTurn` jalan di worker antrean `default`, lalu **memblokir worker itu**
  selama seluruh round-trip hub. Job yang di-dispatch dari sana mengantre di
  belakang giliran yang men-dispatch-nya: tanpa worker `default` kedua yang bebas
  ia baru bisa jalan **setelah** giliran selesai, dan pada saat itu `finally`
  sudah menghapus klaimnya. Hasilnya fitur yang jalan di antrean sepi lalu diam
  di bawah beban — persis keadaan yang ia ada untuk menutupinya. (Typing lolos
  dari jebakan ini hanya karena beat pertamanya di-dispatch dari **webhook**,
  bukan dari worker; itu sebabnya gejalanya "digitando ada, pesannya tidak".)
- **Ambangnya karena itu dibandingkan dengan penantian yang DIPROYEKSIKAN**, bukan
  dengan jam berjalan: waktu yang sudah dihabiskan jendela debounce **plus**
  `AiHoldingMessage::ASSUMED_RUN_SECONDS` (5 dtk, tebakan konservatif — tak ada
  yang bebas menonton jam di sini). Praktisnya: ambang apa pun yang berada dalam
  beberapa detik dari jendela debounce dikirim **andal, tanpa langkah ops**.
  Hanya ambang yang jauh lebih panjang yang jatuh ke job berjadwal — dan hanya di
  situ `AI_PRESENCE_QUEUE` dgn worker sendiri berarti sesuatu.
- Balasan yang datang cepat tetap tak pernah didahului permintaan maaf atas
  keterlambatan yang tak terjadi: ambangnya tetap dihormati, hanya diukur lebih
  awal. Node yang ambangnya belum terjangkau tetap diam.
- **Satu baris per penantian** (`Cache::add` pada `ai-holding:{conversationId}`).
  Giliran yang ditahan menunggu unduhan media mengklaimnya; giliran yang
  melanjutkan sesudahnya menemukan klaim sudah terpakai.
- **Tak jadi dikirim** bila balasan sudah mendarat, seseorang sudah mengambil
  thread, atau flow sudah pindah node.
- **Rotasi** antar baris, dipilih dari id pesan — tak ada counter yang ditulis
  ke `state_data`, karena job ini jalan **di luar** kunci percakapan dan salinan
  `state_data` yang basi dari sana akan membatalkan watermark giliran.
- **Daftar kedua** (`media_messages`) dipakai saat pelanggan mengirim foto,
  berkas, atau voice note. Penantian itu yang terpanjang — file-nya harus
  diunduh dulu.
- ⚠️ **Ditandai `meta.ai_holding`, dan `AiConversationContext` melewatinya.**
  Semua yang masuk blok transkrip dikirim ke hub sebagai `message.content` dan
  dipindai detektor handoff di sana; kalimat kita sendiri bukan isi percakapan.

### Setelan

| Tempat | Kunci | Default |
|---|---|---|
| `.env` / `config/ai.php` | `AI_TYPING_INDICATOR_ENABLED` | `true` |
| `.env` / `config/ai.php` | `AI_TYPING_MAX_SECONDS` | 180 detik (plafon keras 600) |
| `.env` / `config/ai.php` | `AI_HOLDING_MESSAGES_ENABLED` | `true` (kill switch platform) |
| `.env` / `config/ai.php` | `AI_HOLDING_AFTER_SECONDS` | 8 detik |
| `.env` / `config/ai.php` | `AI_PRESENCE_QUEUE` | `default` (antrean untuk typing + pesan tunggu) |
| Flow builder → node AIAgent | `holding_message.{messages,media_messages,after_seconds,enabled}` | kosong = diam |

### ⚠️ Ops

Keduanya menambah job ke antrean **`default`** yang sama: satu job pesan tunggu
per giliran (hanya bila node-nya punya daftar) dan satu job typing per beat
selama penantian. Pada kanal ber-refresh 10 detik, satu percakapan yang menunggu
30 detik = 3 job. Tak ada service baru, tapi kalau antrean sedang tertahan,
indikatornya tersendat — dan itu memang kosmetik: `sendTyping` menelan errornya
sendiri, dan pesan tunggu yang gagal kirim tak pernah di-retry (`$tries = 1` —
percobaan kedua adalah gelembung kedua di depan pelanggan yang sudah dijawab).

## Kalau ada yang aneh

```bash
# apa gilirannya benar-benar terpasang, dan berapa jedanya
grep 'AIAgent turn armed' storage/logs/laravel.log

# giliran yang tak pernah jalan sama sekali
grep 'RunAiAgentTurn: AI turn never ran' storage/logs/laravel.log

# pesan tunggu yang benar-benar terkirim, dan yang gagal
grep 'AIAgent holding message sent' storage/logs/laravel.log
grep 'failed to send the AIAgent holding message' storage/logs/laravel.log

# ⚠️ dan yang TIDAK dikirim, beserta alasannya — tiap cabang yang mundur
# menyebutkan dirinya, supaya "pesannya tak datang" bisa dibedakan antara
# penjagaan yang bekerja dan fitur yang rusak
grep 'AIAgent holding message not sent' storage/logs/laravel.log
```

Di produksi (log tak disimpan lintas deploy) baca dari container:

```bash
docker compose logs queue --since 30m | grep -i 'holding message'
docker compose exec app php artisan queue:failed | grep -i SendAiHoldingMessage
```

Tes: `tests/Feature/Flow/AiAgentBurstTest.php`,
`tests/Feature/Flow/AiHoldingMessageTest.php`.
