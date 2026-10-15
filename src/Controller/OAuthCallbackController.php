<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Controller;

use Survos\YoutubeBundle\Service\WebAuthorization;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Google's redirect target for WebAuthorization (survos_youtube.redirect_uri). Import it only where
 * authorization happens, e.g. a dev-only route import:
 *   survos_youtube: { resource: '@SurvosYoutubeBundle/src/Controller/', type: attribute }.
 */
final class OAuthCallbackController
{
    public function __construct(private readonly WebAuthorization $authorization)
    {
    }

    #[Route('/youtube/oauth2callback', name: 'survos_youtube_oauth_callback', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        try {
            $this->authorization->complete($request->query->all());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return new Response('YouTube authorization failed: '.$e->getMessage(), Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain']);
        }

        return new Response('YouTube authorized. You can close this tab and return to the terminal.', headers: ['Content-Type' => 'text/plain']);
    }
}
