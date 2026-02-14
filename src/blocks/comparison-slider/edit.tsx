/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps, MediaUpload, MediaUploadCheck, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, Button, RangeControl, Placeholder } from '@wordpress/components';
import type { BlockEditProps } from '@wordpress/blocks';

/**
 * Block attributes interface
 */
interface ComparisonSliderAttributes {
    imageId?: number;
    imageUrl?: string;
    blurAmount: number;
    initialPosition: number;
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
    const { imageId, imageUrl, blurAmount, initialPosition } = attributes;
    const blockProps = useBlockProps();

    const onSelectImage = ( media: MediaObject ): void => {
        setAttributes( {
            imageId: media.id,
            imageUrl: media.url,
        } );
    };

    const onRemoveImage = (): void => {
        setAttributes( {
            imageId: undefined,
            imageUrl: undefined,
        } );
    };

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Slider Settings', 'sell-my-images' ) }>
                    <RangeControl
                        label={ __( 'Blur Amount', 'sell-my-images' ) }
                        value={ blurAmount }
                        onChange={ ( value ) => setAttributes( { blurAmount: value ?? 2 } ) }
                        min={ 0 }
                        max={ 10 }
                        step={ 0.5 }
                        help={ __( 'How blurry the "before" side appears', 'sell-my-images' ) }
                    />
                    <RangeControl
                        label={ __( 'Initial Position', 'sell-my-images' ) }
                        value={ initialPosition }
                        onChange={ ( value ) => setAttributes( { initialPosition: value ?? 0 } ) }
                        min={ 0 }
                        max={ 100 }
                        help={ __( 'Where the slider starts (0=only original, 100=fully enhanced)', 'sell-my-images' ) }
                    />
                </PanelBody>
            </InspectorControls>

            <div { ...blockProps }>
                { ! imageUrl ? (
                    <MediaUploadCheck>
                        <Placeholder
                            icon="image-flip-horizontal"
                            label={ __( 'Before/After Comparison', 'sell-my-images' ) }
                            instructions={ __( 'Select an image to show the upscaling quality comparison.', 'sell-my-images' ) }
                        >
                            <MediaUpload
                                onSelect={ onSelectImage }
                                allowedTypes={ [ 'image' ] }
                                value={ imageId }
                                render={ ( { open } ) => (
                                    <Button variant="primary" onClick={ open }>
                                        { __( 'Select Image', 'sell-my-images' ) }
                                    </Button>
                                ) }
                            />
                        </Placeholder>
                    </MediaUploadCheck>
                ) : (
                    <div className="smi-comparison-editor-preview">
                        <div className="smi-comparison-preview-image">
                            <img src={ imageUrl } alt={ __( 'Comparison preview', 'sell-my-images' ) } />
                            <div className="smi-comparison-preview-overlay">
                                <span>{ __( 'Before/After Slider', 'sell-my-images' ) }</span>
                            </div>
                        </div>
                        <div className="smi-comparison-preview-actions">
                            <MediaUploadCheck>
                                <MediaUpload
                                    onSelect={ onSelectImage }
                                    allowedTypes={ [ 'image' ] }
                                    value={ imageId }
                                    render={ ( { open } ) => (
                                        <Button variant="secondary" onClick={ open }>
                                            { __( 'Replace Image', 'sell-my-images' ) }
                                        </Button>
                                    ) }
                                />
                            </MediaUploadCheck>
                            <Button variant="link" isDestructive onClick={ onRemoveImage }>
                                { __( 'Remove', 'sell-my-images' ) }
                            </Button>
                        </div>
                    </div>
                ) }
            </div>
        </>
    );
}
