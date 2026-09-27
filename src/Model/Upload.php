<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Model;

use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;

final readonly class Upload
{
    /** @param list<string> $tags */
    public function __construct(
        public string $filename,
        public string $title,
        public string $description = '',
        public string $visibility = 'unlisted',
        public string $categoryId = '22',
        public array $tags = [],
        public bool $madeForKids = false,
        public bool $notifySubscribers = false,
        public ?string $thumbnail = null,
    ) {
        if (!is_file($filename) || !is_readable($filename) || filesize($filename) === 0) {
            throw new \InvalidArgumentException('Video must be a readable, nonempty file.');
        }
        if (trim($title) === '' || mb_strlen($title) > 100 || strpbrk($title, '<>') !== false) {
            throw new \InvalidArgumentException('Title must contain 1–100 characters and no angle brackets.');
        }
        if (strlen($description) > 5000 || strpbrk($description, '<>') !== false) {
            throw new \InvalidArgumentException('Description must be at most 5000 bytes and contain no angle brackets.');
        }
        if (!in_array($visibility, ['private', 'unlisted', 'public'], true)) {
            throw new \InvalidArgumentException('Visibility must be private, unlisted or public.');
        }
        if (!ctype_digit($categoryId)) {
            throw new \InvalidArgumentException('Category ID must be numeric.');
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                throw new \InvalidArgumentException('Tags must be nonempty strings.');
            }
        }
        if ($thumbnail !== null && (!is_file($thumbnail) || !is_readable($thumbnail) || filesize($thumbnail) === 0 || filesize($thumbnail) > 2 * 1024 * 1024 || !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($thumbnail), ['image/jpeg', 'image/png'], true))) {
            throw new \InvalidArgumentException('Thumbnail must be a readable JPEG or PNG of at most 2 MiB.');
        }
    }

    public function video(): Video
    {
        $snippet = new VideoSnippet();
        $snippet->setTitle($this->title);
        $snippet->setDescription($this->description);
        $snippet->setCategoryId($this->categoryId);
        if ($this->tags !== []) {
            $snippet->setTags($this->tags);
        }
        $status = new VideoStatus();
        $status->setPrivacyStatus($this->visibility);
        $status->setSelfDeclaredMadeForKids($this->madeForKids);
        $video = new Video();
        $video->setSnippet($snippet);
        $video->setStatus($status);

        return $video;
    }
}
