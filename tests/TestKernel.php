<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Tests;

use Survos\CommandBundle\SurvosCommandBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class TestKernel extends BaseKernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new SurvosCommandBundle(),
        ];
    }

    /**
     * @throws \Exception
     */
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__.'/config.yml');
    }

    public function getProjectDir(): string
    {
        return __DIR__.'/project-dir';
    }
}
