<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Service;

/**
 * OAuth through the application's own public callback route (e.g. behind a cloudflared tunnel):
 * the command calls start() and prints the consent link; Google redirects the browser to
 * redirect_uri, where OAuthCallbackController calls complete(), which saves the token.
 *
 * The pending state (OAuth state + PKCE verifier) lives beside the token file, mode 0600, and is
 * consumed by the first valid callback. Use LoopbackAuthorization when there is no public URL.
 */
final class WebAuthorization
{
    private const int MAX_AGE = 900;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly string $redirectUri = '',
        private readonly string $tokenFile = '',
    ) {
    }

    public function enabled(): bool
    {
        return '' !== $this->redirectUri;
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    /** Begin an authorization: returns the Google consent URL. */
    public function start(): string
    {
        if (!$this->enabled()) {
            throw new \LogicException('Configure survos_youtube.redirect_uri to authorize through the application.');
        }
        $client = $this->clients->forAuthorization();
        $client->setRedirectUri($this->redirectUri);
        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $client->setState($state);
        PrivateJsonFile::write($this->pendingFile(), ['state' => $state, 'verifier' => $verifier, 'created' => time()]);

        return $client->createAuthUrl().'&code_challenge_method=S256&code_challenge='.rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** True while an authorization started by start() is still waiting for its callback. */
    public function pending(): bool
    {
        return is_file($this->pendingFile());
    }

    /**
     * Finish an authorization from the callback's query string; saves the offline token.
     *
     * @param array<string, mixed> $query
     */
    public function complete(array $query): void
    {
        $pending = is_file($this->pendingFile()) ? PrivateJsonFile::read($this->pendingFile()) : [];
        if (!isset($pending['state'], $pending['verifier'], $pending['created']) || time() - (int) $pending['created'] > self::MAX_AGE) {
            throw new \InvalidArgumentException('No authorization is waiting. Run youtube:authorize again.');
        }
        $code = LoopbackAuthorization::callbackCode($query, (string) $pending['state']);
        $client = $this->clients->forAuthorization();
        $client->setRedirectUri($this->redirectUri);
        $token = $client->fetchAccessTokenWithAuthCode($code, (string) $pending['verifier']);
        if (empty($token['refresh_token'])) {
            throw new \RuntimeException('Google did not return an offline token. Revoke the previous app grant and authorize again.');
        }
        $this->clients->saveToken($token);
        @unlink($this->pendingFile());
    }

    private function pendingFile(): string
    {
        if ('' === $this->tokenFile) {
            throw new \RuntimeException('Configure a writable token_file.');
        }

        return \dirname($this->tokenFile).'/pending-authorization.json';
    }
}
