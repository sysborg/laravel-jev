<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Facades\Jev;

/*
 * Real calls against the Jev API. Skipped unless JEV_LIVE_TESTS=1 and a real
 * JEV_AI_API_KEY are exported. Each run costs a few input tokens.
 *
 *   JEV_LIVE_TESTS=1 JEV_AI_API_KEY=sk-... composer test:live
 */

beforeEach(function () {
    if (getenv('JEV_LIVE_TESTS') !== '1') {
        $this->markTestSkipped('Set JEV_LIVE_TESTS=1 and a real JEV_AI_API_KEY to run live tests.');
    }
});

it('evaluates a noul question on jev-latest and reports usage and billing', function () {
    $result = Jev::model('jev-latest')
        ->state('The server has been down for two hours and customers are complaining.')
        ->noul('is_urgent', 'Does this convey urgency?')
        ->evaluate();

    fwrite(STDERR, sprintf(
        "\n[live] noul=%.3f input_tokens=%d billing=%s charged=%d credits=%s run_id=%s latency=%dms\n",
        $result->noul('is_urgent')->noul,
        $result->usage->inputTokens,
        $result->billing->mode->value,
        $result->billing->inputTokensCharged,
        $result->billing->creditsCharged,
        $result->meta->runId ?? 'none',
        $result->meta->latencyMs,
    ));

    expect($result->noul('is_urgent')->noul)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0)
        ->and($result->usage->inputTokens)->toBeGreaterThan(0)
        ->and($result->billing->mode)->not->toBe(BillingMode::Unknown)
        ->and($result->billing->wasCharged())->toBeTrue();
});

it('lists models and reads the balance', function () {
    expect(Jev::models())->toContain('jev-latest')
        ->and(Jev::balance()->creditsRemaining)->toBeGreaterThanOrEqual(0.0);
});
