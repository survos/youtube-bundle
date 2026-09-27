<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Service;

use Symfony\Component\Filesystem\Filesystem;

/** Tokens and upload session URLs are credentials; never send these files to logs. */
final class PrivateJsonFile
{
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Expected a JSON object in the YouTube state file.');
        }

        return $data;
    }

    public static function write(string $path, array $data): void
    {
        $fs = new Filesystem();
        $fs->mkdir(dirname($path), 0700);
        $umask = umask(0077);
        try {
            if (is_file($path)) {
                $fs->chmod($path, 0600);
            }
            $fs->dumpFile($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
        } finally {
            umask($umask);
        }
    }
}
