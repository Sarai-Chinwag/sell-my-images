/**
 * Edit component for Image Uploader block
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { 
    PanelBody, 
    TextControl, 
    TextareaControl,
    RangeControl,
    ToggleControl 
} from '@wordpress/components';
import type { BlockEditProps } from '@wordpress/blocks';

/**
 * Block attributes interface
 */
interface ImageUploaderAttributes {
    title: string;
    description: string;
    maxFileSize: number;
    showTermsLink: boolean;
}

export default function Edit( { attributes, setAttributes }: BlockEditProps< ImageUploaderAttributes > ): JSX.Element {
    const { title, description, maxFileSize, showTermsLink } = attributes;
    const blockProps = useBlockProps( {
        className: 'smi-image-uploader-editor',
    } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Settings', 'sell-my-images' ) }>
                    <TextControl
                        label={ __( 'Title', 'sell-my-images' ) }
                        value={ title }
                        onChange={ ( value: string ) => setAttributes( { title: value } ) }
                    />
                    <TextareaControl
                        label={ __( 'Description', 'sell-my-images' ) }
                        value={ description }
                        onChange={ ( value: string ) => setAttributes( { description: value } ) }
                    />
                    <RangeControl
                        label={ __( 'Max File Size (MB)', 'sell-my-images' ) }
                        value={ maxFileSize }
                        onChange={ ( value ) => setAttributes( { maxFileSize: value ?? 10 } ) }
                        min={ 1 }
                        max={ 25 }
                    />
                    <ToggleControl
                        label={ __( 'Show Terms & Conditions Link', 'sell-my-images' ) }
                        checked={ showTermsLink }
                        onChange={ ( value: boolean ) => setAttributes( { showTermsLink: value } ) }
                    />
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <div className="smi-uploader-preview">
                    <div className="smi-uploader-header">
                        <h3>{ title || __( 'Upscale Your Image', 'sell-my-images' ) }</h3>
                        <p>{ description }</p>
                    </div>
                    <div className="smi-uploader-dropzone-preview">
                        <div className="smi-dropzone-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                <polyline points="17 8 12 3 7 8" />
                                <line x1="12" y1="3" x2="12" y2="15" />
                            </svg>
                        </div>
                        <p className="smi-dropzone-text">
                            { __( 'Drag & drop your image here or click to browse', 'sell-my-images' ) }
                        </p>
                        <p className="smi-dropzone-hint">
                            { __( 'Supports: JPEG, PNG, WebP (max', 'sell-my-images' ) } { maxFileSize }MB)
                        </p>
                    </div>
                    <p className="smi-editor-note">
                        { __( '⚡ This is a preview. The actual uploader will appear on the frontend.', 'sell-my-images' ) }
                    </p>
                </div>
            </div>
        </>
    );
}
