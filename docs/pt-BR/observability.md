# Observabilidade

> 🇺🇸 [Read in English](../en/observability.md)

Tudo o que uma chamada faz é observável sem mexer no seu código: eventos do Laravel (com a resposta
completa), logs estruturados, spans de tracing, métricas, um card do Pulse e uma tabela de uso.

Todos os sinais de uma chamada compartilham o seu **correlation id** (UUIDv7), que também é enviado para a Jev no
`trace` privado (e como `session_id` quando você não definiu um), para que a página de uso da Jev possa ser cruzada
com os seus logs.

## Eventos

Os eventos são objetos simples e serializáveis, disparados pelo dispatcher do Laravel, então listeners enfileirados
funcionam. Um listener que lança exceção nunca quebra a chamada (o erro vai para o log). Desative-os com
`JEV_AI_EVENTS=false` (os registros de uso continuam funcionando).

Todo evento implementa `JevEvent`: `name()`, `correlationId()`, `context()` (seus dados locais),
`occurredAt()`, e carrega a `connection` e o `model`.

| Evento | `name()` | Quando | Campos principais |
|---|---|---|---|
| `DecisionRequested` | `jev.decision.requested` | chamada validada, antes da primeira tentativa | `operation`, `model`, `questionIds`, `judgeId`, `judgeRevision`, `sessionId`, `user`, `statePreview` (mascarado) |
| `DecisionSucceeded` | `jev.decision.succeeded` | chamada de decisão ou de judge bem-sucedida | `operation`, `result` (`DecisionResult`; `raw` apenas com `events.include_raw`) |
| `WebContextResolved` | `jev.web_context.resolved` | chamada de contexto da web bem-sucedida | `result` (`WebContextResult`) |
| `TokenUsageRecorded` | `jev.usage.recorded` | após todo sucesso | `operation`, `usage`, `billing` |
| `DecisionFailed` | `jev.decision.failed` | qualquer chamada que falhou após os retries | `operation`, `exception` (classe), `message`, `httpStatus`, `errorCode`, `retryable`, `mayHaveBeenBilled`, `attempts`, `latencyMs` |
| `RetryScheduled` | `jev.retry.scheduled` | antes de cada retry | `failedAttempt`, `delayMs`, `reason`, `httpStatus`, `retryAfterSeconds`, `wasRateLimited()` |
| `CreditsExhausted` | `jev.credits.exhausted` | após um 402 | `message`, `errorCode` |
| `JudgeRulesChanged` | `jev.judge.rules_changed` | após um 409 em um judge com revisão fixada | `judgeId`, `pinnedRevision` |
| `BalanceLow` | `jev.balance.low` | tokens restantes < `JEV_AI_LOW_BALANCE_TOKENS` (uma vez por hora e por conexão) | `tokensRemaining`, `threshold` |
| `CircuitOpened` / `CircuitClosed` | `jev.circuit.opened` / `.closed` | transições do circuit breaker | `consecutiveFailures`, `openSeconds` |

Ordem em uma chamada bem-sucedida: `DecisionRequested` → (`RetryScheduled`…) → `DecisionSucceeded` →
`TokenUsageRecorded` → (`BalanceLow`). Em uma falha: `DecisionRequested` → … → `DecisionFailed` →
(`CreditsExhausted` | `JudgeRulesChanged`).

```php
use Sysborg\LaravelJevai\Domain\Events\{DecisionFailed, TokenUsageRecorded};

Event::listen(function (TokenUsageRecorded $event) {
    Billing::addJevSpend($event->context()->get('tenant_id'), $event->billing->inputTokensCharged);
});

Event::listen(function (DecisionFailed $event) {
    if ($event->mayHaveBeenBilled) {
        report("Jev call {$event->correlationId()} timed out and may have been billed.");
    }
});
```

## Logs

Com `JEV_AI_LOG_CALLS=true` (padrão), no `JEV_AI_LOG_CHANNEL` (ou no canal padrão):

| Nível | Mensagem | Contexto |
|---|---|---|
| debug | `Jev call started.` | `correlation_id`, `operation`, `model`, `connection`, `state` (prévia mascarada) |
| debug | `Jev call succeeded.` | + `run_id`, tokens, cobrança, `attempts`, `latency_ms` |
| warning | `Jev attempt failed; retrying.` | `failed_attempt`, `delay_ms`, `exception`, `http_status` |
| error | `Jev call failed.` | `exception`, `message`, `http_status`, `error_code`, `may_have_been_billed`, `attempts` |

Warnings do próprio pacote (um listener com falha, o armazenamento de uso ou o backend de métricas) são sempre registrados no log.

## Tracing

`JEV_AI_TRACING=otel` cria um span CLIENT por chamada (com todas as tentativas dentro dele), definido como span atual para que a
requisição HTTP fique aninhada sob ele. Configure o SDK/exporter do OpenTelemetry na sua aplicação;
o pacote usa apenas `open-telemetry/api` (`composer require open-telemetry/api`).
`JEV_AI_TRACING=log` grava os spans no log, em nível debug.

| Atributo | Exemplo |
|---|---|
| `gen_ai.system` | `jev` |
| `gen_ai.operation.name` | `decision`, `judge`, `web_context` |
| `gen_ai.request.model` / `gen_ai.response.model` | `jev-latest` |
| `gen_ai.response.id` | `id` da resposta |
| `gen_ai.usage.input_tokens` / `output_tokens` | `120` / `8` |
| `jev.correlation_id`, `jev.run_id`, `jev.connection` | |
| `jev.billing.mode`, `jev.tokens_charged`, `jev.credits_charged` | `tokens`, `120`, `0` |
| `jev.attempts` | `2` |
| `http.response.status_code`, `jev.error.code` | em caso de falha |

Os spans nunca contêm o state da requisição.

## Métricas

`JEV_AI_METRICS=otel` (OpenTelemetry), `pulse` (Laravel Pulse) ou `otel,pulse`:

| Métrica | Tipo | Dimensões |
|---|---|---|
| `jev.requests` | counter | `operation`, `model`, `status` (`success`/`error`), `billing_mode` ou `error` |
| `jev.tokens.input` / `jev.tokens.output` | counter | as mesmas |
| `jev.tokens.charged` | counter | as mesmas |
| `jev.credits.charged` | counter | as mesmas |
| `jev.latency` | histogram (ms) | as mesmas |
| `jev.attempts` | histogram | as mesmas |

## Card do Pulse

Com `JEV_AI_METRICS=pulse`, adicione o card em `resources/views/vendor/pulse/dashboard.blade.php`:

```blade
<livewire:jev.usage cols="6" />
```

Ele mostra chamadas, erros, tokens cobrados, créditos e latência média/máxima por model.

## Tabela de uso e relatórios

```bash
php artisan vendor:publish --tag=jev-migrations && php artisan migrate
```

```dotenv
JEV_AI_USAGE_DRIVER=database
```

Uma linha por chamada em `jev_runs` (sucesso ou falha final):

| Coluna | Significado |
|---|---|
| `correlation_id` (unique) | a chamada |
| `operation`, `status` | `decision`/`judge`/`web_context`, `succeeded`/`failed` |
| `model`, `connection`, `run_id` | |
| `judge_id`, `judge_revision`, `session_id`, `user_label` | |
| `http_status`, `error_code` | falhas |
| `input_tokens`, `output_tokens`, `input_tokens_charged`, `credits_charged`, `billing_mode` | custo |
| `billing_uncertain` | a chamada falhou de um jeito que ainda pode ter sido cobrado (timeouts) |
| `latency_ms`, `attempts` | |
| `context` (JSON) | seu contexto local |
| `payload` (JSON) | resposta bruta, apenas com `usage.store_payloads` |
| `occurred_at` (UTC) | |

O state da requisição nunca é armazenado.

```php
use Sysborg\LaravelJevai\Facades\JevUsage;

JevUsage::lastDays(7)->byModel()->get();          // list<UsageSummary>, um por model
JevUsage::today()->total()->inputTokensCharged;    // total único
JevUsage::period('30d')->byDay()->whereUser('42')->get();

$summary->calls; $summary->failures; $summary->errorRate();
$summary->inputTokensCharged; $summary->creditsCharged; $summary->averageLatencyMs();
$summary->uncertainBillings;
```

Agrupamentos: `byModel`, `byConnection`, `byOperation`, `byUser`, `bySession`, `byDay`. Filtros:
`whereModel`, `whereConnection`, `whereOperation`, `whereUser`, `whereSession`.

O model Eloquent `JevRun` está disponível para consultas customizadas. Agende a limpeza:

```php
Schedule::command('jev:prune')->daily();
```
