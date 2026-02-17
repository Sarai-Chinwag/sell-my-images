# Changelog

## Unreleased

### Changed
- Remove console statements from modal script
- Rebuild comparison slider: clip-path reveal instead of width:200% hack

## [1.3.1] - 2026-02-12

- fix: critical checkout bug - Stripe response key mismatch after stripe-integration migration (7 days broken)
- fix: stale awaiting_payment jobs now swept to abandoned by daily cleanup cron
- fix: fallback to home_url when HTTP_HOST missing in checkout URLs
- test: rewrite PaymentService tests for stripe-integration + add smoke test + regression tests

## 1.3.0
- Refactor to use shared stripe-integration plugin
