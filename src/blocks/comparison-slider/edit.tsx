/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps, MediaUpload, MediaUploadCheck, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, Button, RangeControl, Placeholder, TextControl } from '@wordpress/components';
import type { BlockEditProps } from '@wordpress/blocks';

/**
 * Block attributes interface
 */
interface ComparisonSliderAttributes {
    beforeImageId?: number;
    beforeImageUrl?: string;
    afterImageId?: number;
    afterImageUrl?: string;
    initialPosition: number;
    beforeLabel: string;
    afterLabel: string;
}

/**
 * Media object interface
 */
interface MediaObject {
    id: number;
    url: string;
}

/**
 * Edit component
 */
export default function Edit( { attributes, setAttributes }: BlockEditProps< ComparisonSliderAttributes > ): JSX.Element {
    const { beforeImageId, beforeImageUrl, afterImageId, afterImageUrl, initialPosition, beforeLabel, afterLabel } = attributes;
    const blockProps = useBlockProps();

    const onSelectBeforeImage = ( media: MediaObject ): void => {
        setAttributes( {
            beforeImageId: media.id,
            beforeImageUrl: media.url,
        } );
    };

    const onSelectAfterImage = ( media: MediaObject ): void => {
        setAttributes( {
            afterImageId: media.id,
            afterImageUrl: media.url,
        } );
    };

    const onRemoveBeforeImage = (): void => {
        setAttributes( {
            beforeImageId: undefined,
            beforeImageUrl: undefined,
        } );
    };

    const onRemoveAfterImage = (): void => {
        setAttributes( {
            afterImageId: undefined,
            afterImageUrl: undefined,
        } );
    };

    const hasImages = beforeImageUrl && afterImageUrl;

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Slider Settings', 'sell-my-images' ) }>
                    <TextControl
                        label={ __( 'Before Label', 'sell-my-images' ) }
                        value={ beforeLabel }
                        onChange={ ( value ) => setAttributes( { beforeLabel: value } ) }
                        help={ __( 'Label for the original/before image', 'sell-my-images' ) }
                    />
                    <TextControl
                        label={ __( 'After Label', 'sell-my-images' ) }
                        value={ afterLabel }
                        onChange={ ( value ) => setAttributes( { afterLabel: value } ) }
                        help={ __( 'Label for the enhanced/after image', 'sell-my-images' ) }
                    />
                    <RangeControl
                        label={ __( 'Initial Position', 'sell-my-images' ) }
                        value={ initialPosition }
                        onChange={ ( value ) => setAttributes( { initialPosition: value ?? 50 } ) }
                        min={ 0 }
                        max={ 100 }
                        help={ __( 'Where the slider starts (0=only original, 100=fully enhanced)', 'sell-my-images' ) }
                    />
                </PanelBody>
            </InspectorControls>

            <div { ...blockProps }>
                { ! hasImages ? (
                    <Placeholder
                        icon="image-flip-horizontal"
                        label={ __( 'Before/After Comparison', 'sell-my-images' ) }
                        instructions={ __( 'Select before and after images to show the comparison.', 'sell-my-images' ) }
                    >
                        <div className="smi-comparison-upload-grid">
                            <div className="smi-comparison-upload-item">
                                <h4>{ __( 'Before Image', 'sell-my-images' ) }</h4>
                                <MediaUploadCheck>
                                    <MediaUpload
                                        onSelect={ onSelectBeforeImage }
                                        allowedTypes={ [ 'image' ] }
                                        value={ beforeImageId }
                                        render={ ( { open } ) => (
                                            <Button variant={ beforeImageUrl ? 'secondary' : 'primary' } onClick={ open }>
                                                { beforeImageUrl ? __( 'Replace Before', 'sell-my-images' ) : __( 'Select Before', 'sell-my-images' ) }
                                            </Button>
                                        ) }
                                    />
                                </MediaUploadCheck>
                                { beforeImageUrl && (
                                    <img 
                                        src={ beforeImageUrl } 
                                        alt={ __( 'Before image preview', 'sell-my-images' ) }
                                        style={{ width: '100px', height: '100px', objectFit: 'cover', marginTop: '8px' }}
                                    />
                                ) }
                            </div>
                            
                            <div className="smi-comparison-upload-item">
                                <h4>{ __( 'After Image', 'sell-my-images' ) }</h4>
                                <MediaUploadCheck>
                                    <MediaUpload
                                        onSelect={ onSelectAfterImage }
                                        allowedTypes={ [ 'image' ] }
                                        value={ afterImageId }
                                        render={ ( { open } ) => (
                                            <Button variant={ afterImageUrl ? 'secondary' : 'primary' } onClick={ open }>
                                                { afterImageUrl ? __( 'Replace After', 'sell-my-images' ) : __( 'Select After', 'sell-my-images' ) }
                                            </Button>
                                        ) }
                                    />
                                </MediaUploadCheck>
                                { afterImageUrl && (
                                    <img 
                                        src={ afterImageUrl } 
                                        alt={ __( 'After image preview', 'sell-my-images' ) }
                                        style={{ width: '100px', height: '100px', objectFit: 'cover', marginTop: '8px' }}
                                    />
                                ) }
                            </div>
                        </div>
                    </Placeholder>
                ) : (
                    <div className="smi-comparison-editor-preview">
                        <div className="smi-comparison-preview-container">
                            <div className="smi-comparison-preview-before">
                                <img src={ beforeImageUrl } alt={ __( 'Before image preview', 'sell-my-images' ) } />
                                <span className="smi-comparison-preview-label">{ beforeLabel }</span>
                            </div>
                            <div className="smi-comparison-preview-after">
                                <img src={ afterImageUrl } alt={ __( 'After image preview', 'sell-my-images' ) } />
                                <span className="smi-comparison-preview-label">{ afterLabel }</span>
                            </div>
                            <div className="smi-comparison-preview-overlay">
                                <span>{ __( 'Interactive Slider (Frontend)', 'sell-my-images' ) }</span>
                            </div>
                        </div>
                        
                        <div className="smi-comparison-preview-actions">
                            <MediaUploadCheck>
                                <MediaUpload
                                    onSelect={ onSelectBeforeImage }
                                    allowedTypes={ [ 'image' ] }
                                    value={ beforeImageId }
                                    render={ ( { open } ) => (
                                        <Button variant="secondary" onClick={ open }>
                                            { __( 'Replace Before', 'sell-my-images' ) }
                                        </Button>
                                    ) }
                                />
                            </MediaUploadCheck>
                            
                            <MediaUploadCheck>
                                <MediaUpload
                                    onSelect={ onSelectAfterImage }
                                    allowedTypes={ [ 'image' ] }
                                    value={ afterImageId }
                                    render={ ( { open } ) => (
                                        <Button variant="secondary" onClick={ open }>
                                            { __( 'Replace After', 'sell-my-images' ) }
                                        </Button>
                                    ) }
                                />
                            </MediaUploadCheck>
                            
                            <Button variant="link" isDestructive onClick={ onRemoveBeforeImage }>
                                { __( 'Remove Before', 'sell-my-images' ) }
                            </Button>
                            
                            <Button variant="link" isDestructive onClick={ onRemoveAfterImage }>
                                { __( 'Remove After', 'sell-my-images' ) }
                            </Button>
                        </div>
                    </div>
                ) }
            </div>
        </>
    );
}