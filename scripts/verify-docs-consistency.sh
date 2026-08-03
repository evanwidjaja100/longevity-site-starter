#!/usr/bin/env bash
# Keep supported topology, commands, CI registry, and operational symbols aligned.
# Single-quoted php/awk/jq snippets below are intentional (no shell expansion).
# shellcheck disable=SC2016
set -euo pipefail
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"
failures=0
fail() { printf 'ERROR: %s\n' "$1" >&2; failures=1; }

docs=(Makefile README.md)
while IFS= read -r file; do docs+=("$file"); done < <(find docs/operations docs/adr -type f -name '*.md' | sort)

for doc in "${docs[@]}"; do
  while IFS= read -r ref; do [[ -f "$ref" ]] || fail "$doc references missing script: $ref"; done < <(grep -oE 'scripts/[A-Za-z0-9._-]+\.(sh|php|py)' "$doc" | sort -u || true)
done

# Production is managed-host-only. Historical reports are intentionally excluded;
# executable templates and normative operator/architecture docs may not revive these paths.
for file in README.md .env.example .env.production.example compose.yaml docker/development/Dockerfile docker/development/Dockerfile.wpcli "${docs[@]:2}"; do
  grep -qiE '\b(redis|VPS)\b|LEL_IMAGE_TAG|Docker image or tarball' "$file" 2>/dev/null && fail "$file references an unsupported production topology"
done

php -r '
  $registry=json_decode(file_get_contents("config/release-required-jobs.json"),true);
  if(($registry["schema_version"]??null)!==2) {fwrite(STDERR,"registry schema mismatch\n");exit(1);}
  $workflow=file_get_contents(".github/workflows/ci.yml");
  preg_match_all("/^  ([a-z0-9-]+):$/m",$workflow,$m);$jobs=array_flip($m[1]);
  $release=substr($workflow,(int)strpos($workflow,"  release-evidence:"));
  $seen=[];
  foreach($registry["required"]??[] as $row){
    $job=$row["job"]??"";$artifact=$row["artifact"]??"";
    if($job===""||$artifact===""||isset($seen[$job])||!isset($jobs[$job])||!preg_match("/^\\s+- ".preg_quote($job,"/")."$/m",$release)||strpos($workflow,"name: ".$artifact)===false||empty($row["reports"])) {fwrite(STDERR,"workflow/registry mismatch: $job/$artifact\n");exit(1);} $seen[$job]=1;
    foreach($row["reports"] as $report) if(empty($report["path"])||empty($report["format"])||empty($report["schema"])) {fwrite(STDERR,"incomplete report contract: $job\n");exit(1);}
  }
' || fail 'release registry does not match workflow jobs, needs, artifacts, or report contracts'

php -r '
  $code=file_get_contents("wp-content/mu-plugins/longevity-core/class-cli.php");
  preg_match_all("/add_command\\( [\x27\x22]longevity ([a-z-]+)/",$code,$m);$commands=array_flip($m[1]);
  foreach(glob("docs/operations/*.md") as $file){$text=file_get_contents($file);preg_match_all("/wp longevity ([a-z-]+)/",$text,$refs);foreach(array_unique($refs[1]) as $name)if(!isset($commands[$name])){fwrite(STDERR,"$file references missing WP-CLI root: $name\n");exit(1);}}
' || fail 'operational docs reference a nonexistent WP-CLI command'

for symbol in audit_write_failure migration_failed freshness_cycle_failed invalidation_job_failed invalidation_fallback_failure csp_violation approve_publication view_operational_readiness complete_medical_review approve_commercial_disclosure; do
  grep -Rqs --include='*.php' "['\"]${symbol}['\"]" wp-content/mu-plugins/longevity-core || fail "documented event/capability is absent from code: $symbol"
done

today=$(date -u +%s)
while IFS= read -r doc; do
  grep -q '^\*\*Owner:\*\* ' "$doc" || fail "$doc has no Owner metadata"
  reviewed=$(sed -n 's/^\*\*Last reviewed:\*\* //p' "$doc" | head -n 1)
  [[ "$reviewed" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || { fail "$doc has no valid Last reviewed date"; continue; }
  reviewed_epoch=$(date -u -d "$reviewed" +%s 2>/dev/null || echo 0)
  (( reviewed_epoch <= today && today - reviewed_epoch <= 15552000 )) || fail "$doc review date is future-dated or older than 180 days"
done < <(find docs/operations -type f -name '*.md' | sort)

(( failures == 0 )) || { echo 'Docs/config consistency check FAILED.' >&2; exit 1; }
echo 'Docs/config consistency check passed.'
