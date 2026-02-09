/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import Edit from './edit';
import metadata from './block.json';
import './editor.scss';
import './style.scss';

/**
 * Register the block
 */
registerBlockType( metadata.name, {
    edit: Edit,
    save: () => null, // Dynamic block, rendered via PHP
} );
