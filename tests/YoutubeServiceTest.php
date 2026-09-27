<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Tests;

use Google\Client;
use Google\Service\YouTube\Video;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Survos\YoutubeBundle\Model\Upload;
use Survos\YoutubeBundle\Service\ClientFactory;
use Survos\YoutubeBundle\Service\PrivateJsonFile;
use Survos\YoutubeBundle\Service\YoutubeService;
use Symfony\Component\Filesystem\Filesystem;

final class YoutubeServiceTest extends TestCase
{
    private string $dir;
    private array $history = [];
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/youtube-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }
    private function json(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
    private function service(array $responses): YoutubeService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $client = new Client();
        $client->setHttpClient(new HttpClient(['handler' => $stack]));
        $client->setAccessToken(['access_token' => 'test-only', 'created' => time(), 'expires_in' => 3600]);
        $factory = new class ($client) extends ClientFactory {
            public function __construct(private readonly Client $client)
            {
            }
            public function forReading(): Client
            {
                return $this->client;
            }
            public function authorized(): Client
            {
                return $this->client;
            }
        };
        return new YoutubeService($factory, $this->dir.'/sessions', 'UC-test');
    }
    private function channel(): Response
    {
        return $this->json(['items' => [['id' => 'UC-test', 'snippet' => ['title' => 'Test channel']]]]);
    }
    private function video(): array
    {
        return ['id' => 'video-123', 'snippet' => ['title' => 'Launch'], 'status' => ['privacyStatus' => 'private']];
    }
    public function testPaginationAndDeletedVideosPreservePlaylistOrder(): void
    {
        $service = $this->service([
            $this->json(['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU-test']]]]]),
            $this->json(['items' => [['contentDetails' => ['videoId' => 'a']], ['contentDetails' => ['videoId' => 'deleted']], ['contentDetails' => ['videoId' => 'b']]], 'nextPageToken' => 'page2']),
            $this->json(['items' => [['id' => 'b'], ['id' => 'a']]]),
            $this->json(['items' => [['contentDetails' => ['videoId' => 'c']]]]),
            $this->json(['items' => [['id' => 'c']]]),
        ]);
        $videos = iterator_to_array($service->videos());
        self::assertSame(['a', 'b', 'c'], array_map(static fn (Video $video) => $video->getId(), $videos));
        self::assertStringContainsString('pageToken=page2', (string) $this->history[3]['request']->getUri());
        self::assertStringNotContainsString('/search', (string) $this->history[1]['request']->getUri());
    }
    public function testLimitStopsBeforeFetchingAnotherPage(): void
    {
        $service = $this->service([
            $this->json(['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU-test']]]]]),
            $this->json(['items' => [['contentDetails' => ['videoId' => 'a']]], 'nextPageToken' => 'more']),
            $this->json(['items' => [['id' => 'a']]]),
        ]);
        self::assertCount(1, iterator_to_array($service->videos(limit: 1)));
        self::assertCount(3, $this->history);
    }
    public function testChunkedUploadAndCompletedReceiptAvoidDuplicateInsert(): void
    {
        file_put_contents($this->dir.'/video.mp4', str_repeat('x', 1024 * 1024 + 100));
        $service = $this->service([
            $this->channel(), new Response(200, ['Location' => 'https://www.googleapis.com/upload/session-one']),
            new Response(308, ['Range' => 'bytes=0-1048575']), $this->json($this->video()),
            $this->json(['items' => [$this->video()]]),
            $this->channel(), $this->json(['items' => [$this->video()]]),
        ]);
        $upload = new Upload($this->dir.'/video.mp4', 'Launch');
        $video = $service->upload($upload);
        self::assertSame('video-123', $video->getId());
        self::assertSame('private', $video->getStatus()->getPrivacyStatus());
        self::assertSame('video-123', $service->upload($upload)->getId());
        self::assertCount(7, $this->history);
        self::assertSame('bytes 0-1048575/1048676', $this->history[2]['request']->getHeaderLine('Content-Range'));
        self::assertSame(100, $this->history[3]['request']->getBody()->getSize());
        $body = json_decode((string) $this->history[1]['request']->getBody(), true);
        self::assertSame('unlisted', $body['status']['privacyStatus']);
        self::assertFalse($body['status']['selfDeclaredMadeForKids']);
        self::assertStringContainsString('notifySubscribers=false', (string) $this->history[1]['request']->getUri());
    }
    public function testInterruptedUploadResumesAtServerOffset(): void
    {
        file_put_contents($this->dir.'/video.mp4', str_repeat('x', 1024 * 1024 + 100));
        $upload = new Upload($this->dir.'/video.mp4', 'Launch');
        $first = $this->service([
            $this->channel(), new Response(200, ['Location' => 'https://www.googleapis.com/upload/session-two']),
            new Response(308, ['Range' => 'bytes=0-1048575']),
            new ConnectException('simulated disconnect', new Request('PUT', 'https://www.googleapis.com/upload/session-two')),
        ]);
        try {
            $first->upload($upload);
            self::fail('Expected a connection failure');
        } catch (ConnectException) {
        }
        $this->history = [];
        $second = $this->service([
            $this->channel(), new Response(308, ['Range' => 'bytes=0-1048575']),
            $this->json($this->video()), $this->json(['items' => [$this->video()]]),
        ]);
        self::assertSame('video-123', $second->upload($upload)->getId());
        self::assertSame('bytes */1048676', $this->history[1]['request']->getHeaderLine('Content-Range'));
        self::assertSame(100, $this->history[2]['request']->getBody()->getSize());
        self::assertSame('PUT', $this->history[1]['request']->getMethod());
    }
    public function testCompletedServerSessionIsRecoveredAfterLostFinalResponse(): void
    {
        file_put_contents($this->dir.'/video.mp4', 'video');
        $upload = new Upload($this->dir.'/video.mp4', 'Launch');
        $first = $this->service([
            $this->channel(), new Response(200, ['Location' => 'https://www.googleapis.com/upload/session-completed']),
            new ConnectException('lost final response', new Request('PUT', 'https://www.googleapis.com/upload/session-completed')),
        ]);
        try {
            $first->upload($upload);
            self::fail('Expected failure');
        } catch (ConnectException) {
        }
        $this->history = [];
        $second = $this->service([$this->channel(), $this->json($this->video()), $this->json(['items' => [$this->video()]])]);
        self::assertSame('video-123', $second->upload($upload)->getId());
        self::assertCount(3, $this->history);
        self::assertSame('bytes */5', $this->history[1]['request']->getHeaderLine('Content-Range'));
    }

    public function testThumbnailFailureRetriesWithoutUploadingVideoAgain(): void
    {
        file_put_contents($this->dir.'/video.mp4', 'video');
        file_put_contents($this->dir.'/thumbnail.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j/1kAAAAASUVORK5CYII='));
        $upload = new Upload($this->dir.'/video.mp4', 'Launch', thumbnail: $this->dir.'/thumbnail.png');
        $first = $this->service([
            $this->channel(), new Response(200, ['Location' => 'https://www.googleapis.com/upload/session-thumbnail']),
            $this->json($this->video()),
            new Response(403, ['Content-Type' => 'application/json'], '{"error":{"code":403,"message":"Thumbnail denied"}}'),
        ]);
        try {
            $first->upload($upload);
            self::fail('Expected thumbnail error');
        } catch (\Google\Service\Exception $e) {
            self::assertSame(403, $e->getCode());
        }
        $this->history = [];
        $second = $this->service([$this->channel(), $this->json(['items' => []]), $this->json(['items' => [$this->video()]])]);
        self::assertSame('video-123', $second->upload($upload)->getId());
        self::assertCount(3, $this->history);
        self::assertStringContainsString('/thumbnails/set', (string) $this->history[1]['request']->getUri());
    }

    public function testWrongChannelFailsBeforeCreatingUpload(): void
    {
        file_put_contents($this->dir.'/video.mp4', 'video');
        $service = $this->service([$this->channel()]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match');
        $service->upload(new Upload($this->dir.'/video.mp4', 'Launch'), 'UC-other');
    }
    public function testTokenSavePreservesRefreshAndRestrictsPermissions(): void
    {
        $path = $this->dir.'/token.json';
        $factory = new ClientFactory(tokenFile: $path);
        $factory->saveToken(['access_token' => 'new'], ['refresh_token' => 'refresh']);
        self::assertSame('refresh', PrivateJsonFile::read($path)['refresh_token']);
        self::assertSame(0600, fileperms($path) & 0777);
        $this->expectException(\RuntimeException::class);
        $factory->saveToken(['error' => 'access_denied']);
    }
}
