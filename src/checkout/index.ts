/**
 * SMI Checkout — WordPress Abilities API integration.
 *
 * Built by @wordpress/scripts. Enqueued on the frontend for the
 * purchase modal (calculate prices → create checkout → poll status).
 *
 * @package SellMyImages
 */

declare const wpApiSettings: { root: string; nonce: string } | undefined;
declare const smiData: { postId: number; buttonText: string } | undefined;

// GA4 gtag() type declaration
declare function gtag( event: 'event', eventName: string, params?: Record< string, unknown > ): void;

const API_BASE =
	( ( typeof wpApiSettings !== 'undefined' && wpApiSettings?.root ) || '/wp-json/' ) +
	'wp-abilities/v1/abilities/';
const NONCE =
	( typeof wpApiSettings !== 'undefined' && wpApiSettings?.nonce ) || '';

/* ---------- GA4 Event Tracking ---------- */

/**
 * Safely send a GA4 custom event via gtag().
 * Silently no-ops if gtag is not available (e.g., GA4 not configured).
 */
function trackGA4Event( eventName: string, params?: Record< string, unknown > ): void {
	if ( typeof gtag !== 'function' ) {
		return;
	}
	try {
		gtag( 'event', eventName, params );
	} catch {
		// Silently ignore GA4 errors - analytics should never break the UI.
	}
}

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

/* ---------- button injection ---------- */

/**
 * Scan for .wp-block-image figures and inject "Download Hi-Res" buttons.
 *
 * Ported from the original jQuery modal.js injectButtons().
 * Extracts attachment IDs from wp-image-{id} classes on img, picture, or figure.
 */
function injectButtons( root?: Element | null ): void {
	const postId = ( typeof smiData !== 'undefined' && smiData?.postId ) || 0;
	const buttonText = ( typeof smiData !== 'undefined' && smiData?.buttonText ) || 'Download Hi-Res';

	if ( ! postId ) return;

	const scope = root || document;
	const figures = scope.querySelectorAll< HTMLElement >( '.wp-block-image' );

	figures.forEach( ( figure ) => {
		const img = figure.querySelector< HTMLImageElement >( 'img' );

		// Skip if no image or button already exists.
		if ( ! img || figure.querySelector( '.smi-get-button' ) ) return;

		// Extract attachment ID from wp-image-{id} class.
		let attachmentId: string | null = null;

		// First try: img class.
		const imgMatch = ( img.className || '' ).match( /wp-image-(\d+)/ );
		if ( imgMatch ) {
			attachmentId = imgMatch[ 1 ];
		}

		// Second try: picture element class.
		if ( ! attachmentId ) {
			const picture = figure.querySelector( 'picture' );
			if ( picture ) {
				const picMatch = ( picture.className || '' ).match( /wp-image-(\d+)/ );
				if ( picMatch ) attachmentId = picMatch[ 1 ];
			}
		}

		// Third try: figure element class.
		if ( ! attachmentId ) {
			const figMatch = ( figure.className || '' ).match( /wp-image-(\d+)/ );
			if ( figMatch ) attachmentId = figMatch[ 1 ];
		}

		if ( ! attachmentId ) return;

		// Get image dimensions and src.
		const imgSrc = img.getAttribute( 'src' ) || '';
		const imgWidth = img.naturalWidth || parseInt( img.getAttribute( 'width' ) || '0', 10 );
		const imgHeight = img.naturalHeight || parseInt( img.getAttribute( 'height' ) || '0', 10 );

		// Create button element.
		const btn = document.createElement( 'button' );
		btn.className = 'smi-get-button';
		btn.dataset.postId = String( postId );
		btn.dataset.attachmentId = attachmentId;
		btn.dataset.src = imgSrc;
		btn.dataset.width = String( imgWidth );
		btn.dataset.height = String( imgHeight );

		const span = document.createElement( 'span' );
		span.className = 'smi-button-text';
		span.textContent = buttonText;
		btn.appendChild( span );

		figure.appendChild( btn );
	} );
}

/**
 * Watch for dynamically added images (infinite scroll, load-more, etc.)
 * and re-run button injection when new content appears.
 */
function setupDynamicReinit(): void {
	// Listen for custom events (themes/plugins can trigger re-injection).
	document.addEventListener( 'smi:refreshButtons', ( ( e: CustomEvent ) => {
		injectButtons( e.detail?.root || null );
	} ) as EventListener );

	// Observe common gallery containers for child mutations.
	const observe = ( selector: string ): void => {
		const container = document.querySelector( selector );
		if ( ! container || typeof MutationObserver === 'undefined' ) return;

		let timer: ReturnType< typeof setTimeout > | null = null;
		const observer = new MutationObserver( () => {
			if ( timer ) clearTimeout( timer );
			timer = setTimeout( () => injectButtons( container ), 120 );
		} );
		observer.observe( container, { childList: true, subtree: true } );
	};

	observe( '#post-grid' );
	observe( '.image-gallery' );
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
		injectButtons();
		setupDynamicReinit();
		this.bindEvents();
	},

	bindEvents(): void {
		// Download Hi-Res button clicks (delegated).
		document.addEventListener( 'click', ( e: Event ) => {
			const btn = ( e.target as HTMLElement ).closest< HTMLElement >( '.smi-get-button' );
			if ( btn ) {
				e.preventDefault();
				// Track unlock button click before opening modal.
				trackGA4Event( 'smi_unlock_click', {
					post_id: parseInt( btn.dataset.postId || '0', 10 ),
					image_id: parseInt( btn.dataset.attachmentId || '0', 10 ),
				} );
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
		// Track modal open event after showing.
		trackGA4Event( 'smi_modal_open', {
			post_id: this.currentImage.postId,
			image_id: this.currentImage.attachmentId,
		} );
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
		const available = data.prices.filter( ( p ) => p.available );

		// Update preview image.
		const previewImg = main.querySelector< HTMLImageElement >( '.smi-preview-image' );
		if ( previewImg ) {
			previewImg.src = img.src;
			previewImg.alt = 'Preview';
		}

		// Update resolution options with prices and dimensions.
		available.forEach( ( price ) => {
			const radio = main.querySelector< HTMLInputElement >( `input[name="resolution"][value="${ price.resolution }"]` );
			if ( ! radio ) return;

			// Store price/dims as data attributes.
			radio.dataset.price = String( price.price || 0 );
			if ( price.output_width ) radio.dataset.outputWidth = String( price.output_width );
			if ( price.output_height ) radio.dataset.outputHeight = String( price.output_height );

			// Update price display.
			const label = radio.closest( '.smi-option' );
			if ( label ) {
				const priceEl = label.querySelector( '.smi-option-price' );
				if ( priceEl ) {
					priceEl.textContent = `$${ parseFloat( String( price.price ) ).toFixed( 2 ) }`;
				}
				// Update dimensions if available.
				const dimsEl = label.querySelector( '.smi-option-dims' );
				if ( dimsEl && price.output_width && price.output_height ) {
					dimsEl.textContent = `${ price.output_width } × ${ price.output_height }`;
				}
			}
		} );

		// Enable checkout button.
		const checkoutBtn = this.el!.querySelector< HTMLButtonElement >( '.smi-process-btn' );
		if ( checkoutBtn ) checkoutBtn.disabled = false;

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

		// Track purchase start event before initiating payment.
		trackGA4Event( 'smi_purchase_start', {
			post_id: this.currentImage.postId,
			image_id: this.currentImage.attachmentId,
			resolution: selected.value,
		} );

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
			btn.textContent = 'Checkout with Stripe';
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
