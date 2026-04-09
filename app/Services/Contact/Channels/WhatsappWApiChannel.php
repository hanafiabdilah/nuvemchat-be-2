<?php

namespace App\Services\Contact\Channels;

use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\ContactChannelInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WhatsappWApiChannel implements ContactChannelInterface
{
    public function addContact(Connection $connection, array $data): Contact
    {
        // Validate input
        validator($data, [
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string'],
        ])->validate();

        $phoneNumber = $data['phone_number'];
        $name = $data['name'];

        // Check if phone number exists on WhatsApp and get normalized data
        $phoneData = $this->verifyPhoneExists($connection, $phoneNumber);

        // Use normalized phone number from W-API
        $normalizedPhone = $phoneData['phoneNumber'];
        $lid = $phoneData['lid'] ?? null;

        // Check if contact already exists (unique by tenant_id, external_id, channel)
        $existingContact = Contact::where('tenant_id', $connection->tenant_id)
            ->where('external_id', $normalizedPhone)
            ->where('channel', $connection->channel)
            ->first();

        if ($existingContact) {
            throw ValidationException::withMessages([
                'phone_number' => 'This contact already exists in your account.',
            ]);
        }

        // Create the contact with normalized phone number
        $contact = Contact::create([
            'tenant_id' => $connection->tenant_id,
            'external_id' => $normalizedPhone,
            'username' => $normalizedPhone,
            'name' => $name,
            'channel' => $connection->channel,
        ]);

        Log::info('WhatsApp W-API: Contact created successfully', [
            'contact_id' => $contact->id,
            'phone_number' => $normalizedPhone,
            'lid' => $lid,
            'connection_id' => $connection->id,
        ]);

        return $contact;
    }

    /**
     * Verify if phone number exists on WhatsApp
     *
     * @return array Normalized phone data from W-API
     */
    private function verifyPhoneExists(Connection $connection, string $phoneNumber): array
    {
        $instanceId = $connection->credentials['instance_id'] ?? null;
        $token = $connection->credentials['token'] ?? null;

        if (!$instanceId || !$token) {
            throw new ConnectionException('Invalid connection credentials', 400);
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                ])
                ->get('https://api.w-api.app/v1/contacts/phone-exists', [
                    'instanceId' => $instanceId,
                    'phoneNumber' => $phoneNumber,
                ]);

            if ($response->failed()) {
                Log::error('WhatsApp W-API: Phone verification failed', [
                    'phone_number' => $phoneNumber,
                    'response' => $response->json(),
                    'status_code' => $response->status(),
                ]);

                throw new ConnectionException(
                    $response->json('message') ?? 'Failed to verify phone number',
                    $response->status()
                );
            }

            $result = $response->json();
            $exists = $result['exists'] ?? false;

            if (!$exists) {
                throw ValidationException::withMessages([
                    'phone_number' => 'This phone number is not registered on WhatsApp.',
                ]);
            }

            Log::info('WhatsApp W-API: Phone verified successfully', [
                'phone_number' => $phoneNumber,
                'normalized_phone' => $result['phoneNumber'] ?? null,
                'lid' => $result['lid'] ?? null,
                'exists' => $exists,
            ]);

            return [
                'phoneNumber' => $result['phoneNumber'] ?? $phoneNumber,
                'lid' => $result['lid'] ?? null,
            ];

        } catch (ValidationException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('WhatsApp W-API: Phone verification exception', [
                'phone_number' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException('Failed to verify phone number: ' . $e->getMessage(), 500);
        }
    }
}
