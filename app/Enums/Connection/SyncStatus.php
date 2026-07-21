<?php

namespace App\Enums\Connection;

enum SyncStatus: string
{
    case Idle = 'idle';
    case Syncing = 'syncing';
    case Failed = 'failed';
}
