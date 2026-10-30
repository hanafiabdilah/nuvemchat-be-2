<?php

/**
 * CRM (App\Services\Crm, Api\Crm)
 *
 * Dalam bahasa Indonesia; lang/en dan lang/id memuat key yang sama.
 */

return [

    'customers' => [
        'not_found' => 'Pelanggan tidak ditemukan.',
        'no_access' => 'Anda tidak punya akses ke pelanggan ini.',
        'not_linked' => 'Kontak ini belum punya pelanggan.',
        'invalid_phone' => 'Masukkan nomor telepon beserta kode negaranya, misalnya 62812…',
        'tags_need_contact' => 'Tag disimpan di kontak kanal milik pelanggan, dan pelanggan ini belum punya kontak kanal.',
        'group_cannot_link' => 'Grup bukan orang dan tidak bisa ditautkan ke pelanggan.',
        'contact_not_linked' => 'Kontak itu bukan milik pelanggan ini.',
        'last_contact' => 'Ini satu-satunya kontak kanal pelanggan ini. Gabungkan pelanggannya saja.',
        'merge_same' => 'Pilih pelanggan lain untuk digabungkan.',
        'delete_has_contacts' => 'Pelanggan ini menghubungi dari :count kontak kanal. Pisahkan kontaknya atau gabungkan pelanggannya saja.',
        'delete_has_sales' => 'Pelanggan ini punya lead atau deal. Hapus dulu, atau gabungkan pelanggannya.',
        'relate_self' => 'Pelanggan tidak bisa dihubungkan dengan dirinya sendiri.',
        'deleted' => 'Pelanggan dihapus.',
        'linked' => 'Kontak ditautkan ke pelanggan.',
        'unlinked' => 'Kontak dipisahkan menjadi pelanggan tersendiri.',
        'merged' => 'Pelanggan digabungkan.',
        'relationship_added' => 'Hubungan ditambahkan.',
        'relationship_removed' => 'Hubungan dihapus.',
    ],

    'companies' => [
        'not_found' => 'Perusahaan tidak ditemukan.',
        'deleted' => 'Perusahaan dihapus. Pelanggannya tetap ada.',
    ],

    'custom_fields' => [
        'not_found' => 'Field tidak ditemukan.',
        'unknown' => 'Tidak ada field bernama ":key".',
        'invalid' => 'Nilai :field tidak sesuai dengan jenis field-nya.',
        'key_taken' => 'Field dengan key ini sudah ada.',
        'too_many' => 'Satu workspace maksimal punya :max field di sini.',
        'options_required' => 'Field pilihan butuh minimal satu opsi.',
        'deleted' => 'Field dihapus. Nilai yang sudah diisi tetap disimpan.',
    ],

    'leads' => [
        'not_found' => 'Lead tidak ditemukan.',
        'deleted' => 'Lead dihapus.',
        'converted_locked' => 'Lead ini sudah dikonversi menjadi deal; statusnya tidak bisa diubah lagi.',
        'not_convertible' => 'Hanya lead yang masih terbuka yang bisa dikonversi menjadi deal.',
    ],

    'deals' => [
        'not_found' => 'Deal tidak ditemukan.',
        'deleted' => 'Deal dihapus.',
        'stage_not_found' => 'Tahap itu tidak ada.',
        'stage_not_in_pipeline' => 'Tahap itu milik pipeline lain.',
        'customer_required' => 'Pilih pelanggannya, atau mulai dari percakapannya.',
    ],

    'pipelines' => [
        'default_name' => 'Penjualan',
        'default_stages' => [
            'new' => 'Baru',
            'qualified' => 'Terkualifikasi',
            'proposal' => 'Penawaran',
            'negotiation' => 'Negosiasi',
            'won' => 'Menang',
            'lost' => 'Kalah',
        ],
        'not_found' => 'Pipeline tidak ditemukan.',
        'stage_not_found' => 'Tahap tidak ditemukan.',
        'deleted' => 'Pipeline dihapus.',
        'too_many' => 'Satu workspace maksimal punya :max pipeline.',
        'too_many_stages' => 'Satu pipeline maksimal punya :max tahap.',
        'no_stages' => 'Pipeline ini belum punya tahap.',
        'needs_outcomes' => 'Pipeline butuh minimal satu tahap terbuka, satu tahap Menang, dan satu tahap Kalah.',
        'stage_has_deals' => 'Tahap ini berisi :count deal. Pilih tahap tujuan untuk memindahkannya.',
        'pipeline_has_deals' => 'Pipeline ini berisi :count deal. Pilih pipeline tujuan untuk memindahkannya.',
        'move_to_invalid' => 'Pilih tahap atau pipeline lain sebagai tujuan deal.',
        'stage_kind_locked' => 'Pindahkan dulu deal di tahap ini sebelum mengubah apakah tahap ini terbuka, menang, atau kalah.',
        'last_one' => 'Pipeline terakhir tidak bisa dihapus.',
        'order_incomplete' => 'Kirim semua tahap pipeline dalam urutan barunya.',
    ],

    'tasks' => [
        'not_found' => 'Tugas tidak ditemukan.',
        'deleted' => 'Tugas dihapus.',
        'remind_after_due' => 'Pengingat harus sebelum tenggat.',
    ],

    'activities' => [
        'not_found' => 'Aktivitas tidak ditemukan.',
        'deleted' => 'Aktivitas dihapus.',
        'system_entry' => 'Catatan yang dibuat otomatis oleh CRM tidak bisa diubah.',
        'not_yours' => 'Hanya orang yang mencatatnya, atau owner, yang bisa mengubahnya.',
    ],

    'subjects' => [
        'not_found' => 'Data itu tidak ditemukan.',
        'conversation_not_found' => 'Percakapan itu tidak ditemukan.',
        'no_customer' => 'Percakapan ini tidak punya orang di baliknya (grup tidak bisa menyimpan data penjualan).',
        'required' => 'Pilih tentang apa ini: pelanggan, lead, deal, atau perusahaan.',
    ],

    'segments' => [
        'not_found' => 'Segmen tidak ditemukan.',
        'deleted' => 'Segmen dihapus.',
        'too_many' => 'Satu workspace maksimal punya :max segmen.',
        'invalid_rule' => 'Aturan ini tidak valid.',
    ],

    'views' => [
        'not_found' => 'Tampilan tidak ditemukan.',
        'deleted' => 'Tampilan dihapus.',
        'too_many' => 'Anda maksimal bisa menyimpan :max tampilan di halaman ini.',
        'not_yours' => 'Hanya orang yang menyimpan tampilan ini, atau yang mengelola pengaturan CRM, yang bisa mengubahnya.',
        'share_forbidden' => 'Membagikan tampilan ke workspace butuh izin mengelola pengaturan CRM.',
    ],

    'scoring' => [
        'invalid_rule' => 'Aturan skor ini tidak valid.',
        'saved' => 'Skor lead disimpan.',
    ],

    'bulk' => [
        'forbidden' => 'Anda tidak punya izin untuk melakukan ini.',
        'too_many' => 'Aksi massal maksimal untuk :max data sekaligus. Persempit filternya dulu.',
        'invalid_tags' => 'Pilih tag dari workspace ini.',
        'row_failed' => 'Data ini tidak dapat diubah.',
    ],

    'import' => [
        'empty' => 'File butuh baris judul dan minimal satu pelanggan.',
        'too_many' => 'File berisi lebih dari :max pelanggan. Pecah menjadi beberapa file.',
        'expired' => 'Impor ini kedaluwarsa. Kirim filenya lagi.',
        'invalid' => 'Pilih cara memperlakukan pelanggan yang sudah ada.',
        'name_required' => 'Pilih kolom yang berisi nama pelanggan.',
        'name_empty' => 'Namanya kosong.',
        'unknown_status' => 'Status ":value" tidak dikenali dan dilewati.',
        'unknown_owner' => 'Tidak ada anggota workspace bernama atau ber-e-mail ":value"; penanggung jawab tidak diisi.',
        'unknown_tag' => 'Tidak ada tag bernama ":value".',
        'invalid_field' => 'Nilai untuk :field tidak sesuai dan dilewati.',
        'tags_need_contact' => 'Tag tidak dipasang: tag tersimpan di kontak kanal, dan pelanggan ini belum punya.',
        'matched_skipped' => 'Sudah menjadi pelanggan (:name); tidak diubah.',
    ],

    'automations' => [
        'attribute_actions' => 'aksi',
        'invalid_rule' => 'Bagian otomasi ini tidak valid.',
        'invalid_days' => 'Pilih jumlah hari dari 1 sampai :max.',
        'field_not_for_trigger' => 'Kondisi ini tidak berlaku untuk record yang memicu otomasi ini.',
        'action_not_for_trigger' => 'Aksi ini tidak bisa dijalankan pada record yang memicu otomasi ini.',
        'pick_people' => 'Pilih setidaknya satu orang.',
        'task_title_required' => 'Beri judul tugas (maksimal 255 karakter).',
        'message_required' => 'Tulis isi notifikasinya (maksimal 500 karakter).',
        'note_required' => 'Tulis catatannya (maksimal 2.000 karakter).',
        'not_found' => 'Ada yang disebut otomasi ini yang sudah tidak ada di workspace ini.',
        'not_found_automation' => 'Otomasi tidak ditemukan.',
        'too_many' => 'Satu workspace bisa memiliki maksimal :max otomasi.',
        'deleted' => 'Otomasi dihapus.',
    ],

    'notices' => [
        'assigned_many' => [
            'lead' => ':count lead diberikan kepada Anda',
            'deal' => ':count deal diberikan kepada Anda',
            'task' => ':count tugas diberikan kepada Anda',
        ],
    ],

];
