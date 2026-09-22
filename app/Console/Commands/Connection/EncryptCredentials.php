<?php

namespace App\Console\Commands\Connection;

use App\Models\Connection;
use App\Services\Connection\ConnectionCredentials;
use Illuminate\Console\Command;

/**
 * Encrypt the channel secrets sitting in plaintext in `connections.credentials`.
 *
 * Rows written from now on are protected by the cast. This is for the ones
 * written before it existed, which is all of them — and it is a separate step
 * rather than a migration on purpose: it rewrites every connection on the
 * platform, and that is a thing an operator should start deliberately and watch,
 * not something a deploy does while nobody is looking.
 *
 * ⚠️ Nothing breaks while this has not run. Decryption is driven by a marker on
 * the value, so a plaintext row is simply handed back as it is. The only
 * consequence of waiting is that the secrets stay readable in a backup, which
 * is the thing being fixed.
 *
 * Safe to run repeatedly: a row whose secrets are already protected is left
 * alone rather than re-encrypted, so a second run reports nothing to do instead
 * of rewriting the table again.
 */
class EncryptCredentials extends Command
{
    protected $signature = 'connections:encrypt-credentials
                            {--dry-run : Report what would change and write nothing}';

    protected $description = 'Encrypt channel secrets stored in connections.credentials';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $converted = 0;
        $skipped = 0;
        $failed = 0;

        Connection::query()->orderBy('id')->chunkById(100, function ($connections) use ($dryRun, &$converted, &$skipped, &$failed) {
            foreach ($connections as $connection) {
                // ⚠️ The RAW column, not $connection->credentials. Reading
                // through the cast decrypts, and writing it back re-encrypts
                // with a fresh IV — so every row would look changed on every
                // run and this could never report "nothing left to do".
                $raw = $connection->getRawOriginal('credentials');
                $decoded = is_string($raw) ? json_decode($raw, true) : null;

                if (! is_array($decoded) || $decoded === []) {
                    $skipped++;

                    continue;
                }

                if (! ConnectionCredentials::needsProtecting($decoded)) {
                    $skipped++;

                    continue;
                }

                $this->line(sprintf(
                    '  #%d  %s  %s',
                    $connection->id,
                    str_pad((string) $connection->channel?->value, 20),
                    $connection->name ?? '',
                ));

                if ($dryRun) {
                    $converted++;

                    continue;
                }

                try {
                    // Timestamps left alone: protecting a stored secret is not
                    // an edit anybody made to the connection, and `updated_at`
                    // is what the dashboard reads to decide something changed.
                    $connection->timestamps = false;
                    $connection->credentials = $decoded;
                    $connection->save();

                    $converted++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error(sprintf('  #%d failed: %s', $connection->id, $e->getMessage()));
                }
            }
        });

        $this->newLine();
        $this->info(sprintf(
            '%s %d connection(s); %d already protected or empty%s.',
            $dryRun ? 'Would encrypt' : 'Encrypted',
            $converted,
            $skipped,
            $failed ? sprintf('; %d failed', $failed) : '',
        ));

        if ($dryRun && $converted > 0) {
            $this->comment('Run again without --dry-run to apply.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
