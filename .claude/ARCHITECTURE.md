# baconfy/indicators — Architecture

> Framework-agnostic Composer package of technical analysis indicators over exact decimal math.
> This document is the source of truth for implementation. Decisions here take precedence over the implementing agent's preferences.

---

## 1. Goal

Provide technical indicators (SMA, EMA, RSI, ATR, ...) as pure, deterministic decimal math over candle series. Consumers feed candles in, get an index-aligned series out. No exchange knowledge, no HTTP, no framework.

v0 scope: **SMA, EMA, RSI, ATR** — each validated against external golden references.

## 2. Package invariants (non-negotiable)

1. **Framework-free core.** No `use Illuminate\*`, no facades, no `env()`/`config()` outside the bridge. The core must work in a standalone PHP script with plain `new`.
2. **Zero cross-package dependency.** This package does not depend on `baconfy/exchanges` or any HTTP/PSR client — it owns its own `Candle`. Translating between packages is the consuming app's job.
3. **Determinism.** `compute()` is a pure function: same candles + same parameters → same output. No I/O, no clock reads, no randomness, no state kept between calls.
4. **Single math policy.** Every division in every indicator uses the package-wide scale and rounding mode (D5). No indicator invents its own precision.
5. **Public API governed by semver.** Contract, DTOs, exceptions and indicator parameters are public API. Breaking change = major.
6. **No install-time side effects.** Nothing runs on `composer install`.
7. **Thin bridge.** The Laravel integration is a single optional ServiceProvider registering the manager. If it disappears, the core remains intact.

## 3. Layers

```
Consumer (app, script, another package)
        │  knows only ↓
┌───────────────────────────────────────────┐
│ Contracts/Indicator         (the door)    │  list<Candle> → list<BigDecimal|null>
├───────────────────────────────────────────┤
│ IndicatorManager            (the selector)│  name ('rsi') + parameters → Indicator
├───────────────────────────────────────────┤
│ Indicators/Sma|Ema|Rsi|Atr  (the math)    │  final readonly, parameters = identity
├───────────────────────────────────────────┤
│ Data/ Exceptions/ Math/     (the values)  │  pure, immutable PHP
└───────────────────────────────────────────┘
```

## 4. Directory structure

```
baconfy/indicators/
├── composer.json
├── src/
│   ├── Contracts/
│   │   └── Indicator.php
│   ├── Data/
│   │   └── Candle.php
│   ├── Exceptions/
│   │   ├── IndicatorException.php         # package marker interface
│   │   ├── InvalidParameterException.php  # bad constructor parameter (period < 1, unknown name)
│   │   ├── InvalidIndicatorException.php  # registered class does not implement Indicator
│   │   └── UnknownIndicatorException.php  # manager doesn't know the requested name
│   ├── IndicatorManager.php
│   ├── Indicators/
│   │   ├── Sma.php
│   │   ├── Ema.php
│   │   ├── Rsi.php
│   │   └── Atr.php
│   ├── Math/
│   │   └── Decimal.php                    # @internal — the single scale/rounding policy
│   └── Bridge/
│       └── Laravel/
│           └── IndicatorsServiceProvider.php
└── tests/
    ├── Unit/          # Candle, exceptions, manager, parameter validation
    ├── Feature/       # each indicator against golden fixtures
    └── Fixtures/      # real candles + externally verified expected values
```

Root namespace: `Baconfy\Indicators\`.

## 5. Architecture decisions

### D1 — Uniform contract: candles in, aligned series out
```php
interface Indicator
{
    /**
     * @param list<Candle> $candles ordered oldest to newest
     * @return list<BigDecimal|null> same length as $candles, index-aligned;
     *                               null where the indicator does not exist yet (warm-up)
     */
    public function compute(array $candles): array;
}
```
Every indicator has the same signature regardless of which candle fields it reads (EMA reads close; ATR reads high/low/close). This lets the consuming bot iterate configured indicators generically. Input order is the consumer's responsibility and matches the `baconfy/exchanges` contract guarantee (oldest → newest).

### D2 — Own `Candle`, deliberately shaped like the exchanges one
```php
final readonly class Candle
{
    public function __construct(
        public DateTimeImmutable $openTime,
        public BigDecimal $open,
        public BigDecimal $high,
        public BigDecimal $low,
        public BigDecimal $close,
        public BigDecimal $volume,
    ) {}
}
```
Identical shape to `Baconfy\Exchanges\Data\Candle` **by design, not by dependency** — the app translates one into the other with a trivial map. Carrying whole candles (instead of parallel `$highs/$lows/$closes` arrays) makes misalignment structurally impossible.

### D3 — Warm-up is `null`, insufficient data is not an error
- Output is always the same length as input, index-aligned: `$out[$i]` belongs to `$candles[$i]`.
- Positions where the indicator mathematically does not exist yet hold `null` (an SMA(20) has no value at indices 0–18).
- Fewer candles than the warm-up requires → a series of all `null`s. **Never an exception** — "not enough data yet" is a normal state of a running bot, not a failure.
- Empty input → empty output.

### D4 — Parameters are identity, validated at construction
```php
final readonly class Ema implements Indicator
{
    public function __construct(public int $period = 9)
    {
        if ($period < 1) {
            throw new InvalidParameterException(
                sprintf('%s requires a period >= 1, %d given.', self::class, $period),
            );
        }
    }
}
```
An `Ema(9)` and an `Ema(21)` are different indicators, not different calls — so the period lives in the constructor, promoted, `readonly`, validated eagerly with a typed exception. Exception messages name the offending class/value as the subject (lesson from `baconfy/exchanges`: the message must point at the culprit).

### D5 — Single math policy: scale 12, HALF_UP
```php
/** @internal */
final class Decimal
{
    public const SCALE = 12;

    public static function divide(BigDecimal $a, BigDecimal|int $b): BigDecimal
    {
        return $a->dividedBy($b, self::SCALE, RoundingMode::HALF_UP);
    }
}
```
With `BigDecimal`, division **requires** a scale decision (`2/(period+1)` is a repeating decimal). This is a package-wide law, not a per-indicator choice: every division in every indicator goes through this policy. Additions/multiplications stay exact (Brick grows scale as needed); only divisions round. Rationale: two indicators computing "the same" value must never diverge in the 10th digit because each picked its own precision. `Decimal` is `@internal` — not public API.

**Recurrence normalization.** A recurrent state (e.g. the running EMA) is re-quantized to SCALE (HALF_UP) at every step, via the policy. Without this, recurrences that never divide grow their scale unboundedly (+12 digits per bar — O(n²) digit-work over a long series), silently breaking D10. The per-step rounding is < 5e-13 and invisible at any reference precision; golden fixtures are unaffected by construction.

### D6 — Per-indicator math spec (reference-matching conventions)
Implementations must match the conventions below — they are the TradingView/Wilder defaults the golden fixtures are generated against. Deviating "equivalent" formulas fail the fixtures by construction.

**SMA(p)** — arithmetic mean of the last `p` closes. `null` at indices `0..p-2`; first value at `p-1`.

**EMA(p)** — multiplier `k = 2/(p+1)` (policy division). Seed: value at index `p-1` is the SMA of the first `p` closes. Before that, `null`. After: `ema[i] = (close[i] - ema[i-1]) * k + ema[i-1]`.

**RSI(p)** — Wilder. Changes `close[i] - close[i-1]` split into gains/losses. First `avgGain`/`avgLoss`: simple mean of the first `p` changes → first RSI at index `p`; `null` at `0..p-1`. After: Wilder smoothing `avg = (prevAvg*(p-1) + current)/p`. `RSI = 100 - 100/(1+RS)` with `RS = avgGain/avgLoss`. Edge cases, defined not accidental: `avgLoss == 0` → RSI is exactly `100` (no division); `avgGain == 0` → `0`.

**ATR(p)** — `TR[i] = max(high-low, |high-prevClose|, |low-prevClose|)`, first TR at index 1 (needs a previous candle). Seed: value at index `p` is the simple mean of the first `p` TRs; `null` at `0..p-1`. After: Wilder smoothing.

All divisions above go through the D5 policy.

### D7 — `IndicatorManager`: name + parameters → instance
```php
final class IndicatorManager
{
    /** @param array<string, class-string<Indicator|MultiIndicator>> $map merged over the built-ins */
    public function __construct(array $map = []);

    public function register(string $name, string $indicatorClass): void;
    /** @param array<string, int|string|float> $parameters named-argument map, e.g. ['period' => 14] */
    public function make(string $name, array $parameters = []): Indicator|MultiIndicator;
    /** @return list<string> */
    public function available(): array;
}
```
- The built-ins are **not** a literal in this class: each indicator declares its own public name by attribute, discovered once per process (D13). The names themselves are unchanged and remain public API.
- The name string is the key the consuming app persists in its bot config — same boundary role as `exchange_connections.driver` in the exchanges package.
- `make()` spreads `$parameters` as **named arguments**: `new $class(...$parameters)`. An unknown parameter name raises a PHP `Error`; the manager catches it and rethrows `InvalidParameterException` — no raw `Error` escapes (D8).
- Guards **from day one** (lesson from exchanges): `register()` AND constructor-supplied map entries validate the class against `Indicator` **or** `MultiIndicator` (D11) → `InvalidIndicatorException`, with the offending class as the message's subject. Built-ins skip this guard because discovery already applied it: a class in `src/Indicators/` that implements neither contract is never picked up at all (D13).
- Unknown name → `UnknownIndicatorException`. New instance per `make()` call.

### D8 — Package exceptions, never generic ones
Every exception implements `IndicatorException` (marker). `InvalidParameterException` and `InvalidIndicatorException` extend `\InvalidArgumentException`; `UnknownIndicatorException` too. No raw `\Exception`, `\Error` or `\TypeError` escapes the package.

### D9 — Laravel bridge inside the package, optional
`Bridge/Laravel/IndicatorsServiceProvider` registers `IndicatorManager` as a container singleton. It does **not** bind `Indicator::class` — indicators are values created per bot config at runtime, not container services. `illuminate/support` in `suggest`, never `require`. Auto-discovery via `extra.laravel.providers` (added to composer.json only when the bridge exists — see §9 step 1).

### D10 — Complexity is part of an indicator's contract
Every indicator computes its full series in a single O(n) pass — rolling sums and recurrences, never re-scanning the window per index (no O(n × period)). This is free of the classic float trade-off: `BigDecimal` `plus()`/`minus()` are exact, so rolling accumulators carry zero drift and stay byte-identical to naive re-summation. Golden fixtures are the equivalence proof for any internal rework.

Micro-optimizations are NOT the goal — complexity class is. The heavy consumer is the historical/backtest path (§11 E1 of the exchanges roadmap and future backtesting); the live tick (a few hundred candles) never notices. Benchmarks stay out of v0; incremental per-tick compute remains §11 E3.

## 6. Flow of a call (v0)

```
app: (new Rsi(period: 14))->compute($candles)
  or: $manager->make('rsi', ['period' => 14])->compute($candles)

 1. Constructor validated period at creation (InvalidParameterException if bad)
 2. compute() walks the candle list once, reading only the fields it needs
 3. Divisions round via the D5 policy (scale 12, HALF_UP); other ops stay exact
 4. Returns list<BigDecimal|null>, same length as input, nulls during warm-up
```

## 7. Tests (Pest)

- **Golden fixtures, never formula-against-formula.** Each indicator is validated against externally verified values: real candles (e.g. BTCUSDT daily) with expected outputs taken from TradingView / a reference implementation. Each fixture file records its provenance (symbol, timeframe, source, capture date).
- Comparison rule: the computed `BigDecimal` is rounded to the reference's precision (`toScale(n, HALF_UP)`) and compared as strings — exact match at the reference's advertised precision.
- Unit: `Candle`, every parameter validation, warm-up nulls (exact indices), insufficient data → all nulls, empty → empty, RSI edge cases (all-gains → 100, all-losses → 0), manager (make/register/available/unknown/invalid class/invalid parameter name).
- Feature: one golden-fixture test per indicator, plus alignment assertions (output length === input length).
- No network, no I/O beyond reading local fixtures.

## 8. composer.json (decision skeleton)

```json
{
  "name": "baconfy/indicators",
  "require": {
    "php": "^8.3",
    "brick/math": "^0.18"
  },
  "require-dev": {
    "pestphp/pest": "^5.0"
  },
  "suggest": {
    "illuminate/support": "To use the Laravel bridge (IndicatorsServiceProvider)"
  },
  "autoload": { "psr-4": { "Baconfy\\Indicators\\": "src/" } },
  "autoload-dev": { "psr-4": { "Baconfy\\Indicators\\Tests\\": "tests/" } },
  "extra": {
    "laravel": { "providers": ["Baconfy\\Indicators\\Bridge\\Laravel\\IndicatorsServiceProvider"] }
  }
}
```
Version floors follow the decisions already made in `baconfy/exchanges` (PHP ^8.3, brick/math ^0.18, Pest ^5). If Composer resolves newer, reality wins and this section gets updated.

## 9. Build order (one step at a time, TDD, one commit per step)

Each step ends green, committed with a conventional message, and pushed before the next starts.

1. **Skeleton**: composer.json per §8 **without the `extra.laravel` block** (the provider only exists at step 9; discovery pointing at a missing class breaks consuming Laravel apps), Pest installed, one dummy test passing, `.gitignore` (vendor/, tool caches; do not commit composer.lock — this is a library).
2. **Candle + exceptions**: the DTO and the full exception hierarchy, with tests.
3. **Contract + math policy**: `Indicator` interface, `Math/Decimal` with its divide policy (tested directly).
4. **Sma**: first real indicator — proves the golden-fixture methodology end to end.
5. **Ema**: seed convention (SMA of first p) pinned by fixture.
6. **Rsi**: Wilder smoothing + both edge cases pinned.
7. **Atr**: TR + Wilder, the indicator that proves the uniform contract (reads three fields).
8. **IndicatorManager**: make/register/available + all guards.
9. **Laravel bridge**: provider + container test; **now** add `extra.laravel` to composer.json.

## 10. Out of scope for v0 (do not implement)

- MACD, Bollinger Bands, OBV, VWAP, Stochastic — see §11
- Incremental/streaming computation (v0 recomputes the full series per call)
- Multi-timeframe logic, candle resampling
- Any dependency on baconfy/exchanges or any HTTP client

## 11. Planned evolutions (shape already decided)

### E1 — MACD as composition
MACD = EMA(fast) − EMA(slow) + signal EMA. Implemented **composing the existing Ema**, never duplicating EMA math. Returns a richer shape (three aligned series) — will require a decision on multi-series output (dedicated DTO vs array of series) when it lands.

### E2 — Bollinger Bands
Needs standard deviation → square root via `BigDecimal::sqrt($scale)` under the same D5 policy.

### E3 — Incremental compute
For hot ticks: `computeNext(Candle $candle)` on a stateful session object, keeping `compute()` pure. Enters only if profiling shows full recompute is a real cost (200 candles is nothing).

# baconfy/indicators — v0.2 / v0.3 additions

> Apply these blocks to `.claude/ARCHITECTURE.md`. Each block names its target section.

---

## §1 Goal — replace the scope line

v0 scope (shipped): SMA, EMA, RSI, ATR.
v0.2 scope: **RMA, OBV, VWMA** (single-series, no new design).
v0.3 scope: **the MultiIndicator contract (D11)** and **MACD, Bollinger Bands, Stochastic, ADX**.

## D3 — append

`null` also marks a value that is **mathematically undefined mid-series**, not only warm-up: a VWMA window whose total volume is zero, a Stochastic window with no high-low range. Same semantics: "the indicator does not exist here". OBV is the mirror case: defined from bar 0, it has **no leading nulls at all**.

## D5 — append

**Square roots join the policy.** Only `Math\Decimal::sqrt()` may take roots:
```php
public static function sqrt(BigDecimal $value): BigDecimal
{
    // Brick's sqrt($scale) truncates; two guard digits, then the policy rounding.
    return self::round($value->sqrt(self::SCALE + 2));
}
```

## D6 — append these specs

**RMA(p=14)** — Wilder's smoothing as a public indicator. Seed: SMA of the first `p` closes at index `p-1`; `null` before. After: `rma[i] = (prev*(p-1) + close[i]) / p` (policy division — inherently scale-bounded). No one-bar shift: RMA smooths the raw series, unlike ATR whose TR needs a previous close.

**OBV** — no parameters (the constructor takes none). `obv[0] = 0`; close up → `+volume`, down → `-volume`, flat → carry. No leading nulls. Every value emitted through `Decimal::round()` for scale uniformity (additions alone never reach the policy scale).

**VWMA(p=20)** — `sum(close·volume) / sum(volume)` over the window, two rolling sums (D10). Warm-up nulls `0..p-2`. A window with zero total volume → `null` (mid-series undefined, per D3).

**MACD(fast=12, slow=26, signal=9)** — multi (D11), series `macd`, `signal`, `histogram`. Validation: all `>= 1` AND `slow > fast` → `InvalidParameterException`. Composes the existing `Ema` — EMA math is never duplicated. `macd[i] = emaFast[i] − emaSlow[i]` where both exist (first at `slow-1`). `signal` = EMA(`signal`) computed **over the contiguous valid slice** of the macd line (its seed is the SMA of the slice's first `signal` values → lands at index `slow+signal-2`). `histogram = macd − signal` where both exist. Differences are exact; each series' values inherit the policy scale from the EMAs.

**BollingerBands(period=20, multiplier='2.0')** — multi, series `basis`, `upper`, `lower`. `basis` = SMA(period). Population standard deviation over the same window, O(n) via rolling `sum` and `sumSq` (D10): `variance = (n·sumSq − sum²) / n²` — exact numerator, ONE policy division — then `stdev = Decimal::sqrt(variance)`. Bands: `basis ± multiplier·stdev`, wrapped in `Decimal::round()` (the multiplication grows scale). `multiplier` accepted as int|float|string via `BigDecimal::of`, must be `> 0`.

**Stochastic(k_period=14, k_smooth=3, d_smooth=3)** — multi, series `k`, `d`. `rawK = 100·(close − LL) / (HH − LL)` over `k_period` (policy division); window with zero range → `null`. Rolling HH/LL via **monotonic deques** — O(n), never rescanning the window (D10). `k` = `rawK` when `k_smooth == 1`, else SMA(`k_smooth`) over it; `d` = SMA(`d_smooth`) over `k`; both smoothings yield `null` for any window containing a `null`. All params `>= 1`.

**ADX(period=14)** — multi, series `adx`, `plus_di`, `minus_di`. Bar 0: `+DM = −DM = 0`, `TR = high−low`. Bar i>0: `up = high−prevHigh`, `down = prevLow−low`; `+DM = (up > down && up > 0) ? up : 0`, mirrored for `−DM`; `TR` as in ATR's max. All three smoothed with RMA(period) (first values at `period-1`). `DI± = 100·RMA(DM±)/RMA(TR)` (null while RMA null; null when RMA(TR) is zero). `DX = 100·|DI⁺−DI⁻| / (DI⁺+DI⁻)`; both DIs zero → `DX = 0`. `adx` = RMA(period) over the **contiguous valid slice** of DX (lands at `2·period−2`). All divisions through the policy.

## D11 — `MultiIndicator`: the multi-series contract (new)
```php
interface MultiIndicator
{
    /**
     * @param list<Candle> $candles ordered oldest to newest
     * @return array<string, list<BigDecimal|null>> named series; EVERY series has
     *         the same length as $candles, index-aligned, null where undefined
     */
    public function compute(array $candles): array;
}
```
A **sibling** of `Indicator`, deliberately not a parent/child: the single-series contract stays pure — the simple case must not pay for the rich one (same law as `klines()` vs `klinesBetween()`). The series **names are public API** (renaming one is a breaking change). Multi indicators follow every other law: D3 alignment per series, D4 parameters-as-identity, D5 policy, D10 complexity.

Manager changes: the guard accepts classes implementing `Indicator` **or** `MultiIndicator`; `make()` returns `Indicator|MultiIndicator`; consumers branch on `instanceof` (or `array_is_list()` of the result). Built-in names added along the steps: `rma`, `obv`, `vwma`, `macd`, `bollinger_bands`, `stochastic`, `adx`.

## D12 — `Rma` is public, reuse is optional (new)

Wilder's smoothing is a real indicator (SMMA on charts) and the primitive under RSI/ATR/ADX — so it ships public. Rebuilding `Rsi`/`Atr` on top of it is **allowed but not required**: the golden fixtures are the equivalence proof for any such internal rework. `Adx` composes it from day one.

## D13 — Built-in names are attribute-declared, discovered lazily (new)

Supersedes the "built-in default map" literal of D7: the hand-written array in `IndicatorManager` is gone. Everything else in D7 — `register()`, `make()`, `available()`, the contract guard, the `Error` wrapping — is untouched, and the manager's public behaviour is identical.

**The class declares its own public name.**
```php
#[AsIndicator('bollinger-bands')]
final readonly class BollingerBands implements MultiIndicator
```
The name stays what it always was: public API, persisted by consumers, semver-governed. What changes is only *where it is written* — next to the class it names, instead of in a list far away that someone must remember to update.

**Discovery is filename-blind.** `Support\Discovery::scan($directory, $namespace)` — internal, not public API, on the same footing as `Math\Decimal` — globs `*.php`, derives FQCNs by PSR-4, skips anything implementing neither contract, and reads the name off the attribute. A file name never becomes a public name — that was the original objection to directory scanning, and it still holds: renaming `Vwma.php` must stay an internal change, not an invisible breaking one. Returns `array<string, class-string>` sorted by name, so the map is byte-deterministic across filesystems.

**Two loud failures, both at scan time**, because forgetting to register was the whole risk:
- a class implementing `Indicator`/`MultiIndicator` with no `AsIndicator` → `InvalidIndicatorException` naming it ("implements a contract but declares no ... name");
- two classes declaring the same name → `InvalidIndicatorException` naming both. Never last-wins.

**Lazily memoized.** The scan result is cached in a private static, so the filesystem is touched once per process, not once per `new IndicatorManager`.

**`register()` remains the only third-party path.** Discovery scans `src/Indicators/` and nothing else — this is not a plugin ecosystem, and no consumer directory is ever walked.

The completeness guard test (`tests/Unit/BuiltInMapTest.php`) stays exactly as it was, and is now worth more: it checks Discovery's output against an independent scan of the directory, so a bug in Discovery fails the suite instead of hiding inside it.

## §9 Build order — append (fixtures for ALL new indicators are pre-generated; the EMA-era gate rule applies: if a fixture is missing, STOP)

10. **Rma** — golden + seed/warm-up/scale suite (v0.2 begins).
11. **Obv** — golden (level series from 0) + no-leading-null pin + scale uniformity.
12. **Vwma** — golden + zero-volume-window null pin + rolling-sums (D10). → **tag v0.2**
13. **MultiIndicator contract + manager union** — guard accepts both, `FakeMultiIndicator` double, union return pinned. No indicator yet.
14. **Macd** — first multi; composition over Ema pinned (no duplicated EMA math).
15. **Decimal::sqrt + BollingerBands** — sqrt tested directly (truncation guard pinned), then the bands.
16. **Stochastic** — monotonic-deque HH/LL (D10) + zero-range null pin.
17. **Adx** — composes Rma; the valid-slice second smoothing pinned. → **tag v0.3**

## §10 Out of scope — replace the MACD/Bollinger line and append

- ~~MACD, Bollinger Bands~~ (entering v0.3)
- **The structural-analysis family stays out by domain boundary, not by laziness**: Swings, MarketStructure, SupportResistance, Trendline, VolumeProfile, Divergence, Correlation read a whole window and return *shapes and verdicts* (pivot indices, levels, lines, booleans, one coefficient) — not per-bar series. Forcing them into `Indicator`/`MultiIndicator` would destroy the uniformity that makes the consumer's generic loop work. They belong to a future structural-analysis contract (or package) with its own laws.

## §11 — replace E1/E2 (both resolved), keep E3, add E4

### E3 — Incremental compute (unchanged)
### E4 — Structural analysis domain
Swing-pivot primitives and their consumers (market structure, S/R levels, trendlines, volume profile, divergence, correlation) as a separate contract family: window → structured verdicts. Shape to be designed when the bot demonstrates the need; the old app's implementations are the reference material.

### E5 — Built-in map completeness (registration at scale) — RESOLVED
Landed as **D13**: explicit per-class declaration via the `AsIndicator` attribute, lazily memoized discovery over `src/Indicators/`, and two loud scan-time failures. The original constraint survived intact — names are never derived from filenames.