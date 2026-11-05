<?php

namespace App\Console\Commands;

use App\Support\Heartbeat;
use Illuminate\Console\Command;

/**
 * Lets a process that is not Laravel report in to the Health page.
 *
 * The off-site database backup runs on the VPS host (cron + shell script),
 * outside every container, so it cannot call Heartbeat::ping() itself. It
 * calls this at the end of a successful run instead, and the Back Office then
 * turns a backup that stopped running into a red row rather than a discovery
 * made on the day a restore is needed.
 *
 * Only names declared in Heartbeat::PROCESSES are accepted: a typo in a cron
 * script would otherwise write a row nobody watches and leave the real one
 * looking dead.
 */
class RecordHeartbeat extends Command
{
    protected $signature = 'ops:heartbeat
        {name : A process declared in App\Support\Heartbeat::PROCESSES}
        {--meta=* : key=value details shown next to the beat (size, file…)}';

    protected $description = 'Record a heartbeat for a process that runs outside Laravel';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (! array_key_exists($name, Heartbeat::PROCESSES)) {
            $this->error("Unknown process '{$name}'. Declare it in App\\Support\\Heartbeat::PROCESSES first.");

            return self::FAILURE;
        }

        $meta = [];
        foreach ((array) $this->option('meta') as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            if (trim($key) !== '') {
                $meta[trim($key)] = $value;
            }
        }

        Heartbeat::ping($name, $meta);
        $this->info("Heartbeat recorded for {$name}.");

        return self::SUCCESS;
    }
}
