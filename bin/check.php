<?php

declare(strict_types=1);

/*
 * Decides whether a fresh Satis build may replace what is published.
 *
 *   GH_TOKEN=… php bin/check.php <satis.json> <published-dir> <built-dir>
 *
 * GH_TOKEN reads the tags of the repositories in satis.json (git ls-remote, outside the REST API's rate
 * limit). Composer's own version parser comes from vendor/, after composer install.
 *
 * Every repository in satis.json names the one package it publishes ("name"), under one of the
 * "available-package-patterns", and no two repositories name the same package. Whatever a repository's
 * composer.json says, it publishes that package and nothing else.
 *
 * The build is refused when satis.json breaks that, or when the build would:
 *   - publish from a repository anything but the package satis.json names for it;
 *   - leave out a release tag of a listed repository that satis.json does not blacklist. Satis skips a
 *     tag it cannot read (no or invalid composer.json, a version that contradicts the tag) without
 *     failing; the build log, run with -vv, says why;
 *   - point a published version at another commit (a moved or recreated tag);
 *   - lose a published version that satis.json does not blacklist (a deleted tag, a repository dropped
 *     from satis.json, or one the build can no longer read).
 *
 * Prints what the build changes, one line each ("published", "withdrawn", "moved"), for the commit
 * message. Exits 1 with the reasons when the build is refused.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Package\BasePackage;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;

$token = getenv('GH_TOKEN') ?: fail('GH_TOKEN is not set');
[, $configFile, $publishedDir, $builtDir] = $argv + [null, null, null, null];
$builtDir !== null || fail('usage: php bin/check.php <satis.json> <published-dir> <built-dir>');

$config = json_decode(file_get_contents($configFile), true, flags: JSON_THROW_ON_ERROR);
$patterns = $config['available-package-patterns'] ?? fail('satis.json has no available-package-patterns');
$minimumStability = BasePackage::STABILITIES[$config['minimum-stability'] ?? 'dev'];
$parser = new VersionParser();
$blacklist = array_map(static fn (string $c) => $parser->parseConstraints($c), $config['blacklist'] ?? []);
$blacklisted = static fn (string $name, string $normalized): bool => isset($blacklist[$name])
    && $blacklist[$name]->matches(new Constraint('==', $normalized));

$errors = [];
$changes = [];

// The package each listed repository publishes: owner/repo => vendor/name.
$packageOf = [];
foreach ($config['repositories'] as $listed) {
    $repository = strtolower(preg_replace(['#^https://github\.com/#', '#(\.git)?/?$#'], '', $listed['url']));
    $name = $listed['name'] ?? null;
    if ($name === null) {
        $errors[] = "$repository has no \"name\" in satis.json: every repository names the one package it publishes";
        continue;
    }
    if (! array_filter($patterns, static fn (string $p): bool => fnmatch($p, $name))) {
        $errors[] = "$repository is to publish $name, outside ".implode(', ', $patterns).' (available-package-patterns)';
    }
    if ($other = array_search($name, $packageOf, true)) {
        $errors[] = "$name is named for both $other and $repository";
    }
    $packageOf[$repository] = $name;
}
if ($errors !== []) {
    report([], $errors);
}

$published = read_registry($publishedDir);
$built = read_registry($builtDir);

// Each repository publishes its own package and nothing else.
foreach ($built as $name => $versions) {
    foreach (array_unique(array_map(static fn (array $v): string => repository($v), $versions)) as $repository) {
        if (! isset($packageOf[$repository])) {
            $errors[] = "$name comes from $repository, which satis.json does not list";
        } elseif ($packageOf[$repository] !== $name) {
            $errors[] = "$repository publishes only {$packageOf[$repository]}, not $name (the name in its composer.json)";
        }
    }
}

// Every release tag of every listed repository is in the build, at its commit, or blacklisted.
foreach ($packageOf as $repository => $name) {
    $seen = [];
    foreach (tags($repository, $token) as $tag => $sha) {
        try {
            $normalized = $parser->normalize($tag);
        } catch (UnexpectedValueException) {
            continue; // not a version: Composer ignores it too
        }
        if (BasePackage::STABILITIES[VersionParser::parseStability($normalized)] > $minimumStability) {
            continue; // below minimum-stability: not published, on purpose
        }
        if (isset($seen[$normalized])) {
            if ($seen[$normalized][1] !== $sha) {
                $errors[] = "$repository: tags {$seen[$normalized][0]} and $tag are both version $normalized, at different commits";
            }
            continue;
        }
        $seen[$normalized] = [$tag, $sha];

        $version = $built[$name][$normalized] ?? null;
        if ($version === null || repository($version) !== $repository) {
            if (! $blacklisted($name, $normalized)) {
                $errors[] = "$repository $tag is not in the build as $name: Satis skipped it (the build log says why), or blacklist it in satis.json";
            }
        } elseif ($version['dist']['reference'] !== $sha) {
            $errors[] = "$repository $tag is at $sha, the build has {$version['dist']['reference']}";
        }
    }
}

// Nothing published changes its commit or disappears unless it is withdrawn.
foreach ($published as $name => $versions) {
    foreach ($versions as $normalized => $old) {
        $new = $built[$name][$normalized] ?? null;
        if ($new === null) {
            if ($blacklisted($name, $normalized)) {
                $changes[] = "withdrawn $name {$old['version']}";
            } else {
                $errors[] = "$name {$old['version']} would disappear; blacklist it in satis.json to withdraw it";
            }
        } elseif ($new['dist']['reference'] !== $old['dist']['reference']) {
            $errors[] = "$name {$old['version']} would move from {$old['dist']['reference']} to {$new['dist']['reference']}; versions are immutable";
        }
    }
    $from = repository(reset($versions));
    if (isset($built[$name]) && ($to = repository(reset($built[$name]))) !== $from) {
        $changes[] = "moved $name from $from to $to";
    }
}

foreach ($built as $name => $versions) {
    foreach ($versions as $normalized => $new) {
        if (! isset($published[$name][$normalized])) {
            $changes[] = "published $name {$new['version']} from ".repository($new).'@'.$new['dist']['reference'];
        }
    }
}

report($changes, $errors);

/** Prints the changes and the reasons for refusal, and exits. */
function report(array $changes, array $errors): never
{
    foreach ($changes as $line) {
        echo "$line\n";
    }
    foreach ($errors as $line) {
        echo getenv('GITHUB_ACTIONS') ? "::error::$line\n" : "refused: $line\n";
    }

    exit($errors === [] ? 0 : 1);
}

/** @return array<string, array<string, array<string, mixed>>> name => normalized version => version, tagged versions only */
function read_registry(string $dir): array
{
    $packages = [];
    foreach (glob("$dir/p2/*/*.json") as $file) {
        if (str_ends_with($file, '~dev.json')) {
            continue;
        }
        $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['packages'] as $name => $versions) {
            if (($data['minified'] ?? null) === 'composer/2.0') {
                $versions = MetadataMinifier::expand($versions);
            }
            foreach ($versions as $version) {
                $packages[$name][$version['version_normalized']] = $version;
            }
        }
    }

    return $packages;
}

/** owner/repo of the version's source, however the URL is spelled */
function repository(array $version): string
{
    return strtolower(preg_replace(['#^(?:https://github\.com/|git@github\.com:)#', '#\.git$#'], '', $version['source']['url']));
}

/** @return array<string, string> tag => commit, annotated tags peeled */
function tags(string $repository, string $token): array
{
    $auth = base64_encode("x-access-token:$token");
    // Outside any working tree, whose own credentials git would send instead.
    exec(sprintf(
        'git -C %s -c http.extraHeader=%s ls-remote --tags %s 2>&1',
        escapeshellarg(sys_get_temp_dir()),
        escapeshellarg("Authorization: Basic $auth"),
        escapeshellarg("https://github.com/$repository.git"),
    ), $lines, $status);
    $status === 0 || fail("cannot list the tags of $repository: ".implode(' ', $lines));

    $tags = [];
    foreach ($lines as $line) {
        [$sha, $ref] = explode("\t", $line);
        $tag = substr($ref, strlen('refs/tags/'));
        $peeled = str_ends_with($tag, '^{}');
        $tag = $peeled ? substr($tag, 0, -3) : $tag;
        if ($peeled || ! isset($tags[$tag])) {
            $tags[$tag] = $sha;
        }
    }

    return $tags;
}

function fail(string $message): never
{
    fwrite(STDERR, "error: $message\n");
    exit(2);
}
