<?php

use App\Enums\Connection\Channel;
use App\Enums\Flow\FlowStateStatus;
use App\Models\Contact;
use App\Models\FlowState;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/*
 * {{contact.phone}} was offered in the flow's variable picker and was always
 * empty: contacts have no phone column, and the executor read one anyway. It
 * now comes from the channel address, through the same ContactIdentity that
 * campaigns and pixels use — a phone on WhatsApp, an e-mail on e-mail, and
 * nothing on channels whose address is an opaque id.
 */

/** The executor's field reader and interpolator, reachable from a test. */
function flowFieldReader(): FlowExecutor
{
    return new class extends FlowExecutor
    {
        public function read(FlowState $state, string $field)
        {
            return $this->getFieldValue($state, $field);
        }

        public function fill(FlowState $state, string $template): string
        {
            return $this->interpolateVariables($template, $state);
        }

        public function holds(FlowState $state, string $field, string $operator, string $value): bool
        {
            return $this->evaluateCondition($state, $field, $operator, $value);
        }
    };
}

/** A running flow on the fixture conversation, its contact swapped for one on $channel. */
function flowStateForContact(Channel $channel, string $address): FlowState
{
    [$conversation, $ai] = AiAgentFixtures::flow();

    $contact = Contact::create([
        'tenant_id' => $conversation->connection->tenant_id,
        'channel' => $channel,
        'external_id' => $address,
        'name' => 'Ana',
    ]);

    $conversation->update(['contact_id' => $contact->id]);

    return FlowState::create([
        'conversation_id' => $conversation->id,
        'flow_id' => $ai->flow_id,
        'current_node_id' => $ai->id,
        'state_data' => [],
        'status' => FlowStateStatus::Running,
    ]);
}

test('contact.phone is the WhatsApp number', function () {
    $state = flowStateForContact(Channel::WhatsappOfficial, '+55 (11) 99999-9999');
    $reader = flowFieldReader();

    expect($reader->read($state, 'contact.phone'))->toBe('5511999999999')
        ->and($reader->read($state, 'contact.email'))->toBeNull()
        ->and($reader->fill($state, 'Seu número: {{contact.phone}}'))->toBe('Seu número: 5511999999999')
        ->and($reader->holds($state, 'contact.phone', 'is_not_empty', ''))->toBeTrue();
});

test('contact.email is the address on the e-mail channel, and there is no phone', function () {
    $state = flowStateForContact(Channel::Email, 'Ana@Example.com');
    $reader = flowFieldReader();

    expect($reader->read($state, 'contact.email'))->toBe('ana@example.com')
        ->and($reader->read($state, 'contact.phone'))->toBeNull()
        ->and($reader->fill($state, 'Telefone: {{contact.phone}}.'))->toBe('Telefone: .');
});

test('an opaque channel id is never reported as a phone', function () {
    // A Telegram chat id is digits too — and not anybody's number.
    $state = flowStateForContact(Channel::Telegram, '123456789');
    $reader = flowFieldReader();

    expect($reader->read($state, 'contact.phone'))->toBeNull()
        ->and($reader->holds($state, 'contact.phone', 'is_empty', ''))->toBeTrue()
        // The fields a contact does have still read as before.
        ->and($reader->read($state, 'contact.name'))->toBe('Ana');
});

test('conversation.status reads as the status code, in conditions and in text', function () {
    $state = flowStateForContact(Channel::WhatsappOfficial, '5511999999999');
    $reader = flowFieldReader();

    // An enum instance used to come back: never equal to "pending", and a
    // message that interpolated it threw instead of sending.
    expect($reader->read($state, 'conversation.status'))->toBe('pending')
        ->and($reader->holds($state, 'conversation.status', 'equals', 'pending'))->toBeTrue()
        ->and($reader->fill($state, 'Status: {{conversation.status}}'))->toBe('Status: pending');
});
