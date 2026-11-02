# Composer registry (demo)

A Composer repository for packages released from their own GitHub repositories. It holds metadata
only: every version points at the zipball of its source repository at the released commit, so whoever
may read a package's repository may install it, and nobody else.

Only this repository writes to itself. A package's pipeline asks for a release by naming its
repository and a tag; the registry reads that tag on its own and decides what to publish.

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

1. **Admit it.** Add the repository to the installation of the `composer-registry-demo` GitHub App
   (Settings → Applications → composer-registry-demo → Configure → Repository access). The App has
   Contents: read and nothing else. Removing a repository from the installation withdraws it; what it
   already published stays.
2. **Give its pipeline a trigger token** as the secret `COMPOSER_REGISTRY_TOKEN`: a fine-grained token
   with access to `composer-registry` only and Actions: read and write. It can start the registry's
   workflow and nothing more.
3. **Add the release workflow:**

   ```yaml
   # .github/workflows/release.yml
   name: Release
   on:
     push:
       tags: ['v*']
     workflow_dispatch:   # gh workflow run release.yml --ref vX.Y.Z publishes an existing tag
   jobs:
     publish:
       runs-on: ubuntu-latest
       steps:
         - uses: andreapalladiokiv/composer-registry/publish@main
           with:
             token: ${{ secrets.COMPOSER_REGISTRY_TOKEN }}
   ```

The package itself needs a `composer.json` at the root of the tag, with a `name` and without a
`version`. A monorepo releases each package under a tag whose tree is that package's directory
(for example `saga/v1.2.0`) and calls the action once per such tag with `tag:` set.

### Every release

Push a `vX.Y.Z` tag. The first release of a name is no different from the ones after it.

```
package repo: tag pushed → release.yml → publish action
  └─ starts publish.yml here with repository + tag, waits for that run; its result is the release's

registry: publish.yml
  1. the tag looks like vX.Y.Z or <package>/vX.Y.Z, the repository is under this account
  2. the App issues a token for that repository alone, Contents: read, one hour  (not installed → refused)
  3. checks out the tag sparse: its commit and composer.json, nothing else
  4. bin/publish.php writes p2/<vendor>/<name>.json and packages.json
  5. commits with its own GITHUB_TOKEN; a push that lost a race rebases and retries
```

### What the registry refuses

| Request | Result |
|---|---|
| A repository the App is not installed on | refused: not admitted |
| A tag that is not `vX.Y.Z` or `<package>/vX.Y.Z` | refused |
| A name first published from another repository | refused: the name belongs to that one |
| A published version, from a different commit | refused: versions are immutable |
| A published version, from the same commit | accepted, nothing changes |

Tags that existed before a repository was admitted are published the same way, one dispatch per tag:
`gh workflow run release.yml --ref vX.Y.Z` in the package, or here, with rights on this repository:
`gh workflow run publish.yml -f repository=owner/repo -f tag=vX.Y.Z`.

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

The registry's own settings: the variable `REGISTRY_APP_ID` and the secret `REGISTRY_APP_KEY` (the
App's private key). A workflow here can read every admitted repository through that key, so `main`
should only change through reviewed pull requests, and secrets only by its admins.
