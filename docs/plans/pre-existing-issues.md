# Pre-Existing Issues Log (found during scale-readiness work)

Issues discovered while implementing the scale-readiness change index that were **not
introduced by that work**. Logged here so nothing is left behind. Each is tracked to
resolution (fix + test) or an explicit decision. Cross-repo (free + pro).

| ID | Repo | Issue | Severity | Status |
|---|---|---|---|---|
| PE-1 | pro | `ProfileLabelInjectorTest` calls undefined `inject_labels()` — class was refactored to `rest_labels`/`hero_badges_filter`/`byline_meta_filter`; 6 tests (4 err, 2 fail). Stale test, not a product bug. | med (broken test masks coverage) | **FIXED** — test realigned to the real API. |
| PE-2 | free | `Admin/AdminHub.php` — PHPStan L5 `offsetAccess.notFound` (full-project `phpstan analyse` fails). Pre-existing; present with all scale-readiness changes stashed. | low (CI noise; not a runtime bug) | **FIXED** (`305fc8fb`) — added `icon?:string` to the section shape; full-project PHPStan now `[OK]`. |
| PE-3 | pro | Analytics tests "User followed action" / "Member growth math" / "Get viewers latest" fail in the **pro-only** test DB. "User followed"/"Get viewers" need FREE tables the Pro bootstrap doesn't create; **"Member growth math" is date-drift** — it seeds hardcoded May-2026 events and asserts they fall inside a rolling 30-day window, which stopped holding once "today" passed ~2026-06-17. Re-confirmed pre-existing on 2026-06-25 by stashing the C11 change and re-running. | none (env/date artifact) | WONT-FIX (env) — documented; combo suite + a fixed clock would exercise it. |
| PE-4 | both | Pre-commit hook inactive (`git config core.hooksPath` unset) → `bin/check.sh` does **not** run on commit. Commits rely on manual gate runs. | low (process) | OPEN — `git config core.hooksPath .githooks` to enable. |
| PE-5 | both | phpcs quirk: a **specific-sniff** `phpcs:enable` list defeats downstream next-line `phpcs:ignore` for the same sniff. Use **bare** `// phpcs:disable` / `// phpcs:enable` around migration queries. | low (tooling) | DOCUMENTED — convention recorded; applied in all new migration code. |
| PE-6 | free | Legacy `tests/Feed/PollServiceTest.php` carries 18 pre-existing WPCS errors (test methods lack docblocks). | low (test style) | OPEN — backfill docblocks; new C2 tests kept in a separate clean file meanwhile. |

## Notes per item

- **PE-1** — `ProfileLabelInjector` now exposes `rest_labels( array $labels, int $user_id )`
  on `buddynext_profile_labels` (returns the user's label objects), `hero_badges_filter`
  on `buddynext_profile_hero_badges_html`, and `byline_meta_filter` on
  `buddynext_post_byline_meta_html`. The stale test referenced the pre-refactor
  `inject_labels` on `buddynext_profile_extra_data`. Fix = realign the test to the
  current filters; behaviour is unchanged.
- **PE-2** — surfaces only via full-project PHPStan (what `bin/check.sh` runs). Per-file
  analysis of changed files is clean, so it never blocked a scale-readiness commit, but it
  must be fixed for `check.sh` to pass cleanly. Fix is a guarded array access in AdminHub.
- **PE-4** — explains why scale-readiness commits landed without the hook firing; not a
  defect, but enabling the hook (or running `bin/check.sh --staged` pre-commit) is the
  intended workflow.
