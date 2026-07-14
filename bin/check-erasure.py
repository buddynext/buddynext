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

import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INSTALLER = ROOT / "includes" / "Core" / "Installer.php"
CLEANUP = ROOT / "includes" / "Profile" / "MemberCleanupService.php"
PRIVACY = ROOT / "includes" / "Privacy" / "PrivacyTools.php"

# ── The Pro half ─────────────────────────────────────────────────────────────────
#
# This gate used to read the FREE repo only. Pro prefixes its tables `bn_` too and
# keeps per-user data in them, so all of Pro's user-keyed tables were on NEITHER the
# erase list nor the retain list — which is precisely the condition this script exists
# to fail the build on. It passed anyway, because it could not see them.
#
# A green gate meant "did not look", not "is clean". That is the worst possible state
# for a compliance check: it is not merely absent, it is actively reassuring.
#
# It is the same structural cause as two other P0s in this release — Free's uninstall
# wildcard-dropping Pro's tables, and Pro's suite passing only on a dirty DB. Every
# gate scanned one repo. So this one now scans both.
#
# Pro is optional: a Free-only checkout still passes. Override with BN_PRO_PATH.
PRO_ROOT = Path(os.environ.get("BN_PRO_PATH", ROOT.parent / "buddynext-pro"))
PRO_INSTALLER = PRO_ROOT / "includes" / "Core" / "Installer.php"
# Pro declares its tables into Free's registry through the
# buddynext_member_erase_map / _retain_map filters, rather than keeping a second
# registry — a second registry is the drift this gate exists to catch.
PRO_CLEANUP = PRO_ROOT / "includes" / "Core" / "UserCleanupListener.php"

# The derived export stream reaches every erase_map entry that carries a `where` clause.
# An entry WITHOUT one cannot be exported generically, so it must be hand-exported or
# explicitly excluded. Populated below from the registry itself.
def whereless_erase_entries() -> set[str]:
    """erase_map entries with no `where` clause — unreachable by the derived export stream."""
    src = CLEANUP.read_text()
    m = re.search(r"function erase_map\(.*?\n\t}", src, re.S)
    if not m:
        return set()
    body = m.group(0)
    out = set()
    for entry in re.finditer(r"'(bn_\w+)'\s*=>\s*array\((.*?)\)", body, re.S):
        if "'where'" not in entry.group(2):
            out.add(entry.group(1))
    return out

# A column that names a person. If a table has one of these, deleting that person has to
# mean something for this table — even if the answer turns out to be "we keep it".
USER_COLUMNS = {
    "user_id", "owner_id", "actor_id", "author_id", "sender_id", "recipient_id",
    "follower_id", "following_id", "requester_id", "reporter_id", "target_user_id",
    "blocker_id", "blocked_id", "resolved_by", "created_by", "invited_by", "member_id",
    "assigned_to",
}


def _tables_in(installer: Path) -> dict[str, list[str]]:
    """User-keyed bn_* tables declared by one installer."""
    if not installer.exists():
        return {}

    src = installer.read_text()
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


def user_keyed_tables() -> dict[str, list[str]]:
    """Every bn_* table that names a person — across BOTH repos, not just this one."""
    tables = _tables_in(INSTALLER)
    tables.update(_tables_in(PRO_INSTALLER))  # Pro's tables are bn_* too.
    return tables


def registered() -> tuple[set[str], set[str]]:
    """Tables on the ERASE registry, and tables on the RETAIN list — from BOTH repos.

    Free declares its own in erase_map() / retain_map(). Pro declares its own into the
    SAME registry through the buddynext_member_erase_map / _retain_map filters, so its
    declarations live in the methods that back those filters.
    """

    def keys_in(src: str, func: str) -> set[str]:
        # Slice out the function body, then take its bn_* array keys.
        m = re.search(rf"function {func}\(.*?\).*?\n\t}}", src, re.S)
        if not m:
            return set()
        return set(re.findall(r"'(bn_\w+)'", m.group(0)))

    free_src = CLEANUP.read_text()
    erase = keys_in(free_src, "erase_map")
    retain = keys_in(free_src, "retain_map")

    if PRO_CLEANUP.exists():
        pro_src = PRO_CLEANUP.read_text()
        erase |= keys_in(pro_src, "declare_erase_map")
        retain |= keys_in(pro_src, "declare_retain_map")

    return erase, retain


def exported() -> tuple[set[str], set[str]]:
    """Tables the EXPORTER covers, and tables it deliberately excludes (with a reason).

    Erasure was complete and export was 25 tables behind. Every one of those was a table we
    happily DELETE on request while being unable to SHOW it on request — Article 17 satisfied,
    Article 15 not, which is a strange pair of failures to have. We knew exactly where the
    member's data was, and used that knowledge only to destroy it.

    The exporter now DERIVES its sections from erase_map(), so a new table joins the export
    automatically. This half of the gate exists for the two ways that can still be defeated:
    a table hand-listed as already-exported when it is not, and an exclusion added without a
    reason (which is "nobody decided" wearing the costume of "we decided not to").
    """
    src = PRIVACY.read_text()

    def keys_in(func: str) -> set[str]:
        m = re.search(rf"function {func}\(.*?\).*?\n\t}}", src, re.S)
        return set(re.findall(r"'(bn_\w+)'", m.group(0))) if m else set()

    by_hand = keys_in("tables_exported_by_hand")
    exclusions = keys_in("export_exclusions")

    # Pro contributes its exclusions through the filter, in whatever class backs it.
    if PRO_ROOT.exists():
        for php in PRO_ROOT.glob("includes/**/*.php"):
            text = php.read_text(errors="ignore")
            if "buddynext_privacy_export_exclusions" in text:
                exclusions |= set(re.findall(r"'(bn_\w+)'\s*=>\s*'", text))

    return by_hand, exclusions


def reasons_are_real() -> list[str]:
    """An exclusion with an empty reason is not a decision. Name any that are blank."""
    src = PRIVACY.read_text()
    m = re.search(r"function export_exclusions\(.*?\).*?\n\t}", src, re.S)
    if not m:
        return []
    return [t for t, why in re.findall(r"'(bn_\w+)'\s*=>\s*'([^']*)'", m.group(0)) if len(why.strip()) < 20]


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
    # makes residue() query a table that isn't there. Read BOTH schemas, or every Pro
    # table Pro correctly registered would be reported as a phantom.
    schema = set(tables)
    for installer in (INSTALLER, PRO_INSTALLER):
        if installer.exists():
            schema |= set(re.findall(r"CREATE TABLE \{\$p\}(bn_\w+) \(", installer.read_text()))
    phantom = sorted((erase | retain) - schema)

    # ── The EXPORT half ──────────────────────────────────────────────────────────
    # Erasure being complete says nothing about export. It was 25 tables behind.
    #
    # FIRST: does the export machinery exist at all? Without this, the whole half is
    # VACUOUS — delete every export method and the checks below still pass, because an
    # absent mechanism has nothing to disagree with. I wrote it that way once and caught it
    # by mutation-testing my own gate. A check that cannot fail is not a check.
    privacy_src = PRIVACY.read_text() if PRIVACY.exists() else ""
    missing_machinery = [
        fn
        for fn in ("derived_sections", "tables_exported_by_hand", "export_exclusions", "export_table")
        if f"function {fn}(" not in privacy_src
    ]
    if missing_machinery:
        print(
            "\n✗ THE EXPORT IS NO LONGER DERIVED FROM THE ERASE REGISTRY.\n\n"
            "    PrivacyTools is missing: " + ", ".join(missing_machinery) + "\n\n"
            "  Export coverage is derived from erase_map() precisely so a new table cannot be\n"
            "  erasable-but-not-exportable. Remove that and export goes back to being a second\n"
            "  hand-written list that nobody remembers to update. It was 25 tables behind.",
            file=sys.stderr,
        )
        return 1

    by_hand, exclusions = exported()
    # Everything on the erase registry is exported UNLESS it is excluded. The exporter
    # derives its sections from erase_map(), so "covered" is the default and this checks
    # the two ways that can be defeated.
    # The derived export stream reaches an erase_map entry through its `where` clause. An
    # entry without one is erasable but NOT exportable — which is the exact asymmetry this
    # half of the gate exists to catch. (Checking "is it in the export list" would be
    # vacuous: the export list IS erase_map. A check that cannot fail is not a check.)
    unexported = sorted(t for t in whereless_erase_entries() if t not in by_hand and t not in exclusions)
    blank_reasons = reasons_are_real()

    print(f"user-keyed tables: {len(tables)}   erase: {len(erase)}   retain: {len(retain)}")
    print(f"export: derived from erase_map   by-hand: {len(by_hand)}   excluded: {len(exclusions)}")

    if not unregistered and not phantom and not unexported and not blank_reasons:
        print("✓ every user-keyed table is registered for erasure or explicitly retained")
        print("✓ every erased table is exported, or excluded with a stated reason")
        return 0

    if unexported:
        print("\n✗ ERASED but NOT EXPORTED — we delete this on request but cannot show it on request:\n", file=sys.stderr)
        for table in unexported:
            print(f"    {table}", file=sys.stderr)
        print(
            "\n  Article 17 (erasure) is satisfied and Article 15 (access) is not.\n"
            "  Either let it ride the derived export stream, or add it to\n"
            "  PrivacyTools::export_exclusions() WITH A REASON.",
            file=sys.stderr,
        )

    if blank_reasons:
        print("\n✗ EXCLUDED FROM EXPORT WITHOUT A REAL REASON:\n", file=sys.stderr)
        for table in blank_reasons:
            print(f"    {table}", file=sys.stderr)
        print(
            "\n  An exclusion with no reason is not a decision that was made.\n"
            "  It is a decision that was never made, wearing the costume of one.",
            file=sys.stderr,
        )

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
