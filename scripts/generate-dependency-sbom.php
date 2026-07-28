#!/usr/bin/env php
<?php

declare(strict_types=1);

$output        = $argv[1] ?? 'reports/supply-chain/sbom.spdx.json';
$licenseOutput = $argv[2] ?? 'reports/supply-chain/licenses.json';
$scanOutput    = $argv[3] ?? 'reports/supply-chain/scan-metadata.json';
$composer      = json_decode((string) file_get_contents('composer.lock'), true, flags: JSON_THROW_ON_ERROR);
$npm           = json_decode((string) file_get_contents('package-lock.json'), true, flags: JSON_THROW_ON_ERROR);
$packages      = array();

$add = static function (string $ecosystem, string $name, string $version, array $licenses, string $scope) use (&$packages): void {
    $packages[ $ecosystem . ':' . $name . '@' . $version ] = compact('ecosystem', 'name', 'version', 'licenses', 'scope');
};
foreach ($composer['packages'] ?? array() as $p) {
    $add('composer', $p['name'], $p['version'], $p['license'] ?? array(), 'runtime');
}
foreach ($composer['packages-dev'] ?? array() as $p) {
    $add('composer', $p['name'], $p['version'], $p['license'] ?? array(), 'development');
}
foreach ($npm['packages'] ?? array() as $path => $p) {
    if ($path === '' || ! isset($p['version'])) {
        continue;
    }
    $name        = preg_replace('#^.*node_modules/#', '', $path);
    $licenses    = isset($p['license']) ? array( (string) $p['license'] ) : array();
    $packageJson = $path . '/package.json';
    if ($licenses === array() && is_file($packageJson)) {
        $installed = json_decode((string) file_get_contents($packageJson), true);
        if (isset($installed['license'])) {
            $licenses = array( (string) $installed['license'] );
        } elseif (isset($installed['licenses']) && is_array($installed['licenses'])) {
            $licenses = array_values(array_filter(array_map(static fn($entry): string => is_array($entry) ? (string) ( $entry['type'] ?? '' ) : (string) $entry, $installed['licenses'])));
        }
    }
    $licenses = array_map(static fn(string $license): string => array( 'BSD' => 'BSD-3-Clause' )[ $license ] ?? $license, $licenses);
    $add('npm', $name, (string) $p['version'], $licenses, ! empty($p['dev']) ? 'development' : 'runtime');
}
ksort($packages, SORT_STRING);

$runtimeAllowed = array( '0BSD', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'BlueOak-1.0.0', 'CC0-1.0', 'ISC', 'MIT', 'MIT-0', 'Python-2.0', '(MIT OR CC0-1.0)' );
$allowedByScope = array(
    'runtime'     => $runtimeAllowed,
    'development' => array_merge($runtimeAllowed, array( 'LGPL-3.0-or-later', 'MPL-2.0', 'OSL-3.0' )),
);
$prohibited    = array( 'AGPL-1.0', 'AGPL-3.0', 'BUSL-1.1', 'SSPL-1.0' );
$review        = array();
$spdxPackages  = array();
$relationships = array();
foreach ($packages as $key => $p) {
    $licenses = $p['licenses'];
    $allowed  = $allowedByScope[ $p['scope'] ] ?? array();
    $status   = $licenses !== array() && array_diff($licenses, $allowed) === array() ? 'allowed' : 'review_required';
    if (array_intersect($licenses, $prohibited)) {
        $status = 'prohibited';
    }
    if ($status !== 'allowed') {
        $review[] = array(
            'package'  => $key,
            'licenses' => $licenses ?: array( 'UNKNOWN' ),
            'scope'    => $p['scope'],
            'status'   => $status,
        );
    }
    $id              = 'SPDXRef-Package-' . substr(hash('sha256', $key), 0, 20);
    $declared        = $licenses === array() ? 'NOASSERTION' : implode(' OR ', $licenses);
    $purlName        = implode('/', array_map('rawurlencode', explode('/', $p['name'])));
    $spdxPackages[]  = array(
        'SPDXID'           => $id,
        'name'             => $p['name'],
        'versionInfo'      => $p['version'],
        'downloadLocation' => 'NOASSERTION',
        'filesAnalyzed'    => false,
        'licenseConcluded' => 'NOASSERTION',
        'licenseDeclared'  => $declared,
        'externalRefs'     => array(
            array(
                'referenceCategory' => 'PACKAGE-MANAGER',
                'referenceType'     => 'purl',
                'referenceLocator'  => 'pkg:' . $p['ecosystem'] . '/' . $purlName . '@' . rawurlencode($p['version']),
            ),
        ),
    );
    $relationships[] = array(
        'spdxElementId'      => 'SPDXRef-DOCUMENT',
        'relationshipType'   => 'DESCRIBES',
        'relatedSpdxElement' => $id,
    );
}

$lockHash = hash('sha256', hash_file('sha256', 'composer.lock') . hash_file('sha256', 'package-lock.json'));
$created  = getenv('SOURCE_DATE_EPOCH') ? gmdate('Y-m-d\TH:i:s\Z', (int) getenv('SOURCE_DATE_EPOCH')) : gmdate('Y-m-d\TH:i:s\Z');
$discard  = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
$scanUtc  = getenv('DEPENDENCY_SCAN_UTC') ?: gmdate('Y-m-d\TH:i:s\Z');
$spdx     = array(
    'spdxVersion'       => 'SPDX-2.3',
    'dataLicense'       => 'CC0-1.0',
    'SPDXID'            => 'SPDXRef-DOCUMENT',
    'name'              => 'longevity-evidence-lab-dependencies',
    'documentNamespace' => 'https://longevityevidencelab.example/sbom/' . $lockHash,
    'creationInfo'      => array(
        'created'  => $created,
        'creators' => array( 'Tool: scripts/generate-dependency-sbom.php@1' ),
    ),
    'packages'          => $spdxPackages,
    'relationships'     => $relationships,
);
$licenses = array(
    'schema_version' => 1,
    'result'         => $review === array() ? 'success' : 'failure',
    'policy'         => array(
        'allowed_by_scope' => $allowedByScope,
        'review_required' => array( 'unknown or unlisted SPDX expression' ),
        'prohibited'      => $prohibited,
    ),
    'dependencies'   => array_values($packages),
    'exceptions'     => array(),
    'findings'       => $review,
);
$scan = array(
    'schema_version'   => 1,
    'result'           => 'success',
    'scan_utc'         => $scanUtc,
    'tool_versions'    => array(
        'php'      => PHP_VERSION,
        'composer' => trim((string) shell_exec('composer --version ' . $discard)),
        'npm'      => trim((string) shell_exec('npm --version ' . $discard)),
    ),
    'lock_sha256'      => array(
        'composer.lock'     => hash_file('sha256', 'composer.lock'),
        'package-lock.json' => hash_file('sha256', 'package-lock.json'),
    ),
    'advisory_sources' => array(
        array( 'tool' => 'composer audit --locked', 'source' => 'configured Composer/Packagist advisory API', 'database_snapshot_utc' => null, 'snapshot_status' => 'not_exposed_by_upstream_api' ),
        array( 'tool' => 'npm audit --audit-level=high', 'source' => 'configured npm registry audit API', 'database_snapshot_utc' => null, 'snapshot_status' => 'not_exposed_by_upstream_api' ),
    ),
);
foreach (
    array(
    $output        => $spdx,
    $licenseOutput => $licenses,
    $scanOutput    => $scan,
    ) as $path => $document
) {
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
if ($review !== array()) {
    fwrite(STDERR, 'Dependency license policy requires review: ' . json_encode($review, JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
printf("Generated SPDX SBOM for %d locked dependencies; license policy passed.\n", count($packages));
