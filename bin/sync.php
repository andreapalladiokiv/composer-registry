<?php

declare(strict_types=1);

/*
 * Brings the registry up to date with the release tags of the given repositories.
 *
 *   GH_TOKEN=… php bin/sync.php owner/repo [owner/repo …]
 *
 * For every tag `vX.Y.Z`, or `<package>/vX.Y.Z` in a monorepo, whose commit the registry does not
 * already hold from that repository, it reads composer.json at the tag and publishes it through
 * bin/publish.php. Tags already published cost nothing but the one tag listing per repository.
 *
 * A tag the registry refuses (a moved tag, a name that belongs to another repository, a tag without a
 * composer.json) is reported and skipped; the rest of the sync goes on. The exit code is 1 when anything
 * was refused, so a run that skipped something is not green.
 */

ini_set('memory_limit', '-1');
chdir(dirname(__DIR__));

$token = getenv('GH_TOKEN') ?: fail('GH_TOKEN is not set');
$repositories = array_slice($argv, 1) ?: fail('usage: GH_TOKEN=… php bin/sync.php owner/repo [owner/repo …]');

// What the registry already holds, as "owner/repo sha".
$held = [];
foreach (glob('p2/*/*.json') as $file) {
    $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    foreach ($data['packages'] as $versions) {
        $url = null;
        foreach ($versions as $diff) {
            $url = $diff['source']['url'] ?? $url;
            if (isset($diff['dist']['reference'])) {
                $held[preg_replace('#^https://github\.com/(.+)\.git$#', '$1', $url).' '.$diff['dist']['reference']] = true;
            }
        }
    }
}

$published = [];
$refused = [];

foreach ($repositories as $repository) {
    foreach (release_tags($repository, $token) as $tag => $sha) {
        if (isset($held["$repository $sha"])) {
            continue;
        }

        $manifest = github("repos/$repository/contents/composer.json?ref=".rawurlencode($tag), $token);
        if ($manifest === null) {
            $refused[] = "$repository $tag: no composer.json at the tag";
            continue;
        }
        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, $manifest);

        $version = preg_replace('#^(?:.+/)?v#', '', $tag);
        exec(sprintf('php bin/publish.php %s %s %s %s 2>&1', escapeshellarg($repository), escapeshellarg($version), $sha, escapeshellarg($path)), $output, $status);
        unlink($path);
        $line = trim((string) end($output));
        $output = [];

        if ($status === 0) {
            $published[] = $line;
            $held["$repository $sha"] = true;
        } else {
            $refused[] = "$repository $tag: ".preg_replace('/^error: /', '', $line);
        }
    }
}

foreach ($published as $line) {
    echo "$line\n";
}
foreach ($refused as $line) {
    echo getenv('GITHUB_ACTIONS') ? "::warning::$line\n" : "refused: $line\n";
}
echo sprintf("\n%d published, %d refused, %d repositories\n", count($published), count($refused), count($repositories));

exit($refused === [] ? 0 : 1);

/** @return array<string, string> tag => commit, for release tags only, annotated tags peeled */
function release_tags(string $repository, string $token): array
{
    $auth = base64_encode("x-access-token:$token");
    // Outside the registry's working tree: actions/checkout leaves its own token there as an extraheader
    // for github.com, which would be sent instead of this one.
    exec(sprintf('git -C %s -c http.extraHeader=%s ls-remote --tags %s 2>&1', escapeshellarg(sys_get_temp_dir()), escapeshellarg("Authorization: Basic $auth"), escapeshellarg("https://github.com/$repository.git")), $lines, $status);
    if ($status !== 0) {
        fail("cannot list the tags of $repository: ".implode(' ', $lines));
    }

    $tags = [];
    foreach ($lines as $line) {
        [$sha, $ref] = explode("\t", $line);
        $tag = substr($ref, strlen('refs/tags/'));
        $peeled = str_ends_with($tag, '^{}');
        $tag = $peeled ? substr($tag, 0, -3) : $tag;
        if (! preg_match('#^(?:[a-z0-9._-]+/)?v\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$#', $tag)) {
            continue;
        }
        if ($peeled || ! isset($tags[$tag])) {
            $tags[$tag] = $sha;
        }
    }
    uksort($tags, static fn (string $a, string $b): int => version_compare(preg_replace('#^.*v#', '', $a), preg_replace('#^.*v#', '', $b)));

    return $tags;
}

/** The raw body of a GitHub API GET, or null on 404. */
function github(string $path, string $token): ?string
{
    $context = stream_context_create(['http' => [
        'header' => "Authorization: Bearer $token\r\nAccept: application/vnd.github.raw\r\nUser-Agent: composer-registry\r\n",
        'ignore_errors' => true,
    ]]);
    $body = file_get_contents("https://api.github.com/$path", false, $context);
    $status = (int) explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1];
    if ($status === 404) {
        return null;
    }
    if ($status !== 200) {
        fail("GET $path answered $status");
    }

    return $body;
}

function fail(string $message): never
{
    fwrite(STDERR, "error: $message\n");
    exit(2);
}
