/**
 * SMI Checkout — WordPress Abilities API integration.
 *
 * Built by @wordpress/scripts. Enqueued on the frontend for the
 * purchase modal (calculate prices → create checkout → poll status).
 *
 * @package SellMyImages
 */

declare const wpApiSettings: { root: string; nonce: string } | undefined;

const API_BASE =
	( ( typeof wpApiSettings !== 'undefined' && wpApiSettings?.root ) || '/wp-json/' ) +
	'wp-abilities/v1/abilities/';
const NONCE =
	( typeof wpApiSettings !== 'undefined' && wpApiSettings?.nonce ) || '';

/* ---------- types ---------- */

interface PriceOption {
	resolution: string;
	price?: number;
	output_width?: number;
	output_height?: number;
	credits?: number;
	available: boolean;
	reason?: string;
}

interface PriceResult {
	prices: PriceOption[];
	image: { src: string; width: number; height: number; attachment_id: number };
}

interface CheckoutResult {
	checkout_url: string;
	amount: number;
	job_id: number;
}

interface JobStatusResult {
	status: string;
	download_url?: string;
}

interface CurrentImage {
	attachmentId: number;
	postId: number;
	src: string;
	width: number;
	height: number;
}

/* ---------- ability caller ---------- */

async function runAbility< T = unknown >(
	name: string,
	input: Record< string, unknown >,
	method: 'GET' | 'POST' = 'POST',
): Promise< T > {
	const headers: Record< string, string > = { 'X-WP-Nonce': NONCE };
	let url = API_BASE + name + '/run';
	let body: string | null = null;

	if ( method === 'GET' ) {
		const params = new URLSearchParams();
		for ( const [ key, val ] of Object.entries( input ) ) {
			if ( val !== undefined ) {
				params.append( `input[${ key }]`, String( val ) );
			}
		}
		url += '?' + params.toString();
	} else {
		headers[ 'Content-Type' ] = 'application/json';
		body = JSON.stringify( { input } );
	}

	const response = await fetch( url, { method, headers, body } );
	const data = await response.json();

	if ( ! response.ok ) {
		throw new Error( data.message || data.error || 'Ability call failed' );
	}
	return data as T;
}

/* ---------- helpers ---------- */

function escHtml( str: string ): string {
	const div = document.createElement( 'div' );
	div.appendChild( document.createTextNode( str || '' ) );
	return div.innerHTML;
}

/* ---------- modal ---------- */

const Modal = {
	el: null as HTMLElement | null,
	processing: false,
	currentImage: null as CurrentImage | null,
	pollTimer: null as ReturnType< typeof setTimeout > | null,

	init(): void {
		this.el = document.getElementById( 'smi-modal' );
		if ( ! this.el ) return;
		this.bindEvents();
	},

	bindEvents(): void {
		// Buy button clicks (delegated).
		document.addEventListener( 'click', ( e: Event ) => {
			const btn = ( e.target as HTMLElement ).closest< HTMLElement >( '.smi-buy-button' );
			if ( btn ) {
				e.preventDefault();
				this.open( btn );
			}
		} );

		// Close.
		this.el!.querySelector( '.smi-modal-close' )?.addEventListener( 'click', () => this.close() );
		this.el!.querySelector( '.smi-modal-overlay' )?.addEventListener( 'click', () => this.close() );
		document.addEventListener( 'keydown', ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' && ! this.processing ) this.close();
		} );

		// Process button (delegated).
		this.el!.addEventListener( 'click', ( e: Event ) => {
			if ( ( e.target as HTMLElement ).closest( '.smi-process-btn' ) ) {
				e.preventDefault();
				this.processCheckout();
			}
		} );

		this.handleReturnFromStripe();
	},

	async open( button: HTMLElement ): Promise< void > {
		if ( this.processing ) return;

		this.currentImage = {
			attachmentId: parseInt( button.dataset.attachmentId || '0', 10 ),
			postId: parseInt( button.dataset.postId || '0', 10 ),
			src: button.dataset.src || '',
			width: parseInt( button.dataset.width || '0', 10 ),
			height: parseInt( button.dataset.height || '0', 10 ),
		};

		if ( ! this.currentImage.attachmentId || ! this.currentImage.postId ) return;

		this.show();
		this.showLoading( true );
		this.trackClick();

		try {
			const result = await runAbility< PriceResult >(
				'sell-my-images/calculate-prices',
				{
					attachment_id: this.currentImage.attachmentId,
					post_id: this.currentImage.postId,
				},
				'GET',
			);
			this.renderPricing( result );
			this.showLoading( false );
		} catch ( err: unknown ) {
			this.showError( err instanceof Error ? err.message : 'Failed to load prices' );
		}
	},

	close(): void {
		if ( this.processing ) return;
		this.el!.classList.add( 'smi-hidden' );
		document.body.style.overflow = '';
		this.clearPoll();
	},

	show(): void {
		this.el!.classList.remove( 'smi-hidden' );
		document.body.style.overflow = 'hidden';
		this.clearError();
	},

	showLoading( visible: boolean ): void {
		this.el!.querySelector( '.smi-loading' )?.classList.toggle( 'smi-hidden', ! visible );
		this.el!.querySelector( '.smi-modal-main' )?.classList.toggle( 'smi-hidden', visible );
	},

	showError( message: string ): void {
		const errorEl = this.el!.querySelector( '.smi-error-message' );
		const textEl = this.el!.querySelector( '.smi-error-text' );
		if ( errorEl && textEl ) {
			textEl.textContent = message;
			errorEl.classList.remove( 'smi-hidden' );
		}
		this.showLoading( false );
		this.resetProcessButton();
	},

	clearError(): void {
		this.el!.querySelector( '.smi-error-message' )?.classList.add( 'smi-hidden' );
	},

	renderPricing( data: PriceResult ): void {
		const main = this.el!.querySelector( '.smi-modal-main' ) as HTMLElement | null;
		if ( ! main || ! this.currentImage ) return;

		const img = this.currentImage;
		// Only show available prices (2x should never come back, but filter just in case).
		const available = data.prices.filter( ( p ) => p.available );

		let html = '<div class="smi-image-preview">';
		html += `<img src="${ escHtml( img.src ) }" alt="Preview" />`;
		html += `<p class="smi-image-dimensions">${ img.width } × ${ img.height }</p>`;
		html += '</div>';

		html += '<div class="smi-options"><h3>Select Resolution</h3>';

		available.forEach( ( price, i ) => {
			const checked = i === 0 ? 'checked' : '';
			html += '<label class="smi-option">';
			html += `<input type="radio" name="resolution" value="${ price.resolution }" ${ checked }`;
			html += ` data-price="${ price.price || 0 }"`;
			html += ` data-output-width="${ price.output_width || '' }"`;
			html += ` data-output-height="${ price.output_height || '' }">`;
			html += `<span class="smi-option-label">${ price.resolution }</span>`;
			html += `<span class="smi-option-price">$${ parseFloat( String( price.price ) ).toFixed( 2 ) }</span>`;
			if ( price.output_width && price.output_height ) {
				html += `<span class="smi-option-dims">${ price.output_width } × ${ price.output_height }</span>`;
			}
			html += '</label>';
		} );

		html += '</div>';

		html += '<div class="smi-email-field">';
		html += '<label for="smi-email">Email (optional — for delivery confirmation)</label>';
		html += '<input type="email" id="smi-email" placeholder="your@email.com" />';
		html += '</div>';

		html += '<div class="smi-button-container">';
		html += '<button type="button" class="smi-btn smi-btn-primary smi-process-btn">Purchase &amp; Download</button>';
		html += '</div>';

		main.innerHTML = html;
		main.classList.remove( 'smi-hidden' );
	},

	async processCheckout(): Promise< void > {
		if ( this.processing || ! this.currentImage ) return;

		const selected = this.el!.querySelector< HTMLInputElement >( 'input[name="resolution"]:checked' );
		if ( ! selected ) {
			this.showError( 'Please select a resolution option.' );
			return;
		}

		const email = ( this.el!.querySelector< HTMLInputElement >( '#smi-email' )?.value || '' ).trim();

		this.processing = true;
		this.clearError();

		const btn = this.el!.querySelector< HTMLButtonElement >( '.smi-process-btn' );
		if ( btn ) {
			btn.disabled = true;
			btn.textContent = 'Creating checkout…';
		}

		try {
			const result = await runAbility< CheckoutResult >( 'sell-my-images/create-checkout', {
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
		} catch ( err: unknown ) {
			this.showError( err instanceof Error ? err.message : 'Checkout failed' );
		} finally {
			this.processing = false;
		}
	},

	showCheckoutRedirect( data: CheckoutResult ): void {
		const main = this.el!.querySelector( '.smi-modal-main' ) as HTMLElement | null;
		if ( ! main ) return;

		let html = '<div class="smi-checkout-redirect smi-status-container">';
		html += '<div class="smi-spinner"></div>';
		html += '<p>Redirecting to payment…</p>';
		html += `<p>Amount: <strong>$${ parseFloat( String( data.amount ) ).toFixed( 2 ) }</strong></p>`;
		html += `<a href="${ escHtml( data.checkout_url ) }" class="smi-btn smi-btn-primary smi-checkout-link">Continue to Payment</a>`;
		html += '<p class="smi-redirect-note">If you are not redirected automatically, click the button above.</p>';
		html += '</div>';

		main.innerHTML = html;

		setTimeout( () => {
			window.location.href = data.checkout_url;
		}, 1500 );
	},

	resetProcessButton(): void {
		const btn = this.el!.querySelector< HTMLButtonElement >( '.smi-process-btn' );
		if ( btn ) {
			btn.disabled = false;
			btn.textContent = 'Purchase & Download';
		}
	},

	async trackClick(): Promise< void > {
		if ( ! this.currentImage ) return;
		try {
			await runAbility( 'sell-my-images/track-click', {
				post_id: this.currentImage.postId,
				attachment_id: this.currentImage.attachmentId,
			} );
		} catch {
			// Non-critical.
		}
	},

	handleReturnFromStripe(): void {
		const params = new URLSearchParams( window.location.search );
		const jobId = params.get( 'smi_job_id' );
		const status = params.get( 'smi_status' );

		if ( ! jobId ) return;

		// Clean URL.
		const url = new URL( window.location.href );
		url.searchParams.delete( 'smi_job_id' );
		url.searchParams.delete( 'smi_status' );
		window.history.replaceState( {}, '', url.toString() );

		if ( status === 'cancel' ) return;

		this.show();
		this.showProcessingStatus( jobId );
	},

	showProcessingStatus( jobId: string ): void {
		const main = this.el!.querySelector( '.smi-modal-main' ) as HTMLElement | null;
		if ( ! main ) return;

		main.innerHTML =
			'<div class="smi-processing-status smi-status-container">' +
			'<div class="smi-spinner"></div>' +
			'<p>Processing your image…</p>' +
			'</div>';
		main.classList.remove( 'smi-hidden' );
		this.showLoading( false );
		this.pollJobStatus( jobId );
	},

	pollJobStatus( jobId: string ): void {
		this.clearPoll();

		const poll = async (): Promise< void > => {
			try {
				const result = await runAbility< JobStatusResult >(
					'sell-my-images/get-job-status',
					{ job_id: jobId },
					'GET',
				);

				if ( result.status === 'completed' ) {
					this.showCompleted( result );
					return;
				}
				if ( result.status === 'failed' ) {
					this.showError( 'Image processing failed. Please contact support.' );
					return;
				}
				this.pollTimer = setTimeout( poll, 3000 );
			} catch ( err: unknown ) {
				this.showError( 'Could not check job status: ' + ( err instanceof Error ? err.message : 'Unknown error' ) );
			}
		};

		poll();
	},

	showCompleted( data: JobStatusResult ): void {
		const main = this.el!.querySelector( '.smi-modal-main' ) as HTMLElement | null;
		if ( ! main ) return;

		let html = '<div class="smi-completed smi-status-container">';
		html += '<div class="smi-success-icon">✅</div>';
		html += '<h3>Your image is ready!</h3>';
		html += '<p>Your high-resolution image is ready for download.</p>';
		if ( data.download_url ) {
			html += `<a href="${ escHtml( data.download_url ) }" class="smi-btn smi-btn-primary" download>Download Image</a>`;
		}
		html += '</div>';
		main.innerHTML = html;
		this.processing = false;
	},

	clearPoll(): void {
		if ( this.pollTimer ) {
			clearTimeout( this.pollTimer );
			this.pollTimer = null;
		}
	},
};

// Initialize when DOM is ready.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => Modal.init() );
} else {
	Modal.init();
}
