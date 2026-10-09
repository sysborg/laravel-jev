# Beta aberto

> 🇺🇸 [Read in English](../en/beta.md)

`sysborg/laravel-jevai` está em **beta aberto** (`0.x`).

## Estabilidade

- A **API pública** é tudo o que está marcado com `@api` no código-fonte: as facades `Jev` e `JevUsage`,
  o contrato `Ports\Driving\Jev`, os builders, `JevFake` e os value objects, eventos
  e exceções do `Domain`. Mudanças nela seguem o SemVer para `0.x`: versões **minor** podem quebrá-la, e toda
  quebra é listada em **Breaking** no [CHANGELOG](../../CHANGELOG.md).
- Todo o resto (estágios do pipeline, adapters, classes `@internal`) pode mudar em qualquer versão.
- Chaves de config podem ser renomeadas em versões minor (listadas no CHANGELOG).

## Limitações conhecidas

- O formato da resposta de `GET /v1/models`, das chamadas de judges salvos e das fontes próprias de contexto da web não é
  documentado pela Jev: o pacote aceita os formatos plausíveis e tolera campos desconhecidos.
- `X-Jev-Run-Id` é opcional; `run_id` fica null quando a Jev não o envia.
- Após o cooldown do circuit breaker, todas as chamadas são liberadas (não há estado half-open com uma única sonda).
- O orçamento diário pode ser ligeiramente ultrapassado por chamadas já em andamento.
- As mensagens de erro da Jev são mantidas (truncadas) e podem repetir o conteúdo da requisição.

## Reportando problemas

- Bugs e ideias: [issues no GitHub](https://github.com/sysborg/laravel-jev/issues). Inclua a
  versão do pacote, as versões do Laravel/PHP e, se possível, o correlation id da chamada.
- Segurança: de forma privada, veja [SECURITY.md](../../SECURITY.md).
