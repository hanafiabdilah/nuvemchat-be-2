<?php

use App\Models\SystemHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The off-site backup runs on the VPS host, outside Laravel, and reports in
 * through `php artisan ops:heartbeat` so the Health page can show when it
 * stops.
 */
test('a declared process is recorded with its details', function () {
    $this->artisan('ops:heartbeat', [
        'name' => 'db-backup:offsite',
        '--meta' => ['file=daily-20261105.sql.gz', 'size_mb=74'],
    ])->assertSuccessful();

    $beat = SystemHeartbeat::where('name', 'db-backup:offsite')->first();

    expect($beat)->not->toBeNull()
        ->and($beat->meta)->toBe(['file' => 'daily-20261105.sql.gz', 'size_mb' => '74']);
});

test('an unknown name is refused instead of writing a row nobody watches', function () {
    $this->artisan('ops:heartbeat', ['name' => 'db-backup:ofsite'])->assertFailed();

    expect(SystemHeartbeat::count())->toBe(0);
});
