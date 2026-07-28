<?php
/**
 * Manifest regeneration entry point.
 *
 * Delegates to the canonical scripts/regenerate-manifest.sh so only one
 * implementation defines the release file set and hashing rules. Exits
 * nonzero without writing MANIFEST.sha256 on any failure.
 */

declare(strict_types=1);

$root      = dirname(__DIR__);
$canonical = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'regenerate-manifest.sh';

if (! is_file($canonical)) {
    fwrite(STDERR, "ERROR: canonical manifest script is missing.\n");
    exit(1);
}

exec('git -C ' . escapeshellarg($root) . ' rev-parse --is-inside-work-tree 2>&1', $probe, $probeStatus);
if ($probeStatus !== 0) {
    fwrite(STDERR, "ERROR: not a Git checkout; refusing to write a manifest.\n");
    exit(1);
}

$command = 'bash ' . escapeshellarg($canonical) . ' 2>&1';
exec($command, $output, $status);
foreach ($output as $line) {
    echo $line, "\n";
}
if ($status !== 0) {
    fwrite(STDERR, "ERROR: canonical manifest regeneration failed (exit {$status}).\n");
    exit($status);
}
exit(0);
