<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;

class AutomatedMessageService
{
    /**
     * Replace variables in message template with actual values
     */
    private function replaceVariables(string $message, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $message = str_replace('{{' . $key . '}}', $value, $message);
        }

        return $message;
    }

    /**
     * Get welcoming message for a new conversation
     */
    public function getWelcomingMessage(Connection $connection): ?string
    {
        if (empty($connection->welcoming_message)) {
            return null;
        }

        return $connection->welcoming_message;
    }

    /**
     * Get accept message when agent accepts a conversation
     */
    public function getAcceptMessage(Connection $connection, User $agent): ?string
    {
        if (empty($connection->accept_message)) {
            return null;
        }

        return $this->replaceVariables($connection->accept_message, [
            'agent_name' => $agent->name,
        ]);
    }

    /**
     * Get closing message when agent resolves a conversation
     */
    public function getClosingMessage(Connection $connection, User $agent): ?string
    {
        if (empty($connection->closing_message)) {
            return null;
        }

        return $this->replaceVariables($connection->closing_message, [
            'agent_name' => $agent->name,
        ]);
    }
}
