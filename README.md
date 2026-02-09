# Sell My Images

Monetize WordPress images with AI-upscaled downloads via Stripe — visitors click, pay, and receive enhanced hi-res versions.

## What It Does

Sell My Images turns any WordPress image into a purchasable product:

- **Automatic buttons** — Adds "Download Hi-Res" to all Gutenberg image blocks
- **AI upscaling** — 4x or 8x resolution enhancement via Upsampler.com
- **Secure payments** — Stripe checkout with test/live mode support
- **Smart delivery** — Token-based downloads with automatic expiration

## How It Works

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   VISITOR   │ ──▶ │   STRIPE    │ ──▶ │  UPSAMPLER  │ ──▶ │  DOWNLOAD   │
│  Clicks     │     │  Payment    │     │  AI 4x/8x   │     │  Hi-Res     │
│  Button     │     │  Checkout   │     │  Upscale    │     │  Delivery   │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘
```

Payment → Processing → Email notification → Secure download link (auto-expires)

## Features

| Feature | Description |
|---------|-------------|
| **Display Control** | Show/hide buttons by post type, category, tag, or specific posts |
| **Pricing** | Configurable markup percentage over Upsampler costs |
| **Analytics** | Track clicks, conversions, and revenue per post/image |
| **Refunds** | Automatic Stripe refunds if upscaling fails |
| **Mobile** | Responsive design works on all devices |

## Display Modes

| Mode | Use Case |
|------|----------|
| **All Posts** | Monetize everything (default) |
| **Exclude Selected** | Hide on "Free Resources" category |
| **Include Only** | Show only on "Portfolio" posts |

## Third-Party Services

This plugin transmits data to external services:

| Service | Purpose | Data Sent |
|---------|---------|-----------|
| [Stripe](https://stripe.com) | Payment processing | Email, amount, transaction metadata |
| [Upsampler.com](https://upsampler.com) | AI image enhancement | Image URLs, upscale parameters |

No payment card data touches your server. Review service terms before use.

## Requirements

- WordPress 5.0+ (Gutenberg)
- PHP 7.4+
- SSL certificate (required for Stripe)
- Stripe account + Upsampler.com account

## Installation

```bash
# Clone and install dependencies
git clone https://github.com/Sarai-Chinwag/sell-my-images.git
cd sell-my-images && composer install

# Configure in WordPress Admin → Sell My Images
# 1. API Configuration: Add Stripe + Upsampler keys
# 2. Display Control: Choose where buttons appear
# 3. Download Settings: Set pricing and expiry
```

## Development

```bash
# Local webhook testing
stripe listen --forward-to=https://yoursite.local/smi-webhook/stripe/

# Test cards
4242 4242 4242 4242  # Success
4000 0000 0000 0002  # Decline
```

## Documentation

- [AGENTS.md](AGENTS.md) — Technical architecture and implementation details
- [docs/](docs/) — API documentation

## Live Demo

See it in action at [saraichinwag.com](https://saraichinwag.com)

---

**License**: GPL v2+  
**Author**: [Chris Huber](https://chubes.net)
