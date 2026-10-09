# Testing

## Faking Jev

```php
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Facades\Jev;

it('routes urgent billing tickets', function () {
    Jev::fake([
        'department' => 'billing',   // choice: the option
        'is_urgent'  => true,        // noul: bool or 0..1
        'frustration' => 2,          // score: the level
    ])->withUsage(input: 120);

    $this->post('/tickets', ['body' => 'My card was charged twice!'])->assertCreated();

    Jev::assertEvaluated(fn (DecisionRequest $r) => $r->questions?->has('department'));
    Jev::assertTokensUsedLessThan(500);
});
```

`Jev::fake()` only replaces the gateways and the queue. **The real pipeline still runs**: events
are dispatched, usage is recorded, retries and alerts happen exactly as in production, and no HTTP
request is made. Unlisted questions get neutral answers (noul 0.5, first choice option, score 0).

### Sequences and failures

```php
use Sysborg\LaravelJevai\Domain\Exceptions\{InsufficientCredits, UpstreamFailure};

Jev::fake()->sequence(
    ['department' => 'billing'],
    new InsufficientCredits,                 // the next call throws
    ['department' => 'technical'],
    fn (DecisionRequest $r, CorrelationId $id) => $customResult,
);

Jev::fake()->failWith(new UpstreamFailure(503)); // retried by the pipeline, then succeeds
```

### Billing, alerts, web context, account

```php
Jev::fake()
    ->withBilling(BillingMode::CreditsFallback, creditsCharged: 1.0, tokensRemaining: 0)
    ->webContextAnswer('no', 0.75)
    ->withAccount(['jev-latest', 'clef'], credits: 3.0, tokens: 10);
```

### Assertions

| Assertion | Checks |
|---|---|
| `Jev::assertEvaluated(?fn)` | a decision attempt matched |
| `Jev::assertNotEvaluated(?fn)` | no attempt matched |
| `Jev::assertNothingEvaluated()` | no call at all |
| `Jev::assertEvaluatedTimes(n)` | number of attempts (retries included) |
| `Jev::assertJudgeUsed($id, ?$revision)` | a saved judge was used |
| `Jev::assertTokensUsedLessThan(n)` | sum of input tokens of fake results |
| `Jev::assertWebContextResolved(?fn)` | a web-context question matched |
| `Jev::assertQueued(?fn)` | `->queue()` was called (nothing is dispatched) |

`Jev::fake()` returns the `JevFake`; `$fake->recorded()` lists every request sent.

## Events in tests

```php
Event::fake([DecisionSucceeded::class]);
Jev::fake(['is_urgent' => true]);

// ...

Event::assertDispatched(DecisionSucceeded::class, fn ($e) => $e->context()->get('ticket_id') === 42);
```

## Live smoke tests

The package ships opt-in tests against the real API (a few input tokens per run):

```bash
JEV_LIVE_TESTS=1 JEV_AI_API_KEY=sk-... composer test:live
```

## Contributing to the package

```bash
composer check          # Pint, Larastan (max), Pest
composer test:coverage  # Domain + Application >= 90% (needs pcov or Xdebug)
composer test:mutate    # Domain mutation score >= 80%
```
