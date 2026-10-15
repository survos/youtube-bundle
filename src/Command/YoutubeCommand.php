<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Command;

use Survos\YoutubeBundle\Model\Upload;
use Survos\YoutubeBundle\Service\LoopbackAuthorization;
use Survos\YoutubeBundle\Service\WebAuthorization;
use Survos\YoutubeBundle\Service\YoutubeService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class YoutubeCommand
{
    public function __construct(
        private readonly YoutubeService $youtube,
        private readonly LoopbackAuthorization $authorization,
        private readonly WebAuthorization $web,
    ) {
    }

    #[AsCommand('youtube:authorize', 'Authorize a YouTube channel once and store its offline OAuth token')]
    public function authorize(
        SymfonyStyle $io,
        #[Option('Local OAuth callback port (loopback mode, when no redirect_uri is configured)')] int $port = 8769,
        #[Option('Seconds to wait for consent')] int $timeout = 180,
        #[Option('Open the consent page in the default browser (interactive runs)')] bool $open = true,
    ): int {
        // Open the browser; also print a clickable link (OSC 8) and the raw URL as fallbacks.
        $show = static function (string $url) use ($io, $open): void {
            $io->writeln([
                'Select the channel and approve:',
                \sprintf('  <href=%s>Authorize YouTube uploads</>', $url),
                '',
                \sprintf('<comment>%s</comment>', $url),
            ]);
            if ($open && $io->isInteractive() && self::openInBrowser($url)) {
                $io->writeln('Opened in your browser.');
            }
        };

        if ($this->web->enabled()) {
            // Through the application's public callback (e.g. a cloudflared tunnel): wait for it to land.
            $show($this->web->start());
            $io->writeln(\sprintf('Waiting for Google to call back %s …', $this->web->redirectUri()));
            $deadline = time() + $timeout;
            while ($this->web->pending() && time() < $deadline) {
                sleep(1);
            }
            if ($this->web->pending()) {
                $io->error('Authorization timed out. Run youtube:authorize again.');

                return Command::FAILURE;
            }
        } else {
            $this->authorization->authorize($show, $port, $timeout);
        }
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
        if (null !== $descriptionFile && (!is_file($descriptionFile) || !is_readable($descriptionFile))) {
            throw new \InvalidArgumentException('Description file must be readable.');
        }
        $upload = new Upload($filename, $title, null === $descriptionFile ? '' : file_get_contents($descriptionFile), $visibility, $category, '' === $tags ? [] : array_map('trim', explode(',', $tags)), $madeForKids, $notify, $thumbnail);
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

    /** Hand the URL to the desktop's opener; false when there is none. */
    private static function openInBrowser(string $url): bool
    {
        $command = match (\PHP_OS_FAMILY) {
            'Darwin' => ['open', $url],
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => ['xdg-open', $url],
        };
        if (null === (new ExecutableFinder())->find($command[0])) {
            return false;
        }
        $process = new Process($command);
        $process->run();

        return $process->isSuccessful();
    }
}
