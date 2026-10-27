/* GenD Society - Profile Styling builder (Design Studio page gs-studio-profile-styling).
   Header templates + assignments, per-tab layouts and the profile menu. */
( function () {
	'use strict';
	var root = document.getElementById( 'gs-ps-root' );
	if ( ! root || ! window.wp || ! wp.apiFetch ) {
		return;
	}
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var api = wp.apiFetch;
	var base = '/gend-society/v1/profile-styling';
	var state = null;

	function h( tag, attrs ) {
		var el = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( v === null || v === undefined || v === false ) {
				return;
			}
			if ( k === 'class' ) {
				el.className = v;
			} else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) {
				el.addEventListener( k.slice( 2 ), v );
			} else if ( v === true ) {
				el.setAttribute( k, '' );
			} else {
				el.setAttribute( k, v );
			}
		} );
		for ( var i = 2; i < arguments.length; i++ ) {
			[].concat( arguments[ i ] ).forEach( function ( c ) {
				if ( c !== null && c !== undefined && c !== false ) {
					el.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
				}
			} );
		}
		return el;
	}
	function msg( el, text, bad ) {
		el.textContent = text;
		el.className = 'gs-ps-msg' + ( bad ? ' is-bad' : '' );
		setTimeout( function () {
			el.textContent = '';
		}, 4000 );
	}
	function err( e ) {
		return ( e && e.message ) || __( 'Something went wrong.', 'gend-society' );
	}
	function assignedText( t ) {
		var bits = [];
		if ( t.siteDefault ) {
			bits.push( __( 'Site default', 'gend-society' ) );
		}
		t.types.forEach( function ( slug ) {
			bits.push( sprintf( __( 'Default for: %s', 'gend-society' ), state.memberTypes[ slug ] || slug ) );
		} );
		return bits.length ? bits.join( ', ' ) : __( 'Unassigned', 'gend-society' );
	}
	function load() {
		return api( { path: base } ).then( function ( s ) {
			state = s;
			render();
		}, function ( e ) {
			root.textContent = err( e );
		} );
	}
	// Styled stand-in for prompt()/confirm(): resolves with the text (or true), or null when cancelled.
	function ask( title, opts ) {
		opts = opts || {};
		return new Promise( function ( resolve ) {
			var input = opts.input ? h( 'input', { type: 'text', class: 'regular-text', placeholder: opts.placeholder || '' } ) : null;
			var overlay = h( 'div', { class: 'gs-ps-modal-wrap' } );
			var done = function ( v ) {
				overlay.remove();
				resolve( v );
			};
			overlay.appendChild( h( 'div', { class: 'gs-ps-modal', role: 'dialog', 'aria-modal': 'true' },
				h( 'h3', null, title ),
				opts.body ? h( 'p', null, opts.body ) : null,
				input,
				h( 'div', { class: 'gs-ps-modal-actions' },
					h( 'button', { type: 'button', class: 'button', onclick: function () {
						done( null );
					} }, __( 'Cancel', 'gend-society' ) ),
					h( 'button', { type: 'button', class: 'button button-primary' + ( opts.danger ? ' is-danger' : '' ), onclick: function () {
						done( input ? input.value : true );
					} }, opts.ok || __( 'OK', 'gend-society' ) )
				)
			) );
			document.body.appendChild( overlay );
			if ( input ) {
				input.focus();
			}
		} );
	}
	function edit( url ) {
		window.location.href = url;
	}

	// Assign dialog: site default + member types.
	function assign( t ) {
		var types = Object.keys( state.memberTypes );
		var siteDefault = h( 'input', { type: 'checkbox', checked: t.siteDefault } );
		var boxes = types.map( function ( slug ) {
			return h( 'label', { class: 'gs-ps-check' }, h( 'input', { type: 'checkbox', value: slug, checked: t.types.indexOf( slug ) !== -1 } ), ' ' + state.memberTypes[ slug ] );
		} );
		var note = h( 'span', { class: 'gs-ps-msg' } );
		var overlay = h( 'div', { class: 'gs-ps-modal-wrap' } );
		var close = function () {
			overlay.remove();
		};
		overlay.appendChild( h( 'div', { class: 'gs-ps-modal', role: 'dialog', 'aria-modal': 'true' },
			h( 'h3', null, sprintf( __( 'Assign “%s”', 'gend-society' ), t.title ) ),
			h( 'h4', null, __( 'Site default', 'gend-society' ) ),
			h( 'label', { class: 'gs-ps-check' }, siteDefault, ' ' + ( t.tab ? __( 'Use for this tab on every profile without a member-type layout', 'gend-society' ) : __( 'Use on every profile without a member-type header', 'gend-society' ) ) ),
			h( 'h4', null, __( 'Member type defaults', 'gend-society' ) ),
			types.length ? h( 'div', { class: 'gs-ps-checks' }, boxes ) : h( 'p', { class: 'description' }, __( 'No member types are registered yet.', 'gend-society' ) ),
			h( 'div', { class: 'gs-ps-modal-actions' },
				note,
				h( 'button', { type: 'button', class: 'button', onclick: close }, __( 'Cancel', 'gend-society' ) ),
				h( 'button', { type: 'button', class: 'button button-primary', onclick: function () {
					var picked = boxes.map( function ( b ) {
						return b.querySelector( 'input' );
					} ).filter( function ( i ) {
						return i.checked;
					} ).map( function ( i ) {
						return i.value;
					} );
					api( { path: base + '/assign', method: 'POST', data: { id: t.id, siteDefault: siteDefault.checked, types: picked } } ).then( function () {
						close();
						load();
					}, function ( e ) {
						msg( note, err( e ), true );
					} );
				} }, __( 'Save assignment', 'gend-society' ) )
			)
		) );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );
		document.body.appendChild( overlay );
	}

	function headersSection() {
		var note = h( 'span', { class: 'gs-ps-msg' } );
		var create = function ( from ) {
			return function () {
				ask( __( 'New header template', 'gend-society' ), { input: true, placeholder: __( 'e.g. Consultant header', 'gend-society' ), ok: __( 'Create and edit', 'gend-society' ) } ).then( function ( title ) {
					if ( title === null ) {
						return;
					}
					api( { path: base + '/header', method: 'POST', data: { from: from, title: title } } ).then( function ( r ) {
						edit( 'post.php?post=' + r.id + '&action=edit' );
					}, function ( e ) {
						msg( note, err( e ), true );
					} );
				} );
			};
		};
		var rows = state.headers.map( function ( t ) {
			return h( 'tr', null,
				h( 'td', null, h( 'strong', null, t.title ) ),
				h( 'td', null, assignedText( t ) ),
				h( 'td', { class: 'gs-ps-actions' },
					h( 'button', { type: 'button', class: 'button', onclick: function () {
						edit( t.editUrl );
					} }, __( 'Edit', 'gend-society' ) ),
					h( 'button', { type: 'button', class: 'button', onclick: function () {
						assign( t );
					} }, __( 'Assign', 'gend-society' ) ),
					t.siteDefault ? null : h( 'button', { type: 'button', class: 'button button-link-delete', onclick: function () {
						ask( sprintf( __( 'Delete “%s”?', 'gend-society' ), t.title ), { ok: __( 'Delete', 'gend-society' ), danger: true } ).then( function ( yes ) {
							if ( yes ) {
								api( { path: base + '/delete', method: 'POST', data: { id: t.id } } ).then( load );
							}
						} );
					} }, __( 'Delete', 'gend-society' ) )
				)
			);
		} );
		return h( 'section', { id: 'gs-ps-headers' },
			h( 'h3', null, __( 'Header Templates', 'gend-society' ) ),
			h( 'p', { class: 'description' }, __( 'The site default shows on every profile; a member-type default takes over for members of that type. “Edit” opens the block editor: keep the GenD Profile Header block for today’s design, or build your own from the Profile blocks (cover, avatar, name, member type, actions, menu).', 'gend-society' ) ),
			h( 'table', { class: 'widefat striped' },
				h( 'thead', null, h( 'tr', null, h( 'th', null, __( 'Title', 'gend-society' ) ), h( 'th', null, __( 'Assigned to', 'gend-society' ) ), h( 'th', null, '' ) ) ),
				h( 'tbody', null, rows )
			),
			h( 'p', { class: 'gs-ps-row' },
				h( 'button', { type: 'button', class: 'button button-primary', onclick: create( 'blocks' ) }, __( 'New header from blocks', 'gend-society' ) ),
				h( 'button', { type: 'button', class: 'button', onclick: create( 'classic' ) }, __( 'New header from the current design', 'gend-society' ) ),
				note
			)
		);
	}

	function navSection() {
		var nav = state.nav || {};
		var slugs = Object.keys( state.seenTabs );
		Object.keys( nav ).forEach( function ( s ) {
			if ( slugs.indexOf( s ) === -1 ) {
				slugs.push( s );
			}
		} );
		slugs.sort( function ( a, b ) {
			var oa = nav[ a ] && nav[ a ].order ? nav[ a ].order : 1000 + Object.keys( state.seenTabs ).indexOf( a );
			var ob = nav[ b ] && nav[ b ].order ? nav[ b ].order : 1000 + Object.keys( state.seenTabs ).indexOf( b );
			return oa - ob;
		} );
		var note = h( 'span', { class: 'gs-ps-msg' } );
		var tbody = h( 'tbody' );
		var draw = function () {
			tbody.textContent = '';
			slugs.forEach( function ( slug, i ) {
				var c = nav[ slug ] || {};
				var layouts = state.tabs.filter( function ( t ) {
					return t.tab === slug;
				} );
				var label = h( 'input', { type: 'text', value: c.label || '', placeholder: state.seenTabs[ slug ] || slug, 'data-slug': slug } );
				var hidden = h( 'input', { type: 'checkbox', checked: !! c.hidden, 'data-slug': slug } );
				label.addEventListener( 'input', function () {
					nav[ slug ] = Object.assign( {}, nav[ slug ], { label: label.value } );
				} );
				hidden.addEventListener( 'change', function () {
					nav[ slug ] = Object.assign( {}, nav[ slug ], { hidden: hidden.checked } );
				} );
				var move = function ( d ) {
					return function () {
						var j = i + d;
						if ( j < 0 || j >= slugs.length ) {
							return;
						}
						var tmp = slugs[ i ];
						slugs[ i ] = slugs[ j ];
						slugs[ j ] = tmp;
						draw();
					};
				};
				var layoutCell = h( 'td', { class: 'gs-ps-layouts' } );
				layouts.forEach( function ( t ) {
					layoutCell.appendChild( h( 'div', { class: 'gs-ps-layout' },
						h( 'span', null, assignedText( t ) ),
						h( 'button', { type: 'button', class: 'button', onclick: function () {
							edit( t.editUrl );
						} }, __( 'Edit', 'gend-society' ) ),
						h( 'button', { type: 'button', class: 'button', onclick: function () {
							assign( t );
						} }, __( 'Assign', 'gend-society' ) ),
						h( 'button', { type: 'button', class: 'button button-link-delete', title: __( 'The tab goes back to its normal content', 'gend-society' ), onclick: function () {
							ask( __( 'Remove this layout?', 'gend-society' ), { body: __( 'The tab goes back to its normal content.', 'gend-society' ), ok: __( 'Remove', 'gend-society' ), danger: true } ).then( function ( yes ) {
								if ( yes ) {
									api( { path: base + '/delete', method: 'POST', data: { id: t.id } } ).then( load );
								}
							} );
						} }, __( 'Remove', 'gend-society' ) )
					) );
				} );
				var hasDefault = layouts.some( function ( t ) {
					return t.siteDefault;
				} );
				layoutCell.appendChild( h( 'button', { type: 'button', class: 'button' + ( layouts.length ? '' : ' button-primary' ), onclick: function () {
					api( { path: base + '/tab', method: 'POST', data: { slug: slug, variant: hasDefault } } ).then( function ( r ) {
						edit( 'post.php?post=' + r.id + '&action=edit' );
					}, function ( e ) {
						msg( note, err( e ), true );
					} );
				} }, hasDefault ? __( 'Add member-type layout', 'gend-society' ) : __( 'Design this tab', 'gend-society' ) ) );

				tbody.appendChild( h( 'tr', null,
					h( 'td', null, h( 'code', null, slug ) ),
					h( 'td', null, label ),
					h( 'td', { class: 'gs-ps-order' }, h( 'button', { type: 'button', class: 'button', 'aria-label': __( 'Move up', 'gend-society' ), onclick: move( -1 ) }, '↑' ), h( 'button', { type: 'button', class: 'button', 'aria-label': __( 'Move down', 'gend-society' ), onclick: move( 1 ) }, '↓' ) ),
					h( 'td', null, hidden ),
					layoutCell
				) );
			} );
			if ( ! slugs.length ) {
				tbody.appendChild( h( 'tr', null, h( 'td', { colspan: '5' }, h( 'em', null, __( 'Open any member profile once and its tabs will be listed here.', 'gend-society' ) ) ) ) );
			}
		};
		draw();
		return h( 'section', { id: 'gs-ps-nav' },
			h( 'h3', null, __( 'Pages & Menu', 'gend-society' ) ),
			h( 'p', { class: 'description' }, __( 'One row per profile tab. Rename, reorder or hide tabs in the profile menu. “Design this tab” wraps the tab’s content in a block layout you control (keep the Profile Tab Content block where the tab’s own content should go); a member-type layout replaces it for members of those types.', 'gend-society' ) ),
			h( 'table', { class: 'widefat striped' },
				h( 'thead', null, h( 'tr', null, h( 'th', null, __( 'Tab', 'gend-society' ) ), h( 'th', null, __( 'Label', 'gend-society' ) ), h( 'th', null, __( 'Order', 'gend-society' ) ), h( 'th', null, __( 'Hidden', 'gend-society' ) ), h( 'th', null, __( 'Layout', 'gend-society' ) ) ) ),
				tbody
			),
			h( 'p', { class: 'gs-ps-row' },
				h( 'button', { type: 'button', class: 'button button-primary', onclick: function () {
					var out = {};
					slugs.forEach( function ( slug, i ) {
						var c = nav[ slug ] || {};
						out[ slug ] = { label: c.label || '', hidden: !! c.hidden, order: i + 1 };
					} );
					api( { path: base + '/nav', method: 'POST', data: { nav: out } } ).then( function () {
						msg( note, __( 'Menu saved.', 'gend-society' ) );
					}, function ( e ) {
						msg( note, err( e ), true );
					} );
				} }, __( 'Save menu', 'gend-society' ) ),
				note
			)
		);
	}

	function render() {
		root.textContent = '';
		root.appendChild( headersSection() );
		root.appendChild( navSection() );
		if ( location.hash ) {
			var target = document.getElementById( location.hash.slice( 1 ) );
			if ( target ) {
				target.scrollIntoView();
			}
		}
	}

	load();
}() );
