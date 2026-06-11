/**
 * Framix Blocks — editor repeater control.
 *
 * WordPress 7.0 autoRegister generates each block's editor preview and
 * Inspector controls from its block.json, but has no concept of a
 * repeater. For an attribute that declares `control: "repeater"`
 * (always type: array), this shim renders a sidebar CRUD UI:
 *
 *   - No `fields` key on the attribute → simple repeater: array of
 *     strings, one text input per row.
 *   - With `fields` → object repeater: each row is an object whose
 *     keys render per their field def. A field may itself declare
 *     `control: "repeater"` — recursion gives nested repeaters from
 *     the same code path.
 *
 * Rows support add / remove / move up / move down. Object rows render
 * collapsed (labelled by their first non-empty string field) so long
 * lists stay manageable in the sidebar.
 *
 * Loaded via enqueue_block_editor_assets. UMD-style IIFE — no build
 * step, uses the WordPress globals directly. It NEVER renders the
 * block body; that stays in the server-rendered preview (PHP-only
 * philosophy). Data flows as plain arrays through setAttributes and
 * serializes into the block-comment JSON; render.php escapes output.
 */
( function ( wp ) {
	if ( ! wp || ! wp.hooks || ! wp.blockEditor || ! wp.element || ! wp.components || ! wp.compose || ! wp.blocks ) {
		// WordPress globals not present — bail. The attribute keeps its
		// block.json default; the server render is unaffected.
		return;
	}

	var addFilter      = wp.hooks.addFilter;
	var el             = wp.element.createElement;
	var Fragment       = wp.element.Fragment;
	var useState       = wp.element.useState;
	var InspectorCtrls = wp.blockEditor.InspectorControls;
	var PanelBody      = wp.components.PanelBody;
	var Button         = wp.components.Button;
	var TextControl    = wp.components.TextControl;
	var createHOC      = wp.compose.createHigherOrderComponent;

	/**
	 * Collect every attribute on a block type that declares control: "repeater".
	 *
	 * @param {Object} blockType Resolved block type (from getBlockType).
	 * @return {Array<{name:string,def:Object}>} Repeater attributes.
	 */
	function repeaterAttrsOf( blockType ) {
		var out = [];
		if ( ! blockType || ! blockType.attributes ) {
			return out;
		}
		Object.keys( blockType.attributes ).forEach( function ( key ) {
			var def = blockType.attributes[ key ];
			if ( def && def.control === 'repeater' ) {
				out.push( { name: key, def: def } );
			}
		} );
		return out;
	}

	/**
	 * A fresh empty row for a repeater def: '' for simple repeaters, an
	 * object with one empty value per field for object repeaters.
	 *
	 * @param {Object} def Repeater attribute/field definition.
	 * @return {string|Object} New row value.
	 */
	function emptyRowFor( def ) {
		if ( ! def.fields ) {
			return '';
		}
		var row = {};
		Object.keys( def.fields ).forEach( function ( key ) {
			var f = def.fields[ key ];
			row[ key ] = ( f && 'array' === f.type ) ? [] : '';
		} );
		return row;
	}

	/**
	 * Immutable helpers — every mutation returns a new array so React
	 * and the block editor see the change.
	 */
	function withReplaced( rows, index, value ) {
		var next = rows.slice();
		next[ index ] = value;
		return next;
	}

	function withRemoved( rows, index ) {
		var next = rows.slice();
		next.splice( index, 1 );
		return next;
	}

	function withMoved( rows, index, delta ) {
		var target = index + delta;
		if ( target < 0 || target >= rows.length ) {
			return rows;
		}
		var next = rows.slice();
		var tmp  = next[ target ];
		next[ target ] = next[ index ];
		next[ index ]  = tmp;
		return next;
	}

	/**
	 * Collapsed-row label for an object row: first non-empty string
	 * field value, else "Item N".
	 *
	 * @param {Object} def   Repeater definition (has .fields).
	 * @param {Object} row   Row value.
	 * @param {number} index Row index.
	 * @return {string} Label.
	 */
	function rowLabel( def, row, index ) {
		var label = '';
		Object.keys( def.fields ).some( function ( key ) {
			var f = def.fields[ key ];
			var v = row && row[ key ];
			if ( f && 'array' !== f.type && 'string' === typeof v && '' !== v ) {
				label = v;
				return true;
			}
			return false;
		} );
		return label || 'Item ' + ( index + 1 );
	}

	/**
	 * The up / down / remove button cluster for one row.
	 */
	function RowButtons( props ) {
		return el(
			'div',
			{ style: { display: 'flex', gap: '2px', flexShrink: 0 } },
			el( Button, {
				icon: 'arrow-up-alt2',
				label: 'Move up',
				size: 'small',
				disabled: 0 === props.index,
				onClick: props.onMoveUp
			} ),
			el( Button, {
				icon: 'arrow-down-alt2',
				label: 'Move down',
				size: 'small',
				disabled: props.index === props.count - 1,
				onClick: props.onMoveDown
			} ),
			el( Button, {
				icon: 'no-alt',
				label: 'Remove',
				size: 'small',
				isDestructive: true,
				onClick: props.onRemove
			} )
		);
	}

	/**
	 * One field inside an object row. A field declaring
	 * control: "repeater" recurses into RepeaterRows.
	 */
	function FieldControl( props ) {
		var fdef = props.def;

		if ( fdef && fdef.control === 'repeater' ) {
			return el(
				'div',
				{ style: { marginTop: '8px' } },
				el(
					'div',
					{ style: { fontWeight: 600, marginBottom: '4px' } },
					fdef.label || props.fieldKey
				),
				el( RepeaterRows, {
					def: fdef,
					value: props.value,
					onChange: props.onChange,
					depth: props.depth + 1
				} )
			);
		}

		return el( TextControl, {
			label: fdef && fdef.label ? fdef.label : props.fieldKey,
			value: 'string' === typeof props.value ? props.value : '',
			onChange: props.onChange,
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: false
		} );
	}

	/**
	 * One row. Simple repeater → inline text input + buttons.
	 * Object repeater → collapsible card with its fields inside.
	 */
	function RepeaterRow( props ) {
		var def      = props.def;
		var openInit = useState( false );
		var isOpen   = openInit[ 0 ];
		var setOpen  = openInit[ 1 ];

		// Simple string row.
		if ( ! def.fields ) {
			return el(
				'div',
				{ style: { display: 'flex', alignItems: 'center', gap: '4px', marginBottom: '4px' } },
				el(
					'div',
					{ style: { flexGrow: 1, minWidth: 0 } },
					el( TextControl, {
						value: 'string' === typeof props.value ? props.value : '',
						onChange: props.onChange,
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: false
					} )
				),
				el( RowButtons, props )
			);
		}

		// Object row — collapsed header + expandable fields.
		var row    = props.value && 'object' === typeof props.value ? props.value : {};
		var fields = isOpen
			? Object.keys( def.fields ).map( function ( key ) {
				return el( FieldControl, {
					key: key,
					fieldKey: key,
					def: def.fields[ key ],
					value: row[ key ],
					depth: props.depth,
					onChange: function ( v ) {
						var next = {};
						Object.keys( row ).forEach( function ( k ) {
							next[ k ] = row[ k ];
						} );
						next[ key ] = v;
						props.onChange( next );
					}
				} );
			} )
			: null;

		return el(
			'div',
			{ style: { border: '1px solid #ddd', marginBottom: '6px' } },
			el(
				'div',
				{ style: { display: 'flex', alignItems: 'center', gap: '4px', padding: '4px 6px', background: '#f6f7f7' } },
				el(
					Button,
					{
						icon: isOpen ? 'arrow-down' : 'arrow-right-alt2',
						size: 'small',
						label: isOpen ? 'Collapse' : 'Expand',
						onClick: function () {
							setOpen( ! isOpen );
						}
					}
				),
				el(
					'div',
					{
						style: {
							flexGrow: 1,
							minWidth: 0,
							overflow: 'hidden',
							textOverflow: 'ellipsis',
							whiteSpace: 'nowrap',
							fontWeight: 600,
							cursor: 'pointer'
						},
						onClick: function () {
							setOpen( ! isOpen );
						}
					},
					rowLabel( def, row, props.index )
				),
				el( RowButtons, props )
			),
			isOpen ? el( 'div', { style: { padding: '8px' } }, fields ) : null
		);
	}

	/**
	 * The rows + Add button for one repeater level. Recursion entry
	 * point: nested repeater fields render this again via FieldControl.
	 */
	function RepeaterRows( props ) {
		var def      = props.def;
		var rows     = Array.isArray( props.value ) ? props.value : [];
		var onChange = props.onChange;

		var rendered = rows.map( function ( row, index ) {
			return el( RepeaterRow, {
				key: index,
				def: def,
				value: row,
				index: index,
				count: rows.length,
				depth: props.depth,
				onChange: function ( v ) {
					onChange( withReplaced( rows, index, v ) );
				},
				onMoveUp: function () {
					onChange( withMoved( rows, index, -1 ) );
				},
				onMoveDown: function () {
					onChange( withMoved( rows, index, 1 ) );
				},
				onRemove: function () {
					onChange( withRemoved( rows, index ) );
				}
			} );
		} );

		return el(
			'div',
			null,
			rendered,
			el(
				Button,
				{
					variant: 'secondary',
					size: 'small',
					onClick: function () {
						onChange( rows.concat( [ emptyRowFor( def ) ] ) );
					}
				},
				'+ Add'
			)
		);
	}

	var withRepeaterControls = createHOC( function ( BlockEdit ) {
		return function ( props ) {
			var blockType     = wp.blocks.getBlockType( props.name );
			var repeaterAttrs = repeaterAttrsOf( blockType );

			// No repeater attributes — render the block untouched.
			if ( ! repeaterAttrs.length ) {
				return el( BlockEdit, props );
			}

			var panels = repeaterAttrs.map( function ( attr ) {
				return el(
					PanelBody,
					{ key: attr.name, title: attr.def.label || attr.name, initialOpen: true },
					el( RepeaterRows, {
						def: attr.def,
						value: props.attributes[ attr.name ],
						depth: 0,
						onChange: function ( rows ) {
							var update = {};
							update[ attr.name ] = rows;
							props.setAttributes( update );
						}
					} )
				);
			} );

			return el(
				Fragment,
				null,
				el( BlockEdit, props ),
				el( InspectorCtrls, null, panels )
			);
		};
	}, 'withFramixBlocksRepeaterControls' );

	addFilter( 'editor.BlockEdit', 'framix-blocks/repeater-control', withRepeaterControls );
}( window.wp ) );
