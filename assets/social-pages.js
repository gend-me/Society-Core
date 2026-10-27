/* GenD Society - Design Studio › Social › Group Pages / Profile Pages (inc/social-pages.php).
 * One page per scope: header, menu with pages by audience, sidebars, directory.
 * Every "Edit" opens the block editor in a drawer that slides open in place. */
( function () {
	'use strict';

	window.gsStudioPanels = window.gsStudioPanels || {};
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	window.gsStudioPanels[ 'social-pages' ] = function ( hit, kit ) {
		var h = kit.h;
		var icon = kit.icon;
		var api = kit.api;
		var toast = kit.toast;
		var errMsg = kit.errMsg;
		var scope = hit.item.scope === 'profiles' ? 'profiles' : 'groups';
		var isGroups = scope === 'groups';
		var base = '/gend-society/v1/social-pages/' + scope;
		var D = null; // Builder state from the server.
		var openTab = null; // Expanded menu tab slug.
		var drawer = null; // { el, key, close }

		var post = function ( action, data ) {
			return api( { path: base + '/' + action, method: 'POST', data: data || {} } );
		};
		var reload = function () {
			return api( { path: base } ).then( function ( r ) {
				D = r;
				draw();
			} );
		};

		/* ── Slide-down editor ──────────────────────────────────────────── */
		// One drawer at a time; it opens under the card that asked for it.
		function openDrawer( anchor, key, label, url, onClose ) {
			if ( drawer && drawer.key === key ) {
				drawer.close();
				return;
			}
			if ( drawer ) {
				drawer.close( true );
			}
			var frame = h( 'iframe', { class: 'gs-sp-frame', src: url, title: label, allow: 'clipboard-read; clipboard-write' } );
			var loader = h( 'div', { class: 'gs-sp-frame-loader' }, h( 'span', { class: 'gs-st-spinner' } ), __( 'Opening the editor…', 'gend-society' ) );
			frame.addEventListener( 'load', function () {
				loader.classList.add( 'is-done' );
			} );
			var el = h( 'div', { class: 'gs-sp-drawer', role: 'region', 'aria-label': label } );
			var close = function ( quick ) {
				if ( ! drawer || drawer.el !== el ) {
					return;
				}
				drawer = null;
				anchor.classList.remove( 'is-editing' );
				el.classList.remove( 'is-open' );
				var done = function () {
					el.remove();
				};
				if ( quick ) {
					done();
				} else {
					setTimeout( done, 450 );
				}
				if ( onClose ) {
					onClose();
				}
			};
			el.appendChild( h( 'div', { class: 'gs-sp-drawer-bar' },
				h( 'span', { class: 'gs-sp-drawer-title' }, icon( 'edit' ), label ),
				h( 'span', { class: 'gs-st-muted' }, __( 'Changes save with the editor’s Save button.', 'gend-society' ) ),
				h( 'a', { class: 'gs-st-btn is-ghost is-sm', href: url.replace( /([?&])gs_studio_embed=1&?/, '$1' ).replace( /[?&]$/, '' ), target: '_blank', rel: 'noopener' }, icon( 'external' ), __( 'Full screen', 'gend-society' ) ),
				h( 'button', { type: 'button', class: 'gs-st-btn is-sm', onclick: function () {
					close();
				} }, icon( 'arrow-up-alt2' ), __( 'Close', 'gend-society' ) )
			) );
			el.appendChild( h( 'div', { class: 'gs-sp-drawer-body' }, loader, frame ) );
			anchor.classList.add( 'is-editing' );
			anchor.parentNode.insertBefore( el, anchor.nextSibling );
			drawer = { el: el, key: key, close: close };
			requestAnimationFrame( function () {
				requestAnimationFrame( function () {
					el.classList.add( 'is-open' );
					setTimeout( function () {
						anchor.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					}, 120 );
				} );
			} );
		}

		/* ── Drag to reorder ────────────────────────────────────────────── */
		// Rows carry data-key; dragging by the handle, or Alt+Up/Down on it.
		function sortable( list, onDone ) {
			var dragging = null;
			list.addEventListener( 'dragstart', function ( e ) {
				var row = e.target.closest && e.target.closest( '[data-key]' );
				if ( ! row || row.parentNode !== list ) {
					return;
				}
				dragging = row;
				row.classList.add( 'is-dragging' );
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData( 'text/plain', row.getAttribute( 'data-key' ) );
			} );
			list.addEventListener( 'dragover', function ( e ) {
				if ( ! dragging ) {
					return;
				}
				e.preventDefault();
				var over = e.target.closest && e.target.closest( '[data-key]' );
				if ( ! over || over === dragging || over.parentNode !== list ) {
					return;
				}
				var r = over.getBoundingClientRect();
				list.insertBefore( dragging, e.clientY > r.top + r.height / 2 ? over.nextSibling : over );
			} );
			list.addEventListener( 'dragend', function () {
				if ( ! dragging ) {
					return;
				}
				dragging.classList.remove( 'is-dragging' );
				dragging = null;
				onDone( keys() );
			} );
			list.addEventListener( 'keydown', function ( e ) {
				if ( ! e.altKey || ( e.key !== 'ArrowUp' && e.key !== 'ArrowDown' ) ) {
					return;
				}
				var row = e.target.closest && e.target.closest( '[data-key]' );
				if ( ! row || row.parentNode !== list ) {
					return;
				}
				e.preventDefault();
				var sib = e.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;
				while ( sib && ! sib.hasAttribute( 'data-key' ) ) {
					sib = e.key === 'ArrowUp' ? sib.previousElementSibling : sib.nextElementSibling;
				}
				if ( sib ) {
					list.insertBefore( row, e.key === 'ArrowUp' ? sib : sib.nextSibling );
					e.target.focus();
					onDone( keys() );
				}
			} );
			function keys() {
				return Array.prototype.map.call( list.querySelectorAll( ':scope > [data-key]' ), function ( r ) {
					return r.getAttribute( 'data-key' );
				} );
			}
		}
		function handle( label ) {
			return h( 'button', { type: 'button', class: 'gs-sp-handle', draggable: 'true', title: __( 'Drag to reorder (or Alt + arrow keys)', 'gend-society' ), 'aria-label': label }, icon( 'menu' ) );
		}
		// Only the handle starts a drag, so inputs in the row keep working.
		function dragRow( row ) {
			row.addEventListener( 'mousedown', function ( e ) {
				row.draggable = !! ( e.target.closest && e.target.closest( '.gs-sp-handle' ) );
			} );
			return row;
		}

		/* ── Audience ───────────────────────────────────────────────────── */
		function optLabel( group, key ) {
			var o = D.options[ group ] || {};
			return o[ key ] || key;
		}
		function audienceText( a ) {
			if ( ! a ) {
				return __( 'Everyone', 'gend-society' );
			}
			var parts = [];
			if ( a.who === 'out' ) {
				return __( 'Logged-out visitors', 'gend-society' );
			}
			if ( a.who === 'in' ) {
				parts.push( __( 'Signed-in members', 'gend-society' ) );
			}
			( a.roles || [] ).forEach( function ( r ) {
				parts.push( optLabel( 'roles', r ) );
			} );
			( a.memberTypes || [] ).forEach( function ( t ) {
				parts.push( sprintf( __( 'Viewer is %s', 'gend-society' ), optLabel( 'memberTypes', t ) ) );
			} );
			( a.profileTypes || [] ).forEach( function ( t ) {
				parts.push( sprintf( __( 'Profile is %s', 'gend-society' ), optLabel( 'memberTypes', t ) ) );
			} );
			( a.groupTypes || [] ).forEach( function ( t ) {
				parts.push( sprintf( __( '%s groups', 'gend-society' ), optLabel( 'groupTypes', t ) ) );
			} );
			( a.features || [] ).forEach( function ( f ) {
				parts.push( sprintf( __( 'Has %s', 'gend-society' ), optLabel( 'features', f ) ) );
			} );
			return parts.length ? parts.join( ' · ' ) : __( 'Everyone', 'gend-society' );
		}
		// Inline editor for who sees a page; calls onChange with the new rule.
		function audienceEditor( a, onChange ) {
			a = JSON.parse( JSON.stringify( a || { who: 'all', roles: [], memberTypes: [], profileTypes: [], groupTypes: [], features: [] } ) );
			[ 'roles', 'memberTypes', 'profileTypes', 'groupTypes', 'features' ].forEach( function ( k ) {
				a[ k ] = a[ k ] || [];
			} );
			var box = h( 'div', { class: 'gs-sp-aud' } );
			var save = function () {
				onChange( a );
				paint();
			};
			var pills = function ( title, key, options, hint ) {
				var keys = Object.keys( options || {} );
				if ( ! keys.length ) {
					return null;
				}
				return h( 'div', { class: 'gs-sp-aud-group' }, h( 'span', { class: 'gs-sp-aud-label' }, title, hint ? h( 'small', null, hint ) : null ),
					h( 'div', { class: 'gs-sp-pills' }, keys.map( function ( k ) {
						var on = a[ key ].indexOf( k ) !== -1;
						return h( 'button', { type: 'button', class: 'gs-sp-pill' + ( on ? ' is-on' : '' ), 'aria-pressed': on ? 'true' : 'false', onclick: function () {
							var i = a[ key ].indexOf( k );
							if ( i === -1 ) {
								a[ key ].push( k );
							} else {
								a[ key ].splice( i, 1 );
							}
							if ( a[ key ].length && a.who === 'out' ) {
								a.who = 'in';
							}
							save();
						} }, on ? icon( 'yes' ) : null, options[ k ] );
					} ) ) );
			};
			var paint = function () {
				box.textContent = '';
				var who = function ( v, label ) {
					return h( 'button', { type: 'button', class: 'gs-st-seg' + ( a.who === v ? ' is-on' : '' ), 'aria-pressed': a.who === v ? 'true' : 'false', onclick: function () {
						a.who = v;
						if ( v === 'out' ) {
							[ 'roles', 'memberTypes' ].forEach( function ( k ) {
								a[ k ] = [];
							} );
						}
						save();
					} }, label );
				};
				box.appendChild( h( 'div', { class: 'gs-sp-aud-group' }, h( 'span', { class: 'gs-sp-aud-label' }, __( 'Who sees this page', 'gend-society' ) ),
					h( 'div', { class: 'gs-st-segs' }, who( 'all', __( 'Everyone', 'gend-society' ) ), who( 'out', __( 'Logged-out visitors', 'gend-society' ) ), who( 'in', __( 'Signed-in members', 'gend-society' ) ) ) ) );
				if ( a.who !== 'out' ) {
					append( pills( isGroups ? __( 'Group role', 'gend-society' ) : __( 'Viewer', 'gend-society' ), 'roles', D.options.roles, __( 'any of', 'gend-society' ) ) );
					append( pills( __( 'Viewer’s member type', 'gend-society' ), 'memberTypes', D.options.memberTypes, __( 'any of', 'gend-society' ) ) );
				}
				if ( ! isGroups ) {
					append( pills( __( 'Profile’s member type', 'gend-society' ), 'profileTypes', D.options.memberTypes, __( 'any of', 'gend-society' ) ) );
				} else {
					append( pills( __( 'Group type', 'gend-society' ), 'groupTypes', D.options.groupTypes, __( 'any of', 'gend-society' ) ) );
					append( pills( __( 'Feature access (group’s plan)', 'gend-society' ), 'features', D.options.features, __( 'all of', 'gend-society' ) ) );
				}
				box.appendChild( h( 'p', { class: 'gs-st-muted gs-sp-aud-note' }, __( 'Pages are checked from the top: the first one that fits the viewer is shown, then the tab’s default page.', 'gend-society' ) ) );
			};
			var append = function ( el ) {
				if ( el ) {
					box.appendChild( el );
				}
			};
			paint();
			return box;
		}

		/* ── Layout picker ──────────────────────────────────────────────── */
		function layoutPicker( cur, onChange ) {
			var wrap = h( 'div', { class: 'gs-sp-layout' } );
			var paint = function () {
				wrap.textContent = '';
				var opt = function ( v, label, ic ) {
					var on = ( cur.layout || '' ) === v;
					return h( 'button', { type: 'button', class: 'gs-sp-lay' + ( on ? ' is-on' : '' ), 'aria-pressed': on ? 'true' : 'false', title: label, onclick: function () {
						cur.layout = v;
						onChange( { layout: v, sidebar: cur.sidebar || 0 } );
						paint();
					} }, h( 'span', { class: 'gs-sp-lay-ico is-' + ( ic || v ) }, h( 'i' ), h( 'b' ) ), h( 'span', null, label ) );
				};
				wrap.appendChild( h( 'div', { class: 'gs-sp-lays' },
					opt( '', __( 'Default', 'gend-society' ), 'auto' ),
					opt( 'full', __( 'Full width', 'gend-society' ) ),
					opt( 'left', __( 'Left sidebar', 'gend-society' ) ),
					opt( 'right', __( 'Right sidebar', 'gend-society' ) ) ) );
				if ( cur.layout === 'left' || cur.layout === 'right' ) {
					var sel = h( 'select', { class: 'gs-st-input gs-sp-sbsel', 'aria-label': __( 'Sidebar', 'gend-society' ), onchange: function ( e ) {
						cur.sidebar = parseInt( e.target.value, 10 ) || 0;
						onChange( { layout: cur.layout, sidebar: cur.sidebar } );
					} } );
					D.sidebars.forEach( function ( s ) {
						var v = s.isDefault ? 0 : s.id;
						if ( s.status === 'none' ) {
							sel.appendChild( h( 'option', { value: '0', selected: ( cur.sidebar || 0 ) === 0 ? true : null }, __( 'Default sidebar (today’s widgets)', 'gend-society' ) ) );
							return;
						}
						var o = h( 'option', { value: String( v ) }, s.title + ( s.isDefault ? ' (' + __( 'default', 'gend-society' ) + ')' : '' ) + ( s.status !== 'publish' ? ' — ' + __( 'draft', 'gend-society' ) : '' ) );
						if ( ( cur.sidebar || 0 ) === v ) {
							o.selected = true;
						}
						sel.appendChild( o );
					} );
					wrap.appendChild( h( 'label', { class: 'gs-sp-sbpick' }, icon( 'columns' ), __( 'Sidebar', 'gend-society' ), sel ) );
				}
			};
			paint();
			return wrap;
		}

		/* ── Sections ───────────────────────────────────────────────────── */
		function section( n, title, blurb, body, actions ) {
			return h( 'section', { class: 'gs-sp-section' },
				h( 'header', { class: 'gs-sp-sec-head' },
					h( 'span', { class: 'gs-sp-sec-n' }, String( n ) ),
					h( 'div', null, h( 'h2', null, title ), h( 'p', { class: 'gs-st-muted' }, blurb ) ),
					actions ? h( 'div', { class: 'gs-sp-sec-actions' }, actions ) : null ),
				body );
		}

		function headerSection() {
			var list = h( 'div', { class: 'gs-sp-cards' } );
			D.headers.forEach( function ( hd ) {
				var card = h( 'div', { class: 'gs-sp-card' + ( hd.siteDefault ? ' is-default' : '' ) },
					h( 'div', { class: 'gs-sp-card-main' },
						h( 'span', { class: 'gs-sp-card-ico' }, icon( 'cover-image' ) ),
						h( 'div', null, h( 'strong', null, hd.title ),
							h( 'div', { class: 'gs-sp-tags' },
								hd.siteDefault ? h( 'span', { class: 'gs-st-badge is-custom' }, __( 'Site default', 'gend-society' ) ) : null,
								( hd.types || [] ).map( function ( t ) {
									return h( 'span', { class: 'gs-st-badge' }, sprintf( isGroups ? __( '%s groups', 'gend-society' ) : __( '%s members', 'gend-society' ), optLabel( isGroups ? 'groupTypes' : 'memberTypes', t ) ) );
								} ) ) ) ),
					h( 'div', { class: 'gs-sp-card-actions' },
						hd.siteDefault ? null : h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
							post( 'header-default', { id: hd.id } ).then( reload ).then( function () {
								toast( __( 'Default header changed.', 'gend-society' ), 'ok' );
							}, fail );
						} }, __( 'Make default', 'gend-society' ) ),
						h( 'button', { type: 'button', class: 'gs-st-btn is-primary is-sm', onclick: function () {
							openDrawer( card, 'header-' + hd.id, hd.title, hd.editUrl, reload );
						} }, icon( 'edit' ), __( 'Edit', 'gend-society' ) ) ) );
				list.appendChild( card );
			} );
			var add = h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
				kit.promptBox( __( 'New header', 'gend-society' ), __( 'It starts as a copy of today’s default header.', 'gend-society' ), __( 'Name', 'gend-society' ), __( 'e.g. Partner groups', 'gend-society' ) ).then( function ( title ) {
					if ( title ) {
						post( 'header', { title: title } ).then( reload, fail );
					}
				} );
			} }, icon( 'plus-alt2' ), __( 'New header', 'gend-society' ) );
			return section( 1, isGroups ? __( 'Group header', 'gend-society' ) : __( 'Profile header', 'gend-society' ),
				isGroups ? __( 'The banner, identity and menu at the top of every group.', 'gend-society' ) : __( 'The cover, avatar, name and menu at the top of every profile.', 'gend-society' ),
				h( 'div', null, list, photosPanel() ), add );
		}

		// Default avatar + cover (Youzify options), shown until a member or group uploads their own.
		function photosPanel() {
			if ( ! D.photos ) {
				return null;
			}
			var chip = h( 'span', { class: 'gs-sp-photos-chip' } );
			var paintChip = function () {
				var n = ( D.photos.avatar ? 1 : 0 ) + ( D.photos.cover ? 1 : 0 );
				chip.textContent = sprintf( __( '%d of 2 set', 'gend-society' ), n );
			};
			var row = function ( part, title, hint ) {
				var preview = h( 'div', { class: 'gs-sp-photo-prev is-' + part } );
				var input = h( 'input', { type: 'text', inputmode: 'url', class: 'gs-st-input', value: D.photos[ part ] || '',
					placeholder: __( 'Paste an image URL or choose one', 'gend-society' ), 'aria-label': title } );
				var clear = h( 'button', { type: 'button', class: 'gs-st-btn is-text is-sm' }, __( 'Use default', 'gend-society' ) );
				var paint = function ( url ) {
					preview.classList.toggle( 'is-empty', ! url );
					preview.style.setProperty( '--gs-img', url ? 'url("' + url.replace( /["\\]/g, '' ) + '")' : 'none' );
					preview.textContent = '';
					if ( ! url ) {
						preview.appendChild( icon( 'format-image' ) );
					}
					clear.hidden = ! url;
				};
				var save = function ( url ) {
					url = ( url || '' ).trim();
					if ( url === ( D.photos[ part ] || '' ) ) {
						return;
					}
					paint( url );
					post( 'photos', { part: part, url: url } ).then( function ( res ) {
						D.photos = res.photos || D.photos;
						input.value = D.photos[ part ] || '';
						paint( D.photos[ part ] );
						paintChip();
						toast( url ? __( 'Default photo saved.', 'gend-society' ) : __( 'Default photo cleared.', 'gend-society' ), 'ok' );
					}, function ( e ) {
						input.value = D.photos[ part ] || '';
						paint( D.photos[ part ] );
						fail( e );
					} );
				};
				input.addEventListener( 'change', function () {
					save( input.value );
				} );
				input.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Enter' ) {
						e.preventDefault();
						save( input.value );
					}
				} );
				clear.addEventListener( 'click', function () {
					input.value = '';
					save( '' );
				} );
				var pick = h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
					if ( ! window.wp || ! wp.media ) {
						input.focus();
						return;
					}
					var frame = wp.media( { title: title, library: { type: 'image' }, multiple: false, button: { text: __( 'Use this image', 'gend-society' ) } } );
					frame.on( 'select', function () {
						var a = frame.state().get( 'selection' ).first().toJSON();
						input.value = a.url;
						save( a.url );
					} );
					frame.open();
				} }, icon( 'upload' ), __( 'Choose image', 'gend-society' ) );
				paint( D.photos[ part ] );
				return h( 'div', { class: 'gs-sp-photo' }, preview,
					h( 'div', { class: 'gs-sp-photo-body' },
						h( 'strong', null, title ),
						h( 'p', { class: 'gs-st-muted' }, hint ),
						input,
						h( 'div', { class: 'gs-sp-photo-actions' }, pick, clear ) ) );
			};
			paintChip();
			return h( 'div', { class: 'gs-sp-photos' },
				h( 'div', { class: 'gs-sp-photos-head' },
					h( 'span', { class: 'gs-sp-card-ico' }, icon( 'format-image' ) ),
					h( 'div', null, h( 'strong', null, __( 'Default photos', 'gend-society' ) ),
						h( 'p', { class: 'gs-st-muted' }, isGroups ? __( 'Shown on groups until an organizer uploads their own.', 'gend-society' ) : __( 'Shown on profiles until a member uploads their own.', 'gend-society' ) ) ),
					chip ),
				h( 'div', { class: 'gs-sp-photos-grid' },
					row( 'avatar', isGroups ? __( 'Default group avatar', 'gend-society' ) : __( 'Default avatar', 'gend-society' ), __( 'A square image works best.', 'gend-society' ) ),
					row( 'cover', isGroups ? __( 'Default group cover', 'gend-society' ) : __( 'Default cover', 'gend-society' ), __( 'A wide landscape image works best.', 'gend-society' ) ) ) );
		}

		function pagesOf( slug ) {
			return ( D.pages && D.pages[ slug ] ) || [];
		}

		function menuSection() {
			var list = h( 'div', { class: 'gs-sp-menu' } );
			var saveMenu = kit.debounce( function () {
				post( 'menu', { menu: D.menu.map( function ( r ) {
					return { slug: r.slug, oldSlug: r.oldSlug || r.slug, label: r.label, hidden: r.hidden, custom: r.custom };
				} ) } ).then( function ( res ) {
					var renamed = D.menu.some( function ( r ) {
						return r.oldSlug && r.oldSlug !== r.slug;
					} );
					D.menu = res.menu;
					toast( __( 'Menu saved.', 'gend-society' ), 'ok' );
					if ( renamed ) {
						reload();
					}
				}, function ( e ) {
					fail( e );
					reload();
				} );
			}, 500 );
			D.menu.forEach( function ( r ) {
				list.appendChild( menuRow( r, saveMenu ) );
			} );
			sortable( list, function ( order ) {
				D.menu.sort( function ( a, b ) {
					return order.indexOf( a.slug ) - order.indexOf( b.slug );
				} );
				saveMenu();
			} );
			var add = isGroups ? h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
				kit.promptBox( __( 'New menu tab', 'gend-society' ), __( 'A new tab in every group’s menu, with its own page.', 'gend-society' ), __( 'Tab name', 'gend-society' ), __( 'e.g. Roadmap', 'gend-society' ) ).then( function ( label ) {
					if ( ! label ) {
						return;
					}
					var slug = label.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' ) || 'tab';
					D.menu.push( { slug: slug, label: label, defaultLabel: label, hidden: false, custom: true, hasPages: true } );
					post( 'menu', { menu: D.menu } ).then( function () {
						openTab = slug;
						return reload();
					} ).then( function () {
						toast( __( 'Tab added.', 'gend-society' ), 'ok' );
					}, function ( e ) {
						fail( e );
						reload();
					} );
				} );
			} }, icon( 'plus-alt2' ), __( 'Add a menu tab', 'gend-society' ) ) : null;
			return section( 2, __( 'Menu & pages', 'gend-society' ),
				__( 'Drag tabs into order, rename them and hide the ones you don’t need. Open a tab to give it different pages for different people, each with its own layout.', 'gend-society' ), list, add );
		}

		function menuRow( r, saveMenu ) {
			var pages = pagesOf( r.slug );
			var open = openTab === r.slug;
			var label = h( 'input', { type: 'text', class: 'gs-st-input gs-sp-label', value: r.label || '', placeholder: r.defaultLabel, 'aria-label': sprintf( __( 'Label for %s', 'gend-society' ), r.defaultLabel ), oninput: function ( e ) {
				r.label = e.target.value;
				saveMenu();
			} } );
			var slug = r.custom
				? h( 'span', { class: 'gs-sp-slug is-edit' }, '/', h( 'input', { type: 'text', value: r.slug, 'aria-label': __( 'Address (slug)', 'gend-society' ), onchange: function ( e ) {
					var v = e.target.value.toLowerCase().replace( /[^a-z0-9-]+/g, '-' ).replace( /^-|-$/g, '' );
					if ( ! v || v === r.slug ) {
						e.target.value = r.slug;
						return;
					}
					r.oldSlug = r.oldSlug || r.slug;
					r.slug = v;
					e.target.value = v;
					saveMenu();
				} } ) )
				: h( 'span', { class: 'gs-sp-slug', title: __( 'Built-in tabs keep their address; add a tab to choose your own.', 'gend-society' ) }, icon( 'lock' ), '/' + r.slug );
			var eye = h( 'button', { type: 'button', class: 'gs-sp-eye' + ( r.hidden ? ' is-off' : '' ), disabled: r.locked ? true : null, 'aria-pressed': r.hidden ? 'false' : 'true', title: r.locked ? __( 'The landing tab is always shown.', 'gend-society' ) : ( r.hidden ? __( 'Hidden — click to show', 'gend-society' ) : __( 'Shown — click to hide', 'gend-society' ) ), onclick: function () {
				r.hidden = ! r.hidden;
				eye.classList.toggle( 'is-off', r.hidden );
				eye.firstChild.className = 'dashicons dashicons-' + ( r.hidden ? 'hidden' : 'visibility' );
				row.classList.toggle( 'is-hidden', r.hidden );
				saveMenu();
			} }, icon( r.hidden ? 'hidden' : 'visibility' ) );
			var count = r.hasPages ? h( 'span', { class: 'gs-sp-count' }, sprintf( pages.length === 1 ? __( '%d page', 'gend-society' ) : __( '%d pages', 'gend-society' ), pages.length ) ) : h( 'span', { class: 'gs-sp-count is-app' }, __( 'App page', 'gend-society' ) );
			var toggle = h( 'button', { type: 'button', class: 'gs-sp-open', 'aria-expanded': open ? 'true' : 'false', 'aria-label': sprintf( __( 'Pages of %s', 'gend-society' ), r.label || r.defaultLabel ), onclick: function () {
				openTab = openTab === r.slug ? null : r.slug;
				var now = openTab === r.slug;
				toggle.setAttribute( 'aria-expanded', now ? 'true' : 'false' );
				row.classList.toggle( 'is-open', now );
				Array.prototype.forEach.call( row.parentNode.querySelectorAll( '.gs-sp-tab.is-open' ), function ( o ) {
					if ( o !== row ) {
						o.classList.remove( 'is-open' );
						o.querySelector( '.gs-sp-open' ).setAttribute( 'aria-expanded', 'false' );
					}
				} );
				if ( now && ! body.firstChild ) {
					fillTab( body, r );
				}
			} }, icon( 'arrow-down-alt2' ) );
			var head = h( 'div', { class: 'gs-sp-tab-head' }, handle( sprintf( __( 'Move %s', 'gend-society' ), r.label || r.defaultLabel ) ), label, slug, count, eye, toggle );
			head.addEventListener( 'click', function ( e ) {
				if ( e.target === head || e.target === count ) {
					toggle.click();
				}
			} );
			var body = h( 'div', { class: 'gs-sp-tab-body' } );
			var row = dragRow( h( 'div', { class: 'gs-sp-tab' + ( open ? ' is-open' : '' ) + ( r.hidden ? ' is-hidden' : '' ), 'data-key': r.slug }, head, h( 'div', { class: 'gs-sp-tab-wrap' }, body ) ) );
			if ( open ) {
				fillTab( body, r );
			}
			return row;
		}

		function previewUrl( slug, id ) {
			if ( ! D.sampleUrl ) {
				return '';
			}
			var u = D.sampleUrl.replace( /\/?$/, '/' ) + slug + '/';
			return id ? u + '?gs_sp_preview=' + id : u;
		}

		function fillTab( body, r ) {
			body.textContent = '';
			if ( ! r.hasPages ) {
				var tl = ( D.tabLayouts && D.tabLayouts[ r.slug ] ) || { layout: '', sidebar: 0 };
				body.appendChild( h( 'div', { class: 'gs-sp-page is-app' },
					h( 'p', { class: 'gs-st-muted' }, r.slug === 'activity'
						? __( 'This tab shows the group feed. Design it under Social › Activity Feed; here you choose its layout.', 'gend-society' )
						: __( 'An app fills this tab, so it has no block page. You can still choose its layout.', 'gend-society' ) ),
					layoutPicker( tl, function ( l ) {
						post( 'tab-layout', Object.assign( { slug: r.slug }, l ) ).then( function () {
							D.tabLayouts[ r.slug ] = l;
							toast( __( 'Layout saved.', 'gend-society' ), 'ok' );
						}, fail );
					} ) ) );
				return;
			}
			var pages = pagesOf( r.slug ).slice();
			var def = null;
			var audience = [];
			pages.forEach( function ( p ) {
				if ( p.isDefault && ! def ) {
					def = p;
				} else {
					audience.push( p );
				}
			} );
			var list = h( 'div', { class: 'gs-sp-pages' } );
			audience.forEach( function ( p ) {
				list.appendChild( pageCard( p, r ) );
			} );
			sortable( list, function ( ids ) {
				post( 'page-order', { ids: ids.map( Number ) } ).then( function () {
					toast( __( 'Order saved.', 'gend-society' ), 'ok' );
				}, fail );
			} );
			body.appendChild( list );
			if ( def ) {
				body.appendChild( pageCard( def, r ) );
			} else {
				body.appendChild( h( 'div', { class: 'gs-sp-page is-empty' }, h( 'p', { class: 'gs-st-muted' }, __( 'No default page yet: everyone sees the tab’s built-in content.', 'gend-society' ) ),
					h( 'button', { type: 'button', class: 'gs-st-btn is-sm', onclick: function () {
						post( 'page', { slug: r.slug, asDefault: true, title: sprintf( __( 'Default — %s', 'gend-society' ), r.label || r.defaultLabel ) } ).then( reload, fail );
					} }, icon( 'plus-alt2' ), __( 'Create the default page', 'gend-society' ) ) ) );
			}
			body.appendChild( addPageBar( r ) );
		}

		function addPageBar( r ) {
			var presets = [
				[ __( 'Logged-out visitors', 'gend-society' ), { who: 'out' } ],
				[ __( 'Signed-in members', 'gend-society' ), { who: 'in' } ],
			];
			Object.keys( D.options.roles || {} ).forEach( function ( k ) {
				presets.push( [ D.options.roles[ k ], { who: 'in', roles: [ k ] } ] );
			} );
			presets.push( [ __( 'Custom…', 'gend-society' ), null ] );
			return h( 'div', { class: 'gs-sp-addpage' }, h( 'span', { class: 'gs-st-muted' }, icon( 'plus-alt2' ), __( 'Add a page for', 'gend-society' ) ),
				presets.map( function ( pr ) {
					return h( 'button', { type: 'button', class: 'gs-sp-pill', onclick: function () {
						var aud = pr[ 1 ] || { who: 'all' };
						var title = sprintf( __( '%1$s — %2$s', 'gend-society' ), r.label || r.defaultLabel, pr[ 1 ] ? pr[ 0 ] : __( 'custom audience', 'gend-society' ) );
						post( 'page', { slug: r.slug, title: title, audience: aud } ).then( function ( p ) {
							D.pages[ r.slug ] = pagesOf( r.slug ).concat( [ p ] );
							var b = document.querySelector( '.gs-sp-tab[data-key="' + r.slug + '"] .gs-sp-tab-body' );
							if ( b ) {
								fillTab( b, r );
								var card = b.querySelector( '[data-key="' + p.id + '"]' );
								if ( card ) {
									card.classList.add( 'is-new' );
									if ( ! pr[ 1 ] ) {
										card.querySelector( '.gs-sp-who' ).click();
									}
								}
							}
							updateCount( r );
							toast( __( 'Page added. It starts as a copy of the default page.', 'gend-society' ), 'ok' );
						}, fail );
					} }, pr[ 0 ] );
				} ) );
		}

		function updateCount( r ) {
			var c = document.querySelector( '.gs-sp-tab[data-key="' + r.slug + '"] .gs-sp-count' );
			var n = pagesOf( r.slug ).length;
			if ( c ) {
				c.textContent = sprintf( n === 1 ? __( '%d page', 'gend-society' ) : __( '%d pages', 'gend-society' ), n );
			}
		}

		function pageCard( p, r ) {
			var update = function ( data, msg ) {
				return post( 'page-update', Object.assign( { id: p.id }, data ) ).then( function ( np ) {
					Object.assign( p, np );
					if ( msg ) {
						toast( msg, 'ok' );
					}
					return np;
				}, fail );
			};
			var title = h( 'input', { type: 'text', class: 'gs-sp-title', value: p.title, 'aria-label': __( 'Page name', 'gend-society' ), onchange: function ( e ) {
				if ( e.target.value.trim() ) {
					update( { title: e.target.value.trim() }, __( 'Renamed.', 'gend-society' ) );
				}
			} } );
			var whoText = h( 'span', null, p.isDefault ? __( 'Everyone else (default page)', 'gend-society' ) : audienceText( p.audience ) );
			var audBox = h( 'div', { class: 'gs-sp-aud-wrap' } );
			var who = p.isDefault ? h( 'span', { class: 'gs-sp-who is-static' }, icon( 'groups' ), whoText ) : h( 'button', { type: 'button', class: 'gs-sp-who', 'aria-expanded': 'false', onclick: function () {
				var now = ! card.classList.contains( 'is-aud' );
				card.classList.toggle( 'is-aud', now );
				who.setAttribute( 'aria-expanded', now ? 'true' : 'false' );
				if ( now && ! audBox.firstChild ) {
					audBox.appendChild( audienceEditor( p.audience, kit.debounce( function ( a ) {
						p.audience = a;
						whoText.textContent = audienceText( a );
						update( { audience: a }, __( 'Audience saved.', 'gend-society' ) );
					}, 400 ) ) );
				}
			} }, icon( 'groups' ), whoText, icon( 'arrow-down-alt2' ) );
			var head = h( 'div', { class: 'gs-sp-page-head' },
				p.isDefault ? h( 'span', { class: 'gs-sp-pin', title: __( 'The default page is always checked last.', 'gend-society' ) }, icon( 'flag' ) ) : handle( __( 'Move this page', 'gend-society' ) ),
				h( 'div', { class: 'gs-sp-page-id' }, title, who ),
				layoutPicker( { layout: p.layout, sidebar: p.sidebar }, function ( l ) {
					update( l, __( 'Layout saved.', 'gend-society' ) );
				} ),
				h( 'div', { class: 'gs-sp-card-actions' },
					previewUrl( r.slug, p.id ) ? h( 'a', { class: 'gs-st-btn is-ghost is-sm', href: previewUrl( r.slug, p.id ), target: '_blank', rel: 'noopener', title: __( 'See this page on a real group (only you see this preview)', 'gend-society' ) }, icon( 'visibility' ), __( 'Preview', 'gend-society' ) ) : null,
					p.isDefault ? null : h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm is-danger', 'aria-label': __( 'Delete page', 'gend-society' ), onclick: function () {
						kit.confirmBox( __( 'Delete this page?', 'gend-society' ), sprintf( __( '“%s” goes to the trash. Its viewers see the next page that fits them.', 'gend-society' ), p.title ), [ { label: __( 'Cancel', 'gend-society' ), value: false }, { label: __( 'Delete', 'gend-society' ), value: true, primary: true } ] ).then( function ( ok ) {
							if ( ok ) {
								post( 'page-delete', { id: p.id } ).then( function () {
									D.pages[ r.slug ] = pagesOf( r.slug ).filter( function ( x ) {
										return x.id !== p.id;
									} );
									if ( drawer && drawer.key === 'page-' + p.id ) {
										drawer.close( true );
									}
									card.classList.add( 'is-leaving' );
									setTimeout( function () {
										card.remove();
									}, 300 );
									updateCount( r );
								}, fail );
							}
						} );
					} }, icon( 'trash' ) ),
					h( 'button', { type: 'button', class: 'gs-st-btn is-primary is-sm', onclick: function () {
						openDrawer( card, 'page-' + p.id, p.title, p.editUrl );
					} }, icon( 'edit' ), __( 'Edit', 'gend-society' ) ) ) );
			var card = h( 'div', { class: 'gs-sp-page' + ( p.isDefault ? ' is-default' : '' ), 'data-key': p.isDefault ? null : String( p.id ) }, head, h( 'div', { class: 'gs-sp-aud-slide' }, audBox ) );
			return p.isDefault ? card : dragRow( card );
		}

		function sidebarSection() {
			var list = h( 'div', { class: 'gs-sp-cards is-grid' } );
			D.sidebars.forEach( function ( s ) {
				var card = h( 'div', { class: 'gs-sp-card gs-sp-sbcard' + ( s.isDefault ? ' is-default' : '' ) },
					h( 'div', { class: 'gs-sp-card-main' },
						h( 'span', { class: 'gs-sp-card-ico' }, icon( 'align-pull-right' ) ),
						h( 'div', null,
							h( 'input', { type: 'text', class: 'gs-sp-title', value: s.title, readonly: s.id ? null : true, 'aria-label': __( 'Sidebar name', 'gend-society' ), onchange: function ( e ) {
								if ( e.target.value.trim() ) {
									post( 'sidebar-update', { id: s.id, title: e.target.value.trim() } ).then( function () {
										s.title = e.target.value.trim();
										toast( __( 'Renamed.', 'gend-society' ), 'ok' );
									}, fail );
								}
							} } ),
							h( 'div', { class: 'gs-sp-tags' },
								s.isDefault ? h( 'span', { class: 'gs-st-badge is-custom' }, __( 'Default sidebar', 'gend-society' ) ) : null,
								s.status === 'none' ? h( 'span', { class: 'gs-st-badge is-missing' }, __( 'Today’s widgets — not built yet', 'gend-society' ) ) : null,
								s.status === 'draft' ? h( 'span', { class: 'gs-st-badge is-missing' }, isGroups ? __( 'Draft — groups still show today’s widgets', 'gend-society' ) : __( 'Draft — profiles still show today’s widgets', 'gend-society' ) ) : null,
								h( 'span', { class: 'gs-st-badge' }, sprintf( __( 'Used by %d page(s)', 'gend-society' ), usage( s ) ) ) ) ) ),
					h( 'div', { class: 'gs-sp-card-actions' },
						s.status === 'draft' ? h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
							post( 'sidebar-update', { id: s.id, publish: true } ).then( reload, fail );
						} }, __( 'Publish', 'gend-society' ) ) : null,
						s.isDefault ? null : h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm is-danger', 'aria-label': __( 'Delete sidebar', 'gend-society' ), onclick: function () {
							kit.confirmBox( __( 'Delete this sidebar?', 'gend-society' ), __( 'Pages that use it show the default sidebar instead.', 'gend-society' ), [ { label: __( 'Cancel', 'gend-society' ), value: false }, { label: __( 'Delete', 'gend-society' ), value: true, primary: true } ] ).then( function ( ok ) {
								if ( ok ) {
									post( 'sidebar-delete', { id: s.id } ).then( reload, fail );
								}
							} );
						} }, icon( 'trash' ) ),
						h( 'button', { type: 'button', class: 'gs-st-btn is-primary is-sm', onclick: function ( e ) {
							if ( s.id ) {
								openDrawer( card, 'sidebar-' + s.id, s.title, s.editUrl, reload );
								return;
							}
							// The default sidebar is created (as a draft, with today's widgets) on first build.
							var btn = e.currentTarget;
							btn.disabled = true;
							post( 'sidebar-default' ).then( function ( res ) {
								btn.disabled = false;
								s.id = res.id;
								s.editUrl = res.editUrl;
								s.status = 'draft';
								openDrawer( card, 'sidebar-' + res.id, s.title, res.editUrl, reload );
							}, function ( er ) {
								btn.disabled = false;
								fail( er );
							} );
						} }, icon( 'edit' ), __( 'Build', 'gend-society' ) ) ) );
				list.appendChild( card );
			} );
			if ( ! D.sidebars.length ) {
				list.appendChild( h( 'p', { class: 'gs-st-muted' }, __( 'No sidebars yet. Create one, then pick it in a page’s layout.', 'gend-society' ) ) );
			}
			var add = h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
				kit.promptBox( __( 'New sidebar', 'gend-society' ), __( 'It starts with the default sidebar’s blocks. Drag blocks in and out in the editor.', 'gend-society' ), __( 'Name', 'gend-society' ), __( 'e.g. Visitor sidebar', 'gend-society' ) ).then( function ( title ) {
					if ( title ) {
						post( 'sidebar', { title: title } ).then( function ( res ) {
							return reload().then( function () {
								var card = document.querySelectorAll( '.gs-sp-sbcard' );
								var last = card[ card.length - 1 ];
								if ( last ) {
									openDrawer( last, 'sidebar-' + res.id, title, res.editUrl, reload );
								}
							} );
						}, fail );
					}
				} );
			} }, icon( 'plus-alt2' ), __( 'New sidebar', 'gend-society' ) );
			return section( 3, isGroups ? __( 'Group sidebars', 'gend-society' ) : __( 'Profile sidebars', 'gend-society' ),
				__( 'Build sidebars from blocks: drag widgets, text, buttons and app cards in the editor. Pages with a left or right layout pick one.', 'gend-society' ), list, add );
		}
		function usage( s ) {
			var n = 0;
			var v = s.isDefault ? 0 : s.id;
			Object.keys( D.pages || {} ).forEach( function ( k ) {
				D.pages[ k ].forEach( function ( p ) {
					if ( ( p.layout === 'left' || p.layout === 'right' ) && ( p.sidebar || 0 ) === v ) {
						n++;
					}
				} );
			} );
			Object.keys( D.tabLayouts || {} ).forEach( function ( k ) {
				var l = D.tabLayouts[ k ];
				if ( l && ( l.layout === 'left' || l.layout === 'right' ) && ( l.sidebar || 0 ) === v ) {
					n++;
				}
			} );
			return n;
		}

		function directorySection() {
			var card = h( 'div', { class: 'gs-sp-card gs-sp-dir' },
				h( 'div', { class: 'gs-sp-card-main' },
					h( 'span', { class: 'gs-sp-card-ico' }, icon( isGroups ? 'groups' : 'admin-users' ) ),
					h( 'div', null, h( 'strong', null, isGroups ? __( 'Group directory', 'gend-society' ) : __( 'Members directory', 'gend-society' ) ),
						h( 'div', { class: 'gs-sp-tags' }, h( 'span', { class: 'gs-st-badge ' + ( D.directory.published ? 'is-custom' : 'is-missing' ) }, D.directory.published ? __( 'Block layout live', 'gend-society' ) : __( 'Youzify layout (not designed yet)', 'gend-society' ) ) ) ) ),
				h( 'div', { class: 'gs-sp-card-actions' },
					D.directory.url ? h( 'a', { class: 'gs-st-btn is-ghost is-sm', href: D.directory.url, target: '_blank', rel: 'noopener' }, icon( 'external' ), __( 'View', 'gend-society' ) ) : null,
					h( 'button', { type: 'button', class: 'gs-st-btn is-primary is-sm', onclick: function ( e ) {
						var btn = e.currentTarget;
						btn.disabled = true;
						post( 'directory' ).then( function ( res ) {
							btn.disabled = false;
							openDrawer( card, 'directory', isGroups ? __( 'Group directory', 'gend-society' ) : __( 'Members directory', 'gend-society' ), res.editUrl, reload );
						}, function ( er ) {
							btn.disabled = false;
							fail( er );
						} );
					} }, icon( 'edit' ), D.directory.published ? __( 'Edit', 'gend-society' ) : __( 'Design', 'gend-society' ) ) ) );
			return section( 4, isGroups ? __( 'Group directory', 'gend-society' ) : __( 'Members directory', 'gend-society' ),
				isGroups ? __( 'The page that lists every group.', 'gend-society' ) : __( 'The page that lists every member.', 'gend-society' ),
				h( 'div', null, h( 'div', { class: 'gs-sp-cards' }, card ), statsPanel() ) );
		}

		// Card statistics switches (Youzify options); the per-stat switches dim while the master switch is off.
		function statsPanel() {
			if ( ! D.cardStats || ! D.cardStats.length ) {
				return null;
			}
			var grid = h( 'div', { class: 'gs-sp-stats-grid' } );
			var paint = function () {
				var master = D.cardStats[ 0 ].on;
				grid.querySelectorAll( '.gs-sp-stat' ).forEach( function ( el, i ) {
					el.classList.toggle( 'is-muted', i > 0 && ! master );
				} );
			};
			D.cardStats.forEach( function ( st, i ) {
				var id = 'gs-sp-stat-' + st.key;
				var input = h( 'input', { type: 'checkbox', role: 'switch', id: id, class: 'gs-sp-switch-input', checked: st.on ? true : null } );
				input.addEventListener( 'change', function () {
					var on = input.checked;
					input.disabled = true;
					post( 'card-stat', { key: st.key, on: on } ).then( function ( res ) {
						input.disabled = false;
						D.cardStats = res.cardStats || D.cardStats;
						D.cardStats.forEach( function ( r ) {
							if ( r.key === st.key ) {
								st.on = r.on;
							}
						} );
						input.checked = st.on;
						paint();
						toast( on ? sprintf( __( '%s shown on cards.', 'gend-society' ), st.title ) : sprintf( __( '%s hidden on cards.', 'gend-society' ), st.title ), 'ok' );
					}, function ( e ) {
						input.disabled = false;
						input.checked = st.on;
						fail( e );
					} );
				} );
				grid.appendChild( h( 'div', { class: 'gs-sp-stat' + ( i === 0 ? ' is-master' : '' ) },
					h( 'label', { for: id, class: 'gs-sp-stat-text' }, h( 'strong', null, st.title ), h( 'span', { class: 'gs-st-muted' }, st.desc ) ),
					h( 'span', { class: 'gs-sp-switch' }, input, h( 'label', { for: id, class: 'gs-sp-switch-track', 'aria-hidden': 'true' } ) ) ) );
			} );
			paint();
			return h( 'div', { class: 'gs-sp-photos gs-sp-stats' },
				h( 'div', { class: 'gs-sp-photos-head' },
					h( 'span', { class: 'gs-sp-card-ico' }, icon( 'chart-bar' ) ),
					h( 'div', null, h( 'strong', null, __( 'Card statistics', 'gend-society' ) ),
						h( 'p', { class: 'gs-st-muted' }, isGroups ? __( 'The numbers shown on each group’s card in the directory. Changes save straight away.', 'gend-society' ) : __( 'The numbers shown on each member’s card in the directory. Changes save straight away.', 'gend-society' ) ) ) ),
				grid );
		}

		function fail( e ) {
			toast( errMsg( e ), 'error' );
		}

		/* ── Draw ───────────────────────────────────────────────────────── */
		var content = h( 'div', { class: 'gs-sp-sections' }, kit.loadingView() );
		var wrap = h( 'div', { class: 'gs-st-pad gs-sp' }, kit.crumbs( hit ), h( 'h1', { class: 'gs-st-title' }, hit.item.label ),
			h( 'p', { class: 'gs-st-intro' }, isGroups
				? __( 'Everything group pages show, on one page: the header, the menu and the pages behind each tab (different ones for visitors, members, roles and plans), the sidebars, and the group directory.', 'gend-society' )
				: __( 'Everything profiles show, on one page: the header, the menu and the pages behind each tab (different ones for visitors, friends and member types), the sidebars, and the members directory.', 'gend-society' ) ),
			content );
		function draw() {
			var y = wrap.parentNode ? wrap.parentNode.scrollTop : 0;
			if ( drawer ) {
				drawer.close( true );
			}
			content.textContent = '';
			content.appendChild( headerSection() );
			content.appendChild( menuSection() );
			content.appendChild( sidebarSection() );
			content.appendChild( directorySection() );
			if ( wrap.parentNode ) {
				wrap.parentNode.scrollTop = y;
			}
		}
		reload().catch( function ( e ) {
			content.textContent = errMsg( e );
		} );
		return wrap;
	};
}() );
