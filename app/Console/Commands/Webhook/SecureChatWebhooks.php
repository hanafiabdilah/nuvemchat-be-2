<?php

namespace App\Console\Commands\Webhook;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Models\Connection;
use App\Services\Connection\Channels\TelegramChannel;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use App\Services\Webhook\ChatWebhookSecret;
use Illuminate\Console\Command;

/**
 * Give every live Telegram and API Way connection a webhook secret, upstream
 * first.
 *
 * /webhook/chat/{id} used to accept anything, so every connection registered
 * before this existed has its webhook pointing at a URL that carries no
 * credential. The endpoint still serves those (see ChatWebhookSecret) — this is
 * what stops it having to.
 *
 *     php artisan webhooks:secure-chat --dry-run
 *     php artisan webhooks:secure-chat
 *
 * Idempotent: a connection that already has a secret keeps it, and the upstream
 * registration is simply re-asserted. Safe to run again after a partial pass,
 * which is the expected outcome when a customer's instance is offline.
 *
 * ⚠️ Order matters and is the same in both channels: register upstream, then
 * store. A stored secret the sender was never told about is the one state that
 * loses real messages, so it is never reached — if registration fails, nothing
 * is written and the connection stays on the tolerated path.
 *
 * ⚠️ API Way instances that are not paired answer nothing, so they cannot be
 * secured until they are. Those are reported, not retried: their inbound path
 * is dead anyway, and connect() secures them the moment somebody pairs them.
 */
class SecureChatWebhooks extends Command
{
    protected $signature = 'webhooks:secure-chat
        {--connection= : Only this connection id}
        {--dry-run : List what would be done and change nothing}
        {--rotate : Replace secrets that are already stored}';

    protected $description = 'Register a per-connection secret on Telegram and API Way inbound webhooks';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rotate = (bool) $this->option('rotate');

        $connections = Connection::query()
            ->whereIn('channel', [Channel::Telegram->value, Channel::WhatsappApiway->value])
            ->where('status', Status::Active->value)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No active Telegram or API Way connections.');

            return self::SUCCESS;
        }

        $secured = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($connections as $connection) {
            $label = "#{$connection->id} {$connection->channel->value} \"{$connection->name}\"";
            $already = ChatWebhookSecret::of($connection) !== null;

            if ($already && ! $rotate) {
                $this->line("  <fg=gray>skip</>   {$label} — already secured");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  <fg=yellow>would</>  {$label}".($already ? ' — rotate' : ''));
                $secured++;

                continue;
            }

            if ($rotate) {
                // Cleared first so the channel mints a fresh value rather than
                // re-asserting the one already stored.
                $this->forget($connection);
            }

            try {
                $this->register($connection);
            } catch (\Throwable $e) {
                $this->line("  <fg=red>fail</>   {$label} — {$e->getMessage()}");
                $failed++;

                continue;
            }

            if (ChatWebhookSecret::of($connection->refresh()) === null) {
                // API Way swallows its own registration errors and only logs;
                // an unstored secret is how that surfaces here.
                $this->line("  <fg=red>fail</>   {$label} — upstream did not accept the webhook (instance offline?)");
                $failed++;

                continue;
            }

            $this->line("  <fg=green>ok</>     {$label}");
            $secured++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry run] ' : '')."secured: {$secured}   already secured: {$skipped}   failed: {$failed}");

        if ($failed > 0) {
            $this->warn('Re-run once those connections are reachable. Do not set WEBHOOK_CHAT_STRICT=true while any remain.');

            return self::FAILURE;
        }

        if (! $dryRun && $secured > 0) {
            $this->info('Check Back Office → Health → "Chat webhook secrets" before setting WEBHOOK_CHAT_STRICT=true.');
        }

        return self::SUCCESS;
    }

    private function register(Connection $connection): void
    {
        match ($connection->channel) {
            Channel::Telegram => app(TelegramChannel::class)->refreshWebhook($connection),
            Channel::WhatsappApiway => app(WhatsappApiwayChannel::class)->registerWebhook($connection),
            default => null,
        };
    }

    /** Drop the stored secret so the channel mints a new one. */
    private function forget(Connection $connection): void
    {
        $credentials = $connection->credentials ?? [];
        unset($credentials[ChatWebhookSecret::CREDENTIAL_KEY]);

        $connection->update(['credentials' => $credentials]);
        $connection->refresh();
    }
}
