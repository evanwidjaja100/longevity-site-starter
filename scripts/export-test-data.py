#!/usr/bin/env python3
"""Validate and normalize a non-sensitive public test-data CSV export."""
from __future__ import annotations
import argparse
import csv
from pathlib import Path

BLOCKED_HEADERS = {"tester_email", "account_email", "device_serial", "ip_address", "health_notes", "diagnosis", "medication"}
REQUIRED = {"protocol_id", "protocol_version", "product", "observation", "value", "unit", "observed_date", "limitation"}

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("input", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    with args.input.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        headers = set(reader.fieldnames or [])
        missing = REQUIRED - headers
        blocked = BLOCKED_HEADERS & headers
        if missing:
            raise SystemExit(f"Missing required columns: {', '.join(sorted(missing))}")
        if blocked:
            raise SystemExit(f"Sensitive columns are forbidden: {', '.join(sorted(blocked))}")
        rows = list(reader)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=sorted(REQUIRED))
        writer.writeheader()
        for row in rows:
            writer.writerow({key: row.get(key, "") for key in sorted(REQUIRED)})
    print(f"Exported {len(rows)} public observation rows to {args.output}")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
