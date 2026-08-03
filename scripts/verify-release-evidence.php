#!/usr/bin/env php
<?php

declare(strict_types=1);

/** @var int<1, max> $argc */
/** @var list<string> $argv */

if ($argc !== 7) {
    fwrite(STDERR, "usage: verify-release-evidence.php REGISTRY REPORTS EVENT SHA RUN_ID INDEX\n");
    exit(2);
}
[, $registryPath, $reportsRoot, $event, $expectedSha, $expectedRun, $indexPath] = $argv;

function readJson(string $path): array
{
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (! is_array($data)) {
        throw new RuntimeException("missing or malformed JSON: $path");
    }
    return $data;
}
function validReport(string $format, string $schema, string $path): bool
{
    if (! is_file($path) || filesize($path) === 0) {
        return false;
    }
    $raw = (string) file_get_contents($path);
    if (stripos($raw, 'placeholder') !== false) {
        return false;
    }
    if (in_array($format, array( 'json', 'sarif', 'spdx-json' ), true)) {
        $doc = json_decode($raw, true);
        if (! is_array($doc)) {
            return false;
        }
        if ($format === 'sarif') {
            return ( $doc['version'] ?? '' ) === '2.1.0' && isset($doc['runs']) && is_array($doc['runs']);
        }
        if ($format === 'spdx-json') {
            return str_starts_with((string) ( $doc['spdxVersion'] ?? '' ), 'SPDX-') && isset($doc['packages']);
        }
        return match ($schema) {
            'result-v1' => ( $doc['schema_version'] ?? null ) === 1 && ( $doc['result'] ?? '' ) === 'success',
            'eslint-v1' => array_is_list($doc),
            'stylelint-v1' => array_is_list($doc),
            'trufflehog-v1' => array_is_list($doc),
            'trivy-v1' => isset($doc['SchemaVersion'], $doc['Results']) && is_array($doc['Results']),
            'dependency-review-v1' => array_is_list($doc),
            'lighthouse-v1' => isset($doc['lighthouseVersion'], $doc['categories']) && is_array($doc['categories']),
            'license-policy-v1' => ( $doc['schema_version'] ?? null ) === 1 && ( $doc['result'] ?? '' ) === 'success' && isset($doc['dependencies']),
            'scan-metadata-v1' => ( $doc['schema_version'] ?? null ) === 1 && ( $doc['result'] ?? '' ) === 'success' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($doc['scan_utc'] ?? '')) === 1 && !in_array('', $doc['tool_versions'] ?? [], true) && isset($doc['advisory_sources']),
            'composer-audit-v1' => isset($doc['advisories']) && is_array($doc['advisories']),
            'npm-audit-v2' => ( $doc['auditReportVersion'] ?? 0 ) >= 2 && isset($doc['metadata']),
            default => false,
        };
    }
    if (in_array($format, array( 'junit', 'clover', 'checkstyle' ), true)) {
        if (! function_exists('simplexml_load_file')) {
            return preg_match('/<(testsuite|testsuites|coverage|checkstyle)[ >]/', (string) file_get_contents($path)) === 1;
        }
        $xml      = @simplexml_load_file($path);
        $expected = array(
            'junit'      => array( 'testsuite', 'testsuites' ),
            'clover'     => array( 'coverage' ),
            'checkstyle' => array( 'checkstyle' ),
        )[ $format ];
        return $xml !== false && in_array($xml->getName(), $expected, true);
    }
    if ($format === 'archive') {
        $header = (string) file_get_contents($path, false, null, 0, 2);
        try {
            $archive = new PharData($path);
            return str_ends_with($path, '.tar.gz') && $header === "\x1f\x8b" && isset($archive['RELEASE-INFO.txt'], $archive['RELEASE-MANIFEST.sha256']);
        } catch (Throwable) {
            return false;
        }
    }
    if ($format === 'sha256') {
        return preg_match('/^[a-f0-9]{64}  [^\r\n]+\r?\n$/', (string) file_get_contents($path)) === 1;
    }
    if ($format === 'text') {
        return $schema === 'command-log-v1' && trim($raw) !== '';
    }
    return false;
}

function verifyReleaseBundle(string $directory, string $expectedSha): void
{
    $archive = $directory . '/release.tar.gz';
    $sidecar = $directory . '/release.tar.gz.sha256';
    $verification = $directory . '/release-verification.json';
    $reproducibility = $directory . '/reproducibility.json';
    $archiveHash = hash_file('sha256', $archive);
    if ($archiveHash === false) {
        throw new RuntimeException('cannot hash release archive');
    }
    if (! preg_match('/^([a-f0-9]{64})  release\.tar\.gz\r?\n$/', (string) file_get_contents($sidecar), $checksum) || ! hash_equals($archiveHash, $checksum[1])) {
        throw new RuntimeException('release sidecar does not authenticate the archive');
    }

    $freshReport = tempnam(sys_get_temp_dir(), 'lel-release-verify-');
    if ($freshReport === false) {
        throw new RuntimeException('cannot create artifact verification report');
    }
    try {
        $output = array();
        $status = 1;
        $command = 'bash ' . escapeshellarg(__DIR__ . '/verify-release-artifact.sh') . ' ' . escapeshellarg($archive) . ' ' . escapeshellarg($expectedSha) . ' ' . escapeshellarg($freshReport) . ' ' . escapeshellarg($sidecar) . ' 2>&1';
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('release archive verification failed: ' . implode("\n", $output));
        }
        $fresh = readJson($freshReport);
    } finally {
        @unlink($freshReport);
    }

    $recorded = readJson($verification);
    if ($recorded !== $fresh || ($recorded['source_sha'] ?? '') !== $expectedSha || ! hash_equals($archiveHash, (string) ($recorded['artifact_sha256'] ?? ''))) {
        throw new RuntimeException('artifact verification report does not match fresh archive verification');
    }
    $repro = readJson($reproducibility);
    if (($repro['schema_version'] ?? null) !== 1 || ($repro['result'] ?? '') !== 'success' || ($repro['source_sha'] ?? '') !== $expectedSha || ($repro['builds'] ?? null) !== 2 || ($repro['source_date_epoch'] ?? null) !== ($recorded['source_date_epoch'] ?? null) || ! hash_equals($archiveHash, (string) ($repro['artifact_sha256'] ?? ''))) {
        throw new RuntimeException('reproducibility report does not authenticate the verified archive');
    }
}

try {
    if (! preg_match('/^[a-f0-9]{40}$/', $expectedSha) || ! preg_match('/^[0-9]+$/', $expectedRun)) {
        throw new RuntimeException('expected commit or workflow run id is malformed');
    }
    $registry = readJson($registryPath);
    if (( $registry['schema_version'] ?? null ) !== 2 || ! isset($registry['required']) || ! is_array($registry['required'])) {
        throw new RuntimeException('registry schema_version 2 and required array are mandatory');
    }
    $results = readJson($reportsRoot . '/ci-job-results.json');
    if (( $results['schema_version'] ?? null ) !== 1 || ( $results['commit_sha'] ?? '' ) !== $expectedSha || (string) ( $results['workflow_run_id'] ?? '' ) !== $expectedRun || ! is_array($results['jobs'] ?? null)) {
        throw new RuntimeException('job-results schema, commit, or run id mismatch');
    }

    $seenJobs       = $seenArtifacts = array();
    $index          = array();
    $metadataCounts = array();
    $iterator       = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($reportsRoot . '/upstream', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === 'evidence.json') {
            $candidate               = readJson($file->getPathname());
            $name                    = (string) ( $candidate['artifact'] ?? '' );
            $metadataCounts[ $name ] = ( $metadataCounts[ $name ] ?? 0 ) + 1;
        }
    }
    foreach ($registry['required'] as $row) {
        foreach (array( 'job', 'artifact', 'events', 'reports' ) as $key) {
            if (! isset($row[ $key ])) {
                throw new RuntimeException("registry row missing $key");
            }
        }
        if (isset($seenJobs[ $row['job'] ]) || isset($seenArtifacts[ $row['artifact'] ])) {
            throw new RuntimeException('duplicate job or artifact in registry');
        }
        $seenJobs[ $row['job'] ] = $seenArtifacts[ $row['artifact'] ] = true;
        if (! in_array($event, $row['events'], true)) {
            continue;
        }
        if (( $results['jobs'][ $row['job'] ] ?? null ) !== 'success') {
            throw new RuntimeException("required job is not successful: {$row['job']}");
        }
        if (( $metadataCounts[ $row['artifact'] ] ?? 0 ) !== 1) {
            throw new RuntimeException("artifact is missing or duplicated: {$row['artifact']}");
        }

        $dir           = $reportsRoot . '/upstream/' . $row['artifact'];
        $metadataFiles = glob($dir . '/evidence.json') ?: array();
        if (count($metadataFiles) !== 1) {
            throw new RuntimeException("artifact has missing or duplicate evidence metadata: {$row['artifact']}");
        }
        $meta = readJson($metadataFiles[0]);
        if (( $meta['schema_version'] ?? null ) !== 1 || ( $meta['job'] ?? '' ) !== $row['job'] || ( $meta['artifact'] ?? '' ) !== $row['artifact'] || ( $meta['commit_sha'] ?? '' ) !== $expectedSha || (string) ( $meta['workflow_run_id'] ?? '' ) !== $expectedRun || ( $meta['result'] ?? '' ) !== 'success') {
            throw new RuntimeException("artifact provenance/result mismatch: {$row['artifact']}");
        }
        if (! is_array($meta['tool_versions'] ?? null) || $meta['tool_versions'] === array() || in_array('', $meta['tool_versions'], true)) {
            throw new RuntimeException("missing tool versions: {$row['artifact']}");
        }
        if (! is_array($meta['action_shas'] ?? null) || $meta['action_shas'] === array() || array_filter($meta['action_shas'], fn($sha): bool => ! is_string($sha) || preg_match('/^[a-f0-9]{40}$/', $sha) !== 1)) {
            throw new RuntimeException("missing or unpinned action identities: {$row['artifact']}");
        }
        if (! is_array($meta['container_images'] ?? null)) {
            throw new RuntimeException("missing container image identities: {$row['artifact']}");
        }
        $actual = array();
        foreach ($meta['reports'] ?? array() as $report) {
            $path = $report['path'] ?? '';
            if ($path === '' || isset($actual[ $path ]) || str_contains($path, '..') || str_starts_with($path, '/')) {
                throw new RuntimeException("duplicate or unsafe report path: {$row['artifact']}");
            }
            $actual[ $path ] = $report;
        }
        foreach ($row['reports'] as $required) {
            $path   = $required['path'] ?? '';
            $format = $required['format'] ?? '';
            $schema = $required['schema'] ?? '';
            $record = $actual[ $path ] ?? null;
            $full   = $dir . '/' . $path;
            if (! is_array($record) || $schema === '' || ( $record['format'] ?? '' ) !== $format || ( $record['result'] ?? '' ) !== 'success' || ! is_bool($record['redacted'] ?? null) || ! validReport($format, $schema, $full)) {
                throw new RuntimeException("missing, malformed, or failed substantive report: {$row['artifact']}/$path");
            }
            $sha = hash_file('sha256', $full);
            if (! hash_equals($sha, (string) ( $record['sha256'] ?? '' ))) {
                throw new RuntimeException("report checksum mismatch: {$row['artifact']}/$path");
            }
            $index[] = array(
                'path'             => 'artifacts/' . $row['artifact'] . '/' . $path,
                'sha256'           => $sha,
                'producer_job'     => $row['job'],
                'tool_versions'    => $meta['tool_versions'],
                'action_shas'      => $meta['action_shas'],
                'container_images' => $meta['container_images'],
                'format'           => $format,
                'schema'           => $schema,
                'result'           => 'success',
                'redacted'         => $record['redacted'],
            );
        }
        if ($row['job'] === 'release-artifact') {
            verifyReleaseBundle($dir, $expectedSha);
        }
    }
    usort($index, fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
    $out = array(
        'schema_version'  => 1,
        'commit_sha'      => $expectedSha,
        'workflow_run_id' => $expectedRun,
        'event'           => $event,
        'result'          => 'success',
        'reports'         => $index,
    );
    if (! is_dir(dirname($indexPath))) {
        mkdir(dirname($indexPath), 0775, true);
    }
    file_put_contents($indexPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo 'Release evidence verified: ' . count($index) . " substantive reports.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
