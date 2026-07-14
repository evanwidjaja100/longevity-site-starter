#!/usr/bin/env python3
"""Validate editorial calendars, briefs, policies, and repository content contracts."""
from __future__ import annotations

import csv
import re
import sys
from datetime import date, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ERRORS: list[str] = []


def error(message: str) -> None:
    ERRORS.append(message)


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def valid_date(value: str) -> bool:
    try:
        datetime.strptime(value, "%Y-%m-%d")
        return True
    except ValueError:
        return False


def slugify(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", value.lower()).strip("-")


def validate_calendar() -> list[dict[str, str]]:
    path = ROOT / "content/editorial-calendar.csv"
    rows = read_csv(path)
    required = {
        "order",
        "publish_date",
        "cluster",
        "intent",
        "title",
        "target_keyword",
        "target_length",
        "template",
        "medical_review",
        "hands_on_testing",
        "primary_internal_link_order",
        "status",
        "notes",
    }
    if not rows or not required.issubset(rows[0]):
        error(f"{path}: missing required columns")
        return rows
    if len(rows) != 60:
        error(f"{path}: expected 60 rows, found {len(rows)}")
    orders: list[int] = []
    slugs: set[str] = set()
    dates: list[date] = []
    for index, row in enumerate(rows, start=2):
        try:
            order = int(row.get("order", ""))
            orders.append(order)
        except ValueError:
            error(f"{path}:{index}: invalid order")
        title = row.get("title", "").strip()
        slug = slugify(title)
        if not title:
            error(f"{path}:{index}: missing title")
        if slug in slugs:
            error(f"{path}:{index}: duplicate generated slug {slug}")
        slugs.add(slug)
        publish_date = row.get("publish_date", "")
        if not valid_date(publish_date):
            error(f"{path}:{index}: invalid publish_date {publish_date!r}")
        else:
            dates.append(datetime.strptime(publish_date, "%Y-%m-%d").date())
        if row.get("medical_review") not in {"Yes", "No"}:
            error(f"{path}:{index}: medical_review must be Yes or No")
        if row.get("hands_on_testing") not in {"Yes", "No"}:
            error(f"{path}:{index}: hands_on_testing must be Yes or No")
        try:
            if int(row.get("target_length", "0")) < 500:
                error(f"{path}:{index}: target_length is unexpectedly low")
        except ValueError:
            error(f"{path}:{index}: invalid target_length")
    if orders != list(range(1, 61)):
        error(f"{path}: order must be exactly 1..60")
    if dates != sorted(dates):
        error(f"{path}: publish dates are not in non-decreasing order")
    return rows


def validate_launch_calendar() -> None:
    path = ROOT / "content/calendar/launch-calendar.csv"
    if not path.exists():
        error(f"{path}: missing trust-first launch calendar")
        return
    rows = read_csv(path)
    required = {
        "article_id",
        "order",
        "publish_week",
        "title",
        "pillar",
        "status",
        "testing_required",
        "medical_review_required",
        "primary_question",
        "original_contribution",
    }
    if not rows or not required.issubset(rows[0]):
        error(f"{path}: missing required columns")
        return
    orders: list[int] = []
    weeks: list[int] = []
    identifiers: set[str] = set()
    slugs: set[str] = set()
    allowed_statuses = {"Planned", "Blocked until real testing", "Ready", "Published", "Deferred"}
    for line, row in enumerate(rows, start=2):
        article_id = row.get("article_id", "").strip()
        if not re.fullmatch(r"LEL-\d{3}", article_id):
            error(f"{path}:{line}: invalid article_id {article_id!r}")
        if article_id in identifiers:
            error(f"{path}:{line}: duplicate article_id {article_id}")
        identifiers.add(article_id)
        try:
            order = int(row.get("order", ""))
            week = int(row.get("publish_week", ""))
            orders.append(order)
            weeks.append(week)
        except ValueError:
            error(f"{path}:{line}: order and publish_week must be integers")
        title = row.get("title", "").strip()
        slug = slugify(title)
        if not title or not row.get("pillar", "").strip() or not row.get("primary_question", "").strip():
            error(f"{path}:{line}: title, pillar, and primary_question are required")
        if slug in slugs:
            error(f"{path}:{line}: duplicate generated slug {slug}")
        slugs.add(slug)
        for key in ("testing_required", "medical_review_required"):
            if row.get(key) not in {"Yes", "No"}:
                error(f"{path}:{line}: {key} must be Yes or No")
        status = row.get("status", "")
        if status not in allowed_statuses:
            error(f"{path}:{line}: unsupported status {status!r}")
        if row.get("testing_required") == "Yes" and status not in {"Blocked until real testing", "Ready", "Published"}:
            error(f"{path}:{line}: testing-required content must stay blocked until real testing is complete")
        if len(row.get("original_contribution", "").strip()) < 12:
            error(f"{path}:{line}: original contribution is missing or too vague")
    if orders != list(range(1, len(rows) + 1)):
        error(f"{path}: order must be contiguous from 1")
    if weeks != sorted(weeks) or any(week < 1 for week in weeks):
        error(f"{path}: publish_week must be positive and non-decreasing")


def brief_contract(path: Path) -> tuple[int | None, str, str]:
    text = path.read_text(encoding="utf-8")
    heading = re.search(r"^# Brief\s+(\d+):\s+(.+)$", text, re.MULTILINE)
    medical = re.search(r"^- \*\*Medical review:\*\*\s*(Yes|No)\s*$", text, re.MULTILINE)
    if not heading:
        return None, "", medical.group(1) if medical else ""
    return int(heading.group(1)), heading.group(2).strip(), medical.group(1) if medical else ""


def validate_briefs(calendar_rows: list[dict[str, str]]) -> None:
    required_headings = [
        "## Reader outcome",
        "## Evidence and originality requirements",
        "## Release gate",
    ]
    brief_numbers: set[int] = set()
    calendar = {int(row["order"]): row for row in calendar_rows if row.get("order", "").isdigit()}
    paths = sorted((ROOT / "content/first-8-briefs").glob("*.md"))
    if len(paths) != 8:
        error(f"content/first-8-briefs: expected 8 briefs, found {len(paths)}")
    for path in paths:
        text = path.read_text(encoding="utf-8")
        for heading in required_headings:
            if heading not in text:
                error(f"{path}: missing {heading}")
        if not re.search(r"6- or 12-month recheck|next review", text, re.I):
            error(f"{path}: next review interval is unspecified")
        if "original" not in text.lower():
            error(f"{path}: original contribution requirement is missing")
        number, title, medical = brief_contract(path)
        if number is None:
            error(f"{path}: invalid brief heading")
            continue
        if number in brief_numbers:
            error(f"{path}: duplicate brief number {number}")
        brief_numbers.add(number)
        if not medical:
            error(f"{path}: medical review requirement is unspecified")
        row = calendar.get(number)
        if not row:
            error(f"{path}: no matching editorial-calendar row for brief {number}")
            continue
        if row.get("title", "").strip() != title:
            error(f"{path}: title does not match editorial calendar row {number}")
        if row.get("medical_review") != medical:
            error(f"{path}: medical review value conflicts with editorial calendar row {number}")
    if brief_numbers != set(range(1, 9)):
        error("content/first-8-briefs: brief numbers must be exactly 1..8")


def validate_policies() -> None:
    required = [
        ROOT / "content/editorial-policy.md",
        ROOT / "policies/affiliate-disclosure.md",
        ROOT / "policies/medical-disclaimer.md",
        ROOT / "policies/privacy-policy-notes.md",
        ROOT / "content/governance/corrections-policy.md",
        ROOT / "content/governance/ai-assisted-work-policy.md",
    ]
    placeholders = re.compile(r"\b(TODO|TBD|lorem ipsum|insert policy|example\.com)\b", re.I)
    for path in required:
        if not path.exists():
            error(f"{path}: required policy file is missing")
            continue
        text = path.read_text(encoding="utf-8").strip()
        if len(text) < 120:
            error(f"{path}: policy content is unexpectedly short")
        if placeholders.search(text):
            error(f"{path}: contains placeholder policy content")


def validate_templates() -> None:
    required = [
        ROOT / "content/templates/content-brief-v2.md",
        ROOT / "content/templates/test-protocol-template.md",
        ROOT / "content/templates/correction-record-template.md",
        ROOT / "operations/checklists/production-readiness.md",
    ]
    for path in required:
        if not path.exists() or not path.read_text(encoding="utf-8").strip():
            error(f"{path}: required operational template is missing or empty")


def validate_no_tracked_env() -> None:
    for path in ROOT.glob(".env*"):
        if path.name not in {".env.example", ".env.ci"}:
            error(f"{path}: tracked environment files other than .env.example are forbidden")


def main() -> int:
    calendar_rows = validate_calendar()
    validate_launch_calendar()
    validate_briefs(calendar_rows)
    validate_policies()
    validate_templates()
    validate_no_tracked_env()
    if ERRORS:
        for item in ERRORS:
            print(f"ERROR: {item}", file=sys.stderr)
        print(f"Content validation failed with {len(ERRORS)} error(s).", file=sys.stderr)
        return 1
    print("Content validation passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
