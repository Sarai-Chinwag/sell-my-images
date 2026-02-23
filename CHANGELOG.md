# Changelog

## [1.5.0] - 2026-02-23

### Removed
- Legacy jQuery modal.js and AJAX REST endpoints (600+ lines of dead code)

## [1.4.3] - 2026-02-23

### Fixed
- Handle non-WP_Error return from CostCalculator in price check

## [1.4.2] - 2026-02-23

### Fixed
- HTTP method handling for readonly vs destructive abilities
- Price field mapping from CostCalculator

## [1.4.1] - 2026-02-23

### Fixed
- Move show_in_rest and annotations into meta key for WP_Ability compatibility

## [1.4.0] - 2026-02-23

### Changed
- Replace jQuery AJAX checkout flow with WordPress Abilities API
- Add duplicate job prevention for create-checkout
- Remove jQuery dependency from frontend

## [1.3.4] - 2026-02-17

### Added
- proper footer nav in checkout modal with Terms, Learn More, Upscale links

### Fixed
- never delete abandoned job records (keep for analytics)
- swap comparison slider labels (Enhanced left, Original right)
- remove all forced uppercase text-transform from uploader block
- rebuild comparison slider (clip-path), default position to 50%
- move Terms link below checkout button, make it subtle

## [1.3.3] - 2026-02-17

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
