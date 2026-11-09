# Composer registry

The Composer repository for the organization's shared packages. [Satis](https://github.com/composer/satis)
builds it from the release tags of the repositories listed in `satis.json`. It holds metadata only: every
version points at the zipball of its source repository at the released commit, so whoever may read a
package's repository may install it, and nobody else.

> **Status: proof of concept**, in a personal account, without consumers. Moving to the organization
> changes every `andreapalladiokiv/…` in this file, in `satis.json` (`homepage`, `repositories`), in
> `sync/action.yml` (the registry and its owner) and in the consumers' configuration.

```
package repository                         composer-registry
──────────────────                         ─────────────────
push tag v1.2.3
  └─ release.yml ─ sync action ──────────▶ build.yml (workflow_dispatch)
       waits for the build                   1. Satis reads every listed repository (reader App, read-only)
       red if the build is refused           2. bin/check.php: may this build replace what is published?
                                             3. commits packages.json and p2/ to main
```

## Install packages from it

```json
"repositories": [
  {
    "type": "composer",
    "url": "https://api.github.com/repos/andreapalladiokiv/composer-registry/contents/packages.json?ref=main",
    "options": {"http": {"header": ["Accept: application/vnd.github.raw"]}},
    "only": ["techork/*"]
  },
  {"type": "composer", "url": "https://repo.packagist.org", "exclude": ["techork/*"]},
  {"packagist.org": false}
]
```

and a GitHub token that can read this repository and the packages' repositories:
`composer config --global github-oauth.github.com <token>`, in CI `COMPOSER_AUTH`.

- `techork/*` comes from the registry and nothing else does. The last two entries replace the default
  Packagist with one that never answers for `techork/*`: without them, a `techork/*` name the registry has
  not published (a typo, a package not released yet) is looked up on Packagist, where anyone can publish
  it.
- The registry is read through the GitHub contents API, not `raw.githubusercontent.com`: Composer sends its
  GitHub token only to GitHub's own hosts.
- In CI, cache `~/.cache/composer`. A cold install costs one GitHub API request per package (its zipball);
  a shared token has 5,000 an hour.

## Release a package through it

### Once per repository

1. **Let the registry read it.** An organization owner adds the repository to the installation of the
   reader App (Settings → GitHub Apps → reader App → Configure → Repository access → Only select
   repositories). The App can read contents and nothing else.
2. **Admit it, under its name.** A pull request adding it to `repositories` in `satis.json`:

   ```json
   {"type": "vcs", "url": "https://github.com/andreapalladiokiv/saga", "name": "techork/saga"}
   ```

   `name` is the one package this repository may publish. The name in its `composer.json` must match;
   whatever it says, nothing else is published from it. The build after the merge publishes every release
   tag the repository already has.
3. **Add the release workflow:**

   ```yaml
   # .github/workflows/release.yml
   name: Release
   on:
     push:
       tags: ['v*']
   jobs:
     registry:
       runs-on: ubuntu-latest
       steps:
         - uses: andreapalladiokiv/composer-registry/sync@main
           with:
             client-id: ${{ vars.COMPOSER_REGISTRY_TRIGGER_CLIENT_ID }}
             private-key: ${{ secrets.COMPOSER_REGISTRY_TRIGGER_KEY }}
   ```

   The variable and the secret are the organization's, shared with the package repositories.
4. **Protect its release tags.** A tag ruleset on `v*`: only release managers create them, nobody deletes
   or moves them. Pushing a release tag is what releasing is; a moved tag is refused by the build anyway,
   but then the release is stuck until someone restores it.

The package needs a `composer.json` at the root of the repository with that `name` and without `version`.
One repository is one package.

### Every release

Push a tag `vX.Y.Z`. The release workflow starts the registry's build and waits for it: green means the
version is published, red means the build refused it, and the run says why. Only stable versions are
published: `v1.2.3-beta.1` and branches (`dev-main`) are not (`minimum-stability` in `satis.json`).

The build also runs when `satis.json` changes on `main`, every night as a safety net, and by hand
(Actions → build → Run workflow). Every build is complete, from all listed repositories; one runs at a
time, and of several waiting only the newest is kept, which loses nothing.

### What the build refuses

`bin/check.php` compares what Satis built with what is published and with the repositories' actual tags.
A refused build publishes nothing, not even what else it would have published, and the build stays red
until the cause is dealt with.

| A build that | Is refused because | Deal with it by |
|---|---|---|
| would publish from a repository anything but its `name` in `satis.json` | a repository publishes its own package only | fixing the `name` in its `composer.json` |
| leaves out a release tag (no or invalid `composer.json`, a version that contradicts the tag) | Satis skips such a tag silently; the build log, with `-vv`, says why | fixing the tag's `composer.json` and releasing a new version, or blacklisting the version |
| would point a published version at another commit (a moved or recreated tag) | versions are immutable | restoring the tag; release a new version instead |
| would lose a published version (a deleted tag, a repository removed from `satis.json` or from the App) | what is published stays installable | restoring it, or withdrawing it on purpose |
| runs on a `satis.json` with a repository without `name`, two repositories with one `name`, or a `name` outside `techork/*` | — | fixing `satis.json` |

## Maintaining it

Every decision is a pull request to `satis.json`: its history says who decided what and why, and the
history of `p2/` what the build then published. `p2/` and `packages.json` are written by the build only.

| To | Change in `satis.json` |
|---|---|
| withdraw a version | `"blacklist": {"techork/name": "1.2.3"}`; any constraint works: `"1.2.*"`, `"*"` for every version. It stays withdrawn while it is listed |
| mark a package abandoned | `"abandoned": {"techork/name": true}` or `"techork/replacement"`; Composer warns on install |
| move a package to another repository | its `url`; refused unless the new repository has every published version at the same commit. The reader App must be installed on the new one |
| stop publishing a repository | remove it from `repositories` and blacklist its package with `"*"` |
| rename a package | admit the new name as a new package and abandon the old one for it: published names never change |

## Who can do what

| To | One needs | Bounded by |
|---|---|---|
| install a package | read on this repository (metadata) and on the package's repository (zipball) | repository access |
| release a version of package X | to push a `v*` tag to X's repository | X's tag ruleset |
| publish name Y from repository X | nobody can, unless `satis.json` names Y for X | `name` in `satis.json`, `bin/check.php` |
| let the registry read a repository | to manage the reader App's installation | organization owners |
| admit a repository, withdraw, abandon, move | a merged pull request to `satis.json` | the review rules of `main` |
| write the registry | only the build workflow, on `main` | its `GITHUB_TOKEN`; the reader key reaches `main` only |
| start a build | the trigger App's key | an extra build is the worst it can do: what a build publishes comes from `satis.json` and the tags it reads itself |

Read access to this repository shows the metadata of every package — names, versions, requirements — also
to someone who cannot read a package's sources.

## Setting it up

Once, by the organization's owners and this repository's admins.

**The reader App** reads the packages' repositories for the build.

- Repository permissions: Contents: Read-only (Metadata: Read-only comes with it). Nothing else; no
  webhook.
- Installed on the package repositories only (Only select repositories).
- Its client ID as the variable `REGISTRY_READER_CLIENT_ID` and a private key as the secret
  `REGISTRY_READER_KEY` of the **environment `registry`** of this repository, whose deployment branches are
  limited to `main`. Not as repository secrets: any workflow on any branch gets those, so anyone who can
  push a branch here could read every repository the App is installed on.

**The trigger App** lets the packages' release workflows start the build.

- Repository permissions: Actions: Read and write. Nothing else; no webhook.
- Installed on this repository only.
- Its client ID as the organization variable `COMPOSER_REGISTRY_TRIGGER_CLIENT_ID` and a private key as
  the organization secret `COMPOSER_REGISTRY_TRIGGER_KEY`, both shared with the package repositories.

**This repository**

- `main` changes only through reviewed pull requests.
- Settings → Actions → General → Access: accessible from repositories in the organization, so the package
  repositories can use `sync/action.yml` while this repository stays private.

## Working on it

```bash
composer install

# A build and its check, as the workflow runs them. A clean COMPOSER_HOME keeps a global GitHub token
# out of it; any token that can read the packages' repositories will do.
export COMPOSER_HOME=$(mktemp -d) GH_TOKEN=$(gh auth token)
export COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$GH_TOKEN\"}}"
composer satis:build satis.json _build --minify -vv
php bin/check.php satis.json . _build
```

Satis has no releases: `composer.json` follows its `4.x-dev` branch and `composer.lock` pins the commit.
Update it on purpose, `composer update composer/satis`, in a pull request of its own.

## What is here

| Path | |
|---|---|
| `satis.json` | the repositories, the package each one publishes, and every decision about what is published |
| `bin/check.php` | what a build may not change about what is published |
| `composer.json`, `composer.lock` | Satis, as a Composer plugin (`composer satis:build`) |
| `packages.json`, `p2/` | written by the build: the entry point, and every version of each package in Composer's minified form |
| `.github/workflows/build.yml` | builds with Satis and checks (job `build`: the reader key, read-only), then commits (job `publish`: write, no key) |
| `sync/action.yml` | what a package's release workflow uses to start the build and wait for it |

The `homepage` in `satis.json` is not a homepage: Satis turns its path into `metadata-url`, which Composer
completes with the host of the registry's URL, `api.github.com`.
