<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;
use Sysborg\LaravelJevai\Adapters\Pulse\JevUsageCard;
use Sysborg\LaravelJevai\Adapters\Pulse\PulseMetricsRecorder;
use Sysborg\LaravelJevai\Facades\Jev;
use Sysborg\LaravelJevai\Ports\Driven\MetricsRecorder;

it('records calls into Pulse and shows them on the card', function () {
    expect(app(MetricsRecorder::class))->toBeInstanceOf(PulseMetricsRecorder::class);

    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(jevFixture('decision-success'), 200, ['X-Jev-Billing' => 'tokens'])
        ->push(jevFixture('error'), 401);

    Jev::state('text')->model('clef')->noul('is_urgent', 'Urgent?')->evaluate();

    try {
        Jev::state('text')->model('clef')->noul('is_urgent', 'Urgent?')->evaluate();
    } catch (Throwable) {
        // the failure is what we want to see on the card
    }

    Pulse::ingest();

    Livewire::test(JevUsageCard::class, ['lazy' => false])
        ->assertSee('Jev AI')
        ->assertSee('jev-latest')
        ->assertSee('clef')
        ->assertSee('120');
});

it('is registered as <livewire:jev.usage />', function () {
    expect(app('livewire')->new('jev.usage'))->toBeInstanceOf(JevUsageCard::class);
});
