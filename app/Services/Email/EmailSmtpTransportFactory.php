<?php

namespace App\Services\Email;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

class EmailSmtpTransportFactory
{
    private const TIMEOUT_SECONDS = 10;

    public function make(array $credentials, string $password): TransportInterface
    {
        $transport = new EsmtpTransport(
            $credentials['smtp_host'],
            (int) $credentials['smtp_port'],
            ($credentials['smtp_encryption'] ?? 'none') === 'ssl'
        );

        $transport->setUsername($credentials['email']);
        $transport->setPassword($password);
        $transport->getStream()->setTimeout(self::TIMEOUT_SECONDS);

        if (($credentials['smtp_encryption'] ?? 'none') === 'tls') {
            $transport->setAutoTls(true);
            $transport->setRequireTls(true);
        } elseif (($credentials['smtp_encryption'] ?? 'none') === 'none') {
            $transport->setAutoTls(false);
        }

        return $transport;
    }
}
