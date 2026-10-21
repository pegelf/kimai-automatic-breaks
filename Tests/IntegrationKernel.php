<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Tests;

use App\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Isolated test container using installed plugins and rollback-only database fixtures. */
final class IntegrationKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 4);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/kimai-automatic-breaks-tests-' . substr(hash('sha256', $this->getProjectDir()), 0, 12);
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('framework', [
            'test' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'mailer' => ['dsn' => 'null://null'],
        ]);
    }
}
