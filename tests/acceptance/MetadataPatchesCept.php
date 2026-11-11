<?php

/**
 * @var \Codeception\Scenario $scenario
 */

use Composer\Json\JsonFile;
use cweagans\Composer\Tests\AcceptanceTester;
use PHPUnit\Framework\Assert;

$I = new AcceptanceTester($scenario);
$I->wantTo('apply bundled metadata patches from a custom metapackage before discovering external dependencies');
$I->amInPath(codecept_data_dir('fixtures/metadata-patches'));
$home = realpath('../metadata-patches-bootstrap');
$composer = 'COMPOSER_HOME=' . escapeshellarg($home) . ' composer --no-interaction';
$I->runShellCommand("$composer install --no-plugins --no-scripts 2>&1", false);
$I->seeResultCodeIsNot(0);
$I->seeInShellOutput('cweagans/metapackage-test-package');

$I->amInPath($home);
$I->runShellCommand("$composer install --no-scripts 2>&1");
$I->amInPath(codecept_data_dir('fixtures/metadata-patches'));
$I->runShellCommand("$composer patches-metadata cweagans/external-metapackage 1.0.0 2>&1");
$I->seeInShellOutput('"cweagans/metapackage-test-package": "^1.0"');
$I->runShellCommand("$composer install --no-scripts -vvv 2>&1");
$I->seeInShellOutput('Patching metadata for cweagans/external-metapackage from cweagans/metadata-provider');
$I->seeFileFound('message.txt', 'vendor/cweagans/metadata-leaf');
$I->dontSeeFileFound('vendor/cweagans/metadata-provider');
$I->dontSeeFileFound('vendor/cweagans/external-metapackage');

$lock = (new JsonFile('composer.lock'))->read();
$packages = array_column($lock['packages'], null, 'name');
Assert::assertSame('^2.0', $packages['cweagans/external-metapackage']['require']['cweagans/metapackage-test-package']);
Assert::assertSame('2.0.0', $packages['cweagans/metapackage-test-package']['version']);
Assert::assertArrayHasKey('cweagans/new-metapackage', $packages);
Assert::assertSame('1.0.0', $packages['cweagans/metadata-leaf']['version']);
Assert::assertSame(
    'cweagans/metadata-provider',
    $packages['cweagans/external-metapackage']['extra']['composer-patches-metadata'][0]['provider']
);
$originalLock = file_get_contents('composer.lock');
$I->runShellCommand("$composer update --no-scripts 2>&1");
Assert::assertSame($originalLock, file_get_contents('composer.lock'));

$I->runShellCommand("$composer update cweagans/metadata-provider --no-scripts 2>&1", false);
$I->seeResultCodeIsNot(0);
$I->seeInShellOutput('Metadata patches require a full composer update');
Assert::assertSame($originalLock, file_get_contents('composer.lock'));

$I->deleteDir('vendor');
$I->runShellCommand(
    'COMPOSER_HOME=' . escapeshellarg($home . '/empty-home') . ' composer --no-interaction install --no-scripts 2>&1'
);
$I->dontSeeInShellOutput('Patching metadata for');
$I->seeFileFound('message.txt', 'vendor/cweagans/metadata-leaf');
Assert::assertSame($originalLock, file_get_contents('composer.lock'));
$I->deleteDir($home . '/empty-home');
