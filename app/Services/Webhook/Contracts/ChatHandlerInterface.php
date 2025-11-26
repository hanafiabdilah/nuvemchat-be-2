<?php

namespace App\Services\Webhook\Contracts;

use App\Models\Connection;

interface ChatHandlerInterface
{
    public function getConversationId(array $payload): ?string;
    public function getMessageId(array $payload): ?string;
    public function getMessageBody(array $payload): ?string;
    public function getMessageSentAt(array $payload): \Carbon\Carbon;

    public function handle(Connection $connection, array $payload);
}
