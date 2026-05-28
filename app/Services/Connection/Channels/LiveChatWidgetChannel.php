<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LiveChatWidgetChannel implements ChannelInterface
{
    public const TEMPLATE_TYPES = ['global', 'proxybr'];

    public function connect(Connection $connection, array $data): void
    {
        $existingAppId = $connection->credentials['app_id'] ?? null;
        $existingTemplateType = $connection->credentials['template_type'] ?? 'global';

        $data['app_id'] = $data['app_id'] ?? $existingAppId ?? (string) Str::uuid();
        $data['template_type'] = $data['template_type'] ?? $existingTemplateType;

        validator($data, [
            'app_id' => ['required', 'string', 'max:255'],
            'template_type' => ['required', 'string', 'in:' . implode(',', self::TEMPLATE_TYPES)],
        ])->validate();

        $appIdTaken = Connection::where('id', '!=', $connection->id)
            ->where('channel', Channel::LiveChatWidget)
            ->where('credentials->app_id', $data['app_id'])
            ->exists();

        if ($appIdTaken) {
            throw ValidationException::withMessages([
                'app_id' => 'The provided app_id is already in use for another Live Chat Widget connection.',
            ]);
        }

        $connection->update([
            'status' => Status::Active,
            'credentials' => array_merge((array) $connection->credentials, [
                'app_id' => $data['app_id'],
                'template_type' => $data['template_type'],
            ]),
        ]);

        Log::info('LiveChatWidget connected', [
            'connection_id' => $connection->id,
            'app_id' => $data['app_id'],
            'template_type' => $data['template_type'],
        ]);
    }

    public function disconnect(Connection $connection): void
    {
        $connection->update([
            'status' => Status::Inactive,
        ]);
    }

    public function checkStatus(Connection $connection)
    {
        // Live Chat Widget has no external dependency; status is owned locally.
        // We just ensure credentials carry app_id + template_type.
        $appId = $connection->credentials['app_id'] ?? null;
        $templateType = $connection->credentials['template_type'] ?? null;

        if (!$appId || !$templateType) {
            $connection->update(['status' => Status::Inactive]);
            return;
        }

        if ($connection->status !== Status::Active) {
            $connection->update(['status' => Status::Active]);
        }
    }
}
