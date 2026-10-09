# Configuração

> 🇺🇸 [Read in English](../en/configuration.md)

Publique o arquivo com `php artisan vendor:publish --tag=jev-config`. Todos os valores também podem ser definidos
pelas variáveis de ambiente abaixo.

## Conexão

| Chave | Env | Padrão | Observações |
|---|---|---|---|
| `default` | `JEV_AI_CONNECTION` | `default` | Conexão usada pelo pacote |
| `connections.{name}.api_key` | `JEV_AI_API_KEY` | — | **Obrigatória.** Lida apenas de config/env, nunca vai para o log |
| `connections.{name}.base_url` | `JEV_AI_BASE_URL` | `https://jev-ai.pro/api` | Precisa ser `https://` (`http://` puro só em `testing`) |
| `connections.{name}.model` | `JEV_AI_MODEL` | `jev-latest` | Usado quando a requisição não especifica um model |
| `connections.{name}.timeout.connect` | `JEV_AI_CONNECT_TIMEOUT` | `5` | Segundos |
| `connections.{name}.timeout.request` | `JEV_AI_REQUEST_TIMEOUT` | `30` | Segundos |

As conexões são validadas no primeiro uso, então uma chave ausente nunca quebra o boot da aplicação. O
pacote sempre usa a conexão `default`; escolher uma conexão por tenant ou por conta fica a cargo da
sua plataforma (o `GatewayFactory::decision('name')`, no nível dos adapters, constrói gateways para qualquer
conexão configurada).

## Retries

| Chave | Env | Padrão |
|---|---|---|
| `retry.max_attempts` | `JEV_AI_RETRY_MAX_ATTEMPTS` | `3` (1 desativa os retries) |
| `retry.max_elapsed_ms` | `JEV_AI_RETRY_MAX_ELAPSED_MS` | `60000` |
| `retry.base_delay_ms` / `retry.max_delay_ms` | — | `250` / `5000` |
| `retry.max_retry_after_seconds` | — | `30` (um `Retry-After` maior falha imediatamente) |
| `retry.on_timeout` | `JEV_AI_RETRY_ON_TIMEOUT` | `false` (veja [cobrança](billing-and-retries.md)) |

## Limites no lado do cliente (todos desligados por padrão)

| Chave | Env | Padrão |
|---|---|---|
| `limits.cache_store` | `JEV_AI_LIMITS_CACHE_STORE` | store padrão |
| `limits.rate_limit_per_minute` | `JEV_AI_RATE_LIMIT_PER_MINUTE` | desligado |
| `limits.rate_limit_block` | `JEV_AI_RATE_LIMIT_BLOCK` | `false` |
| `limits.rate_limit_max_wait_seconds` | — | `10` |
| `limits.circuit_breaker_failures` | `JEV_AI_CIRCUIT_BREAKER_FAILURES` | desligado |
| `limits.circuit_breaker_cooldown_seconds` | `JEV_AI_CIRCUIT_BREAKER_COOLDOWN` | `30` |
| `limits.daily_input_tokens` | `JEV_AI_DAILY_INPUT_TOKENS` | desligado |

## Correlação e mascaramento

| Chave | Env | Padrão |
|---|---|---|
| `correlation.trace_key` | — | `correlation_id` (null: não é enviado para a Jev) |
| `correlation.session_fallback` | — | `true` (o correlation id vira o `session_id` quando nenhum é definido) |
| `redaction.state` | `JEV_AI_REDACT_STATE` | `truncate:200` (`none`, `truncate:N`, `hash`, `omit`) |
| `redaction.trace_deny` | `JEV_AI_TRACE_DENY` | vazio (ex.: `email,phone`) |

## Eventos e alertas

| Chave | Env | Padrão |
|---|---|---|
| `events.enabled` | `JEV_AI_EVENTS` | `true` |
| `events.include_raw` | — | `false` (body bruto da resposta nos eventos de sucesso) |
| `alerts.tokens_remaining_threshold` | `JEV_AI_LOW_BALANCE_TOKENS` | desligado |
| `alerts.debounce_seconds` | — | `3600` |
| `alerts.cache_store` | `JEV_AI_CACHE_STORE` | store padrão |

## Registros de uso

| Chave | Env | Padrão |
|---|---|---|
| `usage.enabled` | — | `true` |
| `usage.driver` | `JEV_AI_USAGE_DRIVER` | `null` (`database` armazena os registros) |
| `usage.connection` | `JEV_AI_USAGE_DB_CONNECTION` | conexão de banco padrão |
| `usage.table` | — | `jev_runs` |
| `usage.store_payloads` | — | `false` (bodies brutos das respostas, podem conter dados pessoais) |
| `usage.retention_days` | `JEV_AI_USAGE_RETENTION_DAYS` | `90` (`jev:prune`) |

## Fila, logs, tracing, métricas

| Chave | Env | Padrão |
|---|---|---|
| `queue.connection` / `queue.queue` | `JEV_AI_QUEUE_CONNECTION` / `JEV_AI_QUEUE` | padrões |
| `logging.channel` | `JEV_AI_LOG_CHANNEL` | canal padrão |
| `logging.calls` | `JEV_AI_LOG_CALLS` | `true` |
| `observability.tracing` | `JEV_AI_TRACING` | `null` (`log`, `otel`) |
| `observability.metrics` | `JEV_AI_METRICS` | `null` (`otel`, `pulse` ou `otel,pulse`) |

Um driver de tracing ou de métricas cujo pacote não esteja instalado cai para um no-op, com um aviso no log.
Valores desconhecidos lançam `InvalidValue` no primeiro uso.
