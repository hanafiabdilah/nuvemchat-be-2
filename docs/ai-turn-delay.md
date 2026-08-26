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

## Kalau ada yang aneh

```bash
# apa gilirannya benar-benar terpasang, dan berapa jedanya
grep 'AIAgent turn armed' storage/logs/laravel.log

# giliran yang tak pernah jalan sama sekali
grep 'RunAiAgentTurn: AI turn never ran' storage/logs/laravel.log
```

Tes: `tests/Feature/Flow/AiAgentBurstTest.php`.
