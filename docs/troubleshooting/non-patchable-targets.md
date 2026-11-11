---
title: Non-patchable targets
weight: 20
---

{{< lead text="There are some things that this plugin cannot patch." >}}

## Changes to `composer.json`

Attempting to use an ordinary filesystem patch to change `composer.json` in a dependency will _never_ change dependency resolution. By the time file patches run, Composer has already selected dependencies using metadata aggregated by Packagist (or whatever metadata repository you're using). Therefore, changes to `composer.json` on disk will have _no effect_ on installed packages.

This means that an ordinary file patch cannot e.g. change a dependency's PHP constraint or the framework version that a plugin depends on.

If you need to modify a dependency's `composer.json` or its underlying dependencies, you must do one of the following:

- Work to get the underlying issue resolved in the upstream package.
- Fork the package and [specify your fork as the package repository](https://getcomposer.org/doc/05-repositories.md#vcs) in your root `composer.json`
- Specify compatible package version requirements in your root `composer.json`
- For metapackages, use the separate pre-resolution [metadata patch workflow]({{< relref "../usage/metapackage-metadata.md" >}}).

@anotherjames over at @computerminds wrote an article about how to work around
that particular problem for a Drupal 8 -> Drupal 9 upgrade:

[James Williams](https://github.com/anotherjames) wrote an article about how to work around this problem for a Drupal 8 -> Drupal 9 upgrade: [Apply Drupal 9 compatibility patches with Composer](https://www.computerminds.co.uk/articles/apply-drupal-9-compatibility-patches-composer) ([archive](https://web.archive.org/web/20210124171010/https://www.computerminds.co.uk/articles/apply-drupal-9-compatibility-patches-composer)). Although it is specific to Drupal, you may be able to use the information to do something more specific to your project/ecosystem.

## Metapackages

Composer has the concept of a "metapackage", which is an empty package that contains requirements and will trigger their installation, but contains no files and will not write anything to the filesystem. Ordinary filesystem patches cannot target metapackages. The separate, opt-in [metadata patch workflow]({{< relref "../usage/metapackage-metadata.md" >}}) can apply dependency metadata patches supplied by a custom metapackage before Composer resolves an external metapackage's dependencies.

There are two different use cases to distinguish:

### Patching files in a metapackage's dependencies

The dependencies of a metapackage can be patched normally, as long as they install files. Use the name of the package that owns the files as the patch target, not the name of the metapackage. You do not need to add the transitive dependency to your root `require` section.

For example, if `vendor/distribution` is a metapackage that requires `vendor/library`, add this to your root `composer.json`:

```json
{
    "extra": {
        "patches": {
            "vendor/library": {
                "Fix the library": "patches/library-fix.patch"
            }
        }
    }
}
```

Metapackages can also declare `extra.patches` targeting their dependencies. The dependency resolver reads these definitions from `composer.lock`, just as it does for other packages, unless [dependency patch resolution is disabled or the package is ignored]({{< relref "../usage/configuration.md" >}}). A metapackage does not need an installation directory to _declare_ patches for other packages. Local patch paths are relative to the root project, not to the metapackage; use a downloadable patch URL when distributing patch definitions.

### Changing a metapackage's dependency requirements

This is a metadata change, not a filesystem patch. Creating a temporary `composer.json` for the metapackage and patching it after installation would not change the dependencies Composer has already selected.

For a metapackage, a fork is not always necessary. Composer's [package repository](https://getcomposer.org/doc/05-repositories.md#package-2) can supply replacement metadata **before dependency resolution**, without a download or installation directory. For example, to replace `vendor/distribution` version `1.0.0`'s requirement on `vendor/library` with `^2.0`, put this repository **before any other repository providing that metapackage** in the root `composer.json`:

```json
{
    "repositories": [
        {
            "type": "package",
            "package": {
                "name": "vendor/distribution",
                "version": "1.0.0",
                "type": "metapackage",
                "require": {
                    "vendor/library": "^2.0"
                }
            }
        }
    ]
}
```

Keep the metapackage in your root `require` section. The example is a **complete replacement**, not a merge: copy the original metadata for the exact version, including all other requirements, `conflict`, `provide`, `replace`, and relevant `extra` fields, then change only what is needed. Ensure the replacement version satisfies the constraints of the root project and other dependencies. Do not copy this minimal example over a real distribution's metadata without preserving its other constraints.

Run `composer update vendor/distribution --with-all-dependencies` to resolve the changed requirements and write them to `composer.lock`. Commit both `composer.json` and `composer.lock`; subsequent `composer install` commands use the overridden requirements from the lock file. If you also define file patches, commit your patch files and `patches.lock.json` as usual. `composer patches-repatch` only reapplies file patches; it does not resolve changed dependency requirements.

Repositories are [root-only](https://getcomposer.org/doc/04-schema.md#repositories), and Composer 2 repositories are [canonical by default](https://getcomposer.org/doc/articles/repository-priorities.md). This override hides other versions of the same metapackage from lower-priority repositories, including Packagist. You are responsible for keeping the replacement metadata up to date; it does not automatically track upstream releases. Changing constraints does not itself make an incompatible library compatible: apply any necessary file patches to the actual dependency and test the resulting combination.

This manual replacement workflow uses Composer's existing repository support and Composer Patches' existing file patching support. For patches and patch files distributed through another metapackage without manually copying replacement metadata to the root project, use [metapackage metadata patches]({{< relref "../usage/metapackage-metadata.md" >}}).

## Specific dependencies

Some dependencies cannot be patched by this plugin.

### `composer/composer` (installed globally)

Because Composer is typically installed and running on a system long before this plugin is available in a particular project, it is not possible for this plugin to modify Composer itself.

If you have installed Composer locally in your project (by requiring it in the `composer.json` for your project), you _can_ patch the project-level Composer. I'm not entirely sure why you'd want to do this, but it would technically work.

### `cweagans/composer-patches`

Composer Patches applies patches to dependencies as they are installed. If Composer Patches isn't installed, it cannot apply patches to itself as it is installed by Composer. There is no supported workaround for this limitation, as most behavior can be changed via [configuration]({{< relref "../usage/configuration.md" >}}) or through the [API]({{< relref "../api/overview.md" >}}).

### `cweagans/composer-configurable-plugin`

Similarly, because Composer Configurable Plugin is a dependency of Composer Patches (and is therefore installed _before_ Composer Patches), Composer Configurable Plugin cannot be patched. There is no supported workaround for this limitation. If you run into problems with this dependency, open an issue upstream and it will be addressed promptly.
