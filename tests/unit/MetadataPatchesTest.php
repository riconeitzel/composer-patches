<?php

namespace cweagans\Composer\Tests\Unit;

use Codeception\Test\Unit;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\ArrayRepository;
use Composer\Util\Filesystem;
use cweagans\Composer\MetadataPatches;
use RuntimeException;

class MetadataPatchesTest extends Unit
{
    private string $directory;
    private Composer $composer;
    private array $target;
    private array $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/metadata-test-' . bin2hex(random_bytes(16));
        mkdir($this->directory, 0700);
        copy(codecept_data_dir('metadata-provider/external.patch'), $this->directory . '/external.patch');
        $this->target = [
            'name' => 'cweagans/external-metapackage',
            'version' => '1.0.0',
            'type' => 'metapackage',
            'require' => ['cweagans/metapackage-test-package' => '^1.0'],
            'extra' => ['preserve-me' => true],
        ];
        $this->provider = [
            'name' => 'cweagans/metadata-provider',
            'version' => '1.0.0',
            'type' => 'metapackage',
            'require' => ['cweagans/external-metapackage' => '1.0.0'],
            'dist' => ['type' => 'path', 'url' => $this->directory],
            'extra' => [
                'metadata-patches' => [
                    'cweagans/external-metapackage' => [
                        'version' => '1.0.0',
                        'patches' => [
                            [
                                'url' => 'external.patch',
                                'sha256' => hash_file('sha256', $this->directory . '/external.patch'),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        (new Filesystem())->removeDirectory($this->directory);
        parent::tearDown();
    }

    private function metadata(string $providerVersion = '1.0.0'): MetadataPatches
    {
        $this->composer = Factory::create(new NullIO(), [
            'name' => 'test/project',
            'require' => ['cweagans/metadata-provider' => $providerVersion],
            'repositories' => [
                ['type' => 'package', 'package' => [$this->provider, $this->target]],
                ['packagist.org' => false],
            ],
        ], true, true);
        return new MetadataPatches($this->composer, new NullIO());
    }

    /**
     * @dataProvider providerRequirements
     */
    public function testPatchesBeforeDiscoveryWithoutMutatingSourceRepository(string $requirement): void
    {
        $this->provider['require']['cweagans/external-metapackage'] = $requirement;
        $metadata = $this->metadata();
        $manager = $this->composer->getRepositoryManager();
        $original = $manager->findPackage('cweagans/external-metapackage', '1.0.0');
        $metadata->prepare(['cweagans/metadata-provider']);
        $patched = $manager->findPackage('cweagans/external-metapackage', '1.0.0');
        $this->assertSame('^2.0', $patched->getRequires()['cweagans/metapackage-test-package']->getPrettyConstraint());
        $this->assertArrayHasKey('cweagans/new-metapackage', $patched->getRequires());
        $this->assertSame('^1.0', $original->getRequires()['cweagans/metapackage-test-package']->getPrettyConstraint());
        $this->assertTrue($patched->getExtra()['preserve-me']);
        $this->assertSame(
            hash_file('sha256', $this->directory . '/external.patch'),
            $patched->getExtra()['composer-patches-metadata'][0]['sha256']
        );
    }

    public function providerRequirements(): iterable
    {
        yield 'explicit version' => ['1.0.0'];
        yield 'self version' => ['self.version'];
    }

    /**
     * @dataProvider invalidProviders
     */
    public function testRejectsUnpinnedProviders(string $version): void
    {
        $metadata = $this->metadata($version);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Metadata patches require an exact stable version');
        $metadata->prepare(['cweagans/metadata-provider']);
    }

    public function invalidProviders(): iterable
    {
        yield 'range' => ['^1.0'];
        yield 'branch' => ['dev-main'];
    }

    /**
     * @dataProvider unsafePatches
     */
    public function testRejectsUnsafePatches(string $url, ?string $contents, string $message): void
    {
        if ($contents !== null) {
            file_put_contents($this->directory . '/external.patch', $contents);
        }
        $definition = &$this->provider['extra']['metadata-patches']['cweagans/external-metapackage']['patches'][0];
        $definition['url'] = $url;
        $definition['sha256'] = hash_file('sha256', $this->directory . '/external.patch');
        $metadata = $this->metadata();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        $metadata->prepare(['cweagans/metadata-provider']);
    }

    public function unsafePatches(): iterable
    {
        yield 'path escape' => ['../external.patch', null, 'must name a file inside'];
        yield 'missing file' => ['missing.patch', null, 'must name a file inside'];
        yield 'other file' => [
            'external.patch',
            "--- a/other.json\n+++ b/other.json\n@@ -1 +1 @@\n-old\n+new\n",
            'must modify only a/composer.json',
        ];
        yield 'identity change' => [
            'external.patch',
            "--- a/composer.json\n+++ b/composer.json\n@@ -1,3 +1,3 @@\n {\n"
            . "-    \"name\": \"cweagans/external-metapackage\",\n+    \"name\": \"other/package\",\n"
            . "     \"version\": \"1.0.0\",\n",
            'may change only require, conflict, provide and replace',
        ];
        yield 'does not apply' => [
            'external.patch',
            "--- a/composer.json\n+++ b/composer.json\n@@ -1 +1 @@\n-old\n+new\n",
            'Metadata patch does not apply',
        ];
    }

    public function testRejectsChecksumMismatch(): void
    {
        $this->provider['extra']['metadata-patches']['cweagans/external-metapackage']['patches'][0]['sha256'] =
            str_repeat('0', 64);
        $metadata = $this->metadata();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHA-256 mismatch');
        $metadata->prepare(['cweagans/metadata-provider']);
    }

    public function testCanonicalRepositoryDoesNotFallThroughToShadowedVersion(): void
    {
        $this->target['version'] = '2.0.0';
        $this->metadata();
        $shadowed = $this->target;
        $shadowed['version'] = '1.0.0';
        $this->composer->getRepositoryManager()->addRepository(
            new ArrayRepository([(new ArrayLoader())->load($shadowed)])
        );
        $metadata = new MetadataPatches($this->composer, new NullIO());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not uniquely resolve metapackage');
        $metadata->prepare(['cweagans/metadata-provider']);
    }

    public function testBundledPatchesRequireDownloadableProvider(): void
    {
        unset($this->provider['dist']);
        $metadata = $this->metadata();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs source/dist metadata to supply bundled patch files');
        $metadata->prepare(['cweagans/metadata-provider']);
    }

    public function testRequiresExplicitChecksum(): void
    {
        unset($this->provider['extra']['metadata-patches']['cweagans/external-metapackage']['patches'][0]['sha256']);
        $metadata = $this->metadata();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a URL/path and lowercase SHA-256');
        $metadata->prepare(['cweagans/metadata-provider']);
    }

    public function testTargetMustSatisfyProviderRequirement(): void
    {
        $this->provider['require']['cweagans/external-metapackage'] = '^2.0';
        $metadata = $this->metadata();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("must satisfy the provider's require");
        $metadata->prepare(['cweagans/metadata-provider']);
    }
}
