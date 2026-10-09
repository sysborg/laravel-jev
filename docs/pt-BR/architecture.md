# Arquitetura

> 🇺🇸 [Read in English](../en/architecture.md)

O pacote segue **ports & adapters** (arquitetura hexagonal). As regras são garantidas por
testes de arquitetura (`tests/Architecture`).

```
                      ┌──────────────────────── your Laravel app ────────────────────────┐
                      │   Jev facade · DecisionBuilder · WebContextBuilder · JevUsage      │
                      └───────────────────────────────┬──────────────────────────────────┘
                                                      │ driving port: Ports\Driving\Jev
┌─────────────────────────────────────────────────────▼─────────────────────────────────────────────┐
│ Application  JevClient → use cases → Pipeline (stages below) → gateway                              │
│ Domain       questions, answers, requests, results, usage, billing, events, exceptions (pure PHP)   │
└──────┬───────────┬───────────┬────────────┬───────────┬───────────┬───────────┬───────────┬────────┘
       │ driven ports (interfaces in Ports\Driven)                                             
 DecisionGateway  EventPublisher  UsageRepository  Tracer  MetricsRecorder  CounterStore  DecisionQueue  ...
       │               │                │             │           │              │             │
 HttpDecisionGateway  Laravel events  Eloquent/Null  Log/OTel   OTel/Pulse   Laravel cache   Laravel queue
 JevFake (tests)                                                                            JevFake (tests)
```

| Camada | Namespace | Pode depender de |
|---|---|---|
| Domain | `Sysborg\LaravelJevai\Domain` | nada (PHP puro) |
| Ports | `Sysborg\LaravelJevai\Ports` | Domain (apenas interfaces) |
| Application | `Sysborg\LaravelJevai\Application` | Domain, Ports, PSR-3 |
| Adapters | `Sysborg\LaravelJevai\Adapters` | qualquer coisa (Laravel, Guzzle, OpenTelemetry, Pulse) |
| Composition root | `JevServiceProvider` | tudo |

## O pipeline de chamadas

Toda chamada passa pelos mesmos estágios, do mais externo para o mais interno:

```
Correlate → Validate → Redact → Log → Alert → Emit → Record → Measure → Budget → Trace → Retry → Protect → gateway
```

| Estágio | Uma vez por | Faz |
|---|---|---|
| Correlate | chamada | correlation id UUIDv7; define `trace.correlation_id` e um `session_id` de fallback; conexão e model padrão |
| Validate | chamada | rejeita bodies acima de 256.000 bytes antes de qualquer envio |
| Redact | chamada | prévia mascarada do state para observabilidade; remove campos de trace proibidos |
| Log | chamada | logs estruturados de debug/erro |
| Alert | chamada | `CreditsExhausted`, `JudgeRulesChanged`, `BalanceLow` |
| Emit | chamada | `DecisionRequested`, depois `DecisionSucceeded`/`WebContextResolved` + `TokenUsageRecorded`, ou `DecisionFailed` |
| Record | chamada | um registro de uso (sucesso ou falha final) |
| Measure | chamada | métricas |
| Budget | chamada | orçamento diário de tokens cobrados |
| Trace | chamada | um span cobrindo todas as tentativas |
| Retry | chamada | política de retry, `RetryScheduled`, total final de tentativas e latência |
| Protect | **tentativa** | rate limiter e circuit breaker |
| gateway | tentativa | exatamente uma requisição HTTP, sem efeitos colaterais |

Gateways nunca fazem retry, log ou emitem eventos: é por isso que o fake se comporta exatamente como o adapter HTTP.
Emit, Record, Measure, Alert, Log e os stores de limites **nunca quebram uma chamada**: as falhas deles mesmos
são registradas no log como warnings.

## Escrevendo seu próprio adapter

Implemente uma port e faça o bind dela em um service provider registrado depois do provider do pacote:

```php
use Sysborg\LaravelJevai\Domain\Usage\{RunRecord, UsageQuery};
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;

final class ClickHouseUsageRepository implements UsageRepository
{
    public function record(RunRecord $record): void { /* inserir */ }
    public function summarize(UsageQuery $query): array { /* agregar em UsageSummary[] */ }
    public function prune(DateTimeImmutable $before): int { /* excluir */ }
}

// AppServiceProvider::register()
$this->app->singleton(UsageRepository::class, ClickHouseUsageRepository::class);
```

O mesmo vale para `Tracer`, `MetricsRecorder`, `EventPublisher`, `CounterStore`, `Debouncer`,
`DecisionQueue`, `Clock` e `IdGenerator`. Rode a suíte de contrato em `tests/Feature/Contract` para
validar um `DecisionGateway` customizado.
