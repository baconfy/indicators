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
    /** @param array<string, class-string<Indicator>> $map */
    public function __construct(array $map = []);

    public function register(string $name, string $indicatorClass): void;
    /** @param array<string, int|string|float> $parameters named-argument map, e.g. ['period' => 14] */
    public function make(string $name, array $parameters = []): Indicator;
    /** @return list<string> */
    public function available(): array;
}
```
- Built-in default map: `['sma' => Sma::class, 'ema' => Ema::class, 'rsi' => Rsi::class, 'atr' => Atr::class]`.
- The name string is the key the consuming app persists in its bot config — same boundary role as `exchange_connections.driver` in the exchanges package.
- `make()` spreads `$parameters` as **named arguments**: `new $class(...$parameters)`. An unknown parameter name raises a PHP `Error`; the manager catches it and rethrows `InvalidParameterException` — no raw `Error` escapes (D8).
- Guards **from day one** (lesson from exchanges): `register()` AND constructor-supplied map entries validate `is_a($class, Indicator::class, true)` → `InvalidIndicatorException`, with the offending class as the message's subject. Built-in defaults are trusted.
- Unknown name → `UnknownIndicatorException`. New instance per `make()` call.

### D8 — Package exceptions, never generic ones
Every exception implements `IndicatorException` (marker). `InvalidParameterException` and `InvalidIndicatorException` extend `\InvalidArgumentException`; `UnknownIndicatorException` too. No raw `\Exception`, `\Error` or `\TypeError` escapes the package.

### D9 — Laravel bridge inside the package, optional
`Bridge/Laravel/IndicatorsServiceProvider` registers `IndicatorManager` as a container singleton. It does **not** bind `Indicator::class` — indicators are values created per bot config at runtime, not container services. `illuminate/support` in `suggest`, never `require`. Auto-discovery via `extra.laravel.providers` (added to composer.json only when the bridge exists — see §9 step 1).

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