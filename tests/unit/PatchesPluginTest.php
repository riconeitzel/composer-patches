<?php

/**
 * @file
 * Test the Patches plugin class.
 */

namespace cweagans\Composer\Tests\Unit;

use Codeception\Test\Unit;
use Codeception\Util\Stub;
use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer\InstallationManager;
use Composer\Installer\InstallerInterface;
use Composer\Installer\PackageEvent;
use Composer\IO\NullIO;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use cweagans\Composer\Patch;
use cweagans\Composer\PatchCollection;
use cweagans\Composer\Plugin\Patches;
use RuntimeException;

class PatchesPluginTest extends Unit
{
    /**
     * Test plugin activation.
     *
     * Despite not actually asserting anything, this test ensures that if the
     * plugin is required and not configured (through any means of doing so),
     * it won't cause an exception to be thrown or something.
     */
    public function testActivate()
    {
        $plugin = new Patches();
        $composer = new Composer();
        $package = new RootPackage('test/package', '1.0.0.0', '1.0.0');
        $package->setExtra([]);
        $composer->setPackage($package);
        $io = new NullIO();
        $plugin->activate($composer, $io);
    }

    /**
     * Test that overridding composer.json also overrides the patches.lock.json filename.
     */
    public function testGetPatchesLockFilePath()
    {
        $path = Patches::getPatchesLockFilePath();
        $filename = pathinfo($path, \PATHINFO_BASENAME);
        $this->assertEquals('patches.lock.json', $filename);

        putenv('COMPOSER=mycomposer.json');
        $path = Patches::getPatchesLockFilePath();
        $filename = pathinfo($path, \PATHINFO_BASENAME);
        $this->assertEquals('mycomposer-patches.lock.json', $filename);
    }

    /**
     * @dataProvider metapackageOperations
     */
    public function testMetapackageWithPatchesFailsClearly(OperationInterface $operation)
    {
        $patch = new Patch();
        $patch->package = 'test/metapackage';
        $patch->url = 'metapackage.patch';
        $patch->description = 'Attempt to patch a metapackage';
        $collection = new PatchCollection();
        $collection->addPatch($patch);
        $installer = $this->createMock(InstallerInterface::class);
        $installer->method('getInstallPath')->willReturn(null);
        $manager = $this->createMock(InstallationManager::class);
        $manager->method('getInstaller')->willReturn($installer);
        $composer = new Composer();
        $composer->setInstallationManager($manager);
        $plugin = Stub::make(Patches::class, [
            'composer' => $composer,
            'io' => new NullIO(),
            'patchCollection' => $collection,
        ]);
        $manager->expects($this->never())->method('getInstaller');
        $event = Stub::make(PackageEvent::class, ['getOperation' => $operation]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Cannot patch metapackage test/metapackage: it has no files or installation directory. '
            . 'Target the dependency that contains the files instead.'
        );

        $plugin->patchPackage($event);
    }

    /**
     * @dataProvider metapackageOperations
     */
    public function testMetapackageWithoutPatchesIsIgnored(OperationInterface $operation)
    {
        $io = $this->createMock(NullIO::class);
        $io->expects($this->once())->method('write')->with(
            'No patches found for <info>test/metapackage</info>',
            true,
            NullIO::DEBUG
        );
        $plugin = Stub::make(Patches::class, [
            'patchCollection' => new PatchCollection(),
            'io' => $io,
        ]);
        $event = Stub::make(PackageEvent::class, ['getOperation' => $operation]);

        $plugin->patchPackage($event);
    }

    public function metapackageOperations(): iterable
    {
        $package = new Package('test/metapackage', '1.0.0.0', '1.0.0');
        $package->setType('metapackage');
        yield 'install' => [new InstallOperation($package)];
        yield 'update' => [new UpdateOperation(new Package('test/metapackage', '0.9.0.0', '0.9.0'), $package)];
    }

    /**
     * Test the patch depth settings.
     *
     * @dataProvider guessDepthDataProvider
     */
    public function testGuessDepth(Patches $plugin, Patch $patch, int $expectedDepth)
    {
        $plugin->guessDepth($patch);
        $this->assertEquals($expectedDepth, $patch->depth);
    }

    /**
     * Provides data to testGuessDepth().
     */
    public function guessDepthDataProvider()
    {
        $package = new RootPackage('cweagans/composer-patches', '0.0.0.0', '0.0.0');
        $package->setExtra([]);

        $io = new NullIO();

        $composer = new Composer();
        $composer->setPackage($package);
        $composer->setEventDispatcher(new EventDispatcher($composer, $io));

        $plugin = new Patches();
        $plugin->activate($composer, $io);

        $patch = new Patch();
        $patch->package = 'some/package';
        yield 'global default depth' => [$plugin, $patch, 1];

        $patch = new Patch();
        $patch->depth = 123;
        yield 'depth set on patch' => [$plugin, $patch, 123];

        $patch = new Patch();
        $patch->package = 'drupal/core';
        yield 'depth set in global package override list' => [$plugin, $patch, 2];

        $package = new RootPackage('cweagans/composer-patches', '0.0.0.0', '0.0.0');
        $package->setExtra([
            'composer-patches' => [
                'package-depths' => [
                    'some/package' => 234,
                ],
            ],
        ]);
        $composer->setPackage($package);
        $plugin = new Patches();
        $plugin->activate($composer, $io);

        $patch = new Patch();
        $patch->package = 'some/package';
        yield 'depth set in project package override list' => [$plugin, $patch, 234];
    }
}
