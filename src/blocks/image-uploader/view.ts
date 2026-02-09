/**
 * Frontend script for Image Uploader block
 */

interface PricingTier {
    output_width: number;
    output_height: number;
    price: number;
}

interface Pricing {
    '4x'?: PricingTier;
    '8x'?: PricingTier;
    [key: string]: PricingTier | undefined;
}

interface ImageInfo {
    width: number;
    height: number;
}

interface UploadResponse {
    success: boolean;
    upload_id?: string;
    pricing?: Pricing;
    image_info?: ImageInfo;
    message?: string;
}

interface CheckoutResponse {
    success: boolean;
    checkout_url?: string;
    message?: string;
}

( function(): void {
    'use strict';

    // Find all uploader instances on the page
    const uploaders = document.querySelectorAll< HTMLElement >( '.smi-image-uploader' );

    uploaders.forEach( function( uploader: HTMLElement ): void {
        initUploader( uploader );
    } );

    function initUploader( container: HTMLElement ): void {
        // Elements
        const dropzone = container.querySelector< HTMLElement >( '#smi-dropzone' );
        const fileInput = container.querySelector< HTMLInputElement >( '#smi-file-input' );
        const browseButton = container.querySelector< HTMLButtonElement >( '#smi-browse-button' );
        const uploadZone = container.querySelector< HTMLElement >( '#smi-upload-zone' );
        const previewZone = container.querySelector< HTMLElement >( '#smi-preview-zone' );
        const previewImage = container.querySelector< HTMLImageElement >( '#smi-preview-image' );
        const removeButton = container.querySelector< HTMLButtonElement >( '#smi-remove-image' );
        const dimensionsEl = container.querySelector< HTMLElement >( '#smi-image-dimensions' );
        const resolutionPicker = container.querySelector< HTMLElement >( '#smi-resolution-picker' );
        const emailSection = container.querySelector< HTMLElement >( '#smi-email-section' );
        const emailInput = container.querySelector< HTMLInputElement >( '#smi-email-input' );
        const checkoutSection = container.querySelector< HTMLElement >( '#smi-checkout-section' );
        const checkoutButton = container.querySelector< HTMLButtonElement >( '#smi-checkout-button' );
        const loadingEl = container.querySelector< HTMLElement >( '#smi-loading' );
        const loadingText = container.querySelector< HTMLElement >( '#smi-loading-text' );
        const errorEl = container.querySelector< HTMLElement >( '#smi-error' );
        const errorText = container.querySelector< HTMLElement >( '#smi-error-text' );

        if ( ! dropzone || ! fileInput || ! browseButton || ! uploadZone || ! previewZone || 
             ! previewImage || ! removeButton || ! checkoutButton || ! loadingEl || ! errorEl ) {
            return;
        }

        // State
        let uploadId: string | null = null;
        let pricing: Pricing | null = null;
        const maxFileSize = parseInt( container.dataset.maxFileSize || '10', 10 ) * 1024 * 1024;

        // Event Listeners
        browseButton.addEventListener( 'click', function(): void {
            fileInput.click();
        } );

        fileInput.addEventListener( 'change', function( e: Event ): void {
            const target = e.target as HTMLInputElement;
            if ( target.files && target.files.length > 0 ) {
                handleFile( target.files[ 0 ] );
            }
        } );

        // Drag and drop
        dropzone.addEventListener( 'dragover', function( e: DragEvent ): void {
            e.preventDefault();
            dropzone.classList.add( 'smi-dragover' );
        } );

        dropzone.addEventListener( 'dragleave', function( e: DragEvent ): void {
            e.preventDefault();
            dropzone.classList.remove( 'smi-dragover' );
        } );

        dropzone.addEventListener( 'drop', function( e: DragEvent ): void {
            e.preventDefault();
            dropzone.classList.remove( 'smi-dragover' );
            if ( e.dataTransfer && e.dataTransfer.files.length > 0 ) {
                handleFile( e.dataTransfer.files[ 0 ] );
            }
        } );

        removeButton.addEventListener( 'click', resetUploader );

        checkoutButton.addEventListener( 'click', handleCheckout );

        // Resolution change
        container.querySelectorAll< HTMLInputElement >( 'input[name="smi-resolution"]' ).forEach( function( radio ): void {
            radio.addEventListener( 'change', updateSelectedPrice );
        } );

        function handleFile( file: File ): void {
            // Validate file type
            const validTypes = [ 'image/jpeg', 'image/png', 'image/webp' ];
            if ( ! validTypes.includes( file.type ) ) {
                showError( 'Please upload a JPEG, PNG, or WebP image.' );
                return;
            }

            // Validate file size
            if ( file.size > maxFileSize ) {
                showError( 'File size exceeds ' + ( maxFileSize / 1024 / 1024 ) + 'MB limit.' );
                return;
            }

            // Show preview immediately
            const reader = new FileReader();
            reader.onload = function( e: ProgressEvent< FileReader > ): void {
                if ( e.target && e.target.result ) {
                    previewImage.src = e.target.result as string;
                }
            };
            reader.readAsDataURL( file );

            // Upload to server
            uploadFile( file );
        }

        function uploadFile( file: File ): void {
            showLoading( 'Uploading...' );
            hideError();

            const formData = new FormData();
            formData.append( 'image', file );

            fetch( '/wp-json/smi/v1/upload-image', {
                method: 'POST',
                body: formData,
            } )
            .then( function( response: Response ): Promise< UploadResponse > {
                return response.json();
            } )
            .then( function( data: UploadResponse ): void {
                hideLoading();

                if ( data.success && data.upload_id && data.image_info ) {
                    uploadId = data.upload_id;
                    pricing = data.pricing || null;
                    showPreview( data.image_info );
                } else {
                    showError( data.message || 'Upload failed. Please try again.' );
                    resetUploader();
                }
            } )
            .catch( function(): void {
                hideLoading();
                showError( 'Upload failed. Please try again.' );
                resetUploader();
            } );
        }

        function showPreview( imageInfo: ImageInfo ): void {
            uploadZone!.style.display = 'none';
            previewZone!.style.display = 'block';
            
            if ( dimensionsEl ) {
                dimensionsEl.textContent = imageInfo.width + ' × ' + imageInfo.height + ' px';
            }

            // Update pricing display
            if ( pricing ) {
                updatePricingDisplay();
            }

            if ( resolutionPicker ) resolutionPicker.style.display = 'block';
            if ( emailSection ) emailSection.style.display = 'block';
            if ( checkoutSection ) checkoutSection.style.display = 'block';
        }

        function updatePricingDisplay(): void {
            const output4x = container.querySelector< HTMLElement >( '#smi-output-4x' );
            const output8x = container.querySelector< HTMLElement >( '#smi-output-8x' );
            const price4x = container.querySelector< HTMLElement >( '#smi-price-4x' );
            const price8x = container.querySelector< HTMLElement >( '#smi-price-8x' );

            if ( pricing?.[ '4x' ] && output4x && price4x ) {
                output4x.textContent = pricing[ '4x' ].output_width + ' × ' + pricing[ '4x' ].output_height + ' px';
                price4x.textContent = '$' + pricing[ '4x' ].price.toFixed( 2 );
            }

            if ( pricing?.[ '8x' ] && output8x && price8x ) {
                output8x.textContent = pricing[ '8x' ].output_width + ' × ' + pricing[ '8x' ].output_height + ' px';
                price8x.textContent = '$' + pricing[ '8x' ].price.toFixed( 2 );
            }
        }

        function updateSelectedPrice(): void {
            const selectedInput = container.querySelector< HTMLInputElement >( 'input[name="smi-resolution"]:checked' );
            const selected = selectedInput?.value || '4x';
            const price = pricing?.[ selected ]?.price ?? 0;
            checkoutButton!.textContent = 'Checkout - $' + price.toFixed( 2 );
        }

        function handleCheckout(): void {
            if ( ! uploadId ) {
                showError( 'Please upload an image first.' );
                return;
            }

            const resolutionInput = container.querySelector< HTMLInputElement >( 'input[name="smi-resolution"]:checked' );
            const resolution = resolutionInput?.value || '4x';
            const email = emailInput?.value.trim() || '';

            showLoading( 'Creating checkout...' );
            hideError();

            const body: { upload_id: string; resolution: string; email?: string } = {
                upload_id: uploadId,
                resolution: resolution,
            };

            if ( email ) {
                body.email = email;
            }

            fetch( '/wp-json/smi/v1/create-checkout-upload', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify( body ),
            } )
            .then( function( response: Response ): Promise< CheckoutResponse > {
                return response.json();
            } )
            .then( function( data: CheckoutResponse ): void {
                hideLoading();

                if ( data.success && data.checkout_url ) {
                    // Redirect to Stripe
                    window.location.href = data.checkout_url;
                } else {
                    showError( data.message || 'Checkout failed. Please try again.' );
                }
            } )
            .catch( function(): void {
                hideLoading();
                showError( 'Checkout failed. Please try again.' );
            } );
        }

        function resetUploader(): void {
            uploadId = null;
            pricing = null;
            fileInput!.value = '';
            previewImage!.src = '';
            if ( emailInput ) emailInput.value = '';

            uploadZone!.style.display = 'block';
            previewZone!.style.display = 'none';
            if ( resolutionPicker ) resolutionPicker.style.display = 'none';
            if ( emailSection ) emailSection.style.display = 'none';
            if ( checkoutSection ) checkoutSection.style.display = 'none';

            // Reset to 4x
            const defaultRadio = container.querySelector< HTMLInputElement >( 'input[name="smi-resolution"][value="4x"]' );
            if ( defaultRadio ) defaultRadio.checked = true;
            checkoutButton!.textContent = 'Proceed to Checkout';

            hideError();
            hideLoading();
        }

        function showLoading( text: string ): void {
            if ( loadingText ) loadingText.textContent = text;
            loadingEl!.style.display = 'flex';
        }

        function hideLoading(): void {
            loadingEl!.style.display = 'none';
        }

        function showError( message: string ): void {
            if ( errorText ) errorText.textContent = message;
            errorEl!.style.display = 'block';
        }

        function hideError(): void {
            errorEl!.style.display = 'none';
        }

        // Initialize button text
        updateSelectedPrice();
    }
} )();
