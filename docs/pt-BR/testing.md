# Testes

> 🇺🇸 [Read in English](../en/testing.md)

## Simulando a Jev com fake

```php
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Facades\Jev;

it('routes urgent billing tickets', function () {
    Jev::fake([
        'department' => 'billing',   // choice: a opção
        'is_urgent'  => true,        // noul: bool ou 0..1
        'frustration' => 2,          // score: o nível
    ])->withUsage(input: 120);

    $this->post('/tickets', ['body' => 'My card was charged twice!'])->assertCreated();

    Jev::assertEvaluated(fn (DecisionRequest $r) => $r->questions?->has('department'));
    Jev::assertTokensUsedLessThan(500);
});
```

`Jev::fake()` substitui apenas os gateways e a fila. **O pipeline real continua rodando**: os eventos
são disparados, o uso é registrado, retries e alertas acontecem exatamente como em produção, e nenhuma requisição HTTP
é feita. Perguntas não listadas recebem respostas neutras (noul 0.5, primeira opção do choice, score 0).

### Sequências e falhas

```php
use Sysborg\LaravelJevai\Domain\Exceptions\{InsufficientCredits, UpstreamFailure};

Jev::fake()->sequence(
    ['department' => 'billing'],
    new InsufficientCredits,                 // a próxima chamada lança a exceção
    ['department' => 'technical'],
    fn (DecisionRequest $r, CorrelationId $id) => $customResult,
);

Jev::fake()->failWith(new UpstreamFailure(503)); // repetida pelo pipeline, depois tem sucesso
```

### Cobrança, alertas, contexto da web, conta

```php
Jev::fake()
    ->withBilling(BillingMode::CreditsFallback, creditsCharged: 1.0, tokensRemaining: 0)
    ->webContextAnswer('no', 0.75)
    ->withAccount(['jev-latest', 'clef'], credits: 3.0, tokens: 10);
```

### Assertions

| Assertion | Verifica |
|---|---|
| `Jev::assertEvaluated(?fn)` | alguma tentativa de decisão correspondeu |
| `Jev::assertNotEvaluated(?fn)` | nenhuma tentativa correspondeu |
| `Jev::assertNothingEvaluated()` | nenhuma chamada |
| `Jev::assertEvaluatedTimes(n)` | número de tentativas (incluindo retries) |
| `Jev::assertJudgeUsed($id, ?$revision)` | um judge salvo foi usado |
| `Jev::assertTokensUsedLessThan(n)` | soma dos tokens de entrada dos resultados fake |
| `Jev::assertWebContextResolved(?fn)` | alguma pergunta de contexto da web correspondeu |
| `Jev::assertQueued(?fn)` | `->queue()` foi chamado (nada é despachado) |

`Jev::fake()` retorna o `JevFake`; `$fake->recorded()` lista todas as requisições enviadas.

## Eventos nos testes

```php
Event::fake([DecisionSucceeded::class]);
Jev::fake(['is_urgent' => true]);

// ...

Event::assertDispatched(DecisionSucceeded::class, fn ($e) => $e->context()->get('ticket_id') === 42);
```

## Smoke tests reais

O pacote inclui testes opt-in contra a API real (alguns tokens de entrada por execução):

```bash
JEV_LIVE_TESTS=1 JEV_AI_API_KEY=sk-... composer test:live
```

## Contribuindo com o pacote

```bash
composer check          # Pint, Larastan (max), Pest
composer test:coverage  # Domain + Application >= 90% (requer pcov ou Xdebug)
composer test:mutate    # mutation score do Domain >= 80%
```
