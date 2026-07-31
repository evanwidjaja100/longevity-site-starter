#!/bin/sh
# Real-DB exact affiliate dependency-edge test (PR-10).
#
# Contracts that need a real MySQL server and the installed index table:
#   1. Reindexing a parent that links merchant A materializes an edge to A
#      and no edge to unrelated merchant B (no broad parent-x-merchant rows).
#   2. Reverse lookup targets invalidation precisely: find_parents(A) lists
#      the parent, find_parents(B) does not.
#   3. Removing the link and reindexing removes the obsolete edge.
# Single-quoted php/awk/jq snippets below are intentional (no shell expansion).
# shellcheck disable=SC2016
set -eu
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT"

run() { docker compose run --rm wpcli wp eval "$1" --allow-root; }

run "if (!\Longevity\Core\Dependency_Index::exists()) { throw new \RuntimeException('dependency index table unavailable; run wp longevity migrate run'); }" >/dev/null

SETUP='
\Longevity\Core\Meta_Authorization::enter_trusted_scope();
$a = wp_insert_post(array("post_type" => "lel_affiliate", "post_status" => "private", "post_title" => "PR10 Merchant A"), true);
$b = wp_insert_post(array("post_type" => "lel_affiliate", "post_status" => "private", "post_title" => "PR10 Merchant B"), true);
if (is_wp_error($a) || is_wp_error($b)) { throw new \RuntimeException("merchant seed failed"); }
update_post_meta($a, "merchant_domain", "pr10-merchant-a.example");
update_post_meta($b, "merchant_domain", "pr10-merchant-b.example");
$p = wp_insert_post(array(
    "post_type"    => "post",
    "post_status"  => "draft",
    "post_title"   => "PR10 Edge Parent",
    "post_content" => "<a href=\"https://pr10-merchant-a.example/item\" rel=\"sponsored\">A</a>",
), true);
if (is_wp_error($p)) { throw new \RuntimeException("parent seed failed"); }
update_post_meta($p, "_lel_has_affiliate_links", "1");
\Longevity\Core\Meta_Authorization::exit_trusted_scope();
echo $a . ":" . $b . ":" . $p;
'
IDS=$(run "$SETUP" | tr -d '[:space:]')
A=${IDS%%:*}
REST=${IDS#*:}
B=${REST%%:*}
P=${REST#*:}
case "$A$B$P" in
	*[!0-9]*) echo "ERROR: could not seed synthetic records ('$IDS')." >&2; exit 1 ;;
esac

cleanup() {
	run "global \$wpdb; \$t=\Longevity\Core\Dependency_Index::table_name(); foreach (array($A, $B, $P) as \$id) { \$wpdb->query(\$wpdb->prepare(\"DELETE FROM \$t WHERE parent_post_id = %d OR (dependency_type = 'lel_affiliate' AND dependency_id = %d)\", \$id, \$id)); wp_delete_post(\$id, true); }" >/dev/null || true
}
trap cleanup EXIT

# --- Part 1: exact edges after reindex ---------------------------------------
run "\Longevity\Core\Dependency_Index::reindex_parent($P);" >/dev/null
EDGES=$(run "global \$wpdb; \$t=\Longevity\Core\Dependency_Index::table_name(); echo (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM \$t WHERE dependency_type = 'lel_affiliate' AND parent_post_id = %d AND dependency_id = %d\", $P, $A)) . ':' . (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM \$t WHERE dependency_type = 'lel_affiliate' AND parent_post_id = %d AND dependency_id = %d\", $P, $B));" | tr -d '[:space:]')
[ "$EDGES" = "1:0" ] || { echo "ERROR: expected exactly one edge to merchant A and none to B, got '$EDGES'." >&2; exit 1; }
echo 'Exact-edge contract passed: parent binds only the merchant it links.'

# --- Part 2: reverse lookup targets only linked parents ----------------------
LOOKUP=$(run "\$a = in_array($P, \Longevity\Core\Dependency_Index::find_parents('lel_affiliate', $A), true) ? 'yes' : 'no'; \$b = in_array($P, \Longevity\Core\Dependency_Index::find_parents('lel_affiliate', $B), true) ? 'yes' : 'no'; echo \$a . ':' . \$b;" | tr -d '[:space:]')
[ "$LOOKUP" = "yes:no" ] || { echo "ERROR: expected find_parents A=yes B=no, got '$LOOKUP'." >&2; exit 1; }
echo 'Targeted-invalidation contract passed: only merchant A resolves the parent.'

# --- Part 3: removing the link removes the obsolete edge ----------------------
run "\$r = wp_update_post(array('ID' => $P, 'post_content' => '<p>Link removed.</p>'), true); if (is_wp_error(\$r)) { throw new \RuntimeException('content update failed'); } \Longevity\Core\Dependency_Index::reindex_parent($P);" >/dev/null
REMAINING=$(run "global \$wpdb; \$t=\Longevity\Core\Dependency_Index::table_name(); echo (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM \$t WHERE dependency_type = 'lel_affiliate' AND parent_post_id = %d\", $P));" | tr -d '[:space:]')
[ "$REMAINING" = "0" ] || { echo "ERROR: expected 0 affiliate edges after link removal, got '$REMAINING'." >&2; exit 1; }
echo 'Edge-removal contract passed: reindex drops the obsolete dependency.'

echo 'Affiliate dependency-edge integration test passed (exact edges, targeted lookup, obsolete-edge removal).'
