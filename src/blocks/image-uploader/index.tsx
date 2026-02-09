/**
 * Image Uploader Block
 * 
 * Allows visitors to upload and upscale any image.
 */

import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import metadata from './block.json';
import './editor.scss';
import './style.scss';

registerBlockType( metadata.name, {
    edit: Edit,
    save: () => null, // Dynamic block, rendered via PHP
} );
