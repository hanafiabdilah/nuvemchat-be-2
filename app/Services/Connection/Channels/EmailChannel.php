<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Status;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

class EmailChannel implements ChannelInterface
{
    private const TIMEOUT_SECONDS = 10;

    public function connect(Connection $connection, array $data): void
    {
        $credentials = $this->validatedCredentials($data);
        $password = $credentials['password'];

        $this->assertImapLogin($credentials, $password);
        $this->assertSmtpLogin($credentials, $password);

        $connection->update([
            'status' => Status::Active,
            'credentials' => [
                'email' => $credentials['email'],
                'password' => Crypt::encryptString($password),
                'imap_host' => $credentials['imap_host'],
                'imap_port' => $credentials['imap_port'],
                'imap_encryption' => $credentials['imap_encryption'],
                'smtp_host' => $credentials['smtp_host'],
                'smtp_port' => $credentials['smtp_port'],
                'smtp_encryption' => $credentials['smtp_encryption'],
            ],
        ]);
    }

    /**
     * Re-save the credentials of an already-connected mailbox (host/port/security
     * changes, a rotated app password, or a corrected address).
     *
     * The password is optional here: leaving it blank keeps the stored one, so an
     * agent can fix a port without knowing the mailbox password. Both IMAP and SMTP
     * are re-tested before anything is written — a mailbox that fails to log in must
     * never overwrite credentials that currently work.
     *
     * @throws ConnectionException|ValidationException
     */
    public function updateCredentials(Connection $connection, array $data): void
    {
        $existing = is_array($connection->credentials) ? $connection->credentials : [];

        // Blank/absent password means "keep the current one".
        if (trim((string) ($data['password'] ?? '')) === '') {
            unset($data['password']);
            $data['password'] = $this->storedPassword($existing);
        }

        $credentials = $this->validatedCredentials($data);
        $password = $credentials['password'];

        $this->assertImapLogin($credentials, $password);
        $this->assertSmtpLogin($credentials, $password);

        $connection->update([
            'status' => Status::Active,
            'credentials' => [
                'email' => $credentials['email'],
                'password' => Crypt::encryptString($password),
                'imap_host' => $credentials['imap_host'],
                'imap_port' => $credentials['imap_port'],
                'imap_encryption' => $credentials['imap_encryption'],
                'smtp_host' => $credentials['smtp_host'],
                'smtp_port' => $credentials['smtp_port'],
                'smtp_encryption' => $credentials['smtp_encryption'],
            ],
        ]);
    }

    /**
     * The mailbox password currently on file, decrypted.
     *
     * @throws ValidationException when there is nothing usable to fall back to.
     */
    private function storedPassword(array $existing): string
    {
        if (empty($existing['password'])) {
            throw ValidationException::withMessages([
                'password' => 'Informe a senha da caixa de e-mail.',
            ]);
        }

        try {
            return Crypt::decryptString($existing['password']);
        } catch (DecryptException) {
            throw ValidationException::withMessages([
                'password' => 'A senha armazenada nao pode ser lida. Informe a senha novamente.',
            ]);
        }
    }

    public function disconnect(Connection $connection): void
    {
        $connection->update([
            'status' => Status::Inactive,
            'credentials' => null,
        ]);
    }

    public function checkStatus(Connection $connection): void
    {
        $credentials = $connection->credentials ?? [];

        if (! is_array($credentials) || empty($credentials['password'])) {
            $connection->update(['status' => Status::Inactive]);

            throw new ConnectionException('Credenciais de e-mail ausentes. Conecte a caixa novamente.', 422);
        }

        try {
            $password = Crypt::decryptString($credentials['password']);
            $this->assertImapLogin($credentials, $password);

            $connection->update(['status' => Status::Active]);
        } catch (ValidationException $exception) {
            $connection->update(['status' => Status::Inactive]);

            throw new ConnectionException($exception->validator->errors()->first() ?: 'Falha na autenticacao IMAP.', 422);
        } catch (DecryptException) {
            $connection->update(['status' => Status::Inactive]);

            throw new ConnectionException('Credenciais de e-mail invalidas. Conecte a caixa novamente.', 422);
        } catch (ConnectionException $exception) {
            $connection->update(['status' => Status::Inactive]);

            throw $exception;
        }
    }

    private function validatedCredentials(array $data): array
    {
        $credentials = validator($data, self::rules())->validate();

        foreach (['email', 'password', 'imap_host', 'imap_encryption', 'smtp_host', 'smtp_encryption'] as $key) {
            $credentials[$key] = trim((string) $credentials[$key]);
        }

        foreach (['imap_port', 'smtp_port'] as $key) {
            $credentials[$key] = (int) $credentials[$key];
        }

        return $credentials;
    }

    public static function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'imap_host' => ['required', 'string', 'max:255'],
            'imap_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'imap_encryption' => ['required', 'in:ssl,tls,none'],
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['required', 'in:ssl,tls,none'],
        ];
    }

    /**
     * @throws ConnectionException
     */
    protected function assertImapLogin(array $credentials, string $password): void
    {
        $client = (new ClientManager([
            'default' => 'email',
            'accounts' => [
                'email' => [
                    'host' => $credentials['imap_host'],
                    'port' => $credentials['imap_port'],
                    'protocol' => 'imap',
                    'encryption' => $this->imapEncryption($credentials['imap_encryption']),
                    'validate_cert' => true,
                    'username' => $credentials['email'],
                    'password' => $password,
                    'timeout' => self::TIMEOUT_SECONDS,
                ],
            ],
            'options' => [
                'debug' => false,
                'uid_cache' => false,
                'delimiter' => '/',
            ],
        ]))->account('email');

        try {
            $client->connect();
            $client->openFolder('INBOX', true);
        } catch (AuthFailedException) {
            throw ValidationException::withMessages([
                'password' => 'Falha na autenticacao IMAP: usuario ou senha invalidos.',
            ]);
        } catch (ConnectionFailedException) {
            throw new ConnectionException(
                "Nao foi possivel conectar em {$credentials['imap_host']}:{$credentials['imap_port']}.",
                502
            );
        } catch (\Throwable) {
            throw new ConnectionException('Falha ao validar IMAP. Verifique servidor, porta e seguranca.', 502);
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
                //
            }
        }
    }

    /**
     * @throws ConnectionException
     */
    protected function assertSmtpLogin(array $credentials, string $password): void
    {
        $transport = new EsmtpTransport(
            $credentials['smtp_host'],
            $credentials['smtp_port'],
            $credentials['smtp_encryption'] === 'ssl'
        );

        $transport->setUsername($credentials['email']);
        $transport->setPassword($password);
        $transport->getStream()->setTimeout(self::TIMEOUT_SECONDS);

        if ($credentials['smtp_encryption'] === 'tls') {
            $transport->setAutoTls(true);
            $transport->setRequireTls(true);
        } elseif ($credentials['smtp_encryption'] === 'none') {
            $transport->setAutoTls(false);
        }

        try {
            $transport->start();
        } catch (TransportExceptionInterface $exception) {
            if ($this->isSmtpAuthenticationFailure($exception)) {
                throw ValidationException::withMessages([
                    'password' => 'Falha na autenticacao SMTP: usuario ou senha invalidos.',
                ]);
            }

            throw new ConnectionException(
                "Nao foi possivel conectar em {$credentials['smtp_host']}:{$credentials['smtp_port']}.",
                502
            );
        } catch (\Throwable) {
            throw new ConnectionException('Falha ao validar SMTP. Verifique servidor, porta e seguranca.', 502);
        } finally {
            try {
                $transport->stop();
            } catch (\Throwable) {
                //
            }
        }
    }

    private function imapEncryption(string $encryption): string
    {
        return match ($encryption) {
            'ssl' => 'ssl',
            'tls' => 'starttls',
            default => 'none',
        };
    }

    private function isSmtpAuthenticationFailure(TransportExceptionInterface $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return in_array((int) $exception->getCode(), [334, 454, 530, 535], true)
            || str_contains($message, 'auth')
            || str_contains($message, 'username')
            || str_contains($message, 'password');
    }
}
