<?php

namespace App\Services\Message;

use App\Models\Conversation;
use App\Models\Message;
use Carbon\Carbon;

interface MessageHandlerInterface
{
    public function getMessageId(array $payload): string;
    public function getMessageSentAt(array $payload): Carbon;

    public function handleSendMessage(Conversation $conversation, array $data): ?Message;
    public function handleSendImage(Conversation $conversation, array $data): ?Message;
    public function handleSendAudio(Conversation $conversation, array $data): ?Message;
    public function handleSendVideo(Conversation $conversation, array $data): ?Message;
}
