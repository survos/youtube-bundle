<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Command;

use Survos\YoutubeBundle\Model\Upload;
use Survos\YoutubeBundle\Service\LoopbackAuthorization;
use Survos\YoutubeBundle\Service\YoutubeService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final class YoutubeCommand
{
    public function __construct(private readonly YoutubeService $youtube, private readonly LoopbackAuthorization $authorization)
    {
    }

    #[AsCommand('youtube:authorize', 'Authorize a YouTube channel once and store its offline OAuth token')]
    public function authorize(SymfonyStyle $io, #[Option('Local OAuth callback port')] int $port = 8769, #[Option('Seconds to wait for consent')] int $timeout = 180): int
    {
        $this->authorization->authorize(static fn (string $url) => $io->writeln(['Open this URL in your browser and select the channel:', $url]), $port, $timeout);
        $io->success('Authorization saved. Run youtube:channel to verify the channel.');
        return Command::SUCCESS;
    }

    #[AsCommand('youtube:channel', 'Show the channel authorized for uploads')]
    public function channel(SymfonyStyle $io): int
    {
        $channel = $this->youtube->channel();
        $io->table(['Channel ID', 'Title'], [[$channel->getId(), $channel->getSnippet()?->getTitle()]]);
        return Command::SUCCESS;
    }

    #[AsCommand('youtube:fetch', 'Stream channel videos as JSON Lines (no database writes)')]
    public function fetch(SymfonyStyle $io, #[Argument('Channel ID; defaults to configured channel')] ?string $channel = null, #[Option('Maximum videos; 0 = all')] int $limit = 0): int
    {
        foreach ($this->youtube->videos($channel, $limit) as $video) {
            $io->writeln(json_encode($video->toSimpleObject(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SymfonyStyle::OUTPUT_RAW);
        }
        return Command::SUCCESS;
    }

    #[AsCommand('youtube:upload', 'Upload or resume a video; identical completed uploads return their existing URL')]
    public function upload(
        SymfonyStyle $io,
        #[Argument('Local video file')] string $filename,
        #[Option('Video title (required)')] string $title = '',
        #[Option('UTF-8 file containing the description')] ?string $descriptionFile = null,
        #[Option('private, unlisted or public')] string $visibility = 'unlisted',
        #[Option('JPEG or PNG thumbnail, at most 2 MiB')] ?string $thumbnail = null,
        #[Option('Expected channel ID; refuse a different authorized channel')] ?string $channelId = null,
        #[Option('YouTube category ID')] string $category = '22',
        #[Option('Comma-separated tags')] string $tags = '',
        #[Option('Declare the video as made for kids')] bool $madeForKids = false,
        #[Option('Notify subscribers (off by default)')] bool $notify = false,
        #[Option('Validate local inputs without network requests')] bool $dryRun = false,
    ): int {
        if ($descriptionFile !== null && (!is_file($descriptionFile) || !is_readable($descriptionFile))) {
            throw new \InvalidArgumentException('Description file must be readable.');
        }
        $upload = new Upload($filename, $title, $descriptionFile === null ? '' : file_get_contents($descriptionFile), $visibility, $category, $tags === '' ? [] : array_map('trim', explode(',', $tags)), $madeForKids, $notify, $thumbnail);
        if ($dryRun) {
            $io->success('Local inputs are valid. No network requests were made.');
            return Command::SUCCESS;
        }
        $video = $this->youtube->upload($upload, $channelId);
        $io->success('https://www.youtube.com/watch?v='.$video->getId());
        $actual = $video->getStatus()?->getPrivacyStatus();
        $io->writeln('Actual visibility: '.($actual ?? 'unknown'));
        if ($actual !== $visibility) {
            $io->warning('Requested visibility differs from YouTube. Unaudited API projects may be restricted to private uploads.');
        }
        return Command::SUCCESS;
    }
}
