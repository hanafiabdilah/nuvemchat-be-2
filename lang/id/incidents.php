<?php

return [
    'errors' => [
        'over' => 'Insiden ini sudah berakhir dan tidak bisa diubah lagi.',
        'not_active' => 'Insiden ini tidak aktif.',
        'not_scheduled' => 'Hanya insiden terjadwal yang bisa dibatalkan. Untuk mengakhiri insiden aktif, selesaikan insiden tersebut.',
        'cannot_send' => 'Pemberitahuan ini tidak bisa dikirim manual.',
        'overlap' => 'Insiden ":title" sudah mencakup :connections pada waktu tersebut. Akhiri insiden itu atau pilih koneksi lain.',
        'keywords_required' => 'Tambahkan minimal satu kata kunci, atau pilih "Semua pesan".',
        'keyword_too_short' => 'Kata kunci ":keyword" terlalu pendek. Gunakan minimal 3 huruf.',
        'connection_unknown' => 'Salah satu koneksi yang dipilih tidak ada atau Anda tidak punya akses ke koneksi itu.',
        'connection_email' => 'Insiden tidak berlaku untuk koneksi e-mail.',
        'min_duration' => 'Insiden harus berlangsung minimal 15 menit.',
        'max_duration' => 'Insiden paling lama berlangsung 72 jam.',
        'ends_in_past' => 'Waktu berakhir insiden harus di masa depan.',
        'bad_time' => 'Tanggal atau jam tidak valid.',
        'resolution_required' => 'Tulis pesan penutup, atau matikan pengirimannya.',
        'ai_agent_required' => 'Pilih agen AI aktif di workspace ini untuk menulis pemberitahuan.',
        'ai_brief_required' => 'Tulis apa yang perlu disampaikan AI kepada pelanggan tentang insiden ini.',
        'template_limit' => 'Workspace ini sudah mencapai jumlah maksimum template insiden. Hapus template yang tidak dipakai lagi.',
    ],
];
