<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Sysborg\LaravelJevai\Adapters\Jev\ConnectionRegistry;
use Sysborg\LaravelJevai\Adapters\Queue\EvaluateDecisionJob;
use Sysborg\LaravelJevai\Domain\Events\JevEvent;
use Sysborg\LaravelJevai\Facades\Jev;

const API_KEY_CANARY = 'sk-live-CANARY-7f3a9c2e-never-leak';
const STATE_CANARY = 'CARD-4111-1111-1111-1111-CANARY';

beforeEach(function () {
    config()->set('jev.connections.default.api_key', API_KEY_CANARY);
    config()->set('jev.observability.tracing', 'log');
    config()->set('jev.logging.calls', true);
    config()->set('jev.events.include_raw', true);
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('jev.usage.driver', 'database');
    (require __DIR__.'/../../../database/migrations/create_jev_runs_table.php')->up();

    $this->captured = [];

    Event::listen(MessageLogged::class, function (MessageLogged $log): void {
        $this->captured[] = $log->message.' '.json_encode($log->context);
    });
    Event::listen('Sysborg\LaravelJevai\Domain\Events\*', function (string $name, array $payload): void {
        foreach ($payload as $event) {
            if ($event instanceof JevEvent) {
                $this->captured[] = serialize($event).json_encode((array) $event).print_r($event, true);
            }
        }
    });

    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(jevFixture('decision-success'), 200, ['X-Jev-Tokens-Remaining' => '10'])
        ->push(jevFixture('error'), 503)
        ->push(jevFixture('decision-success'))
        ->push(jevFixture('error'), 401)
        ->pushFailedConnection();
});

/**
 * Run the scenario: success, retried success, auth failure and timeout.
 *
 * @param  string  $state  The request state.
 * @return list<string> Captured exception dumps.
 */
function runScenario(string $state): array
{
    $dumps = [];

    Jev::state($state)->noul('q', 'Q?')->trace('ticket_id', 42)->context('ticket_id', 42)->evaluate();
    Jev::state($state)->noul('q', 'Q?')->evaluate();

    foreach ([1, 2] as $_) {
        try {
            Jev::state($state)->noul('q', 'Q?')->evaluate();
        } catch (Throwable $e) {
            $dumps[] = $e->getMessage().' '.$e->getTraceAsString().' '.($e->getPrevious()?->getMessage() ?? '');
        }
    }

    return $dumps;
}

it('never leaks the API key into logs, spans, events, exceptions, records, jobs or dumps', function () {
    $exceptions = runScenario('hello');

    Bus::fake();
    Jev::state('hello')->noul('q', 'Q?')->queue();

    // The package's own objects that hold the key. (The HTTP client and the
    // container reference Laravel's config repository, which holds the env
    // value like any other secret, so they are not dumped here.)
    $registry = app(ConnectionRegistry::class);
    $connection = $registry->get();

    ob_start();
    var_dump($registry, $connection);
    $dumped = (string) ob_get_clean();

    $surfaces = [
        'logs, spans and events' => implode("\n", $this->captured),
        'exceptions' => implode("\n", $exceptions),
        'usage table' => json_encode(DB::table('jev_runs')->get()),
        'queued job' => serialize(Bus::dispatched(EvaluateDecisionJob::class)->first()),
        'var_dump' => $dumped,
        'print_r' => print_r($registry, true).print_r($connection, true),
        'var_export' => var_export($registry, true).var_export($connection, true),
    ];

    expect($this->captured)->not->toBeEmpty();

    foreach ($surfaces as $surface => $content) {
        expect(str_contains((string) $content, API_KEY_CANARY))->toBeFalse("The API key leaked into: {$surface}");
    }

    Http::assertSent(fn ($request) => $request->header('Authorization') === ['Bearer '.API_KEY_CANARY]);
});

it('keeps redacted state out of logs, spans, events and the usage table', function (string $strategy) {
    config()->set('jev.redaction.state', $strategy);

    runScenario(STATE_CANARY);

    $surfaces = [
        'logs, spans and events' => implode("\n", $this->captured),
        'usage table' => json_encode(DB::table('jev_runs')->get()),
    ];

    foreach ($surfaces as $surface => $content) {
        expect(str_contains((string) $content, STATE_CANARY))->toBeFalse("The state leaked into: {$surface} ({$strategy})");
    }

    Http::assertSent(fn ($request) => json_decode($request->body(), true)['state'] === STATE_CANARY);
})->with(['hash', 'omit', 'truncate:4']);

it('shows the full state only when redaction is explicitly disabled', function () {
    config()->set('jev.redaction.state', 'none');

    runScenario(STATE_CANARY);

    expect(implode("\n", $this->captured))->toContain(STATE_CANARY)
        ->and(json_encode(DB::table('jev_runs')->get()))->not->toContain(STATE_CANARY);
});
