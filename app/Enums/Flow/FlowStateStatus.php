<?php

namespace App\Enums\Flow;

enum FlowStateStatus: string
{
    case Running = 'running';      // Flow sedang berjalan
    case Completed = 'completed';  // Flow selesai natural (tidak ada edge berikutnya)
    case Stopped = 'stopped';      // Flow dihentikan karena admin handover
    case Failed = 'failed';        // Flow error/gagal
}
