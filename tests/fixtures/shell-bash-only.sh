#!/usr/bin/env bash
# Regression fixture: Bash-only script using process substitution.
# validate.sh must use `bash -n` for this file, not `sh -n`.
set -euo pipefail
diff <(echo "alpha") <(echo "alpha") >/dev/null
echo "bash fixture ok"
