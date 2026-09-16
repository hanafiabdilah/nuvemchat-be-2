# Mensagem proativa da IA + contato no payload do run

Dua hal yang dikerjakan bersama, karena keduanya lahir dari satu permintaan
partner (ProxyBR, Set 2026): **agen AI harus bisa tahu siapa yang sedang
menulis**, dan **harus bisa bicara lagi setelah sesuatu terjadi di luar chat**.

Dokumen aslinya: `pedido-han-pingly-suporte.md` + balasannya
`resposta-pingly-proxybr-atendimento-autonomo.md` (root monorepo).

---

## 1. Siapa pelanggannya — `conversation.contactPhone` dkk

### Kenapa

Payload run **sudah lama** membawa `contactExternalId`, dan di kanal WhatsApp
kolom itu memang nomor telepon. Masalahnya kanal lain: chat id Telegram, scoped
id Instagram, user id Discord — semuanya di kunci yang sama, dan **tak ada apa
pun di payload yang menyebutkan yang mana**.

Chat id Telegram 11 digit yang dibandingkan dengan telepon cadastro **bisa
cocok**. Di field yang memutuskan apakah data langganan seseorang diserahkan,
false positive adalah hasil terburuk yang tersedia.

### Yang dikirim

`App\Services\AiAgentHub\AiContactContext::for()` menambahkan ke objek
`conversation`:

| Field | Kapan ada |
|---|---|
| `contactPhone` | hanya bila kanalnya benar-benar beralamat telepon (`Channel::broadcastAddressType()`) **dan** nomornya valid |
| `phoneSource` | `meta_wa_id` (WA Official) / `whatsmeow` (API Way); **absen** = provenance tak diketahui |
| `contactDisplayName` | hanya bila `contacts.name` benar-benar nama (`ContactIdentity::name()`) |

Aturannya: **ada atau tidak ada**. Tidak ada fallback ke `contactExternalId`.
`contactExternalId` dan `contactName` tetap dikirim apa adanya — hub membacanya.

### ⚠️ Kill switch dan urutan deploy

```
AI_RUN_CONTACT_CONTEXT=true      # default
```

Hub memvalidasi run DTO-nya **ketat**: satu field tak dikenal menolak seluruh
run dengan 400. Itu pernah terjadi (28 Agu 2026, `inputAudio`): manual sudah
ada, deployment hub belum, dan **setiap voice note gagal**. Yang
menyelamatkannya adalah retry yang membuang bagian opsional — dan retry itu
**tidak** membuang field ini.

Dan seharusnya tidak. Kalau dibuang, gejalanya bukan error: agen menemukan
**tak seorang pun** terverifikasi dan mengirim link portal ke semua orang
selamanya — kegagalan yang terlihat seperti software yang bekerja.

**Urutan deploy: hub menerima field-nya dulu → baru `AI_RUN_CONTACT_CONTEXT=true`.**

---

## 2. Mensagem proativa — `POST /api/v1/conversations/messages`

### Kenapa bukan `conversation_id`

Permintaan awal: endpoint menerima `conversations.id`. Kolom itu
auto-increment se-platform, jadi satu API key workspace + id yang bisa ditebak
= penulis bisa menulis ke **thread mana pun** milik workspace itu: teks bebas,
di gelembung yang terbaca sebagai bot resmi, ke pelanggan orang lain. Dan
teksnya ditulis model yang baru saja membaca konten dari pelanggan — jadi
"penulis" termasuk siapa pun yang berhasil membujuk agen itu.

Gantinya `App\Services\AiAgentHub\AiCallbackRef`: handle ber-HMAC yang dicetak
**di dalam run** dan menamai konteks run itu sendiri (conversation, flow node,
agent, expiry).

```
cr1.<base64url(claims)>.<base64url(hmac)>
```

- **tak bisa dienumerasi** — tak ada "ref untuk conversation 12346";
- **membatasi dirinya** — hanya menunjuk conversation tempat ia dicetak, dan
  hanya selama agent itu masih di node itu, jadi atendente yang mengambil alih
  mencabutnya tanpa ada yang perlu dicabut;
- **tanpa storage dan tanpa migration** — itu sebabnya seluruh mekanismenya satu
  file.

Kasus terburuk yang tersisa: pelanggan membuat bot mengatakan sesuatu yang aneh
**kepada dirinya sendiri**. Itu bisa diterima; yang satunya tidak.

⚠️ **Ref yang valid = izin untuk meminta, bukan izin untuk mengirim.** Apakah
thread masih dipegang AI adalah state hidup, dibaca ulang di **setiap**
panggilan (`AiProactiveMessageService::assertStillWithTheAgent`).

Ref **tidak** dicetak untuk run yang memakai `conversationExternalId` sendiri
(AI-suggest, vocabulary bench): draft tak boleh membawa kemampuan mengirim.

### Kontrak

```http
POST /api/v1/conversations/messages
X-Api-Key: pk_…
Idempotency-Key: evt_9f3a…        # WAJIB
Content-Type: application/json

{ "callback_ref": "cr1.…", "text": "Pronto, Maria! Confirmei que a conta é sua. …" }
```

**Tak ada field `sender`.** Atribusi datang dari ref (yang menamai agent-nya).
Penulis yang bisa mendeklarasikan dirinya adalah penulis yang suatu hari akan
mendeklarasikan dirinya manusia.

| Status | `code` | Kapan |
|---|---|---|
| `201` | — | terkirim; `message_id`, `conversation_id`, `duplicate: false` |
| `200` | — | `Idempotency-Key` yang sama; respons pertama diulang + `duplicate: true` |
| `401` | `api_key_invalid` | key tak ada / dicabut |
| `403` | `callback_ref_invalid` | tanda tangan salah / bentuk rusak |
| `403` | `callback_ref_expired` | lewat TTL |
| `403` | `proactive_messages_disabled` | kill switch mati |
| `404` | `conversation_not_found` | conversation tak ada **atau** milik workspace lain (sengaja jawaban yang sama) |
| `409` | `conversation_with_human` | atendente mengambil alih (`active`) |
| `409` | `conversation_closed` | conversation `resolved` |
| `409` | `conversation_not_with_ai` | flow berhenti / pindah node / node menunjuk agent lain |
| `409` | `message_in_progress` | request kembar, atau kirim yang nasibnya tak diketahui (lihat bawah) |
| `422` | `messaging_window_closed` | jendela 24 jam WA Official tutup (+ objek `window`) |
| `422` | (validasi) | `text` kosong/terlalu panjang, `Idempotency-Key` hilang |
| `429` | `too_many_proactive_messages` | plafon per-conversation (+ `retry_after`) |
| `502` | — | kanal menolak (`UpstreamServiceException` menerjemahkan sendiri) |

### Idempotensi — dan satu keputusan yang mudah salah

Tabel `ai_proactive_messages`, unique `(tenant_id, idempotency_key)`. Barisnya
ditulis **sebelum** kirim, supaya ia ada di celah yang disembunyikan timeout.

⚠️ **Kirim yang gagal TIDAK menghapus barisnya.** Timeout di sisi kita tak
mengatakan apa pun tentang apakah WhatsApp menerima teksnya, jadi menghapus
baris di situ adalah cara satu gangguan jadi dua pesan di chat orang. Baris
ditandai `failed` dan dipertahankan:

- retry di dalam `STALE_MINUTES` (5) → `409 message_in_progress` (tahan dulu);
- setelah itu baris dianggap mati, dihapus, dan percobaan baru boleh jalan —
  alternatifnya adalah conversation yang tak bisa ditulisi lagi selamanya gara-gara
  satu timeout.

**Penolakan (409/422/429) tidak menulis baris apa pun** dan tidak membakar
key-nya: hub bebas memakainya lagi begitu keadaannya berubah.

### Plafon per conversation

```
AI_PROACTIVE_MAX_PER_CONVERSATION_PER_HOUR=6
```

Bukan birokrasi: teksnya ditulis model, dipicu peristiwa di sistem orang lain.
Loop di sistem itu, atau badai retry, jadi rentetan pesan tak diminta di
WhatsApp — risiko ban untuk nomor workspace-nya, dan keluhan spam yang juga
mendarat di reputasi platform. Hand-back verifikasi butuh satu-dua.

Di atasnya masih ada `throttle:public-api` (120/menit per key).

### Kalau kredensial disalahgunakan

```
AI_PROACTIVE_MESSAGES_ENABLED=false
```

Mematikan **kedua** paruh: tak ada ref baru dicetak, dan ref yang sudah terbang
berhenti dihormati.

---

## 3. Dampak ke perilaku yang sudah ada

**Hitungan turn (`AI_MAX_TURNS = 20`) tidak tersentuh** — counter hanya naik di
`handleAIAgentInput`, yaitu saat agen menjawab pesan pelanggan. Pesan proaktif
tak lewat sana, dan menunggu verifikasi tak menghitung apa pun.

⚠️ **`meta.ai_hub_proactive` bukan hiasan.** `AiConversationContext` melewati
apa yang sudah diketahui hub dengan mencari `ai_hub_run_id`, dan pesan proaktif
**tak punya** run di sisi kita. Tanpa flag itu:

1. hub menerima kembali kalimatnya sendiri di transkrip; dan
2. yang lebih berbahaya — apa pun yang mendarat di `message.content` dipindai
   detektor handoff hub, jadi pesan proaktif yang menyebut "atendente" akan
   menyerahkan conversation ke manusia di giliran berikutnya. Kegagalan persis
   itu memakan 53 dari 53 run di Sep 2026.

**Link sudah clickable** — tak ada apa pun di jalur kirim yang mengubah teks.
Dua catatan: WA Official dikirim tanpa `preview_url` (link **tappable** tapi
tanpa kartu preview — sengaja tak diubah, itu memengaruhi setiap pesan teks
semua tenant), dan **markdown tidak jadi link di WhatsApp** — `[clique](url)`
mendarat sebagai teks literal. Agen harus mengeluarkan **URL nua**; itu
instruksi prompt di hub, bukan kode di sini.

---

## Kredensial

Key dibuat di **Desenvolvedor › Chaves de API** di workspace partner, diserahkan
lewat kanal aman, dan bisa dicabut kapan saja. Hanya hash yang disimpan di sisi
kita.

⚠️ **Syarat di sisi hub, bukan saran.** Pingly adalah **satu** tenant di hub dan
semua workspace berbagi scope di sana. Kalau key callback disimpan di
konfigurasi **global** hub, agent workspace mana pun bisa menulis ke conversation
partner ini dengan key-nya. Key harus **terikat per agent**, dan tak boleh
terlihat oleh model (bukan di prompt, bukan sebagai argumen skill yang bisa
diulang model di jawabannya).

## Diagnosis

```bash
# pesan proaktif yang masuk
docker compose logs queue app | grep 'the hub wrote into a conversation'

# kanal menolak
grep 'the channel did not take the message'

# ref ditolak — reason-nya hanya di log, tak pernah di response body
grep 'AiCallbackRef: refused a conversation reference'

# plafon per conversation kena
grep 'conversation ceiling reached'
```

Riwayat lengkapnya ada di barisnya sendiri, bukan hanya di log (produksi tak
menyimpan log lintas-deploy):

```sql
SELECT id, conversation_id, ai_hub_agent_id, status, created_at
FROM ai_proactive_messages ORDER BY id DESC LIMIT 20;
```

## Tes

- `tests/Feature/PublicApi/ProactiveMessageTest.php`
- `tests/Feature/AiHub/AiRunContactContextTest.php`
