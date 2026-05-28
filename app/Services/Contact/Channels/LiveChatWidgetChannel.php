<?php

namespace App\Services\Contact\Channels;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\ContactChannelInterface;
use Illuminate\Support\Str;

class LiveChatWidgetChannel implements ContactChannelInterface
{
    public function addContact(Connection $connection, array $data): Contact
    {
        validator($data, [
            'name' => ['required', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $externalId = $data['external_id'] ?? (string) Str::uuid();

        return Contact::createFromExternalData(
            $connection,
            $externalId,
            $data['name'],
            $data['username'] ?? null,
        );
    }
}
