# Composer registry (demo)

Metadata only: each version points at a zipball of its own source repository, so who may install a
package is whoever may read that repository.

**Consume**

```json
"repositories": [{
  "type": "composer",
  "url": "https://raw.githubusercontent.com/andreapalladiokiv/composer-registry/main"
}]
```
plus `composer config --global github-oauth.github.com <token>`.

**Publish a new package**

1. PR here adding `vendor/name: owner/repo` to `owners.yml`.
2. Add the deploy key's private half as `REGISTRY_DEPLOY_KEY` in the package repository.
3. Add the release workflow shown at the top of `.github/workflows/publish.yml`. Push a `vX.Y.Z` tag.

Published versions are immutable; withdrawing one is a PR removing it from `p2/`.
