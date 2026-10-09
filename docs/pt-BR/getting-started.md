# Primeiros passos

> 🇺🇸 [Read in English](../en/getting-started.md)

## Requisitos

- PHP 8.3+
- Laravel 11.45+, 12 ou 13
- Uma API key da Jev ([obtenha a sua](https://jev-ai.pro/jev-api-key))

## Instalação

```bash
composer require sysborg/laravel-jevai
```

```dotenv
JEV_AI_API_KEY=your-key
```

Isso já basta para fazer chamadas. Passos opcionais:

```bash
php artisan vendor:publish --tag=jev-config       # config/jev.php
php artisan vendor:publish --tag=jev-migrations   # tabela jev_runs, para relatórios de uso
php artisan migrate
```

O pacote se registra sozinho (auto-discovery) com duas facades: `Jev` e `JevUsage`.

## Sua primeira decisão

A Jev responde **perguntas tipadas** sobre um **state** (texto ou dados estruturados):

| Tipo | Pergunta | Resposta |
|---|---|---|
| `noul` | uma pergunta de sim/não | probabilidade de "sim", 0..1 |
| `choice` | escolher uma entre 2–255 opções nomeadas | a opção, sua confiança e as probabilidades |
| `score` | avaliar em 2–10 níveis ordenados | nível esperado, nível mais provável, label |

```php
use Sysborg\LaravelJevai\Facades\Jev;

$result = Jev::state($ticket->body)
    ->noul('is_urgent', 'Does this convey urgency?')
    ->choice('department', 'Which team should handle this?', [
        'billing'   => 'Payments and refunds',
        'technical' => 'Bugs and outages',
        'sales'     => 'Pricing',
    ])
    ->score('frustration', 'How frustrated is the customer?', ['Calm', 'Frustrated', 'Very angry'])
    ->evaluate();

$result->noul('is_urgent')->isYes();        // true (probabilidade >= 0.5)
$result->noul('is_urgent')->isYes(0.9);     // limiar mais rigoroso
$result->choice('department')->choice;      // 'billing'
$result->choice('department')->probability('technical'); // 0.01
$result->score('frustration')->label();     // 'Frustrated'
```

State estruturado também funciona: `Jev::state(['subject' => $subject, 'body' => $body, 'plan' => 'pro'])`.

## O builder

Todo método retorna um **novo** builder, então um builder parcialmente configurado pode ser reaproveitado.

```php
$result = Jev::model('clef')                       // ou Jev::state(...) / Jev::request()
    ->state($text)
    ->noul('is_spam', 'Is this spam?')
    ->session("ticket-{$ticket->id}")              // label session_id da Jev
    ->user((string) auth()->id())                  // label de usuário da Jev
    ->trace(['pipeline' => 'triage-v2'])           // armazenado de forma privada pela Jev
    ->context(['ticket_id' => $ticket->id])        // nunca é enviado: anexado a todos os eventos
    ->evaluate();
```

`trace` é enviado para a Jev (privado, máx. 64 campos / 8 KB). `context` **nunca sai da sua aplicação**:
ele acompanha todos os eventos e registros de uso para que você consiga associar os resultados de volta aos seus models.

## O que um resultado carrega

```php
$result->answers;                    // AnswerSet, iterável pelo id da pergunta
$result->usage->inputTokens;         // 120
$result->usage->outputTokens;        // 8   (tokens de saída são gratuitos na Jev)
$result->billing->mode;              // BillingMode::Tokens | Credits | CreditsFallback | TokensFallback | Unknown
$result->billing->inputTokensCharged;
$result->billing->creditsCharged;
$result->billing->tokensRemaining;   // de X-Jev-Tokens-Remaining, quando enviado
$result->meta->correlationId;        // UUIDv7, também presente no trace e no session_id da Jev
$result->meta->runId;                // X-Jev-Run-Id, quando enviado
$result->meta->attempts;             // tentativas HTTP, incluindo retries
$result->meta->latencyMs;            // chamada inteira, incluindo retries
```

`usage->cost` é sempre `null`: a Jev não o retorna, e um custo ausente é **desconhecido**, não
zero. A cobrança real está em `billing`.

## Judges salvos

```php
Jev::judge('judge_123')->state($transcript)->evaluate();              // regras mais recentes
Jev::judge('judge_123', revision: 3)->state($transcript)->evaluate(); // falha (409) se as regras mudaram
```

Uma chamada fixada em uma revisão que é recusada porque as regras mudaram lança `JudgeRevisionMismatch` e dispara
`JudgeRulesChanged`.

## Contexto da web

Uma pergunta de sim/não respondida com e sem evidências recentes da web:

```php
$result = Jev::webContext('Has OpenAI released GPT-6?')
    ->query('OpenAI releases GPT-6 announcement')
    ->criteria(yes: 'A model named GPT-6 is public', no: 'No such model is public')
    ->numResults(6)
    ->resolve();

$result->isYes();                    // decisão final
$result->confidence;                 // 0.88
$result->evidenceChangedDecision();  // as evidências mudaram a resposta?
$result->sources;                    // list<WebSource>
```

Cada busca na web custa 170.000 tokens de entrada. Forneça suas próprias evidências com
`->sources(new WebSource($title, $url, $publishedAt, $highlights))` para evitar a taxa da busca.

## Avaliação em segundo plano

```php
$pending = Jev::state($ticket->body)
    ->noul('is_urgent', 'Urgent?')
    ->context('ticket_id', $ticket->id)
    ->queue();

$pending->correlationId; // o resultado chega por eventos que carregam este id
```

```php
use Sysborg\LaravelJevai\Domain\Events\DecisionSucceeded;

Event::listen(function (DecisionSucceeded $event) {
    Ticket::find($event->context()->get('ticket_id'))
        ?->markUrgent($event->result->noul('is_urgent')->isYes());
});
```

O job (`EvaluateDecisionJob`) roda **uma única vez**: falhas são reportadas via `DecisionFailed`
em vez de fazer o job falhar, porque um job repetido poderia ser cobrado duas vezes. Configure a fila com
`JEV_AI_QUEUE_CONNECTION` / `JEV_AI_QUEUE`.

## Erros

Toda exceção implementa `Sysborg\LaravelJevai\Domain\Exceptions\JevThrowable`. Falhas de chamada
estendem `JevException`, que diz o que é seguro fazer:

```php
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;

try {
    $result = Jev::state($text)->noul('q', 'Q?')->evaluate();
} catch (JevException $e) {
    $e->httpStatus;          // null quando nenhuma resposta chegou
    $e->errorCode;           // error.code da Jev
    $e->isRetryable();       // é seguro tentar de novo mais tarde?
    $e->mayHaveBeenBilled(); // true para timeouts: confira o uso antes de tentar de novo
}
```

| Exceção | Quando | Repetida pelo pacote |
|---|---|---|
| `InvalidValue` | um valor viola um limite documentado (verificado antes do envio) | — |
| `Unauthorized` | 401 | não |
| `InsufficientCredits` | 402 | não |
| `JudgeNotFound` / `JudgeRevisionMismatch` | 404 / 409 em chamadas de judge | não |
| `InvalidRequest` | 400 / 422 | não |
| `RateLimited` | 429 | sim, respeitando `Retry-After` |
| `UpstreamFailure` | 502 / 503 / 504 | sim, com backoff |
| `TransportFailure` | timeout, conexão interrompida (pode ter sido cobrada) | não (opt-in) |
| `UnexpectedResponse` | o body não pode ser interpretado | não |
| `ApiError` | qualquer outro status | não |
| `LocallyRateLimited`, `CircuitOpen`, `BudgetExceeded` | limites no lado do cliente (não enviada) | não |

## Conta e uso

```php
Jev::models();   // ['jev-latest', 'clef', ...] (gratuito)
Jev::balance();  // Balance: creditsRemaining, paidInputTokensRemaining (gratuito)
```

```bash
php artisan jev:models
php artisan jev:balance
php artisan jev:usage --since=7d --by=model     # requer JEV_AI_USAGE_DRIVER=database
php artisan jev:prune --days=90
```

Próximos: [Configuração](configuration.md) · [Observabilidade](observability.md)
