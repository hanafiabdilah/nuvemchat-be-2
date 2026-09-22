# Menjalankan flow dari antrean (`FLOW_QUEUE_ENABLED`)

## Masalahnya

Sebuah flow dieksekusi **di dalam request yang mengantarkan pesan pelanggan**.
Node Mensagem memanggil API kanal, node `http_request` memanggil endpoint apa
pun yang diketik penulis flow, dan webhook — beserta worker PHP-FPM yang
melayaninya — menunggu semuanya.

Aritmetikanya tidak ramah. Dengan `pm.max_children` 20 dan sebuah flow yang
menghabiskan 30 detik, dua puluh pesan masuk yang bersamaan mengisi seluruh
pool. Yang terjadi berikutnya bukan "workspace itu lambat" melainkan
**seluruh platform menjawab 502** — dashboard, Back Office, webhook kanal lain,
semuanya.

Dan tak ada yang perlu menyerang untuk sampai ke sana: satu endpoint pelanggan
yang menggantung di node `http_request` sudah cukup.

## Yang sudah ada sebelum ini

Sebagian jalur panas memang sudah di antrean, dan itu memperkecil masalahnya:

| | |
|---|---|
| Giliran AI | `RunAiAgentTurn` (+ debounce 8 dtk) |
| Unduhan media masuk | `DownloadInboundMedia` |
| Rantai pesan ber-jeda | `RunFlowMessageNode` |
| Tunggu balasan / timeout | `RunFlowWaitResponse*` |

Yang **tersisa sinkron** adalah giliran flow itu sendiri: node pertama sampai
node yang memarkir, termasuk tiap panggilan kirim dan tiap `http_request`.

## Perbaikannya

`App\Services\Flow\FlowRunner` — satu tempat yang memutuskan **di mana** sebuah
giliran flow berjalan. Delapan call site (tujuh handler chat + widget) tak lagi
membuat `FlowExecutor` sendiri:

```php
FlowRunner::start($conversation);
FlowRunner::resume($conversation, $userInput);
```

Dengan `FLOW_QUEUE_ENABLED=false` (default) ia memanggil executor **inline,
persis seperti sebelumnya** — executor yang sama, request yang sama, urutan yang
sama. Dengan `true` ia men-dispatch `App\Jobs\RunFlowTurn`.

## ⚠️ Kenapa default MATI

Ini **perubahan perilaku, bukan optimasi**:

1. **Balasan flow berhenti sinkron dengan pesan masuk.** Di jalur yang
   sehat itu selisih di bawah satu detik; di antrean yang tertahan itu terlihat.
2. **Mode gagalnya berubah.** Hari ini worker mati = flow tetap jalan. Sesudah
   ini worker mati = **flow berhenti total**. Itu bukan otomatis lebih buruk
   (AI sudah bergantung pada worker), tapi itu ketergantungan baru yang harus
   dipilih seseorang, bukan diwariskan diam-diam oleh sebuah deploy.

## ⚠️ Antrean mana

Default `FLOW_QUEUE=default` supaya menyalakannya **tidak butuh langkah ops** —
worker yang sudah ada mengambilnya. Pola yang sama dengan `config('queue.media')`
dan `AI_PRESENCE_QUEUE`.

Di platform yang ramai, **beri ia worker sendiri**. Sebuah giliran flow memblokir
worker-nya selama seluruh panggilan kanal yang ia lakukan, dan `default` adalah
tempat `RunAiAgentTurn` juga berjalan: dibiarkan bersama di bawah beban, satu
node `http_request` yang lambat menunda balasan AI untuk pelanggan orang lain.

Ini pelajaran yang sudah pernah dibayar di sini — lihat catatan
`SendAiHoldingMessage` di CLAUDE.md: **apa pun yang harus terjadi selama sebuah
giliran berjalan tak boleh dijadwalkan dari dalam giliran itu.**

## ⚠️ `$tries = 1`

Percobaan kedua atas sebuah giliran flow bukan percobaan kedua atas satu
pengiriman — ia **gelembung kedua di depan pelanggan yang sudah menerima yang
pertama**, dan eksekusi kedua dari apa pun yang dilakukan node `http_request`
pada sistem orang lain. Giliran yang melempar di-log lalu dijatuhkan; pesan
pelanggan berikutnya melanjutkan flow dari posisi yang sebenarnya, karena
posisinya ada di `flow_states`, bukan di job.

## ⚠️ Conversation dibaca ulang, tak pernah di-serialize

Antara webhook dan job, seorang agen bisa sudah mengambil alih thread, thread
bisa sudah diresolve, atau flow-nya sudah dilepas dari connection. Salinan yang
ter-serialize membawa keadaan sebelum semua itu. Job memuat ulang barisnya dan
penjagaan executor memutuskan, persis seperti saat inline.

## Plafon `http_request` ditegakkan saat runtime

`FlowBlueprint` memvalidasi `timeout` 1–120 detik **saat simpan**, tapi baris
yang ditulis sebelum aturan itu ada tidak tervalidasi, dan flow yang di-import
membawa apa pun isi berkasnya. `FlowExecutor::executeHttpNode()` kini men-clamp
ke `config('flow.http_max_timeout')` (env `FLOW_HTTP_MAX_TIMEOUT`, default 120).

Ini berlaku **terlepas dari saklar antrean** — selama flow masih inline, angka
itu adalah berapa lama satu worker PHP-FPM ditahan oleh endpoint yang tak pernah
menjawab.

## Cara menyalakannya

1. Pastikan worker `default` sehat (BO → Health → Processes).
2. ⚠️ `--timeout` worker minimal **240** (`RunFlowTurn::$timeout`).
3. `FLOW_QUEUE_ENABLED=true` di `/opt/pingly/.env`.
4. `up -d --force-recreate` **semua** container PHP — `env_file` hanya dibaca
   saat container start.
5. Kirim satu pesan ke flow uji dan pastikan balasannya datang.

Mematikannya kembali: ubah env-nya, recreate. Tak ada state yang tertinggal.

Tes: `tests/Feature/Flow/FlowRunnerTest.php`.
