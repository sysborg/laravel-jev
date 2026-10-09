# Changelog

All notable changes to `sysborg/laravel-jevai` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the package is in `0.x` (open beta), minor releases may contain breaking changes;
every one of them is listed under a **Breaking** heading.

## [Unreleased]

### Added
- Project foundation: Composer package, service provider with publishable `config/jev.php`,
  ports & adapters directory layout, Pest + Testbench, Larastan (level max), Pint,
  architecture tests and GitHub Actions CI matrix.
- Domain layer: question and answer value objects, `QuestionSet` / `AnswerSet`, `DecisionRequest`
  (inline questions or saved judge), `State`, `Trace`, `Context`, `JudgeRef`, `DecisionResult`,
  `Usage`, `Billing` / `BillingMode`, `CorrelationId`, `RunMetadata`, web-context request/result
  types, and the `JevException` hierarchy with retry and billing semantics.
