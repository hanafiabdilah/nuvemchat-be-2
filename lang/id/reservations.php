<?php

/**
 * Reservasi (App\Services\Reservations, Api\Reservations)
 *
 * Dalam bahasa Indonesia; lang/en, lang/id dan lang/pt_BR punya kunci yang sama.
 */

return [
    'not_open' => 'Reservasi ini tidak bisa dijawab lagi — sudah diputuskan, atau waktunya sudah lewat.',
    'offer_time_required' => 'Pilih tanggal dan jam yang ditawarkan.',
    'offer_in_the_past' => 'Jam yang ditawarkan sudah lewat.',
    'service_required' => 'Pilih layanan.',
    'service_has_upcoming' => 'Layanan ini punya reservasi mendatang. Nonaktifkan untuk menghentikan reservasi baru, atau batalkan dulu reservasinya.',
    'only_manual_delete' => 'Hanya reservasi yang dicatat tim yang bisa dihapus. Batalkan reservasi ini saja.',
    'deposit_required' => 'Isi jumlah DP.',
    'price_required' => 'Isi harga.',
    'invalid_date' => 'Gunakan tanggal dengan format YYYY-MM-DD.',
    'invalid_time' => 'Gunakan jam dengan format HH:MM.',
    'conversation_exclusive' => 'Percakapan ini eksklusif milik agen lain.',
    'problems' => [
        'inactive' => 'Layanan ini sedang tidak menerima reservasi.',
        'closed' => 'Layanan tutup pada jam itu.',
        'not_a_slot' => 'Jam itu di luar jam buka, atau reservasi tidak selesai sebelum tutup.',
        'in_the_past' => 'Jam itu sudah lewat.',
        'too_soon' => 'Lebih cepat dari yang diterima layanan.',
        'too_far' => 'Lebih jauh ke depan dari yang diterima layanan.',
        'full' => 'Jam itu sudah penuh.',
        'day_full' => 'Hari itu ditandai penuh.',
        'party_too_large' => 'Lebih banyak orang dari yang diterima layanan per reservasi.',
    ],
];
