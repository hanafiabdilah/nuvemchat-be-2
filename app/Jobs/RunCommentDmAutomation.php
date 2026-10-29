<?php

namespace App\Jobs;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Services\Instagram\CommentDm\CommentDmAutomations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One Instagram comment against the account's "Comentário → Direct" rules,
 * off the webhook: sending the DM and the public reply is two Graph calls,
 * and Meta wants its webhook answered fast.
 *
 * One try: Instagram accepts a single private reply per comment, so a second
 * attempt could only fail — or, after a timeout that hid a success, look
 * like a duplicate to the person.
 */
class RunCommentDmAutomation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    /** @param  array<string, mixed>  $comment  the `value` of a `comments` webhook change */
    public function __construct(
        public int $connectionId,
        public array $comment,
    ) {}

    public function handle(CommentDmAutomations $automations): void
    {
        $connection = Connection::where('channel', Channel::Instagram)->find($this->connectionId);

        if (! $connection) {
            return;
        }

        $automations->run($connection, $this->comment);
    }
}
