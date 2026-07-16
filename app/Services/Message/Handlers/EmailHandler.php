<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Exceptions\ConnectionException;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Email\EmailSmtpTransportFactory;
use App\Services\Message\MessageHandlerInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EmailHandler implements MessageHandlerInterface
{
    public function getMessageId(array $payload): string
    {
        return $payload['message_id'];
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        return isset($payload['date'])
            ? Carbon::createFromTimestamp((int) $payload['date'])
            : Carbon::now();
    }

    public function handleSendMessage(Conversation $conversation, array $data): ?Message
    {
        // E-mail reply may carry text and/or multiple file attachments.
        validator($data, [
            'message' => ['required_without:attachments', 'nullable', 'string'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['file', 'max:25600'],
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        return $this->sendEmailReply($conversation, $data, MessageType::Text);
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        $this->rejectRemoteAttachment($data);

        validator($data, [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        return $this->sendEmailReply($conversation, $data, MessageType::Image, 'image');
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        $this->rejectRemoteAttachment($data);

        validator($data, [
            'audio' => 'required|file|mimes:ogg,mp3,wav,m4a,opus,webm|max:16384',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        return $this->sendEmailReply($conversation, $data, MessageType::Audio, 'audio');
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        $this->rejectRemoteAttachment($data);

        validator($data, [
            'video' => 'required|file|mimes:mp4,avi,mov,wmv,flv,webm,mkv|max:51200',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        return $this->sendEmailReply($conversation, $data, MessageType::Video, 'video');
    }

    public function handleSendDocument(Conversation $conversation, array $data): ?Message
    {
        $this->rejectRemoteAttachment($data);

        validator($data, [
            'document' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,csv|max:102400',
            'message' => 'nullable|string',
            'replied_message_id' => 'nullable|integer|exists:messages,id',
        ])->validate();

        return $this->sendEmailReply($conversation, $data, MessageType::Document, 'document');
    }

    public function handleEditMessage(Message $message, array $data): ?Message
    {
        throw new \RuntimeException('E-mail enviado nao pode ser editado/apagado');
    }

    public function handleDeleteMessage(Message $message): bool
    {
        throw new \RuntimeException('E-mail enviado nao pode ser editado/apagado');
    }

    /**
     * Send a brand-new e-mail (compose), not a reply: an explicit subject and
     * body, no quoted original and no In-Reply-To/References threading headers.
     * The conversation must already carry the recipient on its contact.
     *
     * @param array<int, UploadedFile> $attachments
     */
    public function sendNewEmail(Conversation $conversation, string $subject, string $body, array $attachments = []): Message
    {
        $connection = $conversation->connection;
        $credentials = $this->credentials($connection->credentials ?? []);
        $password = $this->decryptPassword($credentials);
        $recipient = $this->recipientEmail($conversation);

        $newText = trim($body);
        $subject = trim($subject);
        if ($subject === '') {
            $subject = '(no subject)';
        }
        $messageId = $this->generateMessageId($credentials['email']);

        $email = (new Email())
            ->from(new Address($credentials['email'], $connection->name ?: $credentials['email']))
            ->to(new Address($recipient, $conversation->contact?->name ?: ''))
            ->subject($subject)
            ->text($newText);

        $email->getHeaders()->addIdHeader('Message-ID', $messageId);

        $files = array_values(array_filter($attachments, fn ($file) => $file instanceof UploadedFile));
        $this->attachFilesToEmail($email, $files);

        try {
            (new Mailer(app(EmailSmtpTransportFactory::class)->make($credentials, $password)))->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logSendFailure($conversation, $exception);

            if ($this->isSmtpAuthenticationFailure($exception)) {
                throw new ConnectionException('Falha na autenticacao SMTP: usuario ou senha invalidos.', 422, previous: $exception);
            }

            throw new ConnectionException('Falha ao enviar e-mail pelo servidor SMTP.', 502, previous: $exception);
        } catch (\Throwable $exception) {
            $this->logSendFailure($conversation, $exception);

            throw new ConnectionException('Falha ao enviar e-mail pelo servidor SMTP.', 502, previous: $exception);
        }

        $storedAttachments = $this->storeFiles($messageId, $files);

        $emailMeta = [
            'subject' => $subject,
            'from' => $credentials['email'],
            'to' => [$recipient],
            'message_id' => $messageId,
        ];
        if ($storedAttachments !== []) {
            $emailMeta['attachments'] = $storedAttachments;
        }

        $message = $conversation->messages()->create([
            'external_id' => $messageId,
            'sender_type' => SenderType::Outgoing,
            'message_type' => MessageType::Text,
            'body' => $newText,
            'attachment' => $storedAttachments[0]['path'] ?? null,
            'sent_at' => now(),
            'delivery_at' => now(),
            'meta' => ['email' => $emailMeta],
        ]);

        return $message->refresh();
    }

    private function sendEmailReply(
        Conversation $conversation,
        array $data,
        MessageType $messageType,
        ?string $attachmentField = null
    ): Message {
        $connection = $conversation->connection;
        $credentials = $this->credentials($connection->credentials ?? []);
        $password = $this->decryptPassword($credentials);
        $recipient = $this->recipientEmail($conversation);
        $original = $this->originalMessage($conversation, $data['replied_message_id'] ?? null);

        $newText = trim((string) ($data['message'] ?? ''));
        $messageId = $this->generateMessageId($credentials['email']);
        $subject = $this->replySubject($original?->meta['email']['subject'] ?? null);
        $originalMessageId = $original?->meta['email']['message_id'] ?? $original?->external_id;
        $references = $this->references($original?->meta['email']['references'] ?? [], $originalMessageId);
        $body = $this->bodyWithQuote($newText, $conversation, $original);

        $email = (new Email())
            ->from(new Address($credentials['email'], $connection->name ?: $credentials['email']))
            ->to(new Address($recipient, $conversation->contact?->name ?: ''))
            ->subject($subject)
            ->text($body);

        $email->getHeaders()->addIdHeader('Message-ID', $messageId);

        if ($originalMessageId) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $this->cleanMessageId($originalMessageId));
            $email->getHeaders()->addIdHeader('References', array_map(
                fn (string $reference) => $this->cleanMessageId($reference),
                $references
            ));
        }

        // A single-field media upload (send-image/video/document/audio) plus any
        // multi-file "attachments[]" are all attached to the same e-mail.
        $files = $this->collectAttachments($data, $attachmentField);
        $this->attachFilesToEmail($email, $files);

        try {
            (new Mailer(app(EmailSmtpTransportFactory::class)->make($credentials, $password)))->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logSendFailure($conversation, $exception);

            if ($this->isSmtpAuthenticationFailure($exception)) {
                throw new ConnectionException('Falha na autenticacao SMTP: usuario ou senha invalidos.', 422, previous: $exception);
            }

            throw new ConnectionException('Falha ao enviar e-mail pelo servidor SMTP.', 502, previous: $exception);
        } catch (\Throwable $exception) {
            $this->logSendFailure($conversation, $exception);

            throw new ConnectionException('Falha ao enviar e-mail pelo servidor SMTP.', 502, previous: $exception);
        }

        $storedAttachments = $this->storeFiles($messageId, $files);

        $emailMeta = [
            'subject' => $subject,
            'from' => $credentials['email'],
            'to' => [$recipient],
            'message_id' => $messageId,
            'in_reply_to' => $originalMessageId,
            'references' => $references,
        ];
        if ($storedAttachments !== []) {
            $emailMeta['attachments'] = $storedAttachments;
        }

        $message = $conversation->messages()->create([
            'external_id' => $messageId,
            'sender_type' => SenderType::Outgoing,
            'message_type' => $messageType,
            'body' => $newText,
            'attachment' => $storedAttachments[0]['path'] ?? null,
            'replied_message_id' => $original?->id,
            'sent_at' => now(),
            'delivery_at' => now(),
            'meta' => ['email' => $emailMeta],
        ]);

        return $message->refresh();
    }

    private function credentials(array $credentials): array
    {
        foreach (['email', 'password', 'smtp_host', 'smtp_port', 'smtp_encryption'] as $key) {
            if (!isset($credentials[$key]) || $credentials[$key] === '') {
                throw new ConnectionException('Credenciais de e-mail ausentes. Conecte a caixa novamente.', 422);
            }
        }

        return $credentials;
    }

    private function decryptPassword(array $credentials): string
    {
        try {
            return Crypt::decryptString((string) $credentials['password']);
        } catch (DecryptException $exception) {
            throw new ConnectionException('Credenciais de e-mail invalidas. Conecte a caixa novamente.', 422, previous: $exception);
        }
    }

    private function recipientEmail(Conversation $conversation): string
    {
        $email = trim((string) $conversation->contact?->external_id);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'recipient' => 'Contato da conversa nao possui um endereco de e-mail valido.',
            ]);
        }

        return strtolower($email);
    }

    private function originalMessage(Conversation $conversation, mixed $repliedMessageId): ?Message
    {
        if ($repliedMessageId) {
            $message = $conversation->messages()
                ->where('id', $repliedMessageId)
                ->whereNotNull('meta')
                ->first();

            if ($message && isset($message->meta['email'])) {
                return $message;
            }
        }

        return $conversation->messages()
            ->where('sender_type', SenderType::Incoming->value)
            ->whereNotNull('meta')
            ->latest('sent_at')
            ->latest('id')
            ->get()
            ->first(fn (Message $message) => isset($message->meta['email']));
    }

    private function replySubject(?string $subject): string
    {
        $subject = trim((string) $subject);

        if ($subject === '') {
            $subject = '(no subject)';
        }

        return preg_match('/^\s*re\s*:/i', $subject) ? $subject : 'Re: ' . $subject;
    }

    private function bodyWithQuote(string $newText, Conversation $conversation, ?Message $original): string
    {
        if (!$original) {
            return $newText;
        }

        $emailMeta = $original->meta['email'] ?? [];
        $fromEmail = (string) ($emailMeta['from'] ?? $conversation->contact?->external_id ?? '');
        $fromName = $conversation->contact?->name ?: $fromEmail;
        $date = $this->messageDate($original);
        $quotedBody = $this->quoteText((string) $original->body);

        return rtrim($newText) . "\n\n"
            . "Em {$date}, {$fromName} <{$fromEmail}> escreveu:\n"
            . $quotedBody;
    }

    private function messageDate(Message $message): string
    {
        $timestamp = $message->sent_at ?: $message->created_at?->timestamp;

        return Carbon::createFromTimestamp((int) $timestamp)
            ->timezone(config('app.timezone'))
            ->format('d/m/Y H:i');
    }

    private function quoteText(string $body): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body));

        if (!$lines || $lines === ['']) {
            return '> ';
        }

        return implode("\n", array_map(fn (string $line) => '> ' . $line, $lines));
    }

    /**
     * @param array<int, string> $existingReferences
     * @return array<int, string>
     */
    private function references(array $existingReferences, ?string $originalMessageId): array
    {
        return array_values(array_unique(array_filter([
            ...$existingReferences,
            $originalMessageId,
        ])));
    }

    private function generateMessageId(string $fromEmail): string
    {
        $domain = Str::after($fromEmail, '@') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return Str::uuid()->toString() . '@' . $domain;
    }

    private function cleanMessageId(string $messageId): string
    {
        return trim(trim($messageId), '<>');
    }

    /**
     * Gather every uploaded file for this send: the optional single media field
     * (send-image/video/document/audio) plus any "attachments[]" array.
     *
     * @return array<int, UploadedFile>
     */
    private function collectAttachments(array $data, ?string $attachmentField): array
    {
        $files = [];

        if ($attachmentField && ($data[$attachmentField] ?? null) instanceof UploadedFile) {
            $files[] = $data[$attachmentField];
        }

        foreach ((array) ($data['attachments'] ?? []) as $file) {
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @param array<int, UploadedFile> $files
     */
    private function attachFilesToEmail(Email $email, array $files): void
    {
        foreach ($files as $file) {
            $email->attachFromPath(
                $file->getRealPath(),
                $file->getClientOriginalName(),
                $file->getMimeType() ?: null
            );
        }
    }

    /**
     * Persist each uploaded file and return its metadata for meta.email.attachments.
     *
     * @param array<int, UploadedFile> $files
     * @return array<int, array{name: string, content_type: ?string, path: string}>
     */
    private function storeFiles(string $messageId, array $files): array
    {
        $stored = [];

        foreach ($files as $file) {
            $stored[] = [
                'name' => $file->getClientOriginalName(),
                'content_type' => $file->getClientMimeType(),
                'path' => $this->storeAttachment($messageId, $file),
            ];
        }

        return $stored;
    }

    private function storeAttachment(string $messageId, UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'attachment';
        $path = 'media/' . Str::before($messageId, '@') . '_' . uniqid() . '_' . $safeName;

        if ($extension) {
            $path .= '.' . strtolower($extension);
        }

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }

    private function isSmtpAuthenticationFailure(TransportExceptionInterface $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return in_array((int) $exception->getCode(), [334, 454, 530, 535], true)
            || str_contains($message, 'auth')
            || str_contains($message, 'username')
            || str_contains($message, 'password');
    }

    private function logSendFailure(Conversation $conversation, \Throwable $exception): void
    {
        Log::error('EmailHandler: Failed to send email', [
            'exception' => get_class($exception),
            'conversation_id' => $conversation->id,
            'connection_id' => $conversation->connection_id,
        ]);
    }

    private function rejectRemoteAttachment(array $data): void
    {
        if (!empty($data['media_url'])) {
            throw ValidationException::withMessages([
                'media_url' => 'Envio de anexos por URL em e-mail ainda nao suportado. Envie o arquivo diretamente.',
            ]);
        }
    }
}
