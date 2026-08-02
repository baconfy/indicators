# baconfy/indicators

Technical analysis indicators over exact decimal math. Framework-free, deterministic, no I/O.

Candles in, an index-aligned series out. No exchange knowledge, no HTTP client, no framework — the core runs in a plain PHP script with `new`.

## Requirements

- PHP `^8.4`
- [`brick/math`](https://github.com/brick/math) `^0.18`

## Installation

```bash
composer require baconfy/indicators
```

## Quick start

```php
use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Indicators\Rsi;
use Brick\Math\BigDecimal;

$candles = [
    new Candle(
        openTime: new DateTimeImmutable('2026-01-01 00:00:00'),
        open: BigDecimal::of('42000.00'),
        high: BigDecimal::of('42500.00'),
        low: BigDecimal::of('41800.00'),
        close: BigDecimal::of('42300.00'),
        volume: BigDecimal::of('1250.5'),
    ),
    // ... ordered oldest to newest
];

$rsi = (new Rsi(period: 14))->compute($candles);

// $rsi[0] === null   — warm-up
// $rsi[14] instanceof BigDecimal
```

Or resolve by name, the way a bot resolves what its config persists:

```php
use Baconfy\Indicators\IndicatorManager;

$manager = new IndicatorManager;

$manager->make('rsi', ['period' => 14])->compute($candles);
$manager->make('macd')->compute($candles);   // full defaults
$manager->available();                       // list<string> of built-in names
```

## The two contracts

Single-series indicators implement `Contracts\Indicator`:

```php
/** @return list<BigDecimal|null> */
public function compute(array $candles): array;
```

Multi-series indicators implement `Contracts\MultiIndicator` — a **sibling**, not a subtype, so the simple case never pays for the rich one:

```php
/** @return array<string, list<BigDecimal|null>> */
public function compute(array $candles): array;
```

`make()` returns `Indicator|MultiIndicator`; branch on `instanceof` (or on `array_is_list()` of the result).

## Built-in indicators

| Name              | Class            | Parameters (defaults)                 | Output                        |
| ----------------- | ---------------- | ------------------------------------- | ----------------------------- |
| `sma`             | `Sma`            | `period: 9`                           | series                        |
| `ema`             | `Ema`            | `period: 9`                           | series                        |
| `rsi`             | `Rsi`            | `period: 14`                          | series                        |
| `atr`             | `Atr`            | `period: 14`                          | series                        |
| `rma`             | `Rma`            | `period: 14`                          | series                        |
| `obv`             | `Obv`            | *none*                                | series                        |
| `vwma`            | `Vwma`           | `period: 20`                          | series                        |
| `macd`            | `Macd`           | `fast: 12, slow: 26, signal: 9`       | `macd`, `signal`, `histogram` |
| `bollinger-bands` | `BollingerBands` | `period: 20, multiplier: '2.0'`       | `basis`, `upper`, `lower`     |
| `stochastic`      | `Stochastic`     | `kPeriod: 14, kSmooth: 3, dSmooth: 3` | `k`, `d`                      |
| `adx`             | `Adx`            | `period: 14`                          | `adx`, `plus_di`, `minus_di`  |

Both the **names** and the **series keys** are public API, governed by semver — consumers persist them in their config.

`multiplier` accepts `BigDecimal|int|float|string`; prefer a string to keep the decimal exact.

## Alignment and `null`

- Output length always equals input length: `$out[$i]` belongs to `$candles[$i]`. Every series of a multi indicator too.
- `null` means "the indicator does not exist here" — during warm-up (an SMA(20) has no value before index 19), or where it is mathematically undefined mid-series (a VWMA window of zero total volume, a Stochastic window with no high–low range).
- Fewer candles than the warm-up requires returns all `null`s. **Never an exception** — "not enough data yet" is a normal state of a running bot, not a failure.
- Empty input returns empty output.
- OBV is the mirror case: defined from bar 0, no leading nulls at all.

## Precision

Every division in every indicator goes through one package-wide policy: **scale 12, HALF_UP**. Additions and multiplications stay exact — `BigDecimal` grows the scale as needed — so only divisions round, and two indicators computing the same value can never diverge in the 10th digit. Square roots (Bollinger's standard deviation) go through the same policy.

Each indicator computes its full series in a single O(n) pass — rolling sums, recurrences and monotonic deques, never re-scanning the window per index.

## Parameters are identity

An `Ema(9)` and an `Ema(21)` are different indicators, not different calls — the parameters live in the constructor, promoted and `readonly`, validated eagerly:

```php
new Ema(period: 0);
// InvalidParameterException: Baconfy\Indicators\Indicators\Ema requires a period >= 1, 0 given.
```

Every indicator is `final readonly`, and `compute()` is pure: same candles + same parameters → same output. No clock reads, no randomness, no state between calls.

## Exceptions

All of them implement the `Exceptions\IndicatorException` marker, so a consumer can catch the whole package in one clause. No raw `\Exception`, `\Error` or `\TypeError` escapes.

| Exception                   | Raised when                                                                                 |
| --------------------------- | ------------------------------------------------------------------------------------------- |
| `InvalidParameterException` | a bad constructor parameter (period `< 1`), or an unknown parameter name handed to `make()` |
| `UnknownIndicatorException` | the manager does not know the requested name                                                |
| `InvalidIndicatorException` | a registered class implements neither contract                                              |

## Registering your own

```php
$manager->register('my-indicator', MyIndicator::class);

// or through the constructor, merged over the built-in defaults
$manager = new IndicatorManager(['my-indicator' => MyIndicator::class]);
```

Both paths guard the class against the two contracts and name the offender when it fails.

## Laravel

The bridge is a single optional ServiceProvider, auto-discovered, registering `IndicatorManager` as a container singleton:

```php
public function __construct(private readonly IndicatorManager $indicators) {}
```

It does **not** bind `Indicator::class` — indicators are values created per config at runtime, not container services. `illuminate/support` is a `suggest`, never a `require`; delete the bridge and the core is intact.

## Testing

```bash
composer test
```

Every indicator is validated against **golden fixtures** — real candles with expected values taken from external references — never formula against formula. The computed `BigDecimal` is rounded to the reference's precision and compared as strings.

## License

MIT. See [LICENSE](LICENSE).
