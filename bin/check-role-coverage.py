#!/usr/bin/env python3
"""Role-aware journey coverage: is every functionality walked as admin AND member?

WHY THIS EXISTS
`check-capability-journeys.py` answers "does this capability have a journey?" -
one dimension. It cannot answer the question a buyer actually checks first: "can
I do this AS an admin (configure it) and AS a member (use it)?" A feature that is
only ever walked by the default admin fixture is untested for the member who
lives in it, and that is exactly where browser-level bugs, UX inconsistencies and
UX gaps hide - a control that works at the admin's desk and is broken, missing,
or misaligned for the member on a phone.

THE MODEL (two dimensions: capability x role)
  * CAPABILITIES.md   - the census of promises (buyer language). ESSENTIAL rows
                        (daily-use, matched by the same regex the QA pack uses)
                        are the release gate. Each capability NEEDS a set of
                        roles, inferred from the promise (see needed_roles()).
  * JOURNEYS.md       - journey -> Covers: cap-<slug>  (which promise it proves).
  * *.spec.ts docblock- the spec declares the journey id(s) it proves (J-NNN,
                        already required by check-journey-tags.py) AND, now, the
                        role(s) it walks:  `* Roles: admin, member`.
                        Roles cannot be parsed from loginAs(...) because specs
                        pass username VARIABLES, so the role is DECLARED, once,
                        where the spec already declares its journey id.

  A (capability, role) cell is COVERED when an implemented journey Covers: that
  capability and at least one spec proving that journey declares that role.

OUTPUT
  Default: report the ESSENTIAL capability x {admin, member} gaps (and, per role,
  which are pinned vs still need a spec's Roles: line). Exit 0 - a report.
  --gate : exit 1 if any ESSENTIAL capability is missing a running journey for
           admin or for member. This is the release gate.

Roles vocabulary: admin, member, moderator, anon.
"""
import re
import sys
from pathlib import Path

ROLES = ('admin', 'member', 'moderator', 'anon')

# ESSENTIAL: the daily-use promises. Same spirit as check-capability-journeys.py -
# the paths 70-80% of members and every owner touch. Kept in sync deliberately.
ESSENTIAL = re.compile(
    r'feed|post|comment|repl|react|bookmark|reshare|share|poll|'
    r'space|join|member|profile|follow|connect|notification|'
    r'search|discover|explore|upload|image|video|photo|album|'
    r'edit|delete|draft|mention|hashtag|online|directory|'
    r'register|log ?in|sign ?up|onboard|moderat|report|space|setting',
    re.I,
)


# Promises that are real, but NOT a browser usability journey - they need a
# different kind of proof, so they are out of the admin+member gate rather than
# faked with a shallow walk. Matched as a substring of the capability question.
GATE_EXCLUDE = (
    'stay fast at 100k',          # load/scale test, not a Playwright walk
    'send mobile push',           # device delivery, not the browser
    'connect a native mobile app',  # app pairing/token, not a browser journey
    'rank the feed with ai',      # ranking QUALITY is non-deterministic (the toggle is tested elsewhere)
)


def slug(text):
    return re.sub(r'[^a-z0-9]+', '-', text.lower()).strip('-')


def needed_roles(question):
    """The roles a promise must be walked in, inferred from the buyer's words.

    Most configurable features have BOTH an admin surface (turn it on, shape it)
    and a member surface (use it), so the default for an ESSENTIAL promise is
    {admin, member}. Pure front-of-house use adds nothing; sign-in is anon+member;
    moderation adds moderator. The inference is the bootstrap - narrow it per row
    later if a promise genuinely serves one role.
    """
    q = question.lower()
    roles = set()
    if re.search(r'admin|setting|menu|email|slug|white ?label|licen|webhook|'
                 r'banned|rate.?limit|configure|enable|manage|owner|dashboard', q):
        roles.add('admin')
    if re.search(r'register|log ?in|sign ?up|sign in|two.?factor|2fa|password|'
                 r'invite|onboard|guest|visitor|anonymous|logged.?out', q):
        roles.update(('anon', 'member'))
    if re.search(r'moderat|report|suspend|shadow|appeal|ban', q):
        roles.update(('moderator', 'admin'))
    # Anything a member does day to day.
    if re.search(r'post|comment|react|bookmark|share|poll|join|follow|connect|'
                 r'profile|feed|space|message|notification|search|explore|'
                 r'upload|photo|album|mention|hashtag|kudos|badge|point', q):
        roles.add('member')
    if not roles:
        roles.add('member')
    # An ESSENTIAL, configurable promise is walked as both by default.
    if ESSENTIAL.search(question) and 'member' in roles and re.search(
            r'setting|enable|configure|manage|admin|owner|palette|type|field|'
            r'label|category|role|plan|gate|feature|space|feed|moderat', q):
        roles.add('admin')
    return roles


def read_capabilities(path):
    caps = []
    if not path.exists():
        return caps
    for line in path.read_text(encoding='utf-8').splitlines():
        if not line.startswith('|'):
            continue
        cells = [c.strip() for c in line.strip('|').split('|')]
        if len(cells) < 2:
            continue
        question, status = cells[0], cells[1]
        if not question or question.lower().startswith('can it'):
            continue
        if set(question) <= set('-: '):
            continue
        if not re.match(r'^(YES|PARTIAL)', status, re.I):  # shipped promises only
            continue
        caps.append(question)
    return caps


ROLES_RE = re.compile(r'Roles:\s*([a-z, ]+)', re.I)
COVERS_RE = re.compile(r'Covers:\s*([a-z0-9, \-]+)', re.I)


def read_specs(e2e_dirs):
    """spec files -> {cap-slug: set(roles)} from each spec's leading docblock.

    A spec is the implementation, so a spec that exists and declares
    `Covers: cap-x` and `Roles: admin, member` is proof cap-x is walked as both.
    Both live in the spec docblock (spec-local, per the repo's declare-locally
    rule) so pinning is parallelizable per test folder with no shared-file churn.
    """
    covered = {}
    tagged_specs = 0
    for d in e2e_dirs:
        if not d.exists():
            continue
        for spec in d.rglob('*.spec.ts'):
            text = spec.read_text(encoding='utf-8', errors='ignore')
            # The whole leading /** ... */ docblock, however long - not a fixed
            # byte window. A Covers:/Roles: block placed past a 2500-char cutoff
            # read as a false GAP even though the pin was there (hero-actions).
            end = text.find('*/')
            head = text[:end + 2] if end != -1 else text[:4000]
            rm, cm = ROLES_RE.search(head), COVERS_RE.search(head)
            if not (rm and cm):
                continue
            roles = {r.strip() for r in rm.group(1).split(',') if r.strip() in ROLES}
            caps = {c.strip() for c in cm.group(1).split(',') if c.strip().startswith('cap-')}
            if roles and caps:
                tagged_specs += 1
            for cap in caps:
                covered.setdefault(cap, set()).update(roles)
    return covered, tagged_specs


def main():
    root = Path(__file__).resolve().parent.parent
    pro = root.parent / 'buddynext-pro'
    gate = '--gate' in sys.argv

    # cap-slug -> set(roles a real spec walks it in), read from spec docblocks.
    covered, tagged = read_specs([root / 'tests' / 'e2e', pro / 'tests' / 'e2e'])

    caps = read_capabilities(root / 'CAPABILITIES.md') + \
        read_capabilities(pro / 'CAPABILITIES.md')

    essential = [q for q in caps if ESSENTIAL.search(q)
                 and not any(x in q.lower() for x in GATE_EXCLUDE)]
    gaps = []          # (question, [missing roles])
    partial = []       # (question, have, need)
    for q in essential:
        cap_id = 'cap-' + slug(q)[:60]
        need = needed_roles(q) & {'admin', 'member'}  # gate scope
        have = covered.get(cap_id, set())
        missing = need - have
        if missing == need:
            gaps.append((q, sorted(need)))
        elif missing:
            partial.append((q, sorted(have & need), sorted(missing)))

    print(f'=== ESSENTIAL capability x role coverage (gate scope: admin + member) ===')
    print(f'essential promises: {len(essential)}')
    print(f'fully covered     : {len(essential) - len(gaps) - len(partial)}')
    print(f'partial (a role missing): {len(partial)}')
    print(f'no running journey in either role: {len(gaps)}')
    print(f'specs pinned with Covers: + Roles:: {tagged}\n')

    for q, miss in gaps:
        print(f'  GAP  [{"+".join(miss)}]  {q}')
    for q, have, miss in partial:
        print(f'  PART [have: {",".join(have) or "-"} | need: {",".join(miss)}]  {q}')

    total = len(gaps) + len(partial)
    if gate:
        if total:
            print(f'\nFAIL: {total} ESSENTIAL promise(s) not walked as both admin and member.')
            return 1
        print('\nPASS: every ESSENTIAL promise is walked as admin and member.')
        return 0
    print(f'\nreport only. Run with --gate to enforce. {total} essential gap(s) to close.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
