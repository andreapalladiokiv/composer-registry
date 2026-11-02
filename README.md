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
2. A read-only deploy key on the package repository, its private half here as the secret
   `DEPLOY_KEY_<OWNER>_<REPO>` (upper case, other characters `_`).
3. In the package's release pipeline, on a `vX.Y.Z` tag:

   ```yaml
   - uses: andreapalladiokiv/composer-registry/publish@main
     with: { token: ${{ secrets.COMPOSER_REGISTRY_TOKEN }} }
   ```
   The token needs only Actions: write on this repository. The step fails when the registry refuses.

Only this repository writes to itself: a request names a repository and a tag, and the registry reads
the tag on its own. Published versions are immutable; withdrawing one is a PR removing it from `p2/`.
