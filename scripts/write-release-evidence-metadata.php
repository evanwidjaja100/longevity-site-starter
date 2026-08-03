#!/usr/bin/env php
<?php

declare(strict_types=1);

/** @var int<1, max> $argc */
/** @var list<string> $argv */

if ($argc < 6) {
    fwrite(STDERR, "usage: write-release-evidence-metadata.php JOB ARTIFACT DIRECTORY RESULT FORMAT:PATH...\n");
    exit(2);
}
[, $job, $artifact, $directory, $result] = array_slice($argv, 0, 5);
$commit                                  = getenv('GITHUB_SHA') ?: '';
$runId                                   = getenv('GITHUB_RUN_ID') ?: '';
if (! preg_match('/^[a-f0-9]{40}$/', $commit) || ! preg_match('/^[0-9]+$/', $runId)) {
    fwrite(STDERR, "GITHUB_SHA and GITHUB_RUN_ID are required and malformed values are rejected.\n");
    exit(2);
}
if (! is_dir($directory)) {
    mkdir($directory, 0775, true);
}

$reports = array();
foreach (array_slice($argv, 5) as $spec) {
    [$format, $path] = array_pad(explode(':', $spec, 2), 2, '');
    if (! preg_match('/^[a-z0-9-]+$/', $format) || $path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
        fwrite(STDERR, "Invalid report specification: $spec\n");
        exit(2);
    }
    $full = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if ($result === 'success' && ( ! is_file($full) || filesize($full) === 0 )) {
        fwrite(STDERR, "Successful job is missing required report: $full\n");
        exit(1);
    }
    if (is_file($full)) {
        $reports[] = array(
            'path'     => $path,
            'format'   => $format,
            'sha256'   => hash_file('sha256', $full),
            'result'   => $result,
            'redacted' => false,
        );
    }
}

$tools   = array( 'php' => PHP_VERSION );
$discard = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
foreach (
    array(
    'git'      => 'git --version',
    'composer' => 'composer --version',
    'node'     => 'node --version',
    'npm'      => 'npm --version',
    ) as $name => $command
) {
    $value = trim((string) shell_exec($command . ' ' . $discard));
    if ($value !== '') {
        $tools[ $name ] = strtok($value, "\r\n");
    }
}
foreach (
    array(
    'tar'   => 'tar --version',
    'gzip'  => 'gzip --version',
    'psalm' => 'php vendor/bin/psalm --version',
    ) as $name => $command
) {
    $value = trim((string) shell_exec($command . ' ' . $discard));
    if ($value !== '') {
        $tools[ $name ] = strtok($value, "\r\n");
    }
}
$actions = array();
foreach (preg_split('/\s+/', trim((string) getenv('EVIDENCE_ACTION_SHAS'))) ?: array() as $identity) {
    if ($identity === '') {
        continue;
    }
    if (! preg_match('/^([^@\s]+)@([a-f0-9]{40})$/', $identity, $match)) {
        fwrite(STDERR, "Invalid per-job action identity: $identity\n");
        exit(2);
    }
    $actions[ $match[1] ] = $match[2];
}
if ($actions === array()) {
    fwrite(STDERR, "EVIDENCE_ACTION_SHAS must identify the actions actually used by this job.\n");
    exit(2);
}
$images = array_values(array_unique(array_filter(preg_split('/\s+/', trim((string) getenv('EVIDENCE_CONTAINER_IMAGES'))) ?: array())));
foreach ($images as $image) {
    if (! preg_match('/^[a-z0-9._\/:@-]+$/i', $image)) {
        fwrite(STDERR, "Invalid per-job container identity: $image\n");
        exit(2);
    }
}
$doc    = array(
    'schema_version'   => 1,
    'job'              => $job,
    'artifact'         => $artifact,
    'commit_sha'       => $commit,
    'workflow_run_id'  => $runId,
    'result'           => $result,
    'tool_versions'    => $tools,
    'action_shas'      => $actions,
    'container_images' => $images,
    'reports'          => $reports,
);
file_put_contents($directory . DIRECTORY_SEPARATOR . 'evidence.json', json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
