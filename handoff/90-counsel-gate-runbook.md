# GenD Match — Counsel-Gate Operator Runbook (`GS_COLLAB_MARKET_PUBLIC`)

**Milestone:** v12.0 — GenD Match
**Phase:** 90 (counsel-gate hardening + UAT) — milestone finale
**Companion UAT:** `handoff/90-uat-ship-gate.php` (the consolidated master ship-gate)
**Audience:** operators + counsel reviewers

---

## 1. TL;DR — the flag is false by default and MUST stay false

`GS_COLLAB_MARKET_PUBLIC` is **false by default and MUST stay false.** The entire Tier B money
surface — **market, staking, portfolio, resolution, payout, and the container market mirror** — is
**DARK**: the money REST routes return **404 (route-ABSENT, not 403)** and the DOM surfaces are
**absent** (not CSS-hidden). Nothing about the staking/market layer is reachable by any member,
logged-out visitor, or container site while the flag is off.

> **Do NOT flip this flag on** without the signed counsel memo described in §3. Flipping it on
> without that sign-off exposes a real-value retail prediction market that has **no legal pathway
> in Canada** (see §3). This is a hard, non-discretionary gate.

The public **Tier A** layer (swipe deck / match / intro thread / collaboration-contract escalation)
ships live and is **independent** of this flag — it keeps working whether the flag is on or off.

---

## 2. What the flag gates

`GS_COLLAB_MARKET_PUBLIC` is a **require-time `define()`** in `gend-society.php` (approx. lines
27–29), **default false**, wrapped in a `! defined()` guard so `wp-config.php` can pre-set it. Because
it is read at plugin-require time, it **cannot be toggled in-process** — every gated surface reads
the constant when it registers.

### Tier B — DARK under flag-off (this is what the flag gates)

**The 5 money REST routes (all route-ABSENT → 404 when off):**

- `gs/v1/market/{id}` — market read (Phase 86)
- `gs/v1/market/{id}/quote` — LMSR quote (Phase 86)
- `gs/v1/market/{id}/bet` — place bet / escrow (Phase 87)
- `gs/v1/portfolio` — own-member positions (Phase 87)
- `gs/v1/markets?scope=hub` — hub-wide market list (Phase 89)

**The DOM / UI surfaces (all DOM-ABSENT when off):**

- the **Collaborations → Markets** member-nav tab (Phase 87 `member-markets.php`)
- the **container market mirror** (Phase 89 `class-collab-market-mirror.php` renders nothing)
- every market/portfolio asset enqueue (transitively dark — enqueued only inside the
  never-registered Markets screen callback)

### Tier A — LIVE regardless of the flag (NOT gated)

- swipe deck + rule-based matching + mutual match + intro thread (Phases 82/83)
- match → collaboration-contract escalation (Phase 84 — public by the Phase-84 decision; hub-only;
  **not** `GS_COLLAB_MARKET_PUBLIC`-gated)

### Flag-INDEPENDENT backend (correctly NOT gated — see §5)

- the Phase-85 resolver (records contract outcomes) and the Phase-88 settlement engine
  (resolve / pay / VOID) run **regardless** of the flag. They have **no** user-facing route or DOM.
  This is deliberate money-safety, not a leak.

---

## 3. Why it must stay off — the regulatory posture

Reproduced verbatim from the v12.0 milestone `REQUIREMENTS-v12.0.md` "Regulatory constraint
(load-bearing, HIGH-confidence research finding)":

> Canada currently offers **no independent legal pathway** for a real-value retail prediction
> market: retail binary options are banned (CSA, 2017); Ontario fined + permanently banned Polymarket
> in 2025 ($200k) for this exact product shape; independent markets can also be treated as illegal
> lotteries under Criminal Code ss.202/206; and the narrow 2026 CIRO event-contract pathway covers
> only economic-indicator/climate contracts via regulated venues — not "will this collaboration
> succeed." **Implication baked into scope:** every Tier B REQ ships built-but-fully-dark behind
> `GS_COLLAB_MARKET_PUBLIC`; the flag flip is gated on a Canadian securities **and** gaming counsel
> memo that may never clear. The design keeps the eventual counsel ask as narrow as possible
> (contract-linked resolution only, no secondary transfer, no non-collab markets, no public P/L).

**The flag is NOT flippable** until a **signed Canadian securities AND gaming counsel memo** clears,
at minimum, all three of these questions:

1. **Binary-option question** — does the LMSR contract-outcome market fall under the 2017 CSA retail
   binary-options ban (the same product shape Ontario permanently banned Polymarket for in 2025)?
2. **Illegal-lottery question** — could the market be treated as an illegal lottery under
   **Criminal Code ss.202/206**?
3. **Contract-linked-resolution question** — does resolving strictly against a signed collaboration
   contract's on-platform outcome (no secondary transfer, no non-collab markets, no public P/L)
   change either analysis?

**Research indicates this may never clear.** Until a signed memo answering all three arrives, treat
the flag as permanently off. There is no operator discretion here.

---

## 4. Ordered pre-flip money gates (necessary, NOT sufficient)

Even *if* counsel ever signs off (§3), the following UAT gates must **all** print
`=== SUCCESS ===` **before** the flag could be considered. These prove the money engine is correct;
they do **not** replace the counsel memo — the memo (§3) is the hard gate.

Run in this order (as `www-data`, on the hub, after a full gend-society deploy):

- [ ] **(a)** `wp eval-file wp-content/plugins/gend-society/handoff/86-uat-lmsr-invariants.php` → `=== SUCCESS ===`
      *(LMSR crown / escrow-invariant — highest value)*
- [ ] **(b)** `wp eval-file wp-content/plugins/gend-society/handoff/87-uat-bet-escrow.php` → `=== SUCCESS ===`
      *(bet + escrow + no double-debit / no-mint)*
- [ ] **(c)** `wp eval-file wp-content/plugins/gend-society/handoff/88-uat-resolution-payout.php` → `=== SUCCESS ===`
      *(resolution + payout + VOID / funds never stranded)*
- [ ] **(d)** `wp eval-file wp-content/plugins/gend-society/handoff/90-uat-ship-gate.php` → `=== SUCCESS ===`
      *(the consolidated master gate — proves Tier B dark, no P/L leak, Tier A live, and re-runs all
      10 prior batteries 82–89 green)*

> **Order matters:** 86 (LMSR crown) → 87 (bet/escrow) → 88 (resolution/payout) → 90 (master gate).
> The 90 master gate is the definitive "GenD Match is ship-ready" proof, but it certifies the code
> only — **the counsel memo (§3) is still required before the flag can flip.**

---

## 5. Money-safety guarantee — flag-off-safe settlement

The **Phase-85 resolver** and the **Phase-88 settlement engine** (`resolve()` /
`pay_market_batch()` / `settle()`) are **flag-INDEPENDENT by design.**

This means: if markets ever exist with real bettor stakes and the flag is **later turned back OFF**,
the settlement engine **still resolves and pays** — **bettors' DGEN is never stranded**, and the
engine keeps bettors whole (it resolves, pays, or VOIDs every open position). The flag gates the
market **surface** and the **create-market caller**, **not** the settlement engine. Turning the flag
off closes the door to new bets and hides the UI; it never traps money already staked.

---

## 6. ON-smoke invocation — DEVELOPER / COUNSEL-REVIEW ONLY, never in production

Because `GS_COLLAB_MARKET_PUBLIC` is a require-time constant that **cannot be toggled in-process**,
the master UAT's flag-ON branch (which asserts the Tier B routes **DO** register when the flag is on
— i.e. the gate is a real toggle, not permanently dead code) is exercised by pre-defining the
constant **before** plugins load, via `--exec`:

```
wp eval-file wp-content/plugins/gend-society/handoff/90-uat-ship-gate.php --exec='define("GS_COLLAB_MARKET_PUBLIC",true);'
```

**This is DEVELOPER / COUNSEL-REVIEW ONLY.** It runs the flag-ON smoke branch to confirm the routes
register when the flag is on. It moves **no** real money, but it lights up the Tier B surface for the
duration of that CLI run. **NEVER run this in production**, and **never** set
`define('GS_COLLAB_MARKET_PUBLIC', true)` in a production `wp-config.php`, until the counsel memo (§3)
has cleared.

---

## 7. Deploy note (standing house checklist — money-free)

This file is **pure-additive** — a handoff doc, no class or entrypoint change.

- Deploy via `MSYS_NO_PATHCONV=1 kubectl cp` onto the live PVC, alongside `90-uat-ship-gate.php`:
  ```
  MSYS_NO_PATHCONV=1 kubectl cp handoff/90-counsel-gate-runbook.md <pod>:/var/www/html/wp-content/plugins/gend-society/handoff/90-counsel-gate-runbook.md
  ```
- No class / entrypoint change → **no classes-before-entrypoint ordering concern** (nothing is
  `require_once`'d).
- After deploy, confirm the plugin is **NOT** in `recently_activated` (i.e. it did not fatal):
  ```
  wp option get recently_activated
  ```
- No money moves. The DGEN peg is untouched. This runbook and its companion UAT are audit + proof +
  docs only.

---

*Phase 90 — counsel-gate hardening + UAT. `GS_COLLAB_MARKET_PUBLIC` stays false until a signed
Canadian securities AND gaming counsel memo clears the binary-option, illegal-lottery, and
contract-linked-resolution questions.*
