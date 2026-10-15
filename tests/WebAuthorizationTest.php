<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\YoutubeBundle\Service\ClientFactory;
use Survos\YoutubeBundle\Service\PrivateJsonFile;
use Survos\YoutubeBundle\Service\WebAuthorization;

final class WebAuthorizationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/yt-web-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    private function web(): WebAuthorization
    {
        $clients = new ClientFactory(clientId: 'id.apps.googleusercontent.com', clientSecret: 'secret', tokenFile: $this->dir.'/token.json');

        return new WebAuthorization($clients, 'https://tunnel.example.org/youtube/oauth2callback', $this->dir.'/token.json');
    }

    public function testStartRecordsPendingStateAndUsesTheRedirectUri(): void
    {
        $url = $this->web()->start();
        self::assertStringContainsString(urlencode('https://tunnel.example.org/youtube/oauth2callback'), $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
        self::assertTrue($this->web()->pending());
        self::assertSame(0600, fileperms($this->dir.'/pending-authorization.json') & 0777);
    }

    public function testCallbackWithoutPendingAuthorizationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->web()->complete(['state' => 'x', 'code' => 'y']);
    }

    public function testCallbackWithWrongStateIsRejected(): void
    {
        $this->web()->start();
        $this->expectException(\InvalidArgumentException::class);
        $this->web()->complete(['state' => 'not-the-state', 'code' => 'y']);
    }

    public function testExpiredPendingAuthorizationIsRejected(): void
    {
        PrivateJsonFile::write($this->dir.'/pending-authorization.json', ['state' => 's', 'verifier' => 'v', 'created' => time() - 3600]);
        $this->expectException(\InvalidArgumentException::class);
        $this->web()->complete(['state' => 's', 'code' => 'y']);
    }
}
