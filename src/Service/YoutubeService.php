<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Service;

use Google\Http\MediaFileUpload;
use Google\Service\YouTube;
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
        if ($channelId === '' || $limit < 0) {
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
            ], static fn ($v) => $v !== null));
            $ids = [];
            foreach ($items->getItems() as $item) {
                if ($id = $item->getContentDetails()?->getVideoId()) {
                    $ids[] = $id;
                }
            }
            if ($ids !== []) {
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

    /** @param null|callable(int, int): void $progress */
    public function upload(Upload $upload, ?string $expectedChannel = null, ?callable $progress = null): Video
    {
        $client = $this->clients->authorized();
        $youtube = new YouTube($client);
        $channel = $youtube->channels->listChannels('snippet', ['mine' => true])->getItems()[0]
            ?? throw new \RuntimeException('No authorized YouTube channel.');
        $expectedChannel = $expectedChannel ?: $this->defaultChannel;
        if ($expectedChannel !== '' && $channel->getId() !== $expectedChannel) {
            throw new \RuntimeException('Authorized channel does not match the requested channel. Run youtube:channel to inspect it.');
        }
        $key = hash('sha256', json_encode([$channel->getId(), hash_file('sha256', $upload->filename), $upload->video()->toSimpleObject(), $upload->notifySubscribers], JSON_THROW_ON_ERROR));
        $path = $this->uploadDirectory.'/'.$key.'.json';
        (new Filesystem())->mkdir($this->uploadDirectory, 0700);
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
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
                    if ($handle === false) {
                        throw new \RuntimeException('Cannot open the video.');
                    }
                    try {
                        while (!$video) {
                            if (fseek($handle, $media->getProgress()) !== 0) {
                                throw new \RuntimeException('Cannot seek to the upload offset.');
                            }
                            $chunk = fread($handle, self::CHUNK_SIZE);
                            if ($chunk === false || $chunk === '') {
                                throw new \RuntimeException('Upload ended without a completion response. Rerun to query the saved session.');
                            }
                            $video = $media->nextChunk($chunk);
                            $state['resume_uri'] = $media->getResumeUri();
                            PrivateJsonFile::write($path, $state);
                            if ($progress !== null) {
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
            if ($upload->thumbnail !== null) {
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
}
