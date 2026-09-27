<?php

declare(strict_types=1);

namespace Survos\YoutubeBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\YoutubeBundle\Service\LoopbackAuthorization;
use Survos\YoutubeBundle\SurvosYoutubeBundle;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class BundleTest extends TestCase
{
    public function testCommandsAutowireWithoutCredentialsOrOtherSurvosBundles(): void
    {
        $kernel = new YoutubeTestKernel('test', true);
        try {
            $application = new Application($kernel);
            foreach (['youtube:authorize', 'youtube:fetch', 'youtube:channel', 'youtube:upload'] as $name) {
                self::assertTrue($application->has($name), $name);
            }
            $command = new CommandTester($application->find('youtube:upload'));
            self::assertSame(0, $command->execute(['filename' => __FILE__, '--title' => 'Offline test', '--dry-run' => true]));
            self::assertStringContainsString('No network requests', $command->getDisplay());
            $this->expectException(\InvalidArgumentException::class);
            $command->execute(['filename' => '/nonexistent', '--title' => 'Missing', '--dry-run' => true]);
        } finally {
            $kernel->shutdown();
        }
    }
    public function testOAuthRejectsWrongState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LoopbackAuthorization::callbackCode(['state' => 'wrong', 'code' => 'code'], 'expected');
    }
    public function testOAuthAcceptsMatchingStateAndRejectsDenial(): void
    {
        self::assertSame('code', LoopbackAuthorization::callbackCode(['state' => 'expected', 'code' => 'code'], 'expected'));
        $this->expectException(\RuntimeException::class);
        LoopbackAuthorization::callbackCode(['state' => 'expected', 'error' => 'access_denied'], 'expected');
    }
}

final class YoutubeTestKernel extends Kernel
{
    use MicroKernelTrait;
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SurvosYoutubeBundle();
    }
    public function getProjectDir(): string
    {
        return __DIR__.'/cache/project';
    }
    public function getCacheDir(): string
    {
        return __DIR__.'/cache/kernel';
    }
    public function getLogDir(): string
    {
        return __DIR__.'/cache/log';
    }
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'test', 'test' => true, 'http_method_override' => false]);
        $container->extension('survos_youtube', []);
    }
}
