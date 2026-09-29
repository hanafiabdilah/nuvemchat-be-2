<?php

namespace App\Console\Commands\Media;

use App\Models\Broadcast;
use App\Models\FlowNode;
use App\Services\Media\MediaFilename;
use App\Services\Media\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Give flow, campaign and product media its name back.
 *
 * `media:restore-filenames` deliberately leaves `uploads/` alone, and this is
 * the command that finally deals with it — by copying rather than moving, which
 * is the only reason it is safe.
 *
 * ⚠️ **Copy, never move.** A scan of production found 677 `messages.attachment`
 * rows, 15 `messages.meta`, 8 `messages.body` and an audit-log entry pointing
 * into `uploads/`. Those are history: a record of the file a customer was
 * actually sent. Moving the file would rewrite the past into a 404, and
 * rewriting those rows to follow it would be worse — it would claim we sent
 * something we did not. So the original stays exactly where it is and keeps
 * serving, forever.
 *
 * What gets rewritten is only the three places a *future* send reads from:
 * `flow_nodes.data`, `broadcasts.payload` and `products.image_url`. A reference
 * this command fails to find therefore keeps working too, which is what makes
 * it safe to run before anybody is certain the list is complete.
 *
 * ⚠️ Only `{name}_{12-hex}.{ext}` is recoverable — the shape this platform
 * wrote for one release. Everything older is Laravel's `hashName()`
 * (`oLXug5pUMJYR…mp3`) and its name was never stored anywhere: `POST
 * /api/uploads` has no database row, so unlike message media there is no
 * `meta.filename` to recover from. On production that is 14 of 26 references,
 * and no amount of work here brings them back.
 *
 * The clean copy lands in the workspace folder the uploader writes to now,
 * `uploads/{tenant}/{name}`, so a file touched by this command is
 * indistinguishable from one uploaded today.
 */
class RestoreUploadFilenames extends Command
{
    protected $signature = 'media:restore-upload-filenames
        {--dry-run : List what would change and change nothing}
        {--tenant= : Only this workspace}
        {--limit=500 : Most references to rewrite in one pass}';

    protected $description = 'Copy flow/campaign/product media to its real filename and repoint the references';

    /** `{base}_{12 hex}.{ext}` — the one shape that carries a name plus a code. */
    private const CODED = '~^(?<base>.+)_[0-9a-f]{12}$~';

    /** Clean copies made this run, keyed by "{source path}|{tenant}". */
    private array $copied = [];

    private int $rewritten = 0;

    private int $unrecoverable = 0;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $onlyTenant = $this->option('tenant') === null ? null : (int) $this->option('tenant');

        // ⚠️ `LIKE '%uploads%'` without the slash on purpose: a JSON cast escapes
        // it, so what actually sits in these columns is `uploads\/`. Matching on
        // `uploads/` finds none of them — which is how the first scan of
        // production reported zero flow nodes.
        foreach (FlowNode::whereRaw("data LIKE '%uploads%'")->with('flow')->get() as $node) {
            if ($this->rewritten >= $limit) {
                break;
            }

            $tenant = $node->flow?->tenant_id;

            if ($tenant === null || ($onlyTenant !== null && $tenant !== $onlyTenant)) {
                continue;
            }

            $this->rewriteJson(
                "flow_nodes#{$node->id}",
                $tenant,
                $node->data ?? [],
                $dryRun,
                fn (array $data) => FlowNode::whereKey($node->getKey())->toBase()->update([
                    'data' => json_encode($data),
                ]),
            );
        }

        foreach (Broadcast::whereRaw("payload LIKE '%uploads%'")->get() as $broadcast) {
            if ($this->rewritten >= $limit) {
                break;
            }

            if ($onlyTenant !== null && $broadcast->tenant_id !== $onlyTenant) {
                continue;
            }

            // Safe on a campaign that is mid-send: the bytes at the new address
            // are a copy of the bytes at the old one, so a recipient reached
            // before the rewrite and one reached after get the same file.
            $this->rewriteJson(
                "broadcasts#{$broadcast->id}",
                (int) $broadcast->tenant_id,
                $broadcast->payload ?? [],
                $dryRun,
                fn (array $payload) => Broadcast::whereKey($broadcast->getKey())->toBase()->update([
                    'payload' => json_encode($payload),
                ]),
            );
        }

        // Guarded: the catalog is a recent table, and a cleanup command has no
        // business being the thing that fails on an install that has not
        // migrated to it yet.
        $products = Schema::hasTable('products') ? DB::table('products')
            ->whereRaw("image_url LIKE '%uploads%'")
            ->when($onlyTenant !== null, fn ($q) => $q->where('tenant_id', $onlyTenant))
            ->get(['id', 'tenant_id', 'image_url']) : collect();

        foreach ($products as $product) {
            if ($this->rewritten >= $limit) {
                break;
            }

            $fresh = $this->rewriteUrl((string) $product->image_url, (int) $product->tenant_id, $dryRun);

            if ($fresh === null) {
                continue;
            }

            $this->line(($dryRun ? '  would rewrite ' : '  rewrote ')."products#{$product->id}");
            $this->rewritten++;

            if (! $dryRun) {
                DB::table('products')->where('id', $product->id)->update(['image_url' => $fresh]);
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%s %d reference(s), %s %d file(s). %d reference(s) had no name to recover.',
            $dryRun ? 'Would rewrite' : 'Rewrote',
            $this->rewritten,
            $dryRun ? 'would copy' : 'copied',
            count($this->copied),
            $this->unrecoverable,
        ));

        if ($this->unrecoverable > 0) {
            $this->newLine();
            $this->warn('Those are Laravel hashName() uploads from before the platform kept the');
            $this->line('  original name. POST /api/uploads writes no database row, so there is no');
            $this->line('  record of what they were called. They keep working under the name they have.');
        }

        return self::SUCCESS;
    }

    /**
     * Rewrite every upload URL inside a JSON column and hand the result back to
     * the caller to persist.
     *
     * @param  array<mixed>  $data
     * @param  callable(array<mixed>): mixed  $persist
     */
    private function rewriteJson(string $label, int $tenant, array $data, bool $dryRun, callable $persist): void
    {
        $changed = false;

        $walk = function (mixed $value) use (&$walk, $tenant, $dryRun, &$changed): mixed {
            if (is_array($value)) {
                return array_map($walk, $value);
            }

            if (! is_string($value) || ! str_contains($value, 'uploads/')) {
                return $value;
            }

            $fresh = $this->rewriteUrl($value, $tenant, $dryRun);

            if ($fresh === null) {
                return $value;
            }

            $changed = true;

            return $fresh;
        };

        $updated = $walk($data);

        if (! $changed) {
            return;
        }

        $this->line(($dryRun ? '  would rewrite ' : '  rewrote ').$label);
        $this->rewritten++;

        if (! $dryRun) {
            $persist($updated);
        }
    }

    /**
     * The same URL pointing at a clean copy, or null when there is nothing to
     * gain — not an upload URL, already clean, or a name that was never stored.
     */
    private function rewriteUrl(string $url, int $tenant, bool $dryRun): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || ! str_contains($path, '/uploads/')) {
            return null;
        }

        // Everything from `uploads/` on is the storage path; what precedes it is
        // the bucket prefix or the app host, and neither is ours to assume.
        $from = 'uploads/'.rawurldecode(explode('/uploads/', $path, 2)[1]);

        // Already in a workspace folder: nothing to do.
        if (preg_match('~^uploads/\d+/~', $from) === 1) {
            return null;
        }

        $name = basename($from);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        if (preg_match(self::CODED, $base, $matches) !== 1) {
            $this->unrecoverable++;

            return null;
        }

        $clean = MediaFilename::name($matches['base'], $extension);
        $key = $from.'|'.$tenant;

        if (isset($this->copied[$key])) {
            return MediaStorage::publishedUrl($this->copied[$key]);
        }

        $disk = MediaStorage::published();

        if (! $disk->exists($from)) {
            $this->warn("  skipped {$from}: not on the published disk");

            return null;
        }

        $to = $this->freePath($tenant, $clean);

        $this->line("    {$name} → ".basename($to));

        if (! $dryRun && ! rescue(fn () => $disk->copy($from, $to), false, false)) {
            Log::warning('media:restore-upload-filenames could not copy a file', [
                'from' => $from,
                'to' => $to,
            ]);
            $this->warn("  skipped {$from}: copy failed");

            return null;
        }

        $this->copied[$key] = $to;

        return MediaStorage::publishedUrl($to);
    }

    /**
     * `uploads/{tenant}/{name}`, numbered if something is already there.
     *
     * A name already taken in the workspace folder is most likely the same file
     * uploaded again by hand — but "most likely" is not good enough to point a
     * live flow at somebody else's bytes, so this keeps both.
     */
    private function freePath(int $tenant, string $name): string
    {
        $disk = MediaStorage::published();
        $folder = "uploads/{$tenant}";
        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? ".{$extension}" : '';

        if (! $disk->exists("{$folder}/{$name}")) {
            return "{$folder}/{$name}";
        }

        for ($n = 2; $n <= 999; $n++) {
            if (! $disk->exists("{$folder}/{$base} ({$n}){$suffix}")) {
                return "{$folder}/{$base} ({$n}){$suffix}";
            }
        }

        return "{$folder}/{$base} (".MediaFilename::token().")".$suffix;
    }
}
