<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sysborg\LaravelJevai\Adapters\Database\EloquentUsageRepository;
use Sysborg\LaravelJevai\Adapters\Database\JevRun;
use Sysborg\LaravelJevai\Adapters\Null\NullUsageRepository;
use Sysborg\LaravelJevai\Application\Usage\UsageReports;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\Billing;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;
use Sysborg\LaravelJevai\Domain\Usage\RunRecord;
use Sysborg\LaravelJevai\Domain\Usage\RunStatus;
use Sysborg\LaravelJevai\Domain\Usage\Usage;
use Sysborg\LaravelJevai\Facades\Jev;
use Sysborg\LaravelJevai\Facades\JevUsage;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;
use Sysborg\LaravelJevai\Tests\Support\FakeClock;

beforeEach(function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('jev.usage.driver', 'database');

    (require __DIR__.'/../../../database/migrations/create_jev_runs_table.php')->up();

    app()->instance(Clock::class, new FakeClock(stepMs: 10, now: new DateTimeImmutable('2026-10-09 12:00:00', new DateTimeZone('UTC'))));
});

/**
 * Build a stored record.
 *
 * @param  string  $id  Correlation id.
 * @param  string  $at  When it happened (UTC).
 * @param  array<string, mixed>  $overrides  Constructor arguments to override.
 * @return RunRecord The record.
 */
function usageRecord(string $id, string $at, array $overrides = []): RunRecord
{
    return new RunRecord(...[
        'correlationId' => new CorrelationId($id),
        'operation' => RunOperation::Decision,
        'status' => RunStatus::Succeeded,
        'occurredAt' => new DateTimeImmutable($at, new DateTimeZone('UTC')),
        'usage' => new Usage(100, 5),
        'billing' => new Billing(BillingMode::Tokens, 100),
        'latencyMs' => 400,
        'attempts' => 1,
        'context' => Context::empty(),
        'model' => 'jev-latest',
        'connection' => 'default',
        ...$overrides,
    ]);
}

it('binds the database repository when configured, the null one otherwise', function () {
    expect(app(UsageRepository::class))->toBeInstanceOf(EloquentUsageRepository::class);

    app()->forgetInstance(UsageRepository::class);
    config()->set('jev.usage.driver', 'null');

    expect(app(UsageRepository::class))->toBeInstanceOf(NullUsageRepository::class);

    app()->forgetInstance(UsageRepository::class);
    config()->set('jev.usage.driver', 'redis');

    expect(fn () => app(UsageRepository::class))->toThrow(InvalidValue::class, 'jev.usage.driver');
});

it('stores every field of a record', function () {
    app(UsageRepository::class)->record(usageRecord('c-1', '2026-10-09 09:00:00-03:00', [
        'operation' => RunOperation::Judge,
        'judgeId' => 'judge_1',
        'judgeRevision' => 3,
        'sessionId' => 'ticket-42',
        'user' => 'user-7',
        'runId' => 'run_1',
        'context' => Context::of(['ticket_id' => 42]),
        'payload' => ['answers' => ['q' => ['noul' => 0.9]]],
        'billing' => new Billing(BillingMode::CreditsFallback, 0, 1.5),
    ]));

    $run = JevRun::firstOrFail();

    expect($run->correlation_id)->toBe('c-1')
        ->and($run->operation)->toBe('judge')
        ->and($run->status)->toBe('succeeded')
        ->and($run->judge_id)->toBe('judge_1')
        ->and($run->judge_revision)->toBe(3)
        ->and($run->session_id)->toBe('ticket-42')
        ->and($run->user_label)->toBe('user-7')
        ->and($run->run_id)->toBe('run_1')
        ->and($run->input_tokens)->toBe(100)
        ->and((float) $run->credits_charged)->toBe(1.5)
        ->and($run->billing_mode)->toBe('credits-fallback')
        ->and($run->billing_uncertain)->toBeFalse()
        ->and($run->context)->toBe(['ticket_id' => 42])
        ->and($run->payload)->toBe(['answers' => ['q' => ['noul' => 0.9]]])
        ->and($run->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-10-09 12:00:00');
});

it('summarizes by model, day, operation, user and as a total, with filters', function () {
    $repository = app(UsageRepository::class);

    $repository->record(usageRecord('c-1', '2026-10-08 10:00:00'));
    $repository->record(usageRecord('c-2', '2026-10-09 10:00:00', ['model' => 'clef', 'billing' => new Billing(BillingMode::Tokens, 600), 'user' => 'u-1']));
    $repository->record(usageRecord('c-3', '2026-10-09 11:00:00', [
        'status' => RunStatus::Failed,
        'usage' => Usage::none(),
        'billing' => Billing::unknown(),
        'billingUncertain' => true,
        'latencyMs' => 30_000,
        'operation' => RunOperation::WebContext,
    ]));
    $repository->record(usageRecord('c-old', '2026-09-01 10:00:00'));

    $total = JevUsage::lastDays(7)->total();
    $byModel = JevUsage::lastDays(7)->byModel()->get();
    $byDay = JevUsage::lastDays(7)->byDay()->get();

    expect($total->calls)->toBe(3)
        ->and($total->failures)->toBe(1)
        ->and($total->inputTokens)->toBe(200)
        ->and($total->inputTokensCharged)->toBe(700)
        ->and($total->uncertainBillings)->toBe(1)
        ->and(round($total->errorRate(), 3))->toBe(0.333)
        ->and(array_map(fn ($s) => [$s->group, $s->calls, $s->inputTokensCharged], $byModel))->toBe([
            ['clef', 1, 600],
            ['jev-latest', 2, 100],
        ])
        ->and(array_map(fn ($s) => [$s->group, $s->calls], $byDay))->toBe([
            ['2026-10-08', 1],
            ['2026-10-09', 2],
        ])
        ->and(JevUsage::lastDays(7)->whereModel('clef')->total()->calls)->toBe(1)
        ->and(JevUsage::lastDays(7)->whereUser('u-1')->total()->inputTokensCharged)->toBe(600)
        ->and(JevUsage::lastDays(7)->whereOperation(RunOperation::WebContext)->total()->failures)->toBe(1)
        ->and(JevUsage::lastDays(7)->byOperation()->get())->toHaveCount(2)
        ->and(JevUsage::lastDays(60)->total()->calls)->toBe(4)
        ->and(JevUsage::lastHours(1)->total()->calls)->toBe(1) // 11:00 is included: periods start inclusive
        ->and(JevUsage::lastHours(1)->total()->failures)->toBe(1);
});

it('parses relative periods', function (string $period, string $from) {
    expect(app(UsageReports::class)->period($period)->query()->from->format('Y-m-d H:i'))->toBe($from);
})->with([
    ['30m', '2026-10-09 11:30'],
    ['24h', '2026-10-08 12:00'],
    ['7d', '2026-10-02 12:00'],
    ['2w', '2026-09-25 12:00'],
    ['2026-10-01', '2026-10-01 00:00'],
]);

it('rejects unreadable periods', function () {
    app(UsageReports::class)->period('last tuesday-ish!!');
})->throws(InvalidValue::class, 'usage period');

it('prunes old records', function () {
    $repository = app(UsageRepository::class);
    $repository->record(usageRecord('c-new', '2026-10-01 00:00:00'));
    $repository->record(usageRecord('c-old', '2026-01-01 00:00:00'));

    expect($repository->prune(new DateTimeImmutable('2026-07-01')))->toBe(1)
        ->and(JevRun::pluck('correlation_id')->all())->toBe(['c-new']);
});

it('records real calls end to end, including final failures', function () {
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(jevFixture('decision-success'), 200, ['X-Jev-Tokens-Remaining' => '9880'])
        ->push(jevFixture('error'), 503)
        ->push(jevFixture('error'), 503)
        ->push(jevFixture('error'), 503);

    Jev::state('text')->noul('is_urgent', 'Urgent?')->user('user-7')->context('ticket_id', 42)->evaluate();

    expect(fn () => Jev::state('text')->noul('is_urgent', 'Urgent?')->evaluate())->toThrow(UpstreamFailure::class);

    $runs = JevRun::orderBy('id')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs[0]->status)->toBe('succeeded')
        ->and($runs[0]->input_tokens)->toBe(120)
        ->and($runs[0]->user_label)->toBe('user-7')
        ->and($runs[0]->context)->toBe(['ticket_id' => 42])
        ->and($runs[0]->payload)->toBeNull()
        ->and($runs[1]->status)->toBe('failed')
        ->and($runs[1]->http_status)->toBe(503)
        ->and($runs[1]->attempts)->toBe(3)
        ->and($runs[1]->model)->toBe('jev-latest')
        ->and($runs[1]->connection)->toBe('default');
});

it('flags timeouts as possibly billed', function () {
    app(UsageRepository::class)->record(RunRecord::forFailure(
        RunOperation::Decision, new CorrelationId('t-1'), new TransportFailure, new DateTimeImmutable('2026-10-09 11:00:00'),
        30_000, 1, Context::empty(), 'jev-latest', 'default',
    ));

    expect(JevRun::firstOrFail()->billing_uncertain)->toBeTrue()
        ->and(JevUsage::today()->total()->uncertainBillings)->toBe(1);
});

it('lets model:prune use the retention period', function () {
    config()->set('jev.usage.retention_days', 30);
    $this->travelTo(new DateTimeImmutable('2026-10-09 12:00:00'));
    app(UsageRepository::class)->record(usageRecord('c-new', '2026-10-01 00:00:00'));
    app(UsageRepository::class)->record(usageRecord('c-old', '2026-08-01 00:00:00'));

    expect((new JevRun)->prunable()->pluck('correlation_id')->all())->toBe(['c-old']);
});
