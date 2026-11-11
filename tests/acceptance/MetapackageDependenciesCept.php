<?php

/**
 * @var \Codeception\Scenario $scenario
 */

use Composer\Json\JsonFile;
use cweagans\Composer\Tests\AcceptanceTester;
use PHPUnit\Framework\Assert;

$I = new AcceptanceTester($scenario);
$I->wantTo('override metapackage requirements before solving and patch its filesystem dependency');
$I->amInPath(codecept_data_dir('fixtures/metapackage-dependencies'));
$I->runComposerCommand('install', ['-vvv']);
$I->seeInComposerOutput('Patching cweagans/metapackage-test-package');
$I->dontSeeInComposerOutput('Patching cweagans/test-metapackage');
$I->openFile('vendor/cweagans/metapackage-test-package/message.txt');
$I->seeInThisFile('Patched dependency contents.');
$I->dontSeeInThisFile('Original dependency contents.');
$I->dontSeeFileFound('vendor/cweagans/test-metapackage');

$lock = (new JsonFile('composer.lock'))->read();
$packages = array_column($lock['packages'], null, 'name');
Assert::assertSame('^2.0', $packages['cweagans/test-metapackage']['require']['cweagans/metapackage-test-package']);
Assert::assertSame('2.0.0', $packages['cweagans/metapackage-test-package']['version']);
Assert::assertArrayNotHasKey('cweagans/metapackage-test-package', (new JsonFile('composer.json'))->read()['require']);
$I->openFile('patches.lock.json');
$I->seeInThisFile('dependency:cweagans/test-metapackage');

$I->deleteDir('vendor');
$I->runComposerCommand('install', ['-vvv']);
$I->openFile('vendor/cweagans/metapackage-test-package/message.txt');
$I->seeInThisFile('Patched dependency contents.');

$I->runComposerCommand('update', ['packages' => ['cweagans/metapackage-test-package'], '-vvv']);
$lock = (new JsonFile('composer.lock'))->read();
$packages = array_column($lock['packages'], null, 'name');
Assert::assertSame('^2.0', $packages['cweagans/test-metapackage']['require']['cweagans/metapackage-test-package']);
Assert::assertSame('2.0.0', $packages['cweagans/metapackage-test-package']['version']);
$I->openFile('vendor/cweagans/metapackage-test-package/message.txt');
$I->seeInThisFile('Patched dependency contents.');

$I->runShellCommand('composer --no-interaction patches-repatch -vvv 2>&1');
$I->seeResultCodeIs(0);
$I->seeInShellOutput('Patching cweagans/metapackage-test-package');
$I->openFile('vendor/cweagans/metapackage-test-package/message.txt');
$I->seeInThisFile('Patched dependency contents.');
