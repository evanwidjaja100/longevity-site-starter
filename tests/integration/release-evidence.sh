#!/usr/bin/env bash
# Positive and fail-closed matrix for release evidence provenance and schemas.
# Single-quoted php/awk/jq snippets below are intentional (no shell expansion).
# shellcheck disable=SC2016
set -uo pipefail
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT" || exit 1
SANDBOX=build/release-evidence-test
R="$SANDBOX/reports"
SHA=$(git rev-parse HEAD)
OTHER=2222222222222222222222222222222222222222
RUN=123456
FAILURES=0

rm -rf "$SANDBOX"
FIXTURE_DIR="$SANDBOX/release-fixture"
mkdir -p "$FIXTURE_DIR"
RELEASE_OUT_DIR="$FIXTURE_DIR" bash scripts/build-release-artifact.sh "$SHA" >/dev/null || exit 1
FIXTURE_ARCHIVE="$FIXTURE_DIR/longevity-release-${SHA}.tar.gz"
bash scripts/verify-release-artifact.sh "$FIXTURE_ARCHIVE" "$SHA" "$FIXTURE_DIR/release-verification.json" "$FIXTURE_ARCHIVE.sha256" >/dev/null || exit 1
EPOCH=$(git show -s --format=%ct "$SHA")

build_fixture() {
  rm -rf "$R"; mkdir -p "$R/upstream"
  php -r '
    [$registry,$root,$sha,$run,$fixture,$verification,$epoch]=array_slice($argv,1);
    $r=json_decode(file_get_contents($registry),true);$jobs=[];
    foreach($r["required"] as $row){if(!in_array("push",$row["events"],true))continue;$jobs[$row["job"]]="success";$dir="$root/upstream/{$row["artifact"]}";mkdir($dir,0777,true);$reports=[];
      foreach($row["reports"] as $spec){$path=$spec["path"];$full="$dir/$path";$schema=$spec["schema"];$format=$spec["format"];
        if($path==="release.tar.gz"){copy($fixture,$full);}
        elseif($path==="release.tar.gz.sha256"){file_put_contents($full,hash_file("sha256",$fixture)."  release.tar.gz\n");}
        elseif($path==="release-verification.json"){copy($verification,$full);}
        elseif($path==="reproducibility.json"){file_put_contents($full,json_encode(["schema_version"=>1,"result"=>"success","source_sha"=>$sha,"source_date_epoch"=>(int)$epoch,"builds"=>2,"artifact_sha256"=>hash_file("sha256",$fixture)])."\n");}
        else{$body=match($schema){
          "command-log-v1"=>"actual fixture command output\n","dependency-review-v1"=>"[]\n","trufflehog-v1"=>"[]\n","trivy-v1"=>"{\"SchemaVersion\":2,\"Results\":[]}\n",
          "clover-v1"=>"<coverage/>\n","junit-v1"=>"<testsuites/>\n","checkstyle-v1"=>"<checkstyle/>\n","eslint-v1"=>"[]\n","stylelint-v1"=>"[]\n",
          "result-v1"=>"{\"schema_version\":1,\"result\":\"success\"}\n","lighthouse-v1"=>"{\"lighthouseVersion\":\"1\",\"categories\":{}}\n",
          "sarif-2.1.0"=>"{\"version\":\"2.1.0\",\"runs\":[]}\n","spdx-2.x"=>"{\"spdxVersion\":\"SPDX-2.3\",\"packages\":[]}\n",
          "license-policy-v1"=>"{\"schema_version\":1,\"result\":\"success\",\"dependencies\":[]}\n","scan-metadata-v1"=>"{\"schema_version\":1,\"result\":\"success\",\"scan_utc\":\"2026-01-01T00:00:00Z\",\"tool_versions\":{\"tool\":\"1\"},\"advisory_sources\":[]}\n","composer-audit-v1"=>"{\"advisories\":[]}\n",
          "npm-audit-v2"=>"{\"auditReportVersion\":2,\"metadata\":{}}\n",default=>throw new RuntimeException("unsupported fixture schema: $schema")};file_put_contents($full,$body);}
        $reports[]=["path"=>$path,"format"=>$format,"sha256"=>hash_file("sha256",$full),"result"=>"success","redacted"=>false];
      }
      $meta=["schema_version"=>1,"job"=>$row["job"],"artifact"=>$row["artifact"],"commit_sha"=>$sha,"workflow_run_id"=>$run,"result"=>"success","tool_versions"=>["fixture"=>"1.0.0"],"action_shas"=>["actions/checkout"=>str_repeat("a",40)],"container_images"=>[],"reports"=>$reports];file_put_contents("$dir/evidence.json",json_encode($meta));
    }
    file_put_contents("$root/ci-job-results.json",json_encode(["schema_version"=>1,"commit_sha"=>$sha,"workflow_run_id"=>$run,"jobs"=>$jobs]));
  ' config/release-required-jobs.json "$R" "$SHA" "$RUN" "$FIXTURE_ARCHIVE" "$FIXTURE_DIR/release-verification.json" "$EPOCH"
}
run_generator() { EVIDENCE_REPORTS_DIR="$R" GITHUB_SHA="$SHA" GITHUB_RUN_ID="$RUN" GITHUB_EVENT_NAME=push bash scripts/generate-release-evidence.sh "$SHA" >"$SANDBOX/generator.log" 2>&1; }
expect() {
  local name=$1 expected=$2 code=$3
  if { [[ $expected == pass && $code -eq 0 ]] || [[ $expected == fail && $code -ne 0 ]]; }; then echo "PASS: $name"; else echo "FAIL: $name (exit $code)" >&2; sed -n '1,20p' "$SANDBOX/generator.log" >&2; FAILURES=$((FAILURES+1)); fi
}
mutate_json() { php -r '$f=$argv[1];$d=json_decode(file_get_contents($f),true);eval($argv[2]);file_put_contents($f,json_encode($d));' "$1" "$2"; }
rehash_report() { php -r '$f=$argv[1];$path=$argv[2];$d=json_decode(file_get_contents($f),true);foreach($d["reports"] as &$r)if($r["path"]===$path)$r["sha256"]=hash_file("sha256",dirname($f)."/".$path);file_put_contents($f,json_encode($d));' "$1" "$2"; }

build_fixture; run_generator; expect 'complete evidence with real archive' pass $?
build_fixture; mutate_json "$R/ci-job-results.json" 'unset($d["jobs"]["accessibility"]);'; run_generator; expect 'missing job' fail $?
for state in failure cancelled skipped unknown; do
  build_fixture; php -r '$f=$argv[1];$d=json_decode(file_get_contents($f),true);$d["jobs"]["release-artifact"]=$argv[2];file_put_contents($f,json_encode($d));' "$R/ci-job-results.json" "$state"
  run_generator; expect "job state $state" fail $?
done
build_fixture; rm -rf "$R/upstream/accessibility-evidence"; run_generator; expect 'missing artifact' fail $?
build_fixture; mutate_json "$R/upstream/php-sast-evidence/evidence.json" '$d["commit_sha"]="'$OTHER'";'; run_generator; expect 'wrong commit' fail $?
build_fixture; mutate_json "$R/upstream/php-sast-evidence/evidence.json" '$d["workflow_run_id"]="999";'; run_generator; expect 'wrong run' fail $?
build_fixture; mkdir -p "$R/upstream/duplicate"; cp "$R/upstream/php-sast-evidence/evidence.json" "$R/upstream/duplicate/evidence.json"; run_generator; expect 'duplicate artifact metadata' fail $?
build_fixture; printf 'tampered\n' >> "$R/upstream/php-sast-evidence/psalm.sarif"; run_generator; expect 'checksum mismatch' fail $?
build_fixture; printf '{bad json\n' > "$R/upstream/frontend-quality-evidence/stylelint.json"; rehash_report "$R/upstream/frontend-quality-evidence/evidence.json" stylelint.json; run_generator; expect 'malformed report' fail $?
build_fixture; printf '{"placeholder":true}\n' > "$R/upstream/frontend-quality-evidence/stylelint.json"; rehash_report "$R/upstream/frontend-quality-evidence/evidence.json" stylelint.json; run_generator; expect 'placeholder report' fail $?
build_fixture; printf 'not an archive\n' > "$R/upstream/release-artifact/release.tar.gz"; rehash_report "$R/upstream/release-artifact/evidence.json" release.tar.gz; run_generator; expect 'fake archive' fail $?
build_fixture; printf '%064d  release.tar.gz\n' 0 > "$R/upstream/release-artifact/release.tar.gz.sha256"; rehash_report "$R/upstream/release-artifact/evidence.json" release.tar.gz.sha256; run_generator; expect 'sidecar checksum mismatch' fail $?
build_fixture; mutate_json "$R/upstream/release-artifact/release-verification.json" '$d["artifact_sha256"]=str_repeat("0",64);'; rehash_report "$R/upstream/release-artifact/evidence.json" release-verification.json; run_generator; expect 'artifact verification checksum mismatch' fail $?
build_fixture; mutate_json "$R/upstream/release-artifact/reproducibility.json" '$d["artifact_sha256"]=str_repeat("0",64);'; rehash_report "$R/upstream/release-artifact/evidence.json" reproducibility.json; run_generator; expect 'reproducibility checksum mismatch' fail $?

META="$SANDBOX/producer-metadata"
mkdir -p "$META"; printf '{"schema_version":1,"result":"success"}\n' > "$META/result.json"
GITHUB_SHA="$SHA" GITHUB_RUN_ID="$RUN" EVIDENCE_ACTION_SHAS="actions/checkout@$(printf 'a%.0s' {1..40})" EVIDENCE_CONTAINER_IMAGES='scanner.example/tool@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' \
  php scripts/write-release-evidence-metadata.php fixture fixture-evidence "$META" success json:result.json
php -r '$d=json_decode(file_get_contents($argv[1]),true);exit(array_keys($d["action_shas"]??[])===["actions/checkout"]&&($d["container_images"]??[])===["scanner.example/tool@sha256:".str_repeat("b",64)]?0:1);' "$META/evidence.json" || { echo 'FAIL: producer metadata was not limited to explicit per-job identities' >&2; FAILURES=$((FAILURES+1)); }

rm -rf "$SANDBOX"
(( FAILURES == 0 )) || { echo "release-evidence tests FAILED: $FAILURES" >&2; exit 1; }
echo 'All release-evidence contract tests passed.'
