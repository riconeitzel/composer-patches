---
title: Metapackage metadata patches
weight: 70
---

{{< lead text="Distribute dependency metadata patches in a custom metapackage and apply them before Composer resolves its dependencies." >}}

This is an **opt-in** workflow for three layers: a root project requires your custom metapackage, which requires an external metapackage. Your custom metapackage distributes unified diffs that change the external metapackage's dependency constraints. No manual replacement repository is needed in the root project.

Unlike ordinary file patches, metadata patches run **before package discovery**, not after installation or at `pre-pool-create`. The plugin downloads a temporary copy of the custom metapackage's source/dist, verifies the bundled patch checksums, patches a dependency-only manifest, and prepends an in-memory repository containing the patched metadata. Composer then discovers and resolves the changed dependencies normally, including newly introduced packages and their transitive dependencies.

Neither metapackage acquires a vendor directory. The downloaded assets and temporary manifests are deleted after preparation. Dependency scripts and installers are not invoked when retrieving these assets.

## Bootstrap the plugin

The plugin must already be loaded before the **first unlocked install/update**. Composer cannot run a plugin that has not been installed until after solving; no project plugin can overcome that bootstrap ordering.

Install the feature-enabled version of Composer Patches globally before the first solve, or bootstrap it in the project's vendor directory using a separate minimal manifest containing only the plugin. Your actual project must also require the feature-enabled version so that later updates use the same implementation. For a fork, configure its VCS repository in both the bootstrap/global project and the real project.

Once `composer.lock` contains patched metadata, a clean `composer install` does not need a global plugin or the provider's source files: Composer uses the locked dependency graph. Ordinary file patches still need Composer Patches as usual.

## Prepare a patch

Export the **published dependency metadata**, rather than patching an upstream repository's original `composer.json`:

```shell
composer patches-metadata vendor/external-meta 1.0.0 > composer.json.original
```

The export contains `name`, `version`, `type`, and any `require`, `conflict`, `provide`, and `replace` maps. The dependency maps are sorted by package name. For example:

```json
{
    "name": "vendor/external-meta",
    "version": "1.0.0",
    "require": {
        "vendor/library": "^1.0"
    },
    "type": "metapackage"
}
```

Edit a copy and create a unified diff whose paths are `a/composer.json` and `b/composer.json` (`-p1`). Only the four dependency maps can change. Identity, type, scripts, repositories, download locations, and other metadata cannot be patched. Other published package metadata is preserved.

Keep the patch inside your custom metapackage's source repository and ensure it is included in release archives. Compute its SHA-256 with `sha256sum patches/external-meta.patch`.

## Custom metapackage

Declare the external metapackage in `require` and its metadata patches in the separate `extra.metadata-patches` section:

```json
{
    "name": "my/custom-meta",
    "version": "1.0.0",
    "type": "metapackage",
    "require": {
        "vendor/external-meta": "1.0.0"
    },
    "extra": {
        "metadata-patches": {
            "vendor/external-meta": {
                "version": "1.0.0",
                "patches": [
                    {
                        "url": "patches/external-meta.patch",
                        "sha256": "<64-character lowercase SHA-256>"
                    }
                ]
            }
        }
    }
}
```

`url` is either a **path inside the custom metapackage's downloaded source/dist**, not the project root, or an HTTPS URL. Local files must remain inside that source/dist, including after resolving symlinks. A SHA-256 is mandatory for every patch. Source/dist metadata must be available from the root project's repositories for bundled files; a metapackage with no downloadable source cannot distribute local patch assets.

The external metapackage version must be an exact stable release satisfying the custom metapackage's requirement. Patch providers must also be exact stable releases. Development branches, aliases, and version ranges are deliberately unsupported so that the metadata cannot be supplied by a provider version different from the one actually selected.

## Root project

Require your custom metapackage at an exact stable version and explicitly trust it as a metadata patch provider:

```json
{
    "require": {
        "cweagans/composer-patches": "^2.0",
        "my/custom-meta": "1.0.0"
    },
    "extra": {
        "composer-patches": {
            "metadata-patch-providers": ["my/custom-meta"]
        }
    },
    "config": {
        "allow-plugins": {
            "cweagans/composer-patches": true
        }
    }
}
```

The plugin constraint above assumes a release containing this feature; until then, use your feature-enabled fork's version and repository. All involved package sources must be accessible through **root-level** repositories, as with any Composer installation.

Run a **full** `composer update` (or `composer install` with no lock file after bootstrapping). Commit `composer.lock`. The lock contains the patched requirements and records each patch's provider, provider version/reference, path/URL, and SHA-256 in the target's `extra.composer-patches-metadata`. Metadata patches do not use `patches.lock.json`; that file still describes ordinary filesystem patches.

Repeated updates start from the original repository metadata, not already-patched lock data. A locked install does not download or reapply metadata patches. Publish a new custom metapackage version and update the root requirement when changing patches; then run a full update again.

## Boundaries and safety

- Only explicitly trusted, directly required metapackages can provide metadata patches. `metadata-patch-providers` is empty by default. Ordinary `extra.patches`, dependency resolver settings, and file patching behavior are unchanged.
- Full updates only: partial updates, temporary version overrides, `update --lock`, `--root-reqs`, and `--patch-only` are rejected while providers are enabled. Edit root requirements and use a full update instead of resolving via `composer require` or `composer remove`; their `--no-update` forms can be used to edit the manifest first. This avoids silently retaining a locked graph built with a different provider or patch set.
- The in-memory repository is canonical and exposes the configured exact target/provider versions. Other versions of those package names are intentionally unavailable for that solve. Original root repository priority/filter rules are respected when obtaining the unpatched packages.
- Multiple providers run in root configuration order, and patches run in declaration order. Providers targeting the same package must agree on its version. A provider cannot itself be a target.
- The workflow requires Git and runs even with `--no-scripts`. `--no-plugins` disables it, as it disables all Composer plugins. Direct use of Composer's PHP `Installer` API does not dispatch the CLI command event and is not supported by this workflow.
- Checksum mismatches, missing assets, invalid metadata, unsupported targets, and non-applicable patches abort the command before solving. There is no unpatched fallback.
- These patches can remove constraints or introduce incompatible combinations. Root opt-in is a trust decision, not a compatibility guarantee. Review changes and test the resulting dependency graph.
