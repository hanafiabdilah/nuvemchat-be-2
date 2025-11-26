<?php

namespace App\Services\V1\SendMessage;

use App\Models\Connection;
use Carbon\Carbon;

interface SendMessageHandlerInterface
{
    public function getConversationId(array $payload): string;
    public function getMessageId(array $payload): string;
    public function getMessageSentAt(array $payload): Carbon;

    public function handle(Connection $connection, array $data);
}
