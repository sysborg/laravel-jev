<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sysborg\LaravelJevai\Adapters\Jev\ApiKey;
use Sysborg\LaravelJevai\Adapters\Jev\ConnectionRegistry;
use Sysborg\LaravelJevai\Adapters\Jev\GatewayFactory;
use Sysborg\LaravelJevai\Adapters\Jev\JevConnection;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

$valid = ['api_key' => 'sk-test', 'base_url' => 'https://jev-ai.pro/api', 'model' => 'jev-latest'];

describe('JevConnection', function () use ($valid) {
    it('normalizes its settings', function () use ($valid) {
        $connection = JevConnection::fromConfig('default', [
            ...$valid,
            'base_url' => 'https://jev-ai.pro/api/',
            'timeout' => ['connect' => '3', 'request' => 60],
        ]);

        expect($connection->baseUrl)->toBe('https://jev-ai.pro/api')
            ->and($connection->connectTimeout)->toBe(3)
            ->and($connection->requestTimeout)->toBe(60)
            ->and($connection->apiKey->reveal())->toBe('sk-test');
    });

    it('falls back to documented defaults', function () {
        $connection = JevConnection::fromConfig('default', ['api_key' => 'sk-test']);

        expect($connection->baseUrl)->toBe('https://jev-ai.pro/api')
            ->and($connection->model)->toBe('jev-latest')
            ->and($connection->connectTimeout)->toBe(5)
            ->and($connection->requestTimeout)->toBe(30);
    });

    it('requires an api key', function (mixed $key) use ($valid) {
        JevConnection::fromConfig('default', [...$valid, 'api_key' => $key]);
    })->throws(InvalidValue::class, 'JEV_AI_API_KEY')->with([null, '', '   ']);

    it('requires https', function () use ($valid) {
        JevConnection::fromConfig('default', [...$valid, 'base_url' => 'http://jev-ai.pro/api']);
    })->throws(InvalidValue::class, 'must use https://');

    it('allows http only when explicitly permitted (testing)', function () use ($valid) {
        $connection = JevConnection::fromConfig('local', [...$valid, 'base_url' => 'http://localhost:8080/api'], allowInsecure: true);

        expect($connection->baseUrl)->toBe('http://localhost:8080/api');
    });

    it('rejects invalid urls and timeouts', function (array $override, string $message) use ($valid) {
        JevConnection::fromConfig('default', [...$valid, ...$override]);
    })->throws(InvalidValue::class)->with([
        'not a url' => [['base_url' => 'jev-ai.pro'], 'valid URL'],
        'ftp' => [['base_url' => 'ftp://jev-ai.pro'], 'https://'],
        'text timeout' => [['timeout' => ['request' => 'soon']], 'integer'],
        'zero timeout' => [['timeout' => ['request' => 0]], 'at least 1 second'],
    ]);
});

describe('ConnectionRegistry', function () use ($valid) {
    it('resolves the default and named connections once', function () use ($valid) {
        $registry = new ConnectionRegistry(['default' => $valid, 'tenant-a' => [...$valid, 'model' => 'clef']], 'default');

        expect($registry->get()->name)->toBe('default')
            ->and($registry->get('tenant-a')->model)->toBe('clef')
            ->and($registry->get('tenant-a'))->toBe($registry->get('tenant-a'))
            ->and($registry->names())->toBe(['default', 'tenant-a'])
            ->and($registry->defaultName())->toBe('default');
    });

    it('fails for unknown connections', function () use ($valid) {
        (new ConnectionRegistry(['default' => $valid], 'default'))->get('missing');
    })->throws(InvalidValue::class, 'Jev connection [missing]');
});

describe('GatewayFactory', function () {
    it('sends each connection to its own base url, key and default model', function () {
        config()->set('jev.connections.tenant-a', [
            'api_key' => 'sk-tenant-a',
            'base_url' => 'https://eu.jev.example/api',
            'model' => 'laya-multilingual',
        ]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['answers' => ['q' => ['type' => 'noul', 'noul' => 0.5]]])]);

        app(GatewayFactory::class)->decision('tenant-a')->decide(
            DecisionRequest::forQuestions('text', QuestionSet::of(new NoulQuestion('q', 'Q?'))),
            new CorrelationId('corr-3'),
        );

        Http::assertSent(fn (Request $request) => $request->url() === 'https://eu.jev.example/api/v1/systemone'
            && $request->header('Authorization') === ['Bearer sk-tenant-a']
            && $request['model'] === 'laya-multilingual');
    });

    it('does not validate connections until they are used', function () {
        config()->set('jev.connections.default.api_key', null);

        expect(app(GatewayFactory::class))->toBeInstanceOf(GatewayFactory::class)
            ->and(fn () => app(GatewayFactory::class)->decision())->toThrow(InvalidValue::class, 'api_key');
    });
});

describe('ApiKey', function () {
    $secret = 'sk-live-super-secret-0123456789';

    it('only reveals the key on purpose', function () use ($secret) {
        $key = new ApiKey($secret);

        expect($key->reveal())->toBe($secret)
            ->and((string) $key)->toBe('[redacted]')
            ->and(json_encode(['key' => $key]))->toBe('{"key":"[redacted]"}');
    });

    it('never leaks through dumps, exports or casts', function () use ($secret) {
        $key = new ApiKey($secret);
        $connection = new JevConnection('default', $key, 'https://jev-ai.pro/api', 'jev-latest', 5, 30);

        ob_start();
        var_dump($key, $connection);
        $dumped = (string) ob_get_clean();

        $outputs = [
            $dumped,
            print_r($key, true),
            print_r($connection, true),
            var_export($key, true),
            var_export($connection, true),
            json_encode((array) $key),
            json_encode($connection),
        ];

        foreach ($outputs as $output) {
            expect($output)->not->toContain($secret);
        }
    });

    it('refuses serialization so it never reaches a queued job', function () use ($secret) {
        serialize(new ApiKey($secret));
    })->throws(LogicException::class, 'cannot be serialized');

    it('cannot be cloned', function () use ($secret) {
        $key = new ApiKey($secret);

        clone $key;
    })->throws(Error::class);

    it('hides the key from stack traces', function () {
        $reflection = new ReflectionParameter([ApiKey::class, '__construct'], 'value');

        expect($reflection->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    });
});
