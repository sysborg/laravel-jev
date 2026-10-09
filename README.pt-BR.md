# laravel-jevai

> 🇺🇸 [Read in English](README.md)

> **Beta aberto (`0.x`).** Veja [o que isso significa](docs/pt-BR/beta.md).

Pacote Laravel para a [API de decisão da Jev AI](https://jev-ai.pro/docs), construído com ports & adapters
e observabilidade de primeira classe: a resposta, o consumo de tokens e a cobrança de cada chamada voltam
para a sua aplicação por meio de eventos do Laravel, logs, traces, métricas, um card do Pulse e uma tabela de uso.

```php
use Sysborg\LaravelJevai\Facades\Jev;

$result = Jev::state($ticket->body)
    ->noul('is_urgent', 'Does this convey urgency?')
    ->choice('department', 'Which team should handle this?', [
        'billing' => 'Payments and refunds',
        'technical' => 'Bugs and outages',
    ])
    ->score('frustration', 'How frustrated is the customer?', ['Calm', 'Frustrated', 'Very angry'])
    ->context('ticket_id', $ticket->id)   // fica na sua aplicação, anexado a todos os eventos
    ->evaluate();

$result->choice('department')->choice;    // 'billing'
$result->usage->inputTokens;              // 120
$result->billing->inputTokensCharged;     // 120
$result->meta->correlationId;             // UUIDv7, compartilhado por eventos, logs, spans e o trace da Jev
```

## Destaques

- **Decisões tipadas**: perguntas `noul`, `choice` e `score`, judges salvos (`Jev::judge()`) e
  contexto da web (`Jev::webContext()`), com builders fluentes e imutáveis.
- **Respostas via eventos**: `DecisionSucceeded`, `TokenUsageRecorded`, `DecisionFailed`,
  `RetryScheduled`, `BalanceLow`, `CreditsExhausted`… todos serializáveis, com o seu `context`.
  `->queue()` avalia em segundo plano e entrega o resultado pelos mesmos eventos.
- **Retries seguros para a cobrança**: 429 (`Retry-After`) e 5xx são repetidos; timeouts não, porque
  a Jev não tem chave de idempotência. Rate limiter, circuit breaker e orçamento diário de tokens opcionais.
- **Observabilidade**: logs estruturados, spans e métricas OpenTelemetry, card do Laravel Pulse,
  tabela de uso `jev_runs` com relatórios via `JevUsage` e `php artisan jev:usage`.
- **Seguro por padrão**: a API key nunca aparece em logs, eventos, spans, exceções, dumps ou
  jobs enfileirados; o state da requisição é mascarado em todo lugar onde é observado.
- **Testável**: `Jev::fake([...])` executa o pipeline real sem HTTP, com assertions.

## Requisitos

PHP 8.3+ · Laravel 11.45+, 12 ou 13

## Instalação

```bash
composer require sysborg/laravel-jevai
```

```dotenv
JEV_AI_API_KEY=your-key
```

## Documentação

| | |
|---|---|
| [Primeiros passos](docs/pt-BR/getting-started.md) | instalação, primeira decisão, judges, contexto da web, fila, erros |
| [Configuração](docs/pt-BR/configuration.md) | todas as chaves de config e variáveis de ambiente |
| [Arquitetura](docs/pt-BR/architecture.md) | ports & adapters, o pipeline de chamadas, adapters customizados |
| [Observabilidade](docs/pt-BR/observability.md) | catálogo de eventos, logs, tracing, métricas, Pulse, relatórios de uso |
| [Cobrança, retries e limites](docs/pt-BR/billing-and-retries.md) | o que é cobrado, o que é repetido, limites no lado do cliente |
| [Segurança](docs/pt-BR/security.md) | tratamento da API key, mascaramento, o que sai da sua aplicação |
| [Testes](docs/pt-BR/testing.md) | `Jev::fake()`, assertions, smoke tests reais |
| [Beta aberto](docs/pt-BR/beta.md) | garantias de estabilidade, limitações conhecidas, como reportar problemas |

## Contribuindo

Veja [CONTRIBUTING.md](CONTRIBUTING.md). Problemas de segurança: [SECURITY.md](SECURITY.md).

## Licença

MIT. Veja [LICENSE](LICENSE).
