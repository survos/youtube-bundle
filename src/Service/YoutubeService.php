<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Service;

use Google\Http\MediaFileUpload;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\YouTube;
use Google\Service\YouTube\Caption;
use Google\Service\YouTube\CaptionSnippet;
use Google\Service\YouTube\Channel;
use Google\Service\YouTube\Video;
use Survos\YoutubeBundle\Model\Upload;
use Symfony\Component\Filesystem\Filesystem;

final class YoutubeService
{
    private const CHUNK_SIZE = 1024 * 1024;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly string $uploadDirectory,
        private readonly string $defaultChannel = '',
    ) {
    }

    /** The uploads playlist avoids search.list's incomplete channel search results. @return \Generator<Video> */
    public function videos(?string $channelId = null, int $limit = 0): \Generator
    {
        $channelId = $channelId ?: $this->defaultChannel;
        if ('' === $channelId || $limit < 0) {
            throw new \InvalidArgumentException('Supply a channel ID and a nonnegative limit (0 = all).');
        }
        $youtube = new YouTube($this->clients->forReading());
        $channels = $youtube->channels->listChannels('contentDetails', ['id' => $channelId]);
        $playlist = ($channels->getItems()[0] ?? null)?->getContentDetails()?->getRelatedPlaylists()?->getUploads();
        if (!$playlist) {
            throw new \RuntimeException('Channel not found or uploads playlist unavailable.');
        }
        $count = 0;
        $page = null;
        do {
            $items = $youtube->playlistItems->listPlaylistItems('contentDetails', array_filter([
                'playlistId' => $playlist, 'maxResults' => 50, 'pageToken' => $page,
            ], static fn ($v) => null !== $v));
            $ids = [];
            foreach ($items->getItems() as $item) {
                if ($id = $item->getContentDetails()?->getVideoId()) {
                    $ids[] = $id;
                }
            }
            if ([] !== $ids) {
                $videos = $youtube->videos->listVideos('snippet,statistics,contentDetails,status', ['id' => implode(',', $ids)]);
                $byId = [];
                foreach ($videos->getItems() as $video) {
                    $byId[$video->getId()] = $video;
                }
                foreach ($ids as $id) {
                    if (!isset($byId[$id])) {
                        continue; // Deleted/private items may not be available with this credential.
                    }
                    yield $byId[$id];
                    if ($limit > 0 && ++$count >= $limit) {
                        return;
                    }
                }
            }
            $page = $items->getNextPageToken();
        } while ($page);
    }

    public function channel(): Channel
    {
        $youtube = new YouTube($this->clients->authorized());

        return $youtube->channels->listChannels('snippet', ['mine' => true])->getItems()[0]
            ?? throw new \RuntimeException('No YouTube channel is associated with this authorization.');
    }

    /** @param callable(int, int): void|null $progress */
    public function upload(Upload $upload, ?string $expectedChannel = null, ?callable $progress = null): Video
    {
        $client = $this->clients->authorized();
        $youtube = new YouTube($client);
        $channel = $youtube->channels->listChannels('snippet', ['mine' => true])->getItems()[0]
            ?? throw new \RuntimeException('No authorized YouTube channel.');
        $expectedChannel = $expectedChannel ?: $this->defaultChannel;
        if ('' !== $expectedChannel && $channel->getId() !== $expectedChannel) {
            throw new \RuntimeException('Authorized channel does not match the requested channel. Run youtube:channel to inspect it.');
        }
        $key = hash('sha256', json_encode([$channel->getId(), hash_file('sha256', $upload->filename), $upload->video()->toSimpleObject(), $upload->notifySubscribers], JSON_THROW_ON_ERROR));
        $path = $this->uploadDirectory.'/'.$key.'.json';
        (new Filesystem())->mkdir($this->uploadDirectory, 0700);
        $lock = fopen($path.'.lock', 'c');
        if (false === $lock) {
            throw new \RuntimeException('Cannot open the upload lock.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('This video upload is already running.');
            }
            $state = PrivateJsonFile::read($path);
            if (isset($state['video'])) {
                $video = new Video($state['video']);
            } else {
                $client->setDefer(true);
                try {
                    $request = $youtube->videos->insert('snippet,status', $upload->video(), ['notifySubscribers' => $upload->notifySubscribers]);
                    $media = new MediaFileUpload($client, $request, 'application/octet-stream', '', true, self::CHUNK_SIZE);
                    $size = filesize($upload->filename);
                    $media->setFileSize($size);
                    $video = false;
                    if (isset($state['resume_uri'])) {
                        // Query Google's received-byte offset, including completion after a lost response.
                        $video = $media->resume($state['resume_uri']);
                    } else {
                        $state = ['resume_uri' => $media->getResumeUri()];
                        PrivateJsonFile::write($path, $state);
                    }
                    $handle = fopen($upload->filename, 'rb');
                    if (false === $handle) {
                        throw new \RuntimeException('Cannot open the video.');
                    }
                    try {
                        while (!$video) {
                            if (0 !== fseek($handle, $media->getProgress())) {
                                throw new \RuntimeException('Cannot seek to the upload offset.');
                            }
                            $chunk = fread($handle, self::CHUNK_SIZE);
                            if (false === $chunk || '' === $chunk) {
                                throw new \RuntimeException('Upload ended without a completion response. Rerun to query the saved session.');
                            }
                            $video = $media->nextChunk($chunk);
                            $state['resume_uri'] = $media->getResumeUri();
                            PrivateJsonFile::write($path, $state);
                            if (null !== $progress) {
                                $progress($video ? $size : min($size, $media->getProgress()), $size);
                            }
                        }
                    } finally {
                        fclose($handle);
                    }
                } finally {
                    $client->setDefer(false);
                }
                if (!$video instanceof Video || !$video->getId()) {
                    throw new \RuntimeException('YouTube did not return a video ID. Keep the saved session and investigate before retrying.');
                }
                // Persist success before thumbnail work so a thumbnail failure cannot cause a duplicate.
                $state = ['video' => (array) $video->toSimpleObject()];
                PrivateJsonFile::write($path, $state);
            }
            if (null !== $upload->thumbnail) {
                $thumbnailHash = hash_file('sha256', $upload->thumbnail);
                if (($state['thumbnail_hash'] ?? null) !== $thumbnailHash) {
                    $youtube->thumbnails->set($video->getId(), [
                        'data' => file_get_contents($upload->thumbnail),
                        'mimeType' => (new \finfo(FILEINFO_MIME_TYPE))->file($upload->thumbnail),
                        'uploadType' => 'media',
                    ]);
                    $state['thumbnail_hash'] = $thumbnailHash;
                    PrivateJsonFile::write($path, $state);
                }
            }

            // Read actual visibility; Google may restrict an unaudited project's uploads to private.
            return $youtube->videos->listVideos('snippet,status', ['id' => $video->getId()])->getItems()[0]
                ?? throw new \RuntimeException('Upload saved, but its status could not be read. Rerun safely to check again.');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Add a caption track (SRT, WebVTT, ...) to a video, or replace the uploaded track with the same
     * language and name, so a rerun never stacks duplicates (automatic captions are left alone). Needs the youtube.force-ssl scope
     * (authorizations made before caption support must run youtube:authorize again).
     */
    public function uploadCaptions(string $videoId, string $file, string $language = 'en', string $name = ''): Caption
    {
        if (!is_file($file) || !is_readable($file) || 0 === filesize($file) || filesize($file) > 100 * 1024 * 1024) {
            throw new \InvalidArgumentException('Captions must be a readable, nonempty file of at most 100 MB.');
        }
        if (!preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $language)) {
            throw new \InvalidArgumentException('Language must be a BCP-47 tag such as en or en-US.');
        }
        $youtube = new YouTube($this->clients->authorized());
        $existing = null;
        foreach ($youtube->captions->listCaptions('snippet', $videoId)->getItems() as $track) {
            // Only uploaded ("standard") tracks; YouTube's automatic (ASR) captions cannot be updated.
            if ('standard' === $track->getSnippet()?->getTrackKind() && $track->getSnippet()?->getLanguage() === $language && $track->getSnippet()?->getName() === $name) {
                $existing = $track;
                break;
            }
        }
        $media = ['data' => file_get_contents($file), 'mimeType' => 'application/octet-stream', 'uploadType' => 'multipart'];
        try {
            if (null !== $existing) {
                return $youtube->captions->update('snippet', $existing, $media);
            }
            $snippet = new CaptionSnippet();
            $snippet->setVideoId($videoId);
            $snippet->setLanguage($language);
            $snippet->setName($name);
            $caption = new Caption();
            $caption->setSnippet($snippet);

            return $youtube->captions->insert('snippet', $caption, $media);
        } catch (GoogleServiceException $e) {
            if (403 === $e->getCode()) {
                throw new \RuntimeException('YouTube refused the captions (403). If this token predates caption support, run youtube:authorize again.', 403, $e);
            }
            throw $e;
        }
    }

    /**
     * Edit a published video's metadata in place (no re-upload). Only the given fields change; the
     * rest of the snippet is read back first, since videos.update replaces the whole part.
     *
     * @param list<string>|null $tags
     */
    public function updateMetadata(string $videoId, ?string $title = null, ?string $description = null, ?array $tags = null, ?string $categoryId = null): Video
    {
        $youtube = new YouTube($this->clients->authorized());
        $video = $youtube->videos->listVideos('snippet', ['id' => $videoId])->getItems()[0]
            ?? throw new \InvalidArgumentException(\sprintf('Video %s not found on the authorized channel.', $videoId));
        $snippet = $video->getSnippet();
        if (null !== $title) {
            if ('' === trim($title) || mb_strlen($title) > 100 || false !== strpbrk($title, '<>')) {
                throw new \InvalidArgumentException('Title must contain 1–100 characters and no angle brackets.');
            }
            $snippet->setTitle($title);
        }
        if (null !== $description) {
            if (\strlen($description) > 5000 || false !== strpbrk($description, '<>')) {
                throw new \InvalidArgumentException('Description must be at most 5000 bytes and contain no angle brackets.');
            }
            $snippet->setDescription($description);
        }
        if (null !== $tags) {
            $snippet->setTags($tags);
        }
        if (null !== $categoryId) {
            $snippet->setCategoryId($categoryId);
        }
        $update = new Video();
        $update->setId($videoId);
        $update->setSnippet($snippet);

        return $youtube->videos->update('snippet', $update);
    }
}
