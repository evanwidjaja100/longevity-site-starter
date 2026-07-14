#!/usr/bin/env python3
"""Report due dates in version-controlled planning records."""
from __future__ import annotations

import argparse
import csv
from datetime import date, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--as-of", default=date.today().isoformat())
    parser.add_argument("--no-fail", action="store_true")
    args = parser.parse_args()
    as_of = datetime.strptime(args.as_of, "%Y-%m-%d").date()
    overdue: list[str] = []
    path = ROOT / "content/evidence/freshness-register.csv"
    if path.exists():
        with path.open(encoding="utf-8", newline="") as handle:
            for row in csv.DictReader(handle):
                due = row.get("next_review_date", "")
                if due and datetime.strptime(due, "%Y-%m-%d").date() < as_of and row.get("status") != "retired":
                    overdue.append(f"{row.get('record_id')}: due {due}")
    if overdue:
        print("Freshness items overdue:")
        for item in overdue:
            print(f"- {item}")
        return 0 if args.no_fail else 1
    print(f"Freshness validation passed as of {as_of.isoformat()}.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
