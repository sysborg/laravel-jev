# Cobrança, retries e limites

> 🇺🇸 [Read in English](../en/billing-and-retries.md)

## Como a Jev cobra

- **Tokens de saída são gratuitos.** Cada chamada consome **ou** tokens de entrada pagos (multiplicados pela
  taxa do model: Jev 1×, Laya 2×, Clef 6×…) **ou** créditos, nunca os dois.
- Se os tokens pagos não cobrirem uma chamada, a chamada inteira é cobrada em créditos (`credits-fallback`),
  e vice-versa (`tokens-fallback`).
- Execuções que falharam (402, 422, 502/504…) são reembolsadas ou não são cobradas.
- Uma busca na web custa 170.000 tokens de entrada.

Cada resultado expõe isso como `billing` (`mode`, `inputTokensCharged`, `creditsCharged`,
`tokensRemaining`), lido do body da resposta e dos headers `X-Jev-*`. `usage->cost` continua
`null`: a Jev não o retorna.

## O que é repetido

A Jev **não tem chave de idempotência**, então o pacote só faz retry de falhas que com certeza não foram cobradas:

| Falha | Repetida | Como |
|---|---|---|
| 429 rate limited | sim | espera exatamente o `Retry-After`; falha imediatamente se for maior que `retry.max_retry_after_seconds` (30) |
| 502 / 503 / 504 | sim | backoff exponencial com jitter (250 ms, 500 ms, 1 s… limitado a 5 s) |
| timeout / conexão interrompida | **não** (padrão) | a chamada pode ter sido executada e cobrada |
| 400, 401, 402, 404, 409, 422, outros | nunca | tentar de novo não resolve |

Toda chamada é limitada por `retry.max_attempts` (3) e `retry.max_elapsed_ms` (60 s). Cada retry
dispara `RetryScheduled` e registra um warning no log.

### Timeouts

Um timeout lança `TransportFailure` com `mayHaveBeenBilled() === true`, e o registro de uso é
marcado como `billing_uncertain`. Ative `JEV_AI_RETRY_ON_TIMEOUT=true` apenas se pagar duas vezes por algumas
chamadas for aceitável.

Decisões enfileiradas também nunca fazem retry no nível do job (`$tries = 1`).

## Limites no lado do cliente

Todos desligados por padrão; os contadores são compartilhados pelo cache (use Redis ou outro store compartilhado quando houver
vários servidores). Se o cache falhar, as chamadas são liberadas e um warning é registrado no log.

### Rate limiter

```dotenv
JEV_AI_RATE_LIMIT_PER_MINUTE=900     # a Jev permite 1000 por conta
JEV_AI_RATE_LIMIT_BLOCK=false        # true: espera o próximo minuto (no máximo 10 s)
```

Conta toda tentativa HTTP, incluindo retries. Acima do limite: `LocallyRateLimited` (com
`retryAfterSeconds`), e nada é enviado.

### Circuit breaker

```dotenv
JEV_AI_CIRCUIT_BREAKER_FAILURES=5
JEV_AI_CIRCUIT_BREAKER_COOLDOWN=30
```

Após 5 respostas 502/503/504 ou timeouts consecutivos, as tentativas falham imediatamente com `CircuitOpen` por 30 s
(evento `CircuitOpened`). Depois do cooldown, as chamadas voltam a passar; o primeiro sucesso dispara
`CircuitClosed`. Erros do cliente não contam, e qualquer sucesso zera a contagem.

### Orçamento diário

```dotenv
JEV_AI_DAILY_INPUT_TOKENS=5000000
```

Os tokens de entrada cobrados são contados por conexão e por dia UTC. Quando o limite é atingido, as chamadas lançam
`BudgetExceeded` sem serem enviadas. Chamadas que já estão em andamento ainda são concluídas, então o orçamento pode ser
ligeiramente ultrapassado.

### Alerta de saldo baixo

```dotenv
JEV_AI_LOW_BALANCE_TOKENS=100000
```

Quando uma resposta informa menos tokens de entrada pagos restantes do que esse valor, `BalanceLow` é disparado (no máximo uma vez por
hora e por conexão). Um 402 sempre dispara `CreditsExhausted`.
