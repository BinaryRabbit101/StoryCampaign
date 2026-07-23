# StoryCampaign — Living-World Idle RPG

Turn-based idle RPG: scheduled Claude CLI runs narrate turn resolutions into
chapters. Players act through one structured form per turn (contextual action
cards, never free text); the engine owns all mechanics and outcomes; Claude
owns narration. Every chapter is persisted and compiles into a keepsake book
at campaign end. World evolution (`game:evolve`, `WorldEvolver`, Chronicle)
exists in code but is deliberately unscheduled — manual runs only.

Full design rationale: `design/DESIGN_BIBLE.md` (guardrails) and the original
spec decisions embedded as comments in the code.

## Stack

Laravel 13 + Inertia v3 + Vue 3 + TypeScript + Tailwind 4 (laravel/vue-starter-kit,
same as sibling projects). Fortify auth, Wayfinder, PHPUnit 12, SQLite, PWA
(manifest + `public/sw.js`), web push via `laravel-notification-channels/webpush`.

- Dev: `npm run dev` + `php artisan serve` (or Herd). Build: `npm run build`.
- Tests: `php artisan test`. Format: `vendor/bin/pint --dirty`.
- Scheduler (prod): `php artisan schedule:work` — runs `game:resolve-due`
  every 5 min. `game:evolve` is not scheduled (only ever run manually).
- `GAME_TURN_CADENCE_MINUTES=0` in `.env` resolves turns inline on submit
  (dev convenience). Production default is 30.
- Claude CLI config: `CLAUDE_BINARY` / `CLAUDE_MODEL` in `.env`; VAPID keys
  already generated (`php artisan webpush:vapid` — needs `OPENSSL_CONF` set
  to a real openssl.cnf on Windows, e.g. Git's).

## Architecture — the load-bearing split

**Engine decides WHETHER; Claude decides HOW it's told.** Claude never
adjudicates legality or outcomes — it is only ever handed resolved facts.

- `app/Game/` — mechanics. `Capability` (the shared verb vocabulary),
  `CapabilityGroup` (slot scoping), `TurnSlot` (pre/main/post), `BranchTrigger`
  (the 8 vignette stop conditions), `Meters` (tempo regens in real time across
  the idle wait; health only via recovery beats).
- `app/Game/Engine/` — `CardComposer` (the intersection engine: capabilities ×
  affordances × constraints → cards, with graceful degradation and composed
  cards), `TurnResolver` (slot chain with legality-driven abort, seeded `Dice`,
  enemy reaction, branch triggers, next-turn generation), `ActionCard`,
  `BeatOutcome`.
- `app/Services/Claude/` — `ClaudeCli` (stateless CLI runs), `Narrator`
  (resolution → chapter + push), `WorldEvolver` (budgeted evolution + Chronicle),
  `Interviewer` (creation/growth interviews).
- `app/Services/` — `CapabilityClamp` (bible bounds + constraint re-coupling),
  `TurnStarter`, `BookCompiler` (compilation, not generation; coda on early end).
- Affordances are JSON tags on `scene_features` (e.g.
  `{"reachable_via":["climb","swing"],"height":11}`). Zone-level features
  (`scene_id` null) act as templates available to every scene in the zone;
  zone-level actors are spawn templates.

## Invariants (do not break)

- Never resolve a card the engine didn't offer: submissions reference card ids
  stored on the turn; `PlayController::validateChoice` + `TurnResolver` both
  enforce this.
- Every turn stop must offer ≥ 2 legal cards (generic fallbacks guarantee it).
- Improvise resolves against base stats with no bonus — never better than a
  real enumerated option.
- The optional intent text colors narration only; it must never reach the
  mechanics path.
- Chapters are append-only, persisted before any push is sent; the book is a
  compilation (the only new generation at campaign end is the optional coda
  and title flourish).
- Evolution runs are clamped by `config/game.php` bounds no matter what the
  LLM proposes, and log to `evolution_runs` (append-only) for coherence.
- No mechanics language in any narration prompt output (no dice, cards, meters).

## Widget

`GET /api/widget/status?token=…` (token from `POST /widget/token`) serves the
Scriptable iPhone widget — see `scriptable/StoryCampaignWidget.js`. Snapshot +
deep link only; iOS controls refresh timing.
