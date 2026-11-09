<?php

/*
 * Words that go inside the WhatsApp alerts (App\Services\Notification\WhatsappAlerts):
 * the reasons and periods each template's placeholders are filled with, in the
 * recipient's language. The sentences themselves are the notification templates.
 */

return [
    'connection_reasons' => [
        'logged_out' => 'nomor dilepas dari HP atau sesinya diakhiri oleh WhatsApp',
        'offline' => 'nomor sudah offline lebih dari 5 menit',
        'token_revoked' => 'akses yang diberikan Meta kedaluwarsa atau dicabut',
        'app_removed' => 'aplikasi dihapus di pengaturan Facebook',
        'partner_removed' => 'nomor diputus dari aplikasi WhatsApp Business',
    ],
    'receipt_reasons' => [
        'not_configured' => 'belum ada agen AI di langkah flow',
        'unreadable' => 'berkas tidak dapat dibaca',
        'not_a_receipt' => 'berkas bukan bukti pembayaran',
        'not_completed' => 'pembayarannya belum selesai',
        'amount_unreadable' => 'nominalnya tidak terbaca',
        'amount_mismatch' => 'nominalnya lebih kecil dari yang diharapkan',
        'recipient_mismatch' => 'dibayarkan ke orang lain',
        'duplicate' => 'bukti ini sudah pernah dipakai',
        'ai_unavailable' => 'AI tidak dapat memeriksanya',
        'unknown' => 'tidak dapat dikonfirmasi',
    ],
    'unnamed_flow' => 'tanpa nama',
    'unknown_reason' => 'tidak disebutkan',
    'period_hour' => 'antara :from dan :to',
    'period_day' => 'kemarin (:date)',
    'details_connection' => '• :connection: :count',
    'details_sales' => 'Penjualan: :count (:amount)',
    'period_week' => 'minggu lalu (:from–:to)',
    'details_cost_per_lead' => 'Biaya per lead: :amount',
    'details_cost_per_sale' => 'Biaya per penjualan: :amount',
    'details_top_ad' => 'Iklan dengan biaya tertinggi: :ad (:spend, ROAS :roas)',
    'details_unconverted' => 'Akun dalam :currencies tidak dihitung (kurs belum diatur).',
];
