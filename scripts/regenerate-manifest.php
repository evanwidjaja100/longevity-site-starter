<?php
// Simple manifest regeneration using git show
$root = getcwd();
$files = [];
exec("git ls-files -z", $output, $ret);
$raw = implode("", $output);
$lines = explode("\0", $raw);
foreach ($lines as $file) {
    if (substr($file, 0, 2) === './') {
        $file = substr($file, 2);
    }
    if ($file === "") continue;
    if (preg_match('#^(MANIFEST\.sha256|\.env|\.env\.local|\.env\..*\.local|vendor/|node_modules/|reports/|\.phpunit\.cache/|coverage/|playwright-report/|test-results/|build/|wp-content/uploads/)#', $file)) continue;
    if (preg_match('/\.(log|sql|sql\.gz|tgz|tar\.gz)$/', $file)) continue;
    $files[] = $file;
}
sort($files);

$entries = [];
$errors = 0;
foreach ($files as $file) {
    $cmd = "git show :" . escapeshellarg($file) . " 2>&1";
    $content = shell_exec($cmd);
    if ($content === null || $content === "") {
        $errors++;
        continue;
    }
    // Check if git returned an error message instead of file content
    if (strpos($content, "fatal:") === 0 || strpos($content, "error:") === 0) {
        $errors++;
        continue;
    }
    $hash = hash("sha256", $content);
    $entries[] = $hash . " *./" . $file;
}

$manifest = implode("\n", $entries) . "\n";
file_put_contents("MANIFEST.sha256", $manifest);
echo "Manifest regenerated with " . count($entries) . " entries (" . $errors . " errors).\n";
