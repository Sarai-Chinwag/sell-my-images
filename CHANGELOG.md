# Changelog

## Unreleased

## [1.7.1] - 2026-03-08

### Added
- GA4 event tracking for purchase funnel visibility (smi_unlock_click, smi_modal_open, smi_purchase_start)

## [1.7.0] - 2026-03-08

### Added
- Auto-generate alt text for image attachments
- add Pageviews, CTR columns and sort options to AnalyticsPage

### Changed
- Add CSS for resolution dimensions display in modal
- add CSS for .smi-option-dims resolution display
- Add WP-CLI revenue report command (wp smi revenue)

### Fixed
- Restore Download Hi-Res button injection lost in TypeScript refactor
- Fix class name mismatch (.smi-buy-button → .smi-get-button)
- Fix modal replacing template with duplicate Purchase & Download button
- modal now updates template instead of replacing it
- restore button injection lost in TypeScript refactor

## [1.6.0] - 2026-02-23

- Remove 2x resolution entirely; refactor checkout to TypeScript built by @wordpress/scripts

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
