<?php

/**
 * The platform's own WhatsApp messages, in Indonesian.
 *
 * See lang/pt_BR/notifications.php for the rules that govern this file — in
 * particular that placeholders are `{{name}}` and never Laravel's `:name`.
 *
 * Vocabulary follows the dashboard's own (see the glossary in CLAUDE.md), so a
 * message and the screen it sends someone to use the same words: paket, saldo,
 * isi saldo, langganan, dasbor. "Anda", never "kamu" — these are messages to a
 * business owner about money.
 */

return [

    'whatsapp_otp' => '🔐 Kode verifikasi Pingly Anda adalah *{{code}}*. Kode ini kedaluwarsa dalam {{ttl}} menit. Jangan bagikan kode ini kepada siapa pun.',

    'password_reset_otp' => '🔑 Halo {{name}}, kode untuk mengatur ulang kata sandi Chat Pingly Anda adalah *{{code}}*. Kode ini kedaluwarsa dalam {{ttl}} menit. Jika bukan Anda yang meminta, abaikan pesan ini dan jangan bagikan kodenya.',

    'password_changed' => '✅ Halo {{name}}, kata sandi akun Chat Pingly Anda diubah pada {{datetime}}. Jika bukan Anda yang melakukannya, segera hubungi dukungan.',

    'welcome_registration' => 'Halo {{name}}! 👋 Akun Chat Pingly Anda berhasil dibuat. Pilih paket untuk mulai.',

    'subscription_activated' => 'Selamat {{name}}! 🎉 Langganan paket {{plan}} Anda sudah aktif. Selamat bekerja!',

    'subscription_due' => 'Halo {{name}}, langganan {{plan}} Anda jatuh tempo pada {{due_date}}. Nominal: {{amount}}.',

    'subscription_past_due' => 'Halo {{name}}, kami belum menerima pembayaran langganan {{plan}} Anda. Segera selesaikan agar layanan tidak ditangguhkan.',

    'subscription_suspended' => 'Halo {{name}}, langganan {{plan}} Anda ditangguhkan karena pembayaran belum diterima. Anda bisa mengaktifkannya kembali kapan saja.',

    'apiway_purchase_activated' => 'Halo {{name}}! 🎉 {{quantity}} instance API Way Anda sudah aktif. Buka dasbor untuk memasangkan WhatsApp Anda.',

    'apiway_renewal_due' => 'Halo {{name}}, langganan API Way Anda jatuh tempo pada {{due_date}}. Nominal: {{amount}}. Perhatian: setelah jatuh tempo, instance dinonaktifkan permanen.',

    'apiway_expired' => 'Halo {{name}}, langganan API Way Anda telah berakhir dan instance-nya dinonaktifkan permanen. Sewa instance baru untuk melanjutkan.',

    'apiway_provision_failed' => 'Halo {{name}}, kami tidak berhasil mengaktifkan instance API Way Anda. Tim kami sudah diberi tahu dan akan menghubungi Anda.',

    'apiway_provision_refunded' => 'Halo {{name}}, kami tidak berhasil mengaktifkan instance API Way Anda dan {{amount}} sudah dikembalikan ke saldo Anda. Silakan coba lagi dari dasbor.',

    'apiway_renewal_no_credit' => 'Halo {{name}}, langganan API Way Anda jatuh tempo pada {{due_date}} dan saldo Anda tidak mencukupi untuk perpanjangan ({{amount}}). Isi saldo sebelum jatuh tempo: setelah itu instance dinonaktifkan permanen dan tidak bisa dipulihkan.',

    'credit_low_balance' => 'Halo {{name}}, saldo Anda menipis: tersisa {{amount}}. Isi saldo agar layanan AI dan instance Anda tetap berjalan.',

    'virtual_number_renewal_no_credit' => 'Halo {{name}}, nomor {{msisdn}} Anda diperpanjang pada {{due_date}} dan saldo Anda tidak mencukupi untuk perpanjangan ({{amount}}). Isi saldo sebelum jatuh tempo: tanpa saldo nomor dibatalkan dan tidak bisa dipulihkan.',

    'virtual_number_cancelled_no_credit' => 'Halo {{name}}, nomor {{msisdn}} Anda dibatalkan karena saldo tidak mencukupi untuk perpanjangan. Anda bisa menyewa nomor baru dari dasbor kapan saja.',

    'virtual_number_refunded' => 'Halo {{name}}, kami tidak berhasil mengaktifkan nomor virtual dan {{amount}} sudah dikembalikan ke saldo Anda. Silakan coba lagi dari dasbor.',

    'gallery_storage_renewal_no_credit' => 'Halo {{name}}, penyimpanan galeri tambahan Anda ({{gb}} GB) diperpanjang pada {{due_date}} dan saldo Anda tidak mencukupi untuk perpanjangan ({{amount}}). Isi saldo sebelum jatuh tempo. Berkas Anda tidak akan dihapus, tetapi unggahan baru ke galeri diblokir selama pemakaian melebihi batas.',

    'gallery_storage_cancelled_no_credit' => 'Halo {{name}}, penyimpanan galeri tambahan Anda ({{gb}} GB) berakhir karena saldo tidak mencukupi. Tidak ada berkas yang dihapus — semuanya tetap bisa dikirim. Untuk mengunggah berkas baru lagi, isi saldo dan sewa ruang penyimpanannya kembali.',

];
