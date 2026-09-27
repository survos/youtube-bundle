# Survos YouTube Bundle

A small Symfony bundle built on `survos/kit-bundle` and Google's official
`google/apiclient`. Fetch channel videos, authorize a channel, and upload local
videos from the console. No Doctrine, Twig, KPA or Mediary dependency.

Requires PHP 8.5 and Symfony 8.1.

## Install and configure

```bash
composer require survos/youtube-bundle
```

Flex registers `Survos\YoutubeBundle\SurvosYoutubeBundle`. Without Flex, add it
manually to `config/bundles.php`.

```yaml
# config/packages/survos_youtube.yaml
survos_youtube:
    api_key: '%env(YOUTUBE_API_KEY)%' # Enough for public channel fetching
    default_channel: '%env(YOUTUBE_CHANNEL)%'
    client_id: '%env(OAUTH_GOOGLE_CLIENT_ID)%'
    client_secret: '%env(OAUTH_GOOGLE_CLIENT_SECRET)%'
    token_file: '%kernel.project_dir%/var/youtube/token.json'
    upload_directory: '%kernel.project_dir%/var/youtube/uploads'
```

All settings are optional until the corresponding operation needs them. A
read-only application can configure just `api_key` and `default_channel`.
Alternatively, set `auth_config` to the absolute path of Google's downloaded
OAuth client JSON; it takes precedence over `client_id`/`client_secret`.
Use a user OAuth client, not a service account. Keep secrets out of source control.
No generic `Google\Client` alias is registered, so existing Google integrations
in the consuming application are unaffected.

## Authorize uploads once

Enable YouTube Data API v3 on the Google Cloud project. For a **Web application**
OAuth client, register this exact authorized redirect URI:

```
http://127.0.0.1:8769/oauth2callback
```

An installed **Desktop app** client can use its loopback redirect flow. In testing
mode, add the Google account as a test user in the consent-screen configuration.

```bash
bin/console youtube:authorize
bin/console youtube:channel
```

Open the printed Google URL, select the correct account/channel, and grant the
requested `youtube.upload` and `youtube.readonly` scopes. The command waits up to
180 seconds for a loopback callback; it binds only to `127.0.0.1`. OAuth state and
PKCE protect the exchange. `--port` and `--timeout` are configurable; a web client
must register the matching redirect URI if the port changes.

Tokens are written atomically with mode 0600 and refreshed automatically. On a
headless server, authorize on your local machine with the same client first and
securely transfer the token file. Google testing-mode refresh tokens may expire;
re-run authorization when access is revoked or the refresh token expires.
Multiple channels can be handled with separate app configurations/token files.

## Fetch videos

```bash
bin/console youtube:fetch UC_CHANNEL_ID --limit=20 > videos.jsonl
# Omit the channel to use default_channel; omit --limit to fetch all available videos.
```

The service follows the channel's **uploads playlist** and paginates it, then
fetches video details in batches of 50. This replaces KPA's `search.list` loop
without relying on search's channel-result limit or relevance ordering. Results
retain playlist order; deleted or inaccessible videos are skipped. Public reads
use the API key when supplied; otherwise they use the authorized user's token.
There is no implicit cache or database write.

```php
use Survos\YoutubeBundle\Service\YoutubeService;

foreach ($youtube->videos($channelId) as $video) {
    // Google\Service\YouTube\Video: persist/map this in KPA, not in the bundle.
    $id = $video->getId();
    $title = $video->getSnippet()->getTitle();
    $description = $video->getSnippet()->getDescription();
    $views = $video->getStatistics()->getViewCount();
}
```

## Upload

```bash
bin/console youtube:upload ./brag.mp4 \
    --title="Ink — From scans to searchable stories" \
    --description-file=./youtube-description.txt \
    --thumbnail=./brag.jpg \
    --channel-id=UC_EXPECTED_CHANNEL \
    --visibility=unlisted
```

- Defaults: **unlisted**, not made for kids, subscriber notifications off, category 22.
- Optional: `--tags=history,newspapers`, `--category=27`, `--made-for-kids`, `--notify`.
- `--dry-run` validates local inputs without accessing credentials or the network.
- `--channel-id` (or `default_channel`) refuses to upload to a different authorized channel.
- The command prints the video URL and **actual** visibility reported by YouTube.
- Video data is read in 1 MiB chunks through Google's resumable uploader. The
  thumbnail is limited to 2 MiB and uploaded after the video's successful receipt.

Upload session URLs and completion receipts live under `upload_directory`, keyed
by channel, video content and requested metadata. Keep this directory on durable
storage. It contains sensitive session URLs and must not be publicly served.
Concurrent attempts for the same upload are locked. Rerunning the same command
resumes from Google's acknowledged byte offset or returns the existing video.
A failed thumbnail can be retried without re-uploading the video.

A changed title/description/visibility is a **new upload identity**, not an edit to
an existing video. This first version does not edit or delete published videos,
manage playlists, or upload captions. Do not delete a completion receipt merely
to change metadata. If Google expires an unfinished session, the command fails
instead of silently starting a new upload; inspect Studio and the saved state
before deliberately removing that session. Losing the initial session URL in a
process crash can leave an abandoned upload session; no client-side receipt can
provide exactly-once guarantees across that boundary.

Google can force uploads from unaudited API projects created after July 28, 2020
to **private**, regardless of the requested setting. This bundle reports that
mismatch rather than promising public/unlisted availability. Video processing
continues asynchronously after upload; the returned URL is not a claim that HD
processing or copyright checks have completed.

## Verification

```bash
composer install
composer test
composer analyse
composer validate --strict
```

Tests use Google's actual SDK with mocked HTTP responses: container/command
registration, OAuth callback validation, pagination, missing videos, chunk sizes,
channel mismatch, interrupted/completed uploads, thumbnail failures and token
storage. No ordinary test uploads media or contacts Google. Live consent and
upload require the consuming application's credentials.

## Upstream contract

Reviewed September 27, 2026:

- [Official PHP client](https://github.com/googleapis/google-api-php-client)
- [videos.insert and visibility restrictions](https://developers.google.com/youtube/v3/docs/videos/insert)
- [Resumable uploads](https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol)
- [Installed-app OAuth and loopback redirects](https://developers.google.com/identity/protocols/oauth2/native-app)

KPA can replace its importer with this service when it adopts the package.
`survos/media-bundle` is not a consumer and remains unchanged.
