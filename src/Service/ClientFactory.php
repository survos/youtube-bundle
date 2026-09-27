<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Service;

use Google\Client;
use Google\Service\YouTube;

class ClientFactory
{
    public function __construct(
        private readonly string $apiKey = '',
        private readonly string $clientId = '',
        private readonly string $clientSecret = '',
        private readonly string $authConfig = '',
        private readonly string $tokenFile = '',
    ) {
    }

    public function forReading(): Client
    {
        if ($this->apiKey === '') {
            return $this->authorized();
        }
        $client = new Client();
        $client->setDeveloperKey($this->apiKey);

        return $client;
    }

    public function forAuthorization(): Client
    {
        $client = new Client();
        $client->setApplicationName('Survos YouTube');
        if ($this->authConfig !== '') {
            $client->setAuthConfig($this->authConfig);
        } elseif ($this->clientId !== '' && $this->clientSecret !== '') {
            $client->setClientId($this->clientId);
            $client->setClientSecret($this->clientSecret);
        } else {
            throw new \RuntimeException('Configure survos_youtube OAuth credentials (client_id/client_secret or auth_config).');
        }
        $client->setScopes([YouTube::YOUTUBE_UPLOAD, YouTube::YOUTUBE_READONLY]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    public function authorized(): Client
    {
        $client = $this->forAuthorization();
        $token = PrivateJsonFile::read($this->tokenFile);
        if (empty($token['access_token'])) {
            throw new \RuntimeException('Run youtube:authorize to authorize this channel before uploading.');
        }
        $client->setAccessToken($token);
        if ($client->isAccessTokenExpired()) {
            if (empty($token['refresh_token'])) {
                throw new \RuntimeException('No refresh token. Run youtube:authorize again.');
            }
            $newToken = $client->fetchAccessTokenWithRefreshToken($token['refresh_token']);
            $this->saveToken($newToken, $token);
            $client->setAccessToken(PrivateJsonFile::read($this->tokenFile));
        }

        return $client;
    }

    public function saveToken(array $token, array $previous = []): void
    {
        if (isset($token['error']) || empty($token['access_token'])) {
            throw new \RuntimeException('Google authorization failed. Check the OAuth configuration and authorize again.');
        }
        if (empty($token['refresh_token']) && !empty($previous['refresh_token'])) {
            $token['refresh_token'] = $previous['refresh_token'];
        }
        if ($this->tokenFile === '') {
            throw new \RuntimeException('Configure a writable token_file.');
        }
        PrivateJsonFile::write($this->tokenFile, $token);
    }
}
