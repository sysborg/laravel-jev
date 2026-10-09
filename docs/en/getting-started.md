# Getting started

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- A Jev API key ([get one](https://jev-ai.pro/jev-api-key))

## Install

```bash
composer require sysborg/laravel-jevai
```

```dotenv
JEV_AI_API_KEY=your-key
```

That is enough to make calls. Optional steps:

```bash
php artisan vendor:publish --tag=jev-config       # config/jev.php
php artisan vendor:publish --tag=jev-migrations   # jev_runs table, for usage reports
php artisan migrate
```

The package registers itself (auto-discovery) with two facades: `Jev` and `JevUsage`.

## Your first decision

Jev answers **typed questions** about a **state** (text or structured data):

| Type | Ask | Answer |
|---|---|---|
| `noul` | a yes/no question | probability of "yes", 0..1 |
| `choice` | pick one of 2–255 named options | the option, its confidence and probabilities |
| `score` | rate on 2–10 ordered levels | expected level, most likely level, label |

```php
use Sysborg\LaravelJevai\Facades\Jev;

$result = Jev::state($ticket->body)
    ->noul('is_urgent', 'Does this convey urgency?')
    ->choice('department', 'Which team should handle this?', [
        'billing'   => 'Payments and refunds',
        'technical' => 'Bugs and outages',
        'sales'     => 'Pricing',
    ])
    ->score('frustration', 'How frustrated is the customer?', ['Calm', 'Frustrated', 'Very angry'])
    ->evaluate();

$result->noul('is_urgent')->isYes();        // true (probability >= 0.5)
$result->noul('is_urgent')->isYes(0.9);     // stricter threshold
$result->choice('department')->choice;      // 'billing'
$result->choice('department')->probability('technical'); // 0.01
$result->score('frustration')->label();     // 'Frustrated'
```

Structured state works too: `Jev::state(['subject' => $subject, 'body' => $body, 'plan' => 'pro'])`.

## The builder

Every method returns a **new** builder, so a partially configured one can be reused.

```php
$result = Jev::model('clef')                       // or Jev::state(...) / Jev::request()
    ->state($text)
    ->noul('is_spam', 'Is this spam?')
    ->session("ticket-{$ticket->id}")              // Jev session_id label
    ->user((string) auth()->id())                  // Jev user label
    ->trace(['pipeline' => 'triage-v2'])           // stored privately by Jev
    ->context(['ticket_id' => $ticket->id])        // never sent: attached to every event
    ->evaluate();
```

`trace` is sent to Jev (private, max 64 fields / 8 KB). `context` **never leaves your app**: it
travels with every event and usage record so you can route results back to your models.

## What a result carries

```php
$result->answers;                    // AnswerSet, iterable by question id
$result->usage->inputTokens;         // 120
$result->usage->outputTokens;        // 8   (output tokens are free on Jev)
$result->billing->mode;              // BillingMode::Tokens | Credits | CreditsFallback | TokensFallback | Unknown
$result->billing->inputTokensCharged;
$result->billing->creditsCharged;
$result->billing->tokensRemaining;   // from X-Jev-Tokens-Remaining, when sent
$result->meta->correlationId;        // UUIDv7, also in Jev's trace and session_id
$result->meta->runId;                // X-Jev-Run-Id, when sent
$result->meta->attempts;             // HTTP attempts, retries included
$result->meta->latencyMs;            // whole call, retries included
```

`usage->cost` is always `null`: Jev does not return it, and a missing cost is **unknown**, not
zero. The real charge is in `billing`.

## Saved judges

```php
Jev::judge('judge_123')->state($transcript)->evaluate();              // latest rules
Jev::judge('judge_123', revision: 3)->state($transcript)->evaluate(); // fails (409) if the rules changed
```

A pinned call refused because the rules changed throws `JudgeRevisionMismatch` and dispatches
`JudgeRulesChanged`.

## Web context

A yes/no question answered with and without fresh web evidence:

```php
$result = Jev::webContext('Has OpenAI released GPT-6?')
    ->query('OpenAI releases GPT-6 announcement')
    ->criteria(yes: 'A model named GPT-6 is public', no: 'No such model is public')
    ->numResults(6)
    ->resolve();

$result->isYes();                    // final decision
$result->confidence;                 // 0.88
$result->evidenceChangedDecision();  // did the evidence flip the answer?
$result->sources;                    // list<WebSource>
```

Each web search costs 170,000 input tokens. Supply your own evidence with
`->sources(new WebSource($title, $url, $publishedAt, $highlights))` to skip the search fee.

## Background evaluation

```php
$pending = Jev::state($ticket->body)
    ->noul('is_urgent', 'Urgent?')
    ->context('ticket_id', $ticket->id)
    ->queue();

$pending->correlationId; // the result arrives through events carrying this id
```

```php
use Sysborg\LaravelJevai\Domain\Events\DecisionSucceeded;

Event::listen(function (DecisionSucceeded $event) {
    Ticket::find($event->context()->get('ticket_id'))
        ?->markUrgent($event->result->noul('is_urgent')->isYes());
});
```

The job (`EvaluateDecisionJob`) runs **once**: failures are reported through `DecisionFailed`
instead of failing the job, because a retried job could be billed twice. Configure the queue with
`JEV_AI_QUEUE_CONNECTION` / `JEV_AI_QUEUE`.

## Errors

Every exception implements `Sysborg\LaravelJevai\Domain\Exceptions\JevThrowable`. Call failures
extend `JevException`, which tells you what is safe to do:

```php
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;

try {
    $result = Jev::state($text)->noul('q', 'Q?')->evaluate();
} catch (JevException $e) {
    $e->httpStatus;          // null when no response arrived
    $e->errorCode;           // Jev error.code
    $e->isRetryable();       // safe to try again later?
    $e->mayHaveBeenBilled(); // true for timeouts: check usage before retrying
}
```

| Exception | When | Retried by the package |
|---|---|---|
| `InvalidValue` | a value breaks a documented limit (checked before sending) | — |
| `Unauthorized` | 401 | no |
| `InsufficientCredits` | 402 | no |
| `JudgeNotFound` / `JudgeRevisionMismatch` | 404 / 409 on judge calls | no |
| `InvalidRequest` | 400 / 422 | no |
| `RateLimited` | 429 | yes, honoring `Retry-After` |
| `UpstreamFailure` | 502 / 503 / 504 | yes, with backoff |
| `TransportFailure` | timeout, dropped connection (may have been billed) | no (opt-in) |
| `UnexpectedResponse` | body cannot be understood | no |
| `ApiError` | any other status | no |
| `LocallyRateLimited`, `CircuitOpen`, `BudgetExceeded` | client-side limits (not sent) | no |

## Account and usage

```php
Jev::models();   // ['jev-latest', 'clef', ...] (free)
Jev::balance();  // Balance: creditsRemaining, paidInputTokensRemaining (free)
```

```bash
php artisan jev:models
php artisan jev:balance
php artisan jev:usage --since=7d --by=model     # needs JEV_AI_USAGE_DRIVER=database
php artisan jev:prune --days=90
```

Next: [Configuration](configuration.md) · [Observability](observability.md)
