# Composer registry (demo)

Metadata only: each version points at a zipball of its own source repository, so who may install a
package is whoever may read that repository.

**Consume**

```json
"repositories": [{
  "type": "composer",
  "url": "https://api.github.com/repos/andreapalladiokiv/composer-registry/contents/packages.json?ref=main",
  "options": {"http": {"header": ["Accept: application/vnd.github.raw"]}}
}]
```
plus `composer config --global github-oauth.github.com <token>`.

**Publish a new package**

1. Admit the repository: a read-only deploy key on it, its private half here as the secret
   `DEPLOY_KEY_<OWNER>_<REPO>` (upper case, other characters `_`). Only an admin of the registry can.
2. In the package's release pipeline, on a `vX.Y.Z` tag:

   ```yaml
   - uses: andreapalladiokiv/composer-registry/publish@main
     with: { token: ${{ secrets.COMPOSER_REGISTRY_TOKEN }} }
   ```
   The token needs only Actions: write on this repository. The step fails when the registry refuses.

Only this repository writes to itself: a request names a repository and a tag, and the registry reads
the tag on its own. A package name belongs to the repository that first published it; moving it is a PR
here rewriting its `p2/` file. Published versions are immutable; withdrawing one is a PR removing it from `p2/`.
