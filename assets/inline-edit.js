/**
 * framix-blocks inline edit — click marked text in the editor canvas to edit.
 *
 * Server templates print framix_block_edit_attr() markers (REST-gated, never
 * on the front end). The editor renders dynamic/SSR block previews
 * non-interactively, so a click lands on the block wrapper — an ANCESTOR of
 * the marked element — not on the marker itself. We therefore resolve the
 * marker by hit-testing the pointer coordinates against the marked descendants
 * (a JS mousemove pass outlines them on hover for the same reason). The
 * Popover then opens anchored on the resolved node with the control derived
 * from the block.json attribute schema — no per-block configuration. Writes go
 * through setAttributes; the SSR preview re-renders and a MutationObserver
 * re-anchors the open popover to the fresh node.
 *
 * Plain IIFE, no build step — same conventions as media-control.js.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.components || ! wp.compose || ! wp.blocks ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var Popover = wp.components.Popover;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;

	var MARKER = 'data-framix-edit';
	var MARKER_I = 'data-framix-edit-i';
	var MARKER_F = 'data-framix-edit-f';
	var STYLE_ID = 'framix-inline-edit-style';
	var HOVER_CLASS = 'framix-edit-hover';
	// CSS :hover can't fire on the marker — the SSR preview is non-interactive,
	// so the pointer never reaches it. A JS mousemove pass toggles HOVER_CLASS.
	var HOVER_CSS =
		'[' + MARKER + ']{cursor:text}' +
		'.' + HOVER_CLASS + '{outline:1px dashed currentColor;outline-offset:2px}';

	// The hover affordance must land in the canvas document (iframed in 6.3+).
	function ensureHoverStyle( doc ) {
		if ( ! doc || doc.getElementById( STYLE_ID ) || ! doc.head ) {
			return;
		}
		var style = doc.createElement( 'style' );
		style.id = STYLE_ID;
		style.textContent = HOVER_CSS;
		doc.head.appendChild( style );
	}

	// Control spec for a marker, from the block.json attribute schema.
	// Returns { label, multiline } or null when the target isn't editable text.
	function fieldSpec( blockName, attr, field ) {
		var type = wp.blocks.getBlockType( blockName );
		var schema = type && type.attributes ? type.attributes[ attr ] : null;
		if ( ! schema ) {
			return null;
		}
		if ( field !== null ) {
			if ( schema.control !== 'repeater' || ! schema.fields || ! schema.fields[ field ] ) {
				return null;
			}
			var f = schema.fields[ field ];
			if ( f.type !== 'string' || f.control === 'media' ) {
				return null;
			}
			return { label: field, multiline: f.control === 'textarea' };
		}
		if ( schema.type !== 'string' || schema.control === 'media' ) {
			return null;
		}
		return { label: attr, multiline: schema.control === 'textarea' };
	}

	function targetSelector( t ) {
		var sel = '[' + MARKER + '="' + t.attr + '"]';
		if ( t.i !== null ) {
			sel += '[' + MARKER_I + '="' + t.i + '"]';
		}
		if ( t.f !== null ) {
			sel += '[' + MARKER_F + '="' + t.f + '"]';
		}
		return sel;
	}

	// Resolve the marked element under a viewport point by hit-testing the
	// marked descendants directly. The editor's non-interactive SSR preview
	// makes the block wrapper the pointer target, so e.target.closest() (which
	// only searches ancestors) can't reach the marker; coordinate testing can.
	// On overlapping rects the smallest wins, so a nested marker (a repeater
	// row inside a card) beats its container. Coordinates and rects are both
	// relative to the canvas iframe's viewport, so they compare directly.
	function markerAt( node, x, y ) {
		var els = node.querySelectorAll( '[' + MARKER + ']' );
		var best = null;
		var bestArea = Infinity;
		for ( var i = 0; i < els.length; i++ ) {
			var r = els[ i ].getBoundingClientRect();
			if ( r.width <= 0 || r.height <= 0 ) {
				continue;
			}
			if ( x >= r.left && x <= r.right && y >= r.top && y <= r.bottom ) {
				var area = r.width * r.height;
				if ( area < bestArea ) {
					best = els[ i ];
					bestArea = area;
				}
			}
		}
		return best;
	}

	var withInlineEdit = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var wrapRef = useRef( null );
			// The anchor node lives in a ref, not state: SSR re-renders swap
			// the node out from under us, and the MutationObserver must stay
			// mounted across those swaps (see the re-anchor effect below).
			var anchorRef = useRef( null );
			var editingState = useState( null ); // { attr, i, f, spec }
			var editing = editingState[ 0 ];
			var setEditing = editingState[ 1 ];
			var tickState = useState( 0 ); // render bump when the anchor ref moves
			var setTick = tickState[ 1 ];

			useEffect( function () {
				var node = wrapRef.current;
				if ( ! node ) {
					return undefined;
				}
				ensureHoverStyle( node.ownerDocument );

				function onClick( e ) {
					// Interactive blocks: the marker is (in) the click target.
					// SSR/dynamic previews are non-interactive, so the target is
					// the block wrapper — an ancestor of the marker — and
					// closest() misses; fall back to coordinate hit-testing.
					var hit = e.target && e.target.closest ? e.target.closest( '[' + MARKER + ']' ) : null;
					if ( ! hit || ! node.contains( hit ) ) {
						hit = markerAt( node, e.clientX, e.clientY );
					}
					if ( ! hit || ! node.contains( hit ) ) {
						return;
					}
					var attr = hit.getAttribute( MARKER );
					var iRaw = hit.getAttribute( MARKER_I );
					var f = hit.getAttribute( MARKER_F );
					var spec = fieldSpec( props.name, attr, f );
					if ( ! spec ) {
						return;
					}
					e.preventDefault();
					e.stopPropagation();
					// The capture-phase swallow means the editor never sees the
					// click — select the block ourselves so the toolbar appears.
					if ( wp.data && props.clientId ) {
						wp.data.dispatch( 'core/block-editor' ).selectBlock( props.clientId );
					}
					anchorRef.current = hit;
					setEditing( {
						attr: attr,
						i: iRaw === null ? null : parseInt( iRaw, 10 ),
						f: f,
						spec: spec,
					} );
				}

				// Hover affordance: outline the marker under the pointer. Driven
				// by mousemove (not CSS :hover) for the same non-interactivity
				// reason the click handler hit-tests by coordinate.
				var hovered = null;
				function setHover( m ) {
					if ( m === hovered ) {
						return;
					}
					if ( hovered ) {
						hovered.classList.remove( HOVER_CLASS );
					}
					hovered = m;
					if ( m ) {
						m.classList.add( HOVER_CLASS );
					}
				}
				function onMove( e ) {
					setHover( markerAt( node, e.clientX, e.clientY ) );
				}
				function onLeave() {
					setHover( null );
				}

				node.addEventListener( 'click', onClick, true );
				node.addEventListener( 'mousemove', onMove );
				node.addEventListener( 'mouseleave', onLeave );
				return function () {
					node.removeEventListener( 'click', onClick, true );
					node.removeEventListener( 'mousemove', onMove );
					node.removeEventListener( 'mouseleave', onLeave );
					setHover( null );
				};
			}, [ props.name ] );

			// SSR re-render replaces the preview DOM — re-anchor the open popover.
			// Keyed on the stable target identity (not the editing object), so the
			// observer survives re-anchors instead of tearing down on each swap;
			// the anchor moves via the ref + a tick bump that re-renders the Popover.
			var editingKey = editing ? editing.attr + '|' + editing.i + '|' + editing.f : null;
			useEffect( function () {
				if ( ! editing || ! wrapRef.current ) {
					return undefined;
				}
				var observer = new MutationObserver( function () {
					if ( ! wrapRef.current ) {
						return;
					}
					var fresh = wrapRef.current.querySelector( targetSelector( editing ) );
					if ( fresh && fresh !== anchorRef.current ) {
						anchorRef.current = fresh;
						setTick( function ( t ) {
							return t + 1;
						} );
					}
				} );
				observer.observe( wrapRef.current, { childList: true, subtree: true } );
				return function () {
					observer.disconnect();
				};
			}, [ editingKey ] );

			function currentValue() {
				var v = props.attributes[ editing.attr ];
				if ( editing.f !== null ) {
					var row = Array.isArray( v ) ? v[ editing.i ] : null;
					return row && row[ editing.f ] !== undefined && row[ editing.f ] !== null
						? String( row[ editing.f ] )
						: '';
				}
				return v !== undefined && v !== null ? String( v ) : '';
			}

			function commit( value ) {
				var patch = {};
				if ( editing.f !== null ) {
					var rows = ( props.attributes[ editing.attr ] || [] ).slice();
					var row = Object.assign( {}, rows[ editing.i ] );
					row[ editing.f ] = value;
					rows[ editing.i ] = row;
					patch[ editing.attr ] = rows;
				} else {
					patch[ editing.attr ] = value;
				}
				props.setAttributes( patch );
			}

			var popover = null;
			if ( editing && anchorRef.current ) {
				var Control = editing.spec.multiline ? TextareaControl : TextControl;
				popover = el(
					Popover,
					{
						anchor: anchorRef.current,
						placement: 'bottom-start',
						focusOnMount: true,
						onClose: function () {
							anchorRef.current = null;
							setEditing( null );
						},
					},
					el(
						'div',
						{ style: { padding: '8px', minWidth: '260px' } },
						el( Control, {
							label: editing.spec.label,
							value: currentValue(),
							onChange: commit,
							__nextHasNoMarginBottom: true,
						} )
					)
				);
			}

			return el(
				Fragment,
				null,
				// display:contents keeps the wrapper layout-neutral — the HOC
				// wraps every block, including flex/grid parents like columns.
				el( 'div', { ref: wrapRef, style: { display: 'contents' } }, el( BlockEdit, props ) ),
				popover
			);
		};
	}, 'withFramixInlineEdit' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'framix-blocks/inline-edit', withInlineEdit );
} )( window.wp );
