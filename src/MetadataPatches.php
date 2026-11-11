<?php

namespace cweagans\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackage;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;
use Composer\Util\SyncHelper;
use RuntimeException;

class MetadataPatches
{
    private const LINK_FIELDS = ['require', 'conflict', 'provide', 'replace'];

    private Composer $composer;
    private IOInterface $io;
    private RepositorySet $repositories;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->repositories = new RepositorySet();
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            $this->repositories->addRepository($repository);
        }
    }

    public function findMetapackage(string $name, string $version): CompletePackage
    {
        $constraint = (new VersionParser())->parseConstraints($version);
        if (
            !$constraint instanceof Constraint || $constraint->getOperator() !== '=='
            || VersionParser::parseStability($version) !== 'stable'
        ) {
            throw new RuntimeException("Metadata patches require an exact stable version for $name, not $version.");
        }
        $packages = $this->repositories->findPackages($name, $constraint);
        if (count($packages) !== 1 || !$packages[0] instanceof CompletePackage) {
            throw new RuntimeException(
                "Could not uniquely resolve metapackage $name $version in the root repositories."
            );
        }
        if ($packages[0]->getType() !== 'metapackage') {
            throw new RuntimeException("Metadata patch target/provider $name must have type metapackage.");
        }
        return $packages[0];
    }

    /**
     * A stable, dependency-only composer.json; all other published metadata is preserved separately.
     */
    public static function manifest(PackageInterface $package): array
    {
        $data = (new ArrayDumper())->dump($package);
        return array_intersect_key($data, array_flip(array_merge(['name', 'version', 'type'], self::LINK_FIELDS)));
    }

    public function prepare(array $providers): void
    {
        $directory = sys_get_temp_dir() . '/composer-metadata-' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create metadata patch workspace $directory.");
        }
        try {
            $packages = [];
            $providerPackages = [];
            foreach ($providers as $name) {
                if (!is_string($name) || !isset($this->composer->getPackage()->getRequires()[$name])) {
                    throw new RuntimeException(
                        'Each metadata-patch-provider must be a direct root require dependency.'
                    );
                }
                $version = $this->composer->getPackage()->getRequires()[$name]->getPrettyConstraint();
                $provider = $this->findMetapackage($name, $version);
                $providerPackages[$name] = $provider;
                $this->prepareProvider($provider, $packages, $directory);
            }
            if (array_intersect_key($providerPackages, $packages)) {
                throw new RuntimeException('A metadata patch provider cannot also be a metadata patch target.');
            }
            $overlay = new ArrayRepository();
            foreach (array_merge($providerPackages, $packages) as $package) {
                $overlay->addPackage((new ArrayLoader())->load((new ArrayDumper())->dump($package)));
            }
            $this->composer->getRepositoryManager()->prependRepository($overlay);
        } finally {
            (new Filesystem())->removeDirectory($directory);
        }
    }

    private function prepareProvider(CompletePackage $provider, array &$packages, string $directory): void
    {
        $definitions = $provider->getExtra()['metadata-patches'] ?? null;
        if (!is_array($definitions) || !$definitions) {
            throw new RuntimeException("Metapackage {$provider->getName()} has no extra.metadata-patches definitions.");
        }
        $assets = null;
        foreach ($definitions as $name => $definition) {
            if (
                !is_string($name) || !is_array($definition)
                || !is_string($definition['version'] ?? null)
                || !is_array($definition['patches'] ?? null) || !$definition['patches']
            ) {
                throw new RuntimeException("Invalid metadata-patches definition in {$provider->getName()}.");
            }
            $version = $definition['version'];
            if (!isset($provider->getRequires()[$name])) {
                throw new RuntimeException("Metadata patch target $name $version must satisfy the provider's require.");
            }
            $original = $this->findMetapackage($name, $version);
            $required = $provider->getRequires()[$name]->getConstraint();
            if (!$required->matches(new Constraint('==', $original->getVersion()))) {
                throw new RuntimeException("Metadata patch target $name $version must satisfy the provider's require.");
            }
            $package = $packages[$name] ?? $original;
            if ($package->getVersion() !== $original->getVersion()) {
                throw new RuntimeException("Metadata patch providers disagree on the version of $name.");
            }
            foreach ($definition['patches'] as $definitionPatch) {
                if (
                    !is_array($definitionPatch) || !is_string($definitionPatch['url'] ?? null)
                    || !is_string($definitionPatch['sha256'] ?? null)
                    || !preg_match('/^[a-f0-9]{64}$/', $definitionPatch['sha256'])
                ) {
                    throw new RuntimeException("Metadata patch for $name requires a URL/path and lowercase SHA-256.");
                }
                $url = $definitionPatch['url'];
                if (str_starts_with($url, 'https://')) {
                    $patchFile = $directory . '/download.patch';
                    (new HttpDownloader($this->io, $this->composer->getConfig()))->copy($url, $patchFile);
                } else {
                    if ($assets === null) {
                        $assets = $directory . '/' . hash('sha256', $provider->getName());
                        $this->downloadAssets($provider, $assets);
                    }
                    $patchFile = realpath($assets . '/' . $url);
                    if (
                        $url === '' || str_contains($url, ':') || str_starts_with($url, '/')
                        || $patchFile === false || !is_file($patchFile)
                        || !str_starts_with($patchFile, realpath($assets) . DIRECTORY_SEPARATOR)
                    ) {
                        throw new RuntimeException(
                            "Metadata patch path $url must name a file inside {$provider->getName()}."
                        );
                    }
                }
                if (!hash_equals($definitionPatch['sha256'], hash_file('sha256', $patchFile))) {
                    throw new RuntimeException("SHA-256 mismatch for metadata patch $url from {$provider->getName()}.");
                }
                $this->io->write(
                    "  - Patching metadata for <info>$name</info> from <info>{$provider->getName()}</info>"
                );
                $package = $this->apply($package, $patchFile, $directory . '/target');
                $extra = $package->getExtra();
                $extra['composer-patches-metadata'][] = [
                    'provider' => $provider->getName(),
                    'version' => $provider->getPrettyVersion(),
                    'reference' => $provider->getSourceReference() ?? $provider->getDistReference(),
                    'url' => $url,
                    'sha256' => $definitionPatch['sha256'],
                ];
                $package->setExtra($extra);
            }
            $packages[$name] = $package;
        }
    }

    private function downloadAssets(CompletePackage $provider, string $path): void
    {
        if (!$provider->getDistUrl() && !$provider->getSourceUrl()) {
            throw new RuntimeException(
                "Metapackage {$provider->getName()} needs source/dist metadata to supply bundled patch files."
            );
        }
        // Download a separate library-shaped copy: normal metapackage installation must remain fileless.
        $data = (new ArrayDumper())->dump($provider);
        $data['type'] = 'library';
        $data['transport-options']['symlink'] = false;
        $assets = (new ArrayLoader(null, true))->load($data);
        $loop = $this->composer->getLoop();
        $manager = $this->composer->getDownloadManager();
        try {
            SyncHelper::await($loop, $manager->download($assets, $path));
            SyncHelper::await($loop, $manager->prepare('install', $assets, $path));
            SyncHelper::await($loop, $manager->install($assets, $path));
        } finally {
            if ($assets->getInstallationSource() !== null) {
                SyncHelper::await($loop, $manager->cleanup('install', $assets, $path));
            }
        }
    }

    private function apply(CompletePackage $package, string $patchFile, string $directory): CompletePackage
    {
        (new Filesystem())->ensureDirectoryExists($directory);
        $manifest = self::manifest($package);
        $file = new JsonFile($directory . '/composer.json');
        $file->write($manifest);
        $executor = new ProcessExecutor($this->io);
        $prefix = 'git -C ' . ProcessExecutor::escape($directory);
        $patch = ProcessExecutor::escape($patchFile);
        $output = '';
        if (
            $executor->execute("$prefix init --quiet", $output) !== 0
            || $executor->execute("$prefix apply --recount --numstat -p1 $patch", $output) !== 0
            || !preg_match('/^\d+\t\d+\tcomposer\.json\r?\n?$/D', $output)
        ) {
            throw new RuntimeException('A metadata patch must modify only a/composer.json and b/composer.json.');
        }
        if ($executor->execute("$prefix apply --recount --check -p1 $patch", $output) !== 0) {
            throw new RuntimeException(
                "Metadata patch does not apply to {$package->getName()}: " . $executor->getErrorOutput()
            );
        }
        if ($executor->execute("$prefix apply --recount -p1 $patch", $output) !== 0) {
            throw new RuntimeException(
                "Could not apply metadata patch to {$package->getName()}: " . $executor->getErrorOutput()
            );
        }
        if (is_link($file->getPath()) || !is_file($file->getPath())) {
            throw new RuntimeException('A metadata patch must leave composer.json as a regular file.');
        }
        $patched = $file->read();
        $immutable = array_diff_key($manifest, array_flip(self::LINK_FIELDS));
        if (array_diff_key($patched, array_flip(self::LINK_FIELDS)) !== $immutable) {
            throw new RuntimeException('Metadata patches may change only require, conflict, provide and replace.');
        }
        $data = (new ArrayDumper())->dump($package);
        foreach (self::LINK_FIELDS as $field) {
            unset($data[$field]);
            if (isset($patched[$field])) {
                if (!is_array($patched[$field])) {
                    throw new RuntimeException("Metadata field $field must be a dependency constraint map.");
                }
                foreach ($patched[$field] as $name => $constraint) {
                    if (!is_string($name) || !is_string($constraint)) {
                        throw new RuntimeException("Metadata field $field must contain string dependency constraints.");
                    }
                }
                $data[$field] = $patched[$field];
            }
        }
        $file->validateSchema(JsonFile::LAX_SCHEMA);
        return (new ArrayLoader())->load($data);
    }
}
