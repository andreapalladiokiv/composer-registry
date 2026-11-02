# Composer registry (demo)

A Composer repository for packages released from their own GitHub repositories. It holds metadata
only: every version points at the zipball of its source repository at the released commit, so whoever
may read a package's repository may install it, and nobody else.

Only this repository writes to itself. A package's release pipeline only asks it to look at that
repository; the registry lists the repository's release tags, reads `composer.json` at the new ones
with its own read access, and publishes them.

## Install packages from it

```json
"repositories": [{
  "type": "composer",
  "url": "https://api.github.com/repos/andreapalladiokiv/composer-registry/contents/packages.json?ref=main",
  "options": {"http": {"header": ["Accept: application/vnd.github.raw"]}}
}]
```

and a token that can read this repository and the packages' repositories:
`composer config --global github-oauth.github.com <token>` (in CI, `COMPOSER_AUTH`).

The contents API is used rather than `raw.githubusercontent.com` because Composer sends its GitHub
token only to GitHub's own hosts; a private registry on `raw.githubusercontent.com` answers 404.

## Release a package through it

### Once per repository

1. **Admit it:** add the repository to the installation of the `composer-registry-demo` GitHub App
   (Settings → Applications → composer-registry-demo → Configure → Repository access). The App has
   Contents: read and nothing else. Removing it from the installation withdraws it; what it already
   published stays.
2. **Give its pipeline a trigger token** as the secret `COMPOSER_REGISTRY_TOKEN`, able to run workflows
   in `composer-registry` and nothing else.
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
             token: ${{ secrets.COMPOSER_REGISTRY_TOKEN }}
   ```

The package needs a `composer.json` at the root of the tag, with a `name` and without a `version`. A
monorepo releases each package under a tag whose tree is that package's directory, e.g. `saga/v1.2.0`.

### Every release

Push a tag `vX.Y.Z` (or `<package>/vX.Y.Z`). The release workflow asks the registry to sync this
repository and waits; a refused tag makes the release red. The action sends only the repository's name:

```
release.yml → sync action → sync.yml here, repository=owner/repo
  1. the App issues a read-only token for that repository (not installed → refused)
  2. one tag listing; tags whose commit the registry already holds are skipped
  3. per new tag: composer.json at the tag → bin/publish.php → p2/<vendor>/<name>.json
  4. one commit
```

The first sync after admission publishes every release tag the repository already has. By hand:
`gh workflow run sync.yml -R andreapalladiokiv/composer-registry -f repository=owner/repo`, or without
`repository` for every admitted one.

### What the registry refuses

A refused tag is skipped, reported as a warning, and leaves the run red; the rest of the sync goes on.

| Request or tag | Result |
|---|---|
| a repository the App is not installed on | refused: not admitted, nothing is read |
| no `composer.json` at the tag | refused |
| a name first published from another repository | refused: the name belongs to that one |
| a published version, moved to a different commit | refused: versions are immutable |
| a tag that is not `vX.Y.Z` or `<package>/vX.Y.Z` | ignored |

## Who can do what

| To | One needs | Set by |
|---|---|---|
| install a package | read on this repository (metadata) and on the package's repository (zipball) | repository access; on a personal account every collaborator has write, read-only access needs an organization |
| release a version | to push a release tag to an admitted repository | the package repository; a tag ruleset on `v*` (and `*/v*`) narrows it to release managers |
| admit a repository | to manage the App's installation | the account or organization owners |
| start a sync | the trigger token | which repositories hold `COMPOSER_REGISTRY_TOKEN` (in an organization, one organization secret with selected repositories) |
| change what is published | write on this repository (`maintain`, or pushing to `main`) | this repository's collaborators |

A sync request is not tied to the repository that sent it: `repository` is a plain input, and the run's
actor is whoever owns the trigger token. This is on purpose. A sync decides nothing: it reads tags that
already sit in an admitted repository, so a sync started by anyone only publishes earlier what the
next release would publish anyway. What protects the registry is the App's installation (what may be
read) and the package repositories' tag rules (who may release).

Read access to this repository shows the metadata of every package — names, versions, requirements —
also to someone who cannot read a package's sources. Packages that must stay unseen need a registry
of their own.

## Maintaining it

Everything other than publishing is the `maintain` workflow (Actions → maintain → Run workflow, or
`gh workflow run maintain.yml -f operation=… -f package=… -f argument=… -f reason=…`). Running it takes
write access to this repository. Each run is one commit naming the operation, the reason and who ran it,
so the history of `p2/` is the registry's audit log.

| operation | package | argument | does |
|---|---|---|---|
| `withdraw` | vendor/name | X.Y.Z | takes the version out; it could then be published again, from any commit — use rarely |
| `abandon` | vendor/name | optional vendor/replacement | marks every version abandoned, Composer warns on install |
| `unabandon` | vendor/name | | undoes `abandon` |
| `transfer` | vendor/name | owner/repo | the source repository moved; refused unless the target holds every published commit |
| `reindex` | | | rebuilds `packages.json` from `p2/` |

`p2/` files are in Composer's minified form — each version holds only what differs from the newer
one before it — so they are not edited by hand: removing one version would corrupt the ones after it.

## What is here

| Path | |
|---|---|
| `packages.json` | the entry point: where the per-package files are, and which packages exist |
| `p2/<vendor>/<name>.json` | every version of one package, in Composer's minified form |
| `bin/sync.php` | finds the new release tags of the given repositories and publishes them |
| `bin/publish.php` | adds, withdraws, abandons or transfers one package's versions; the only writer of `p2/` |
| `.github/workflows/sync.yml` | the sync, started by the action or by hand |
| `.github/workflows/maintain.yml` | every other change to what is published |
| `sync/action.yml` | what a package's release pipeline uses to start its sync and wait for it |

The registry's own settings: the variable `REGISTRY_APP_ID` and the secret `REGISTRY_APP_KEY` (the
App's private key). A workflow here can read every admitted repository through that key, so `main`
should only change through reviewed pull requests, and secrets only by its admins.
