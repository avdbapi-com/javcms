<?php

namespace think\composer;

use Composer\Composer;
use Composer\Installer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $manager = $composer->getInstallationManager();

        // Register the framework core installer
        $manager->addInstaller(new ThinkFramework($io, $composer));

        // Register the unit testing package installer
        $manager->addInstaller(new ThinkTesting($io, $composer));

        // Register the extension installer
        $manager->addInstaller(new ThinkExtend($io, $composer));

    }
}