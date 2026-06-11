/**
 * Framix Blocks — editor media control.
 *
 * WordPress 7.0 autoRegister generates each block's editor preview and
 * Inspector controls from its block.json. For an attribute that declares
 * `control: "media"` (always type: integer), the auto-generated control is
 * a plain number field — useless for a marketer. This shim replaces it
 * with a WordPress MediaUpload picker and stores the selected attachment
 * ID (an integer) back into the attribute.
 *
 * Loaded via enqueue_block_editor_assets. UMD-style IIFE — no build step,
 * uses the WordPress globals directly. It NEVER renders the block body;
 * that stays in the server-rendered preview (PHP-only philosophy).
 */
( function ( wp ) {
	if ( ! wp || ! wp.hooks || ! wp.blockEditor || ! wp.element || ! wp.components || ! wp.compose || ! wp.blocks ) {
		// WordPress globals not present — bail. The Inspector falls back to
		// the auto-generated number control for the integer attribute.
		return;
	}

	var addFilter      = wp.hooks.addFilter;
	var createElement  = wp.element.createElement;
	var Fragment       = wp.element.Fragment;
	var InspectorCtrls = wp.blockEditor.InspectorControls;
	var MediaUpload    = wp.blockEditor.MediaUpload;
	var MediaUploadChk = wp.blockEditor.MediaUploadCheck;
	var PanelBody      = wp.components.PanelBody;
	var Button         = wp.components.Button;
	var createHOC      = wp.compose.createHigherOrderComponent;

	/**
	 * Collect every attribute on a block type that declares control: "media".
	 *
	 * @param {Object} blockType Resolved block type (from getBlockType).
	 * @return {Array<{name:string,label:string}>} Media attributes.
	 */
	function mediaAttrsOf( blockType ) {
		var out = [];
		if ( ! blockType || ! blockType.attributes ) {
			return out;
		}
		Object.keys( blockType.attributes ).forEach( function ( key ) {
			var def = blockType.attributes[ key ];
			if ( def && def.control === 'media' ) {
				out.push( { name: key, label: ( def.label || key ) } );
			}
		} );
		return out;
	}

	var withMediaControls = createHOC( function ( BlockEdit ) {
		return function ( props ) {
			var blockType  = wp.blocks.getBlockType( props.name );
			var mediaAttrs = mediaAttrsOf( blockType );

			// No media attributes — render the block untouched.
			if ( ! mediaAttrs.length ) {
				return createElement( BlockEdit, props );
			}

			var controls = mediaAttrs.map( function ( attr ) {
				var currentId = props.attributes[ attr.name ] || 0;

				return createElement(
					MediaUploadChk,
					{ key: attr.name },
					createElement( MediaUpload, {
						allowedTypes: [ 'image' ],
						value: currentId,
						onSelect: function ( media ) {
							var update = {};
							// Store the integer attachment ID.
							update[ attr.name ] = media && media.id ? parseInt( media.id, 10 ) : 0;
							props.setAttributes( update );
						},
						render: function ( open ) {
							return createElement(
								'div',
								{ style: { marginBottom: '12px' } },
								createElement(
									'div',
									{ style: { marginBottom: '6px', fontWeight: 600 } },
									attr.label
								),
								createElement(
									Button,
									{
										variant: currentId ? 'secondary' : 'primary',
										onClick: open.open
									},
									currentId ? 'Replace image' : 'Select image'
								),
								currentId
									? createElement(
										Button,
										{
											variant: 'link',
											isDestructive: true,
											style: { marginLeft: '8px' },
											onClick: function () {
												var update = {};
												update[ attr.name ] = 0;
												props.setAttributes( update );
											}
										},
										'Remove'
									)
									: null
							);
						}
					} )
				);
			} );

			return createElement(
				Fragment,
				null,
				createElement( BlockEdit, props ),
				createElement(
					InspectorCtrls,
					null,
					createElement( PanelBody, { title: 'Media', initialOpen: true }, controls )
				)
			);
		};
	}, 'withFramixBlocksMediaControls' );

	addFilter( 'editor.BlockEdit', 'framix-blocks/media-control', withMediaControls );
}( window.wp ) );
