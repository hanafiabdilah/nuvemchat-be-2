<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Email\EmailSmtpTransportFactory;
use App\Services\Message\MessageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

uses(RefreshDatabase::class);

class CapturingEmailTransport implements TransportInterface
{
    /** @var array<int, RawMessage> */
    public array $messages = [];

    public function __construct(private readonly ?\Throwable $exception = null)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
    {
        if ($this->exception) {
            throw $this->exception;
        }

        $this->messages[] = $message;

        return null;
    }

    public function __toString(): string
    {
        return 'capturing://smtp';
    }
}

class FakeEmailSmtpTransportFactory extends EmailSmtpTransportFactory
{
    public function __construct(public readonly CapturingEmailTransport $transport)
    {
    }

    public function make(array $credentials, string $password): TransportInterface
    {
        return $this->transport;
    }
}

function emailReplyConversation(?User $user = null): Conversation
{
    $user ??= User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->setRelation('tenant', $tenant);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Email,
        'name' => 'Suporte',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'email' => 'inbox@example.com',
            'password' => Crypt::encryptString('secret-password'),
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
        ],
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => 'customer@example.com',
        'channel' => Channel::Email,
        'name' => 'Cliente Teste',
        'username' => null,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'user_id' => $user->id,
        'external_id' => 'email:thread-1',
        'status' => ConversationStatus::Active,
    ]);
}

function emailReplyIncomingMessage(Conversation $conversation, array $overrides = []): Message
{
    return $conversation->messages()->create([
        'external_id' => $overrides['external_id'] ?? 'original-message@example.com',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $overrides['body'] ?? "Linha original 1\nLinha original 2",
        'sent_at' => $overrides['sent_at'] ?? Carbon::parse('2026-07-15 10:30:00'),
        'delivery_at' => $overrides['delivery_at'] ?? Carbon::parse('2026-07-15 10:30:00'),
        'meta' => [
            'email' => [
                'subject' => $overrides['subject'] ?? 'Pedido comercial',
                'from' => 'customer@example.com',
                'to' => ['inbox@example.com'],
                'message_id' => $overrides['message_id'] ?? 'original-message@example.com',
                'in_reply_to' => null,
                'references' => $overrides['references'] ?? ['root-message@example.com'],
            ],
        ],
    ]);
}

test('email reply sends threading headers and quoted original while storing only new text', function () {
    $transport = new CapturingEmailTransport();
    app()->instance(EmailSmtpTransportFactory::class, new FakeEmailSmtpTransportFactory($transport));

    $conversation = emailReplyConversation();
    $original = emailReplyIncomingMessage($conversation);

    $message = (new MessageService())->sendMessage($conversation, [
        'message' => 'Nova resposta do atendente',
        'replied_message_id' => $original->id,
    ]);

    expect($transport->messages)->toHaveCount(1)
        ->and($transport->messages[0])->toBeInstanceOf(Email::class)
        ->and($message->body)->toBe('Nova resposta do atendente')
        ->and($message->body)->not->toContain('Linha original 1')
        ->and($message->sender_type)->toBe(SenderType::Outgoing)
        ->and($message->message_type)->toBe(MessageType::Text)
        ->and($message->external_id)->toBe($message->meta['email']['message_id'])
        ->and($message->meta['email']['subject'])->toBe('Re: Pedido comercial')
        ->and($message->meta['email']['in_reply_to'])->toBe('original-message@example.com')
        ->and($message->meta['email']['references'])->toBe(['root-message@example.com', 'original-message@example.com']);

    /** @var Email $sentEmail */
    $sentEmail = $transport->messages[0];

    expect($sentEmail->getSubject())->toBe('Re: Pedido comercial')
        ->and($sentEmail->getHeaders()->get('In-Reply-To')->getBodyAsString())->toBe('<original-message@example.com>')
        ->and($sentEmail->getHeaders()->get('References')->getBodyAsString())->toBe('<root-message@example.com> <original-message@example.com>')
        ->and($sentEmail->getTextBody())->toContain("Nova resposta do atendente\n\nEm 15/07/2026 10:30, Cliente Teste <customer@example.com> escreveu:")
        ->and($sentEmail->getTextBody())->toContain("> Linha original 1\n> Linha original 2");
});

test('email reply subject does not duplicate re prefix', function () {
    $transport = new CapturingEmailTransport();
    app()->instance(EmailSmtpTransportFactory::class, new FakeEmailSmtpTransportFactory($transport));

    $conversation = emailReplyConversation();
    $original = emailReplyIncomingMessage($conversation, [
        'subject' => 'Re: Pedido comercial',
    ]);

    $message = (new MessageService())->sendMessage($conversation, [
        'message' => 'Continuando',
        'replied_message_id' => $original->id,
    ]);

    expect($message->meta['email']['subject'])->toBe('Re: Pedido comercial');
});

test('smtp authentication failure while sending email does not return http 401', function () {
    $this->withoutMiddleware();

    $transport = new CapturingEmailTransport(new TransportException('535 Authentication failed', 535));
    app()->instance(EmailSmtpTransportFactory::class, new FakeEmailSmtpTransportFactory($transport));

    $user = User::factory()->create();
    $conversation = emailReplyConversation($user);
    emailReplyIncomingMessage($conversation);

    Sanctum::actingAs($user);

    $response = $this->postJson("/api/conversations/{$conversation->id}/send-message", [
        'message' => 'Teste de falha',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Falha na autenticacao SMTP: usuario ou senha invalidos.');
});
