#!/usr/bin/env python3
"""Validate editorial calendars, briefs, policies, and repository content contracts."""
from __future__ import annotations

import csv
import re
import shutil
import subprocess
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


ALLOWED_EDITORIAL_STATUSES = {
    "Planned",
    "Briefing",
    "Researching",
    "Drafting",
    "Human fact-check",
    "Medical review",
    "Editorial approval",
    "Ready for scheduling",
    "Published",
    "Blocked -- testing required",
    "Blocked -- reviewer required",
    "Paused",
    "Retired",
}

def validate_editorial_status(status: str, line: int, path: Path) -> bool:
    if status not in ALLOWED_EDITORIAL_STATUSES:
        error(f"{path}:{line}: unsupported editorial status {status!r}")
        return False
    return True


def validate_launch_calendar() -> list[dict[str, str]]:
    path = ROOT / "content/calendar/launch-calendar.csv"
    if not path.exists():
        error(f"{path}: missing trust-first launch calendar")
        return []
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
        return rows
    orders: list[int] = []
    weeks: list[int] = []
    identifiers: set[str] = set()
    slugs: set[str] = set()
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
        validate_editorial_status(status, line, path)
        if row.get("testing_required") == "Yes" and status not in {"Blocked -- testing required", "Ready for scheduling", "Published"}:
            error(f"{path}:{line}: testing-required content must stay blocked until real testing is complete")
        if len(row.get("original_contribution", "").strip()) < 12:
            error(f"{path}:{line}: original contribution is missing or too vague")
    if orders != list(range(1, len(rows) + 1)):
        error(f"{path}: order must be contiguous from 1")
    if weeks != sorted(weeks) or any(week < 1 for week in weeks):
        error(f"{path}: publish_week must be positive and non-decreasing")
    return rows


def brief_contract(path: Path) -> tuple[str, str, str]:
    text = path.read_text(encoding="utf-8")
    article = re.search(r"^- \*\*Content ID:\*\*\s*(LEL-\d{3})\s*$", text, re.MULTILINE)
    title_m = re.search(r"^- \*\*Title:\*\*\s*(.+)$", text, re.MULTILINE)
    medical = re.search(r"^- \*\*Medical review required:\*\*\s*(Yes|No)\s*$", text, re.MULTILINE)
    article_id = article.group(1) if article else ""
    title = title_m.group(1).strip() if title_m else ""
    med = medical.group(1) if medical else ""
    return article_id, title, med


def validate_briefs(launch_rows: list[dict[str, str]]) -> None:
    required_headings = [
        "## Assignment",
        "## Evidence plan",
        "## Original contribution",
        "## Review and governance",
        "## Release gate",
    ]
    brief_ids: set[str] = set()
    calendar = {row["article_id"]: row for row in launch_rows}
    paths = sorted((ROOT / "content/launch-briefs").glob("*.md"))
    paths_8 = [p for p in paths if any(f"LEL-{n:03d}" in p.name for n in range(1, 9))]
    if len(paths_8) != 8:
        error(f"content/launch-briefs: expected at least 8 briefs (LEL-001 to LEL-008), found {len(paths_8)}")
    for path in paths:
        text = path.read_text(encoding="utf-8")
        for heading in required_headings:
            if heading not in text:
                error(f"{path}: missing {heading}")
        if not re.search(r"next review interval|next review", text, re.I):
            error(f"{path}: next review interval is unspecified")
        article_id, title, medical = brief_contract(path)
        if not article_id:
            error(f"{path}: missing Content ID")
            continue
        if article_id in brief_ids:
            error(f"{path}: duplicate Content ID {article_id}")
        brief_ids.add(article_id)
        if not medical:
            error(f"{path}: medical review requirement is unspecified")
        row = calendar.get(article_id)
        if not row:
            error(f"{path}: no matching launch-calendar row for {article_id}")
            continue
        cal_title = row.get("title", "").strip()
        if title and title != cal_title:
            error(f"{path}: title {title!r} does not match launch calendar {cal_title!r}")
        cal_medical = row.get("medical_review_required", "")
        if medical and cal_medical and medical != cal_medical:
            error(f"{path}: medical review {medical!r} conflicts with launch calendar {cal_medical!r}")


def validate_policies() -> None:
    required = [
        ROOT / "content/editorial-policy.md",
        ROOT / "policies/affiliate-disclosure.md",
        ROOT / "policies/medical-disclaimer.md",
        ROOT / "policies/privacy-policy-notes.md",
        ROOT / "content/governance/corrections-policy.md",
        ROOT / "content/governance/ai-assisted-work-policy.md",
    ]
    placeholders = re.compile(
        r"\b(TODO|TBD|lorem ipsum|insert policy|example\.com"
        r"|To be assigned|To be set|To be populated|To be determined"
        r"|To be confirmed|To be completed|PLACEHOLDER)\b",
        re.I,
    )
    encoding_issues = re.compile(
        r"(\?\?\?|\ufffd|\[\d{4}-\d{2}-\d{2}\]|\[date\]|\[TBD\]|\[TODO\])"
    )
    for path in required:
        if not path.exists():
            error(f"{path}: required policy file is missing")
            continue
        text = path.read_text(encoding="utf-8").strip()
        if len(text) < 120:
            error(f"{path}: policy content is unexpectedly short")
        if placeholders.search(text):
            error(f"{path}: contains placeholder policy content")
        if encoding_issues.search(text):
            error(f"{path}: contains encoding artifacts or bracketed placeholders")


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


def validate_encoding_integrity() -> None:
    """Detect mojibake, replacement characters, and corrupted copy in governed content."""
    encoding_issues = re.compile(
        "(\ufffd|\\?\\?\\?|\u00c3[\u0080-\u00bf]|\u00c2[\u0080-\u00bf]|\u00e2\u0080[\u0098\u0099\u009c\u009d\u009e\u009f])"
    )
    bracketed_placeholders = re.compile(
        r"\[(TBD|TODO|DATE|To be \w+|\d{4}-\d{2}-\d{2})\]"
    )
    scan_dirs = [
        ROOT / "content" / "launch-briefs",
        ROOT / "content" / "governance",
        ROOT / "policies",
    ]
    for scan_dir in scan_dirs:
        if not scan_dir.exists():
            continue
        for path in sorted(scan_dir.glob("*.md")):
            try:
                text = path.read_text(encoding="utf-8")
            except UnicodeDecodeError:
                error(f"{path}: file is not valid UTF-8")
                continue
            match = encoding_issues.search(text)
            if match:
                error(f"{path}: encoding artifact detected near {match.group(0)!r}")
            bracket_match = bracketed_placeholders.search(text)
            if bracket_match:
                error(f"{path}: unresolved bracketed placeholder {bracket_match.group(0)!r}")


def validate_no_tracked_env() -> None:
    allowed = {".env.example", ".env.ci.template", ".env.production.example"}
    tracked: set[str] = set()
    if shutil.which("git") and (ROOT / ".git").exists():
        result = subprocess.run(
            ["git", "ls-files", ".env*"],
            cwd=ROOT,
            check=False,
            capture_output=True,
            text=True,
        )
        if result.returncode == 0:
            tracked.update(line.strip() for line in result.stdout.splitlines() if line.strip())
    elif (ROOT / "MANIFEST.sha256").exists():
        for line in (ROOT / "MANIFEST.sha256").read_text(encoding="utf-8").splitlines():
            if "  " in line:
                tracked.add(line.split("  ", 1)[1].removeprefix("./"))
    for name in sorted(item for item in tracked if Path(item).name.startswith(".env")):
        if Path(name).name not in allowed:
            error(f"{ROOT / name}: tracked environment files other than .env.example are forbidden")


def main() -> int:
    launch_rows = validate_launch_calendar()
    validate_briefs(launch_rows)
    validate_policies()
    validate_templates()
    validate_encoding_integrity()
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
