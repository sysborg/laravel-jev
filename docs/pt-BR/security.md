# Segurança

> 🇺🇸 [Read in English](../en/security.md)

## A API key

- Lida apenas de config/env (`JEV_AI_API_KEY`) e encapsulada em `ApiKey` assim que o pacote
  a recebe. O valor bruto nem sequer é uma propriedade do objeto, então `var_dump()`, `dd()`,
  `print_r()`, `var_export()`, JSON e casts `(array)` imprimem `[redacted]`.
- `ApiKey` recusa `serialize()` e `clone`: ela nunca pode parar em um job enfileirado ou em um cache.
  Jobs enfileirados carregam apenas a requisição e o correlation id; a chave é resolvida quando o job roda.
- O parâmetro do construtor é `#[SensitiveParameter]`, então ele fica oculto nos stack traces.
- A base URL precisa ser `https://` (`http://` puro só é aceito no ambiente `testing`).

Um teste end-to-end executa cenários de sucesso, retry, 401, timeout e fila com uma chave canário e
verifica logs, spans, eventos, exceções, linhas de uso, o job serializado e dumps de objetos.

Fora do controle do pacote, como acontece com qualquer segredo: o repositório de config do Laravel guarda o valor do env,
e os eventos do HTTP client do Laravel (`RequestSending`) expõem o header `Authorization` para os seus
listeners. Não registre esses eventos no log na íntegra.

## O que sai da sua aplicação

| Dado | Enviado para a Jev | Eventos | Logs | Spans | Tabela de uso |
|---|---|---|---|---|---|
| state | sim | prévia mascarada | prévia mascarada | nunca | nunca |
| perguntas / id do judge | sim | apenas ids | — | — | id do judge |
| `trace` | sim (exceto as chaves de `trace_deny`) | — | — | — | — |
| `context` | **nunca** | sim | — | — | sim |
| respostas / body bruto | — | respostas (bruto via opt-in) | — | — | opt-in (`store_payloads`) |
| `user`, `session_id` | sim | sim | — | — | sim |

## Mascaramento

```dotenv
JEV_AI_REDACT_STATE=truncate:200   # none | truncate:N | hash | omit
JEV_AI_TRACE_DENY=email,phone      # campos de trace nunca enviados para a Jev
```

- `truncate:N` mantém os primeiros N caracteres, `hash` mantém um digest `sha256:`, `omit` não mantém nada.
- `none` mostra o state completo em eventos e logs: use apenas onde isso for aceitável.
- Mensagens de validação citam o campo, nunca o seu valor.

Limitação conhecida: as próprias mensagens de erro da Jev são mantidas (truncadas em 500 caracteres) nas exceções,
em `DecisionFailed` e nos logs. Se a Jev algum dia repetir o conteúdo da requisição em uma mensagem de erro, ele
apareceria ali.

## Reportando vulnerabilidades

Veja [SECURITY.md](../../SECURITY.md). Por favor, não abra issues públicas para problemas de segurança.
