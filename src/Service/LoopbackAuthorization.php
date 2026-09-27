<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Service;

/** Local CLI OAuth callback; no application routes or browser automation required. */
final class LoopbackAuthorization
{
    public function __construct(private readonly ClientFactory $clients)
    {
    }

    /** @param callable(string): void $showUrl */
    public function authorize(callable $showUrl, int $port = 8769, int $timeout = 180): void
    {
        if ($port < 1024 || $port > 65535 || $timeout < 1 || $timeout > 600) {
            throw new \InvalidArgumentException('Use a port from 1024 to 65535 and a timeout from 1 to 600 seconds.');
        }
        $client = $this->clients->forAuthorization();
        $redirect = sprintf('http://127.0.0.1:%d/oauth2callback', $port);
        $client->setRedirectUri($redirect);
        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $client->setState($state);
        $url = $client->createAuthUrl().'&code_challenge_method=S256&code_challenge='.rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $server = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $error);
        if ($server === false) {
            throw new \RuntimeException('Cannot listen on the OAuth callback port. Choose another --port.');
        }
        try {
            $showUrl($url);
            $deadline = microtime(true) + $timeout;
            while (($remaining = $deadline - microtime(true)) > 0) {
                $connection = @stream_socket_accept($server, $remaining);
                if ($connection === false) {
                    break;
                }
                try {
                    stream_set_timeout($connection, 5);
                    $request = fgets($connection, 8192);
                    if (!is_string($request) || !preg_match('#^GET (/oauth2callback\?\S+) HTTP/1\.[01]#', $request, $match)) {
                        fwrite($connection, "HTTP/1.1 404 Not Found\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
                        continue;
                    }
                    parse_str((string) parse_url($match[1], PHP_URL_QUERY), $query);
                    try {
                        $code = self::callbackCode($query, $state);
                    } catch (\InvalidArgumentException $e) {
                        fwrite($connection, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
                        continue;
                    }
                    $token = $client->fetchAccessTokenWithAuthCode($code, $verifier);
                    if (empty($token['refresh_token'])) {
                        throw new \RuntimeException('Google did not return an offline token. Revoke the previous app grant and authorize again.');
                    }
                    $this->clients->saveToken($token);
                    $body = 'YouTube authorized. You may close this window and return to the terminal.';
                    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);

                    return;
                } finally {
                    fclose($connection);
                }
            }
            throw new \RuntimeException('Authorization timed out. Run youtube:authorize again.');
        } finally {
            fclose($server);
        }
    }

    public static function callbackCode(array $query, string $state): string
    {
        if (!is_string($query['state'] ?? null) || !hash_equals($state, $query['state'])) {
            throw new \InvalidArgumentException('OAuth state did not match.');
        }
        if (isset($query['error'])) {
            throw new \RuntimeException('Google authorization was declined.');
        }
        if (!is_string($query['code'] ?? null) || $query['code'] === '') {
            throw new \InvalidArgumentException('Missing authorization code.');
        }

        return $query['code'];
    }
}
