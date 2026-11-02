<?php

declare(strict_types=1);

/*
 * Adds one version of one package to the registry.
 *
 *   php bin/publish.php <source-repo> <version> <commit-sha> <path/to/composer.json>
 *
 * The package name comes from the composer.json. A name belongs to the repository that first
 * published it: a version of it from any other repository is refused. A version already published is
 * accepted again only for the same commit: a release is never rewritten.
 * Writes p2/<name>.json (Composer 2 metadata, one file per package) and refreshes packages.json.
 */

chdir(dirname(__DIR__));
if (($argv[1] ?? null) === '--index-only') {
    write_index();
    exit(0);
}

[, $repo, $version, $sha, $manifestPath] = $argv + [null, null, null, null, null];
if ($manifestPath === null || ! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version) || ! preg_match('/^[0-9a-f]{40}$/', $sha)) {
    fail('usage: php bin/publish.php <owner/repo> <X.Y.Z> <sha> <composer.json>');
}

chdir(dirname(__DIR__));
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

$file = "p2/$name.json";
$versions = is_file($file) ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)['packages'][$name] : [];
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

@mkdir(dirname($file), 0777, true);
write($file, ['packages' => [$name => array_values($versions)]]);

write_index();

echo "published $name $version from $repo@$sha\n";

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
