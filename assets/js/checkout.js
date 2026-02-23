/**
 * SMI Checkout - Vanilla JS with WordPress Abilities API
 *
 * Replaces legacy jQuery AJAX modal with clean abilities-based flow.
 *
 * @package SellMyImages
 * @since 2.0.0
 */

( function() {
	'use strict';

	const API_BASE = ( window.wpApiSettings?.root || '/wp-json/' ) + 'wp-abilities/v1/abilities/';
	const NONCE = window.wpApiSettings?.nonce || '';

	/**
	 * Call a WordPress ability via REST API.
	 *
	 * @param {string} name   Ability name (e.g. 'sell-my-images/create-checkout').
	 * @param {Object} input  Input parameters.
	 * @return {Promise<Object>} Ability output.
	 */
	async function runAbility( name, input ) {
		const response = await fetch( API_BASE + name + '/run', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': NONCE,
			},
			body: JSON.stringify( { input: input } ),
		} );

		const data = await response.json();

		if ( ! response.ok ) {
			throw new Error( data.message || data.error || 'Ability call failed' );
		}

		return data;
	}

	/**
	 * Modal controller.
	 */
	const Modal = {
		el: null,
		processing: false,
		currentImage: null,
		pollTimer: null,

		init() {
			this.el = document.getElementById( 'smi-modal' );
			if ( ! this.el ) {
				return;
			}

			this.bindEvents();
		},

		bindEvents() {
			// Buy button clicks (delegated).
			document.addEventListener( 'click', ( e ) => {
				const btn = e.target.closest( '.smi-buy-button' );
				if ( btn ) {
					e.preventDefault();
					this.open( btn );
				}
			} );

			// Close button.
			this.el.querySelector( '.smi-modal-close' )?.addEventListener( 'click', () => this.close() );

			// Overlay click to close.
			this.el.querySelector( '.smi-modal-overlay' )?.addEventListener( 'click', () => this.close() );

			// Escape key.
			document.addEventListener( 'keydown', ( e ) => {
				if ( e.key === 'Escape' && ! this.processing ) {
					this.close();
				}
			} );

			// Process button (delegated since content changes).
			this.el.addEventListener( 'click', ( e ) => {
				if ( e.target.closest( '.smi-process-btn' ) ) {
					e.preventDefault();
					this.processCheckout();
				}
			} );

			// Handle return from Stripe (check URL params).
			this.handleReturnFromStripe();
		},

		async open( button ) {
			if ( this.processing ) {
				return;
			}

			this.currentImage = {
				attachmentId: parseInt( button.dataset.attachmentId, 10 ),
				postId: parseInt( button.dataset.postId, 10 ),
				src: button.dataset.src,
				width: parseInt( button.dataset.width, 10 ),
				height: parseInt( button.dataset.height, 10 ),
			};

			if ( ! this.currentImage.attachmentId || ! this.currentImage.postId ) {
				return;
			}

			this.show();
			this.showLoading( true );
			this.trackClick();

			try {
				const result = await runAbility( 'sell-my-images/calculate-prices', {
					attachment_id: this.currentImage.attachmentId,
					post_id: this.currentImage.postId,
				} );

				this.renderPricing( result );
				this.showLoading( false );
			} catch ( err ) {
				this.showError( err.message );
			}
		},

		close() {
			if ( this.processing ) {
				return;
			}
			this.el.classList.add( 'smi-hidden' );
			document.body.style.overflow = '';
			this.clearPoll();
		},

		show() {
			this.el.classList.remove( 'smi-hidden' );
			document.body.style.overflow = 'hidden';
			this.clearError();
		},

		showLoading( visible ) {
			const loader = this.el.querySelector( '.smi-loading' );
			const main = this.el.querySelector( '.smi-modal-main' );
			if ( loader ) {
				loader.classList.toggle( 'smi-hidden', ! visible );
			}
			if ( main ) {
				main.classList.toggle( 'smi-hidden', visible );
			}
		},

		showError( message ) {
			const errorEl = this.el.querySelector( '.smi-error-message' );
			const textEl = this.el.querySelector( '.smi-error-text' );
			if ( errorEl && textEl ) {
				textEl.textContent = message;
				errorEl.classList.remove( 'smi-hidden' );
			}
			this.showLoading( false );
			this.resetProcessButton();
		},

		clearError() {
			const errorEl = this.el.querySelector( '.smi-error-message' );
			if ( errorEl ) {
				errorEl.classList.add( 'smi-hidden' );
			}
		},

		renderPricing( data ) {
			const main = this.el.querySelector( '.smi-modal-main' );
			if ( ! main ) {
				return;
			}

			// Build the image preview.
			let html = '<div class="smi-image-preview">';
			html += '<img src="' + this.escHtml( this.currentImage.src ) + '" alt="Preview" />';
			html += '<p class="smi-image-dimensions">' + this.currentImage.width + ' × ' + this.currentImage.height + '</p>';
			html += '</div>';

			// Resolution options.
			html += '<div class="smi-options">';
			html += '<h3>Select Resolution</h3>';

			data.prices.forEach( function( price, i ) {
				const disabled = ! price.available;
				const checked = i === 0 && price.available ? 'checked' : '';
				const disabledAttr = disabled ? 'disabled' : '';
				const disabledClass = disabled ? 'smi-option-disabled' : '';

				html += '<label class="smi-option ' + disabledClass + '">';
				html += '<input type="radio" name="resolution" value="' + price.resolution + '" ' + checked + ' ' + disabledAttr;
				html += ' data-price="' + ( price.price || 0 ) + '"';
				html += ' data-output-width="' + ( price.output_width || '' ) + '"';
				html += ' data-output-height="' + ( price.output_height || '' ) + '">';
				html += '<span class="smi-option-label">' + price.resolution + '</span>';
				if ( price.available ) {
					html += '<span class="smi-option-price">$' + parseFloat( price.price ).toFixed( 2 ) + '</span>';
					if ( price.output_width && price.output_height ) {
						html += '<span class="smi-option-dims">' + price.output_width + ' × ' + price.output_height + '</span>';
					}
				} else {
					html += '<span class="smi-option-unavailable">' + ( price.reason || 'Unavailable' ) + '</span>';
				}
				html += '</label>';
			} );

			html += '</div>';

			// Email field (optional).
			html += '<div class="smi-email-field">';
			html += '<label for="smi-email">Email (optional — for delivery confirmation)</label>';
			html += '<input type="email" id="smi-email" placeholder="your@email.com" />';
			html += '</div>';

			// Process button.
			html += '<div class="smi-button-container">';
			html += '<button type="button" class="smi-btn smi-btn-primary smi-process-btn">Purchase & Download</button>';
			html += '</div>';

			main.innerHTML = html;
			main.classList.remove( 'smi-hidden' );
		},

		async processCheckout() {
			if ( this.processing ) {
				return;
			}

			const selected = this.el.querySelector( 'input[name="resolution"]:checked' );
			if ( ! selected ) {
				this.showError( 'Please select a resolution option.' );
				return;
			}

			const email = ( this.el.querySelector( '#smi-email' )?.value || '' ).trim();

			this.processing = true;
			this.clearError();

			const btn = this.el.querySelector( '.smi-process-btn' );
			if ( btn ) {
				btn.disabled = true;
				btn.textContent = 'Creating checkout…';
			}

			try {
				const result = await runAbility( 'sell-my-images/create-checkout', {
					attachment_id: this.currentImage.attachmentId,
					post_id: this.currentImage.postId,
					resolution: selected.value,
					email: email || undefined,
				} );

				if ( result.checkout_url ) {
					this.showCheckoutRedirect( result );
				} else {
					this.showError( 'No checkout URL returned. Please try again.' );
				}
			} catch ( err ) {
				this.showError( err.message );
			} finally {
				this.processing = false;
			}
		},

		showCheckoutRedirect( data ) {
			const main = this.el.querySelector( '.smi-modal-main' );
			if ( ! main ) {
				return;
			}

			let html = '<div class="smi-checkout-redirect smi-status-container">';
			html += '<div class="smi-spinner"></div>';
			html += '<p>Redirecting to payment…</p>';
			html += '<p>Amount: <strong>$' + parseFloat( data.amount ).toFixed( 2 ) + '</strong></p>';
			html += '<a href="' + this.escHtml( data.checkout_url ) + '" class="smi-btn smi-btn-primary smi-checkout-link">Continue to Payment</a>';
			html += '<p class="smi-redirect-note">If you are not redirected automatically, click the button above.</p>';
			html += '</div>';

			main.innerHTML = html;

			// Redirect after a short delay. If it fails, the button is already visible.
			setTimeout( function() {
				window.location.href = data.checkout_url;
			}, 1500 );
		},

		resetProcessButton() {
			const btn = this.el.querySelector( '.smi-process-btn' );
			if ( btn ) {
				btn.disabled = false;
				btn.textContent = 'Purchase & Download';
			}
		},

		async trackClick() {
			try {
				await runAbility( 'sell-my-images/track-click', {
					post_id: this.currentImage.postId,
					attachment_id: this.currentImage.attachmentId,
				} );
			} catch ( e ) {
				// Non-critical — don't block the flow.
			}
		},

		handleReturnFromStripe() {
			const params = new URLSearchParams( window.location.search );
			const jobId = params.get( 'smi_job_id' );
			const status = params.get( 'smi_status' );

			if ( ! jobId ) {
				return;
			}

			// Clean URL.
			const url = new URL( window.location );
			url.searchParams.delete( 'smi_job_id' );
			url.searchParams.delete( 'smi_status' );
			window.history.replaceState( {}, '', url );

			if ( status === 'cancel' ) {
				return;
			}

			// Show modal with processing status.
			this.show();
			this.showProcessingStatus( jobId );
		},

		async showProcessingStatus( jobId ) {
			const main = this.el.querySelector( '.smi-modal-main' );
			if ( ! main ) {
				return;
			}

			main.innerHTML = '<div class="smi-processing-status smi-status-container">' +
				'<div class="smi-spinner"></div>' +
				'<p>Processing your image…</p>' +
				'</div>';
			main.classList.remove( 'smi-hidden' );
			this.showLoading( false );

			this.pollJobStatus( jobId );
		},

		pollJobStatus( jobId ) {
			this.clearPoll();

			const poll = async () => {
				try {
					const result = await runAbility( 'sell-my-images/get-job-status', {
						job_id: jobId,
					} );

					if ( result.status === 'completed' ) {
						this.showCompleted( result );
						return;
					}

					if ( result.status === 'failed' ) {
						this.showError( 'Image processing failed. Please contact support.' );
						return;
					}

					// Still processing — poll again.
					this.pollTimer = setTimeout( poll, 3000 );
				} catch ( err ) {
					this.showError( 'Could not check job status: ' + err.message );
				}
			};

			poll();
		},

		showCompleted( data ) {
			const main = this.el.querySelector( '.smi-modal-main' );
			if ( ! main ) {
				return;
			}

			let html = '<div class="smi-completed smi-status-container">';
			html += '<div class="smi-success-icon">✅</div>';
			html += '<h3>Your image is ready!</h3>';
			html += '<p>Your high-resolution image is ready for download.</p>';

			if ( data.download_url ) {
				html += '<a href="' + this.escHtml( data.download_url ) + '" class="smi-btn smi-btn-primary" download>Download Image</a>';
			}

			html += '</div>';
			main.innerHTML = html;
			this.processing = false;
		},

		clearPoll() {
			if ( this.pollTimer ) {
				clearTimeout( this.pollTimer );
				this.pollTimer = null;
			}
		},

		escHtml( str ) {
			const div = document.createElement( 'div' );
			div.appendChild( document.createTextNode( str || '' ) );
			return div.innerHTML;
		},
	};

	// Initialize when DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function() {
			Modal.init();
		} );
	} else {
		Modal.init();
	}
} )();
