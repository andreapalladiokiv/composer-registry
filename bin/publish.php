<?php

declare(strict_types=1);

/*
 * Adds one version of one package to the registry.
 *
 *   php bin/publish.php <source-repo> <version> <commit-sha> <path/to/composer.json>
 *   php bin/publish.php --withdraw <vendor/name> <X.Y.Z>   # take a version out of the registry
 *   php bin/publish.php --abandon <vendor/name> [<vendor/replacement>|--undo]
 *   php bin/publish.php --transfer <vendor/name> <owner/repo>   # the source repository moved
 *   php bin/publish.php --index-only     # rebuild packages.json from p2/, e.g. after a rebase conflict
 *
 * The package name comes from the composer.json. A name belongs to the repository that first
 * published it: a version of it from any other repository is refused. A version already published is
 * accepted again only for the same commit: a release is never rewritten.
 * Writes p2/<name>.json (Composer 2 metadata, one file per package) and refreshes packages.json.
 *
 * A p2/ file is in Composer's minified form ("minified": "composer/2.0", newest version first): the
 * first version is whole, every one after it holds only the keys that differ from the one before, and
 * "__unset" removes a key. It is written compact, without indentation. Because each version depends on
 * the ones before it, a p2/ file is changed through this script, never by hand; the maintain workflow
 * runs these commands.
 */

ini_set('memory_limit', '-1');
chdir(dirname(__DIR__));

if (($argv[1] ?? null) === '--index-only') {
    write_index();
    exit(0);
}

if (($argv[1] ?? null) === '--abandon') {
    [, , $name, $replacement] = $argv + [null, null, null, null];
    $name !== null || fail('usage: php bin/publish.php --abandon <vendor/name> [<vendor/replacement>|--undo]');
    $versions = read_package($name) ?: fail("$name is not published");
    foreach ($versions as &$v) {
        if ($replacement === '--undo') {
            unset($v['abandoned']);
        } else {
            $v['abandoned'] = $replacement ?? true;
        }
    }
    unset($v);
    write_package($name, $versions);
    echo ($replacement === '--undo' ? "$name is no longer abandoned" : "$name is abandoned".($replacement ? " for $replacement" : '')), "\n";
    exit(0);
}

if (($argv[1] ?? null) === '--transfer') {
    [, , $name, $repo] = $argv + [null, null, null, null];
    $name !== null && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) $repo) || fail('usage: php bin/publish.php --transfer <vendor/name> <owner/repo>');
    $versions = read_package($name) ?: fail("$name is not published");
    foreach ($versions as &$v) {
        $sha = $v['dist']['reference'];
        $v['source'] = ['type' => 'git', 'url' => "https://github.com/$repo.git", 'reference' => $sha];
        $v['dist'] = ['type' => 'zip', 'url' => "https://api.github.com/repos/$repo/zipball/$sha", 'reference' => $sha];
    }
    unset($v);
    write_package($name, $versions);
    echo "$name now belongs to $repo\n";
    exit(0);
}

if (($argv[1] ?? null) === '--withdraw') {
    [, , $name, $version] = $argv + [null, null, null, null];
    $name !== null && $version !== null || fail('usage: php bin/publish.php --withdraw <vendor/name> <X.Y.Z>');
    $versions = read_package($name);
    $left = array_values(array_filter($versions, static fn (array $v): bool => $v['version'] !== $version));
    count($left) < count($versions) || fail("$name $version is not published");
    $left === [] ? unlink(package_file($name)) : write_package($name, $left);
    write_index();
    echo "withdrew $name $version\n";
    exit(0);
}

[, $repo, $version, $sha, $manifestPath] = $argv + [null, null, null, null, null];
if ($manifestPath === null || ! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version) || ! preg_match('/^[0-9a-f]{40}$/', $sha)) {
    fail('usage: php bin/publish.php <owner/repo> <X.Y.Z> <sha> <composer.json>');
}

$manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$name = $manifest['name'] ?? fail('composer.json has no name');

foreach (['require-dev', 'autoload-dev', 'config', 'scripts', 'minimum-stability', 'prefer-stable', 'repositories', 'version'] as $key) {
    unset($manifest[$key]);
}
$entry = $manifest + [
    'version' => $version,
    'version_normalized' => $version.'.0',
    'source' => ['type' => 'git', 'url' => "https://github.com/$repo.git", 'reference' => $sha],
    'dist' => ['type' => 'zip', 'url' => "https://api.github.com/repos/$repo/zipball/$sha", 'reference' => $sha],
];

$versions = read_package($name);
foreach ($versions as $i => $existing) {
    $owner = preg_replace('#^https://github\.com/(.+)\.git$#', '$1', $existing['source']['url'] ?? '');
    if ($owner !== $repo) {
        fail("$name is published from $owner, not $repo");
    }
    if ($existing['version'] === $version) {
        if ($existing['dist']['reference'] !== $sha) {
            fail("$name $version is already published from {$existing['dist']['reference']}; publish a new version instead");
        }
        $entry['time'] = $existing['time'] ?? null;
        unset($versions[$i]);
    }
}
$entry['time'] ??= gmdate('c');
$versions[] = array_filter($entry, static fn ($v) => $v !== null);
usort($versions, static fn ($a, $b) => version_compare($b['version'], $a['version']));

write_package($name, array_values($versions));

write_index();

echo "published $name $version from $repo@$sha\n";

function package_file(string $name): string
{
    return "p2/$name.json";
}

/** @return list<array<string, mixed>> every published version, expanded, newest first */
function read_package(string $name): array
{
    $file = package_file($name);
    if (! is_file($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $versions = $data['packages'][$name] ?? [];

    return ($data['minified'] ?? null) === 'composer/2.0' ? expand($versions) : $versions;
}

/** @param list<array<string, mixed>> $versions expanded, newest first */
function write_package(string $name, array $versions): void
{
    $file = package_file($name);
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, json_encode(
        ['packages' => [$name => minify($versions)], 'minified' => 'composer/2.0'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    )."\n");
}

/** Composer\MetadataMinifier\MetadataMinifier::minify */
function minify(array $versions): array
{
    $minified = [];
    $last = null;
    foreach ($versions as $version) {
        if ($last === null) {
            $minified[] = $version;
        } else {
            $diff = [];
            foreach ($version as $key => $value) {
                if (! array_key_exists($key, $last) || $last[$key] !== $value) {
                    $diff[$key] = $value;
                }
            }
            foreach (array_keys($last) as $key) {
                if (! array_key_exists($key, $version)) {
                    $diff[$key] = '__unset';
                }
            }
            $minified[] = $diff;
        }
        $last = $version;
    }

    return $minified;
}

/** Composer\MetadataMinifier\MetadataMinifier::expand */
function expand(array $versions): array
{
    $expanded = [];
    $current = null;
    foreach ($versions as $diff) {
        if ($current === null) {
            $current = $diff;
        } else {
            foreach ($diff as $key => $value) {
                if ($value === '__unset') {
                    unset($current[$key]);
                } else {
                    $current[$key] = $value;
                }
            }
        }
        $expanded[] = $current;
    }

    return $expanded;
}

function write_index(): void
{
    $available = array_map(static fn ($f) => substr($f, 3, -5), glob('p2/*/*.json'));
    sort($available);
    write('packages.json', ['metadata-url' => 'https://api.github.com/repos/andreapalladiokiv/composer-registry/contents/p2/%package%.json?ref=main', 'available-packages' => $available]);
}

function write(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "error: $message\n");
    exit(1);
}
