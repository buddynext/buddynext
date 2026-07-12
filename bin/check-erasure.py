#!/usr/bin/env python3
"""
Every user-keyed table must be on the erase registry, or explicitly on the retain list.

WHY THIS GATE EXISTS
--------------------
DATA-LIFECYCLE.md §9 has said "every table holding user data is registered for export AND
erasure" for as long as it has existed. Three tables breached it anyway — bn_activity_log,
bn_email_log and bn_webhook_log were never purged when a member was deleted, and nobody
noticed for a very long time.

Not because anyone disagreed with the rule. Because a table was added, and NOTHING FORCED
THE QUESTION. A rule with no gate is a suggestion, and a compliance rule that is merely a
suggestion is a compliance defect waiting for its first subject-access request.

So this is the question, forced:

    Does every bn_* table with a user-bearing column appear in
    MemberCleanupService::erase_map() (we delete it) or ::retain_map() (we keep it, and
    here is why)?

A table on neither list is not a judgement call that was made and lost. It is a judgement
call that was never made. That is the only thing this script is looking for.

Usage:  python3 bin/check-erasure.py       (exit 1 on an unregistered table)
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "includes" / "Core" / "Installer.php"
CLEANUP = ROOT / "includes" / "Profile" / "MemberCleanupService.php"

# A column that names a person. If a table has one of these, deleting that person has to
# mean something for this table — even if the answer turns out to be "we keep it".
USER_COLUMNS = {
    "user_id", "owner_id", "actor_id", "author_id", "sender_id", "recipient_id",
    "follower_id", "following_id", "requester_id", "reporter_id", "target_user_id",
    "blocker_id", "blocked_id", "resolved_by", "created_by", "invited_by", "member_id",
    "assigned_to",
}


def user_keyed_tables() -> dict[str, list[str]]:
    """Every bn_* table in the schema that names a person, and the columns that do it."""
    src = INSTALLER.read_text()
    out: dict[str, list[str]] = {}

    for table, body in re.findall(r"CREATE TABLE \{\$p\}(bn_\w+) \((.*?)\) \{\$cs\}", src, re.S):
        cols = []
        for line in body.splitlines():
            m = re.match(r"\s*(\w+)\s+(BIGINT|INT|MEDIUMINT)", line, re.I)
            if m and m.group(1).lower() in USER_COLUMNS:
                cols.append(m.group(1).lower())
        if cols:
            out[table] = sorted(set(cols))

    return out


def registered() -> tuple[set[str], set[str]]:
    """Tables on the ERASE registry, and tables on the RETAIN list."""
    src = CLEANUP.read_text()

    def keys_in(func: str) -> set[str]:
        # Slice out the function body, then take its bn_* array keys.
        m = re.search(rf"function {func}\(\).*?\n\t}}", src, re.S)
        if not m:
            return set()
        return set(re.findall(r"'(bn_\w+)'\s*=>", m.group(0)))

    return keys_in("erase_map"), keys_in("retain_map")


def main() -> int:
    if not INSTALLER.exists() or not CLEANUP.exists():
        print("check-erasure: cannot find the Installer or MemberCleanupService", file=sys.stderr)
        return 1

    tables = user_keyed_tables()
    erase, retain = registered()

    if not erase:
        print("check-erasure: erase_map() parsed as EMPTY — the gate cannot vouch for anything.", file=sys.stderr)
        return 1

    unregistered = {t: c for t, c in tables.items() if t not in erase and t not in retain}

    # A table on the registry that no longer exists in the schema is dead weight, and it
    # makes residue() query a table that isn't there.
    schema = set(tables) | set(re.findall(r"CREATE TABLE \{\$p\}(bn_\w+) \(", INSTALLER.read_text()))
    phantom = sorted((erase | retain) - schema)

    print(f"user-keyed tables: {len(tables)}   erase: {len(erase)}   retain: {len(retain)}")

    if not unregistered and not phantom:
        print("✓ every user-keyed table is registered for erasure or explicitly retained")
        return 0

    if unregistered:
        print("\n✗ UNREGISTERED user-keyed tables — a member deleted today leaves rows here:\n", file=sys.stderr)
        for table, cols in sorted(unregistered.items()):
            print(f"    {table:30s} names a person via: {', '.join(cols)}", file=sys.stderr)
        print(
            "\n  Add each to MemberCleanupService::erase_map() (we delete it) or ::retain_map()\n"
            "  (we keep it — and say why, with a legal basis).\n"
            "\n"
            "  Deciding to keep a table is a perfectly good answer. Never deciding is not:\n"
            "  that is how bn_activity_log, bn_email_log and bn_webhook_log went unpurged.\n"
            "  See DATA-LIFECYCLE.md §9.",
            file=sys.stderr,
        )

    if phantom:
        print("\n✗ REGISTERED tables that are not in the schema (residue() will query a missing table):\n", file=sys.stderr)
        for table in phantom:
            print(f"    {table}", file=sys.stderr)

    return 1


if __name__ == "__main__":
    sys.exit(main())
