<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\YoutubeBundle\Controller\OAuthCallbackController;
use Survos\YoutubeBundle\Service\ClientFactory;
use Survos\YoutubeBundle\Service\LoopbackAuthorization;
use Survos\YoutubeBundle\Service\WebAuthorization;
use Survos\YoutubeBundle\Service\YoutubeService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosYoutubeBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('api_key')->defaultValue('')->end()
            ->scalarNode('client_id')->defaultValue('')->end()
            ->scalarNode('client_secret')->defaultValue('')->end()
            ->scalarNode('auth_config')->defaultValue('')->end()
            ->scalarNode('token_file')->defaultValue('%kernel.project_dir%/var/youtube/token.json')->end()
            ->scalarNode('upload_directory')->defaultValue('%kernel.project_dir%/var/youtube/uploads')->end()
            ->scalarNode('default_channel')->defaultValue('')->end()
            ->scalarNode('refresh_token')->defaultValue('')->info('Offline refresh token from an earlier youtube:authorize (e.g. from the YOUTUBE_REFRESH_TOKEN env var): upload without authorizing on this machine.')->end()
            ->scalarNode('redirect_uri')->defaultValue('')->info('Public OAuth callback, e.g. https://tunnel.example.org/youtube/oauth2callback (import the bundle controller). Empty: loopback on 127.0.0.1.')->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $builder->autowire(ClientFactory::class)
            ->setArgument('$apiKey', $config['api_key'])
            ->setArgument('$clientId', $config['client_id'])
            ->setArgument('$clientSecret', $config['client_secret'])
            ->setArgument('$authConfig', $config['auth_config'])
            ->setArgument('$tokenFile', $config['token_file'])
            ->setArgument('$refreshToken', $config['refresh_token']);
        $builder->autowire(YoutubeService::class)
            ->setArgument('$uploadDirectory', $config['upload_directory'])
            ->setArgument('$defaultChannel', $config['default_channel']);
        $builder->autowire(LoopbackAuthorization::class);
        $builder->autowire(WebAuthorization::class)
            ->setArgument('$redirectUri', $config['redirect_uri'])
            ->setArgument('$tokenFile', $config['token_file']);
        $builder->autowire(OAuthCallbackController::class)
            ->addTag('controller.service_arguments');
    }
}
