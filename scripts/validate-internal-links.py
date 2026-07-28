#!/usr/bin/env python3
"""Validate both structured launch links and the legacy 60-article map."""
from __future__ import annotations

import csv
import re
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MAP = ROOT / "content/calendar/internal-link-map.csv"
CALENDAR = ROOT / "content/calendar/launch-calendar.csv"
LEGACY_MAP = ROOT / "content/internal-link-map.md"


def rows(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def validate_launch_map(errors: list[str]) -> int:
    if not MAP.exists() or not CALENDAR.exists():
        errors.append("launch calendar or structured internal-link map is missing")
        return 0
    articles = {row["article_id"]: row for row in rows(CALENDAR)}
    links = rows(MAP)
    required_columns = {
        "source_article",
        "destination_article",
        "relationship",
        "anchor_intent",
        "required",
        "publication_dependency",
        "reader_purpose",
    }
    if not links or not required_columns.issubset(links[0]):
        errors.append(f"{MAP}: missing required columns")
        return len(links)
    seen: set[tuple[str, str]] = set()
    inbound: Counter[str] = Counter()
    for line, link in enumerate(links, start=2):
        source = link.get("source_article", "")
        destination = link.get("destination_article", "")
        if source == destination:
            errors.append(f"{MAP}:{line}: self-link {source}")
        if source not in articles:
            errors.append(f"{MAP}:{line}: unknown source {source}")
        if destination not in articles:
            errors.append(f"{MAP}:{line}: unknown destination {destination}")
        pair = (source, destination)
        if pair in seen:
            errors.append(f"{MAP}:{line}: duplicate link {source}->{destination}")
        seen.add(pair)
        inbound[destination] += 1
        if link.get("required") not in {"Yes", "No"}:
            errors.append(f"{MAP}:{line}: required must be Yes or No")
        if not link.get("relationship", "").strip() or not link.get("anchor_intent", "").strip() or not link.get("reader_purpose", "").strip():
            errors.append(f"{MAP}:{line}: relationship, anchor_intent, and reader_purpose are required")
        dependency = link.get("publication_dependency")
        if dependency not in {"Destination first", "Either order"}:
            errors.append(f"{MAP}:{line}: unsupported publication_dependency {dependency!r}")
        if source in articles and destination in articles and dependency == "Destination first":
            if int(articles[destination]["order"]) > int(articles[source]["order"]):
                errors.append(f"{MAP}:{line}: destination-first dependency points to a later article")
    for article, count in inbound.items():
        if count > 8:
            errors.append(f"{MAP}: excessive inbound launch links to {article}: {count}")
    return len(links)


def validate_legacy_map(errors: list[str]) -> int:
    if not LEGACY_MAP.exists():
        errors.append(f"{LEGACY_MAP}: missing legacy internal-link map")
        return 0
    parsed_orders: list[int] = []
    for line_no, raw in enumerate(LEGACY_MAP.read_text(encoding="utf-8").splitlines(), start=1):
        if not re.match(r"^\|\s*\d+\s*\|", raw):
            continue
        cells = [cell.strip() for cell in raw.strip().strip("|").split("|")]
        if len(cells) != 4:
            errors.append(f"{LEGACY_MAP}:{line_no}: expected four table columns")
            continue
        order = int(cells[0])
        parsed_orders.append(order)
        references = [int(value) for value in re.findall(r"\b(?:Article(?:s)?\s+)?(\d+)\b", f"{cells[2]} {cells[3]}")]
        for reference in references:
            if reference < 1 or reference > 60:
                errors.append(f"{LEGACY_MAP}:{line_no}: article reference {reference} is outside 1..60")
            if reference == order:
                errors.append(f"{LEGACY_MAP}:{line_no}: self-referential internal link to article {order}")
    if parsed_orders != list(range(1, 61)):
        errors.append(f"{LEGACY_MAP}: table orders must be exactly 1..60")
    return len(parsed_orders)


def main() -> int:
    errors: list[str] = []
    launch_count = validate_launch_map(errors)
    legacy_count = validate_legacy_map(errors)
    if errors:
        for item in errors:
            print(f"ERROR: {item}", file=sys.stderr)
        print(f"Internal-link validation failed with {len(errors)} error(s).", file=sys.stderr)
        return 1
    print(f"Internal-link validation passed: {launch_count} launch relationships and {legacy_count} legacy rows.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
