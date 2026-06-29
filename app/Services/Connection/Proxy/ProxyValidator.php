<?php

namespace App\Services\Connection\Proxy;

use App\Exceptions\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Parses a user-supplied proxy string (`ip:port:username:password`), detects
 * whether it speaks HTTP or SOCKS5 by probing it, and builds the proxy URL that
 * ProxyHub expects (`{scheme}://user:pass@ip:port`).
 */
class ProxyValidator
{
    /** Endpoint used to probe whether the proxy is reachable. */
    private const PROBE_URL = 'https://api.ipify.org?format=json';

    /**
     * Split & validate the raw `ip:port:username:password` input.
     *
     * @return array{host:string, port:int, username:string, password:string}
     */
    public function parse(string $input): array
    {
        $parts = explode(':', trim($input));

        if (count($parts) !== 4) {
            throw ValidationException::withMessages([
                'proxy' => 'Invalid proxy format. Use ip:port:username:password.',
            ]);
        }

        [$host, $port, $username, $password] = array_map('trim', $parts);

        $isValidHost = filter_var($host, FILTER_VALIDATE_IP)
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);

        if (! $isValidHost
            || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || $username === '' || $password === '') {
            throw ValidationException::withMessages([
                'proxy' => 'Invalid proxy format. Use ip:port:username:password.',
            ]);
        }

        return [
            'host' => $host,
            'port' => (int) $port,
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * Detect the proxy scheme by probing (HTTP first, then SOCKS5) and build the
     * proxy URL. Throws if neither scheme can reach the probe endpoint.
     *
     * @param  array{host:string, port:int, username:string, password:string}  $parts
     * @return array{scheme:string, url:string}
     */
    public function detectAndBuild(array $parts): array
    {
        foreach (['http', 'socks5'] as $scheme) {
            $url = $this->buildUrl($scheme, $parts);

            if ($this->probe($url)) {
                return ['scheme' => $scheme, 'url' => $url];
            }
        }

        throw new ConnectionException('Invalid or unreachable proxy.', 422);
    }

    /**
     * Convenience: parse + detect in one call.
     *
     * @return array{scheme:string, url:string, host:string, port:int}
     */
    public function validate(string $input): array
    {
        $parts = $this->parse($input);
        $detected = $this->detectAndBuild($parts);

        return [
            'scheme' => $detected['scheme'],
            'url' => $detected['url'],
            'host' => $parts['host'],
            'port' => $parts['port'],
        ];
    }

    private function buildUrl(string $scheme, array $parts): string
    {
        return sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            rawurlencode($parts['username']),
            rawurlencode($parts['password']),
            $parts['host'],
            $parts['port'],
        );
    }

    private function probe(string $proxyUrl): bool
    {
        try {
            return Http::withOptions(['proxy' => $proxyUrl])
                ->timeout(10)
                ->get(self::PROBE_URL)
                ->successful();
        } catch (\Throwable $e) {
            Log::info('Proxy probe failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
