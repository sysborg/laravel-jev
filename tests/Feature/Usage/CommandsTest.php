<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\Billing;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;
use Sysborg\LaravelJevai\Domain\Usage\RunRecord;
use Sysborg\LaravelJevai\Domain\Usage\RunStatus;
use Sysborg\LaravelJevai\Domain\Usage\Usage;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;
use Sysborg\LaravelJevai\Tests\Support\FakeClock;

beforeEach(function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('jev.usage.driver', 'database');
    (require __DIR__.'/../../../database/migrations/create_jev_runs_table.php')->up();
    app()->instance(Clock::class, new FakeClock(now: new DateTimeImmutable('2026-10-09 12:00:00', new DateTimeZone('UTC'))));

    foreach ([['a', '2026-10-09 10:00:00', 'jev-latest'], ['b', '2026-10-09 11:00:00', 'clef'], ['c', '2026-01-01 00:00:00', 'clef']] as [$id, $at, $model]) {
        app(UsageRepository::class)->record(new RunRecord(
            new CorrelationId($id), RunOperation::Decision, RunStatus::Succeeded,
            new DateTimeImmutable($at, new DateTimeZone('UTC')), new Usage(100, 5),
            new Billing(BillingMode::Tokens, 100), 400, 1, Context::empty(), $model, 'default',
        ));
    }
});

it('prints usage grouped by model', function () {
    $this->artisan('jev:usage', ['--since' => '7d'])
        ->expectsTable(
            ['Group', 'Calls', 'Failures', 'Error %', 'Input tokens', 'Output tokens', 'Tokens charged', 'Credits', 'Avg latency (ms)', 'Uncertain billing'],
            [
                ['clef', 1, 0, 0, 100, 5, 100, 0, 400, 0],
                ['jev-latest', 1, 0, 0, 100, 5, 100, 0, 400, 0],
            ],
        )
        ->assertSuccessful();
});

it('prints usage as JSON', function () {
    $this->artisan('jev:usage', ['--since' => '7d', '--by' => 'none', '--json' => true])
        ->expectsOutputToContain('"calls": 2')
        ->assertSuccessful();
});

it('rejects invalid options', function (array $options) {
    $this->artisan('jev:usage', $options)->assertFailed();
})->with([
    'grouping' => [['--by' => 'planet']],
    'operation' => [['--operation' => 'chat']],
    'period' => [['--since' => 'whenever!!']],
]);

it('prunes with the default or given retention', function () {
    $this->artisan('jev:prune', ['--days' => '30'])
        ->expectsOutputToContain('Deleted 1 Jev usage record(s) older than 30 day(s).')
        ->assertSuccessful();

    $this->artisan('jev:prune', ['--days' => 'abc'])->assertFailed();
});

it('prints the balance and the models', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/v1/credits' => Http::response(jevFixture('credits')),
        '*/v1/models' => Http::response(jevFixture('models')),
    ]);

    $this->artisan('jev:balance')->expectsOutputToContain('1,250,000')->assertSuccessful();
    $this->artisan('jev:balance', ['--json' => true])->expectsOutputToContain('"credits_remaining": 42')->assertSuccessful();
    $this->artisan('jev:models')->expectsOutputToContain('laya-english')->assertSuccessful();
});

it('fails cleanly when Jev cannot be reached', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(jevFixture('error'), 401)]);

    $this->artisan('jev:balance')->assertFailed();
    $this->artisan('jev:models')->assertFailed();
});
