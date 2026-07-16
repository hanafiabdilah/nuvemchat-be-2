<?php

namespace App\Services\Email;

use App\Exceptions\ConnectionException;
use App\Models\Connection;
use Illuminate\Support\Facades\Crypt;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;

class WebklexEmailInboxClientFactory implements EmailInboxClientFactory
{
    private const TIMEOUT_SECONDS = 10;

    public function make(Connection $connection): EmailInboxClient
    {
        $credentials = $connection->credentials ?? [];

        $client = (new ClientManager([
            'default' => 'email',
            'accounts' => [
                'email' => [
                    'host' => $credentials['imap_host'] ?? null,
                    'port' => $credentials['imap_port'] ?? null,
                    'protocol' => 'imap',
                    'encryption' => $this->imapEncryption($credentials['imap_encryption'] ?? 'none'),
                    'validate_cert' => true,
                    'username' => $credentials['email'] ?? null,
                    'password' => Crypt::decryptString((string) ($credentials['password'] ?? '')),
                    'timeout' => self::TIMEOUT_SECONDS,
                ],
            ],
            'options' => [
                'debug' => false,
                'uid_cache' => false,
                'delimiter' => '/',
                'fetch' => \Webklex\PHPIMAP\IMAP::FT_PEEK,
                'fetch_order' => 'asc',
            ],
            // O default da lib decodifica header/corpo com imap_utf8(), que vem da
            // extensao ext-imap - e nos rodamos em modo PHP puro, sem ela. Sem isso
            // o decode falha em silencio e assunto/remetente ficam no formato cru
            // RFC 2047 (=?UTF-8?B?...?=), quebrando qualquer acento.
            // 'mimeheader' usa mb_decode_mimeheader(), que e PHP puro.
            'decoding' => [
                'options' => [
                    'header' => 'mimeheader',
                    'message' => 'mimeheader',
                    'attachment' => 'mimeheader',
                ],
            ],
        ]))->account('email');

        $client->connect();

        // getFolder() devolve o objeto Folder; openFolder() apenas seleciona a pasta
        // e devolve a resposta crua do IMAP (array).
        $folder = $client->getFolder('INBOX');

        if (! $folder instanceof Folder) {
            throw new ConnectionException('Nao foi possivel abrir a caixa INBOX.', 502);
        }

        return new WebklexEmailInboxClient($client, $folder);
    }

    private function imapEncryption(string $encryption): string
    {
        return match ($encryption) {
            'ssl' => 'ssl',
            'tls' => 'starttls',
            default => 'none',
        };
    }
}
