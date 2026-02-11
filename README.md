# Sell My Images

Monetize WordPress images with AI-upscaled downloads via Stripe. Visitors click, pay, and receive enhanced hi-res versions.

## Description

Sell My Images automatically adds "Download Hi-Res" buttons to Gutenberg image blocks and featured images. When a visitor clicks, they pay via Stripe Checkout, the image is AI-upscaled (4x or 8x) via Upsampler.com, and a secure download link is emailed to them.

## Requirements

- WordPress 6.9+
- PHP 8.1+
- SSL certificate (required for Stripe)
- [Stripe Integration](https://wordpress.org/plugins/stripe-integration/) plugin (declared dependency via `Requires Plugins` header)
- Stripe account
- [Upsampler.com](https://upsampler.com) account

## Installation

```bash
git clone https://github.com/Sarai-Chinwag/sell-my-images.git
cd sell-my-images && composer install
```

1. Ensure the **Stripe Integration** plugin is installed and activated first.
2. Upload `sell-my-images` to `wp-content/plugins/`.
3. Activate in WordPress admin.

## Configuration

Go to **Sell My Images** in the WordPress admin sidebar:

- **API Configuration** — Stripe keys (test/live) and Upsampler API key
- **Display Control** — Choose where buttons appear: all posts, exclude selected, or include only specific posts/categories/tags
- **Download Settings** — Markup percentage, download link expiry, button text

## Usage

Once configured, the plugin automatically:

1. Adds download buttons to qualifying image blocks and featured images
2. Creates Stripe Checkout sessions on click
3. Processes webhooks for payment confirmation
4. Queues upscaling jobs
5. Emails download links on completion
6. Auto-refunds via Stripe if upscaling fails

### Admin Pages

- **Jobs** — View/manage upscaling jobs, retry failed jobs, resend emails
- **Analytics** — Track clicks, conversions, and revenue per post/image
- **Settings** — All configuration options

### Webhook Endpoint

Stripe webhooks are received at `/smi-webhook/stripe/` (registered via `parse_request`).

### Gutenberg Blocks

- **Image Uploader** — Upload interface block
- **Comparison Slider** — Before/after comparison block

## Third-Party Services

| Service | Purpose | Data Sent |
|---------|---------|-----------|
| [Stripe](https://stripe.com) | Payment processing | Email, amount, transaction metadata |
| [Upsampler.com](https://upsampler.com) | AI image enhancement | Image URLs, upscale parameters |

No payment card data touches your server.

## Hooks/Filters

| Hook | Type | Description |
|------|------|-------------|
| `smi_load_assets` | Filter | Control whether frontend assets load on the current page |
| `smi_button_text` | Filter | Customize the download button text |
| `smi_min_image_size` | Filter | Minimum image dimensions to show button (default from Constants) |
| `smi_download_chunk_size` | Filter | Download streaming chunk size |
| `smi_max_webhook_payload_size` | Filter | Maximum webhook payload size |
| `smi_payment_completed` | Action | Fired after successful payment (receives job ID and context) |
| `smi_job_status_changed` | Action | Fired on job status transition (receives job ID, old status, new status, data) |
| `smi_daily_cleanup` | Action | Scheduled daily cleanup of expired downloads |

### Abilities API

Registers abilities via the WordPress Abilities API:

- **Analytics abilities** — Query click/conversion data
- **Inventory abilities** — Query available images and job status
- **Upload abilities** — Programmatic image upload

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

---

**License:** GPL v2+
**Author:** [Chris Huber](https://chubes.net)
