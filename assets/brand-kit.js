/* GenD Society - Design Studio › Branding › Voice & Tone, App Styling, Cast Members (inc/brand-kit.php).
 * Each screen edits one part of the brand kit beside the live Brand Guidelines,
 * which reloads at that chapter after a save. */
( function () {
	'use strict';

	window.gsStudioPanels = window.gsStudioPanels || {};
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var cache = null; // { kit, choices } shared by the three screens.

	function load( kit ) {
		return cache ? Promise.resolve( cache ) : kit.api( { path: '/gend-society/v1/brand-kit' } ).then( function ( r ) {
			cache = r;
			return r;
		} );
	}

	function clone( o ) {
		return JSON.parse( JSON.stringify( o ) );
	}

	// Shared building blocks for the three screens.
	function tools( kit ) {
		var h = kit.h;
		var t = {};
		t.field = function ( label, hint, control ) {
			return h( 'label', { class: 'gs-st-field gs-bk-field' }, h( 'span', null, label ), hint ? h( 'small', { class: 'gs-bk-hint' }, hint ) : null, control );
		};
		t.text = function ( value, onInput, placeholder, rows ) {
			var el = h( rows ? 'textarea' : 'input', rows ? { class: 'gs-st-input', rows: rows, placeholder: placeholder || '' } : { type: 'text', class: 'gs-st-input', placeholder: placeholder || '' } );
			el.value = value || '';
			el.addEventListener( 'input', function () {
				onInput( el.value );
			} );
			return el;
		};
		t.group = function ( title, blurb, body, extra ) {
			return h( 'section', { class: 'gs-bk-group' },
				h( 'header', { class: 'gs-bk-group-head' }, h( 'div', null, h( 'h2', null, title ), blurb ? h( 'p', { class: 'gs-st-muted' }, blurb ) : null ), extra || null ),
				body );
		};
		// Segmented choice from { key: label }.
		t.segments = function ( opts, value, onPick, label ) {
			var wrap = h( 'div', { class: 'gs-bk-seg', role: 'radiogroup', 'aria-label': label || '' } );
			Object.keys( opts ).forEach( function ( k ) {
				var b = h( 'button', { type: 'button', role: 'radio', class: 'gs-bk-seg-opt' + ( k === value ? ' is-on' : '' ), 'aria-checked': k === value ? 'true' : 'false', onclick: function () {
					wrap.querySelectorAll( '.gs-bk-seg-opt' ).forEach( function ( o ) {
						o.classList.toggle( 'is-on', o === b );
						o.setAttribute( 'aria-checked', o === b ? 'true' : 'false' );
					} );
					onPick( k );
				} }, opts[ k ] );
				wrap.appendChild( b );
			} );
			return wrap;
		};
		// Word chips: type and press Enter (or comma) to add, × to remove.
		t.chips = function ( list, placeholder, cls ) {
			var wrap = h( 'div', { class: 'gs-bk-chips ' + ( cls || '' ) } );
			var input = h( 'input', { type: 'text', class: 'gs-bk-chip-input', placeholder: placeholder || __( 'Type and press Enter', 'gend-society' ) } );
			var paint = function () {
				wrap.querySelectorAll( '.gs-bk-chip' ).forEach( function ( c ) {
					c.remove();
				} );
				list.forEach( function ( w, i ) {
					wrap.insertBefore( h( 'span', { class: 'gs-bk-chip' }, w, h( 'button', { type: 'button', 'aria-label': sprintf( __( 'Remove %s', 'gend-society' ), w ), onclick: function () {
						list.splice( i, 1 );
						paint();
					} }, '×' ) ), input );
				} );
			};
			var add = function () {
				input.value.split( ',' ).forEach( function ( w ) {
					w = w.trim();
					if ( w && list.indexOf( w ) === -1 ) {
						list.push( w );
					}
				} );
				input.value = '';
				paint();
			};
			input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ',' ) {
					e.preventDefault();
					add();
				} else if ( e.key === 'Backspace' && ! input.value && list.length ) {
					list.pop();
					paint();
				}
			} );
			input.addEventListener( 'blur', add );
			wrap.addEventListener( 'click', function ( e ) {
				if ( e.target === wrap ) {
					input.focus();
				}
			} );
			wrap.appendChild( input );
			paint();
			return wrap;
		};
		// Save bar under the form.
		t.saveBar = function ( part, getData, done ) {
			var status = h( 'span', { class: 'gs-st-muted gs-bk-status' } );
			var btn = h( 'button', { type: 'submit', class: 'gs-st-btn is-primary' }, __( 'Save', 'gend-society' ) );
			var save = function () {
				btn.disabled = true;
				status.textContent = __( 'Saving…', 'gend-society' );
				return kit.api( { path: '/gend-society/v1/brand-kit', method: 'POST', data: { part: part, data: getData() } } ).then( function ( r ) {
					cache.kit = r.kit;
					btn.disabled = false;
					status.textContent = __( 'Saved. The guide and AI briefs use it now.', 'gend-society' );
					kit.toast( __( 'Brand kit saved.', 'gend-society' ), 'ok' );
					kit.reloadPreview();
					if ( done ) {
						done( r.kit );
					}
				}, function ( e ) {
					btn.disabled = false;
					status.textContent = '';
					kit.toast( kit.errMsg( e ), 'error' );
				} );
			};
			return { el: h( 'div', { class: 'gs-bk-savebar' }, btn, status ), save: save };
		};
		t.form = function ( children, bar ) {
			var f = h( 'form', { class: 'gs-st-stack gs-bk', onsubmit: function ( e ) {
				e.preventDefault();
				bar.save();
			} } );
			children.forEach( function ( c ) {
				if ( c ) {
					f.appendChild( c );
				}
			} );
			f.appendChild( bar.el );
			// Ctrl/Cmd+S saves.
			f.addEventListener( 'keydown', function ( e ) {
				if ( ( e.ctrlKey || e.metaKey ) && e.key === 's' ) {
					e.preventDefault();
					bar.save();
				}
			} );
			return f;
		};
		t.removeBtn = function ( label, onClick ) {
			return h( 'button', { type: 'button', class: 'gs-bk-x', 'aria-label': label, title: label, onclick: onClick }, kit.icon( 'no-alt' ) );
		};
		return t;
	}

	// The screen once the kit has loaded (one preview frame, built with the form).
	function wait( hit, kit, build ) {
		var host = kit.h( 'div', { class: 'gs-bk-host' }, kit.loadingView() );
		load( kit ).then( function ( r ) {
			host.textContent = '';
			host.appendChild( build( r ) );
		}, function ( e ) {
			host.textContent = kit.errMsg( e );
		} );
		return host;
	}

	/* ── Voice & Tone ──────────────────────────────────────────────────── */
	window.gsStudioPanels[ 'brand-voice' ] = function ( hit, kit ) {
		return wait( hit, kit, function ( r ) {
			var h = kit.h;
			var t = tools( kit );
			var C = r.choices;
			var v = clone( r.kit.voice );

			// Personality traits.
			var traitsBox = h( 'div', { class: 'gs-bk-rows' } );
			var paintTraits = function () {
				traitsBox.textContent = '';
				v.traits.forEach( function ( tr, i ) {
					traitsBox.appendChild( h( 'div', { class: 'gs-bk-row' },
						h( 'span', { class: 'gs-bk-row-n' }, sprintf( '%02d', i + 1 ) ),
						h( 'div', { class: 'gs-bk-row-body' },
							t.text( tr.trait, function ( x ) {
								tr.trait = x;
							}, __( 'Trait, e.g. Confident', 'gend-society' ) ),
							h( 'div', { class: 'gs-bk-2col' },
								t.text( tr.is, function ( x ) {
									tr.is = x;
								}, __( 'We are… e.g. clear and direct about what works', 'gend-society' ), 2 ),
								t.text( tr.isnt, function ( x ) {
									tr.isnt = x;
								}, __( 'We are not… e.g. arrogant or pushy', 'gend-society' ), 2 ) ) ),
						t.removeBtn( __( 'Remove trait', 'gend-society' ), function () {
							v.traits.splice( i, 1 );
							paintTraits();
						} ) ) );
				} );
			};
			paintTraits();
			var addTrait = h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
				if ( v.traits.length >= 6 ) {
					kit.toast( __( 'Up to six traits keeps the voice memorable.', 'gend-society' ), 'error' );
					return;
				}
				v.traits.push( { trait: '', is: '', isnt: '' } );
				paintTraits();
				traitsBox.lastChild.querySelector( 'input' ).focus();
			} }, kit.icon( 'plus-alt2' ), __( 'Add trait', 'gend-society' ) );

			// Tone sliders.
			var sliders = h( 'div', { class: 'gs-bk-sliders' } );
			Object.keys( C.sliders ).forEach( function ( k ) {
				var range = h( 'input', { type: 'range', min: '1', max: '5', step: '1', value: String( v.sliders[ k ] ), 'aria-label': C.sliders[ k ][ 0 ] + ' / ' + C.sliders[ k ][ 1 ] } );
				range.addEventListener( 'input', function () {
					v.sliders[ k ] = parseInt( range.value, 10 );
				} );
				sliders.appendChild( h( 'div', { class: 'gs-bk-slider' }, h( 'span', null, C.sliders[ k ][ 0 ] ), range, h( 'span', null, C.sliders[ k ][ 1 ] ) ) );
			} );

			// Tone by context.
			var toneBox = h( 'div', { class: 'gs-bk-2col' } );
			Object.keys( C.contexts ).forEach( function ( k ) {
				toneBox.appendChild( t.field( C.contexts[ k ], null, t.text( v.tone[ k ], function ( x ) {
					v.tone[ k ] = x;
				}, __( 'How we sound here', 'gend-society' ), 2 ) ) );
			} );

			// Writing rules.
			var rules = h( 'div', { class: 'gs-bk-2col' } );
			Object.keys( C.rules ).forEach( function ( k ) {
				var sel = h( 'select', { class: 'gs-st-input' } );
				Object.keys( C.rules[ k ].opts ).forEach( function ( o ) {
					sel.appendChild( h( 'option', { value: o, selected: v.rules[ k ] === o ? true : null }, C.rules[ k ].opts[ o ] ) );
				} );
				sel.addEventListener( 'change', function () {
					v.rules[ k ] = sel.value;
				} );
				rules.appendChild( t.field( C.rules[ k ].label, null, sel ) );
			} );

			// Examples.
			var exBox = h( 'div', { class: 'gs-bk-rows' } );
			var paintEx = function () {
				exBox.textContent = '';
				v.examples.forEach( function ( ex, i ) {
					exBox.appendChild( h( 'div', { class: 'gs-bk-row' },
						h( 'div', { class: 'gs-bk-row-body' },
							t.text( ex.context, function ( x ) {
								ex.context = x;
							}, __( 'Where, e.g. Sign-up button', 'gend-society' ) ),
							h( 'div', { class: 'gs-bk-2col' },
								h( 'div', { class: 'gs-bk-do' }, h( 'small', null, __( 'Write', 'gend-society' ) ), t.text( ex.do, function ( x ) {
									ex.do = x;
								}, __( 'Start building', 'gend-society' ), 2 ) ),
								h( 'div', { class: 'gs-bk-dont' }, h( 'small', null, __( 'Not', 'gend-society' ) ), t.text( ex.dont, function ( x ) {
									ex.dont = x;
								}, __( 'Submit', 'gend-society' ), 2 ) ) ) ),
						t.removeBtn( __( 'Remove example', 'gend-society' ), function () {
							v.examples.splice( i, 1 );
							paintEx();
						} ) ) );
				} );
			};
			paintEx();
			var addEx = h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
				v.examples.push( { context: '', do: '', dont: '' } );
				paintEx();
				exBox.lastChild.querySelector( 'input' ).focus();
			} }, kit.icon( 'plus-alt2' ), __( 'Add example', 'gend-society' ) );

			// What the AI receives.
			var briefPre = h( 'pre', { class: 'gs-bk-brief' }, __( 'Loading…', 'gend-society' ) );
			var loadBrief = function () {
				kit.api( { path: '/gend-society/v1/brand-kit/brief' } ).then( function ( b ) {
					briefPre.textContent = b.brief || __( 'Nothing yet. Fill in any section and save.', 'gend-society' );
				}, function () {
					briefPre.textContent = '';
				} );
			};
			loadBrief();
			var copy = h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( briefPre.textContent ).then( function () {
						kit.toast( __( 'Brief copied.', 'gend-society' ), 'ok' );
					} );
				}
			} }, kit.icon( 'clipboard' ), __( 'Copy', 'gend-society' ) );

			var bar = t.saveBar( 'voice', function () {
				return v;
			}, loadBrief );
			var form = t.form( [
				t.group( __( 'Essence', 'gend-society' ), __( 'The brand in a sentence, and who it speaks to.', 'gend-society' ), h( 'div', { class: 'gs-st-stack' },
					t.field( __( 'Brand essence', 'gend-society' ), __( 'One or two sentences the whole brand lives up to.', 'gend-society' ), t.text( v.essence, function ( x ) {
						v.essence = x;
					}, __( 'We help independent businesses own their corner of the internet.', 'gend-society' ), 2 ) ),
					t.field( __( 'Audience', 'gend-society' ), __( 'Who reads our words, what they care about, what they already know.', 'gend-society' ), t.text( v.audience, function ( x ) {
						v.audience = x;
					}, __( 'Founders and small teams, busy, practical, wary of hype…', 'gend-society' ), 3 ) ) ) ),
				t.group( __( 'Personality', 'gend-society' ), __( 'Three to five traits, each with what it means and what it doesn’t.', 'gend-society' ), h( 'div', { class: 'gs-st-stack' }, traitsBox, addTrait ) ),
				t.group( __( 'Tone', 'gend-society' ), __( 'Where the voice sits overall, and how it shifts by situation.', 'gend-society' ), h( 'div', { class: 'gs-st-stack' }, sliders, toneBox ) ),
				t.group( __( 'Words', 'gend-society' ), __( 'Vocabulary that sounds like us, words we steer clear of, and lines we never cross.', 'gend-society' ), h( 'div', { class: 'gs-st-stack' },
					t.field( __( 'Words we use', 'gend-society' ), null, t.chips( v.use, __( 'e.g. build, own, launch', 'gend-society' ), 'is-use' ) ),
					t.field( __( 'Words we avoid', 'gend-society' ), null, t.chips( v.avoid, __( 'e.g. synergy, revolutionary', 'gend-society' ), 'is-avoid' ) ),
					t.field( __( 'Never say or claim', 'gend-society' ), __( 'Promises and phrases that are off limits, legal or otherwise.', 'gend-society' ), t.chips( v.never, __( 'e.g. guaranteed income', 'gend-society' ), 'is-never' ) ) ) ),
				t.group( __( 'Writing rules', 'gend-society' ), null, rules ),
				t.group( __( 'Examples', 'gend-society' ), __( 'Real lines, the right way and the wrong way.', 'gend-society' ), h( 'div', { class: 'gs-st-stack' }, exBox, addEx ) ),
				t.group( __( 'What the AI receives', 'gend-society' ), __( 'Added to AI chats on this site automatically, so prompts can stay short. Updated when you save.', 'gend-society' ), briefPre, copy ),
			], bar );
			return kit.panelLayout( hit, __( 'How the brand sounds, everywhere: the app, marketing, email and support. AI writing on this site follows it automatically.', 'gend-society' ), form );
		} );
	};

	/* ── App Styling ───────────────────────────────────────────────────── */
	var accents = null; // The site's palette colours for the samples (from the brand-kit GET).
	function demo( h, s, family ) {
		var cls = 'gsg-bk-demo is-' + ( family || s.preset || 'flat' ) + ' c-' + s.corners + ' d-' + s.depth + ' b-' + s.buttons + ' f-' + s.forms + ' k-' + s.cards;
		return h( 'div', { class: cls, style: accents ? '--bk-accent:' + accents[ 0 ] + ';--bk-accent-2:' + accents[ 1 ] : null },
			h( 'div', { class: 'gsg-bk-demo-stage' },
				h( 'div', { class: 'gsg-bk-card' },
					h( 'div', { class: 'gsg-bk-card-k' }, __( 'Card', 'gend-society' ) ),
					h( 'div', { class: 'gsg-bk-card-t' }, __( 'Launch your web app', 'gend-society' ) ),
					h( 'div', { class: 'gsg-bk-card-p' }, __( 'Everything you need, in one place.', 'gend-society' ) ),
					h( 'div', { class: 'gsg-bk-field' }, h( 'span', null, __( 'Email', 'gend-society' ) ), h( 'i', null, 'you@example.com' ) ),
					h( 'div', { class: 'gsg-bk-btns' }, h( 'span', { class: 'gsg-bk-btn' }, __( 'Get started', 'gend-society' ) ), h( 'span', { class: 'gsg-bk-btn is-2' }, __( 'Learn more', 'gend-society' ) ) ) ) ) );
	}

	window.gsStudioPanels[ 'brand-style' ] = function ( hit, kit ) {
		return wait( hit, kit, function ( r ) {
			var h = kit.h;
			var t = tools( kit );
			var C = r.choices;
			var s = clone( r.kit.style );
			accents = r.accents || null;

			var big = h( 'div', { class: 'gs-bk-bigdemo' } );
			var paintBig = function () {
				big.textContent = '';
				big.appendChild( demo( h, s ) );
			};

			// Family tiles, each drawn in its own style.
			var fam = h( 'div', { class: 'gs-bk-families', role: 'radiogroup', 'aria-label': __( 'Style family', 'gend-society' ) } );
			var customBox = h( 'div', { class: 'gs-st-stack' } );
			var paintCustom = function () {
				customBox.hidden = s.preset !== 'custom';
			};
			Object.keys( C.styles ).forEach( function ( k ) {
				var base = { corners: s.corners, depth: s.depth, buttons: 'solid', forms: 'outlined', cards: 'bordered' };
				var tile = h( 'button', { type: 'button', role: 'radio', class: 'gs-bk-family' + ( s.preset === k ? ' is-on' : '' ), 'aria-checked': s.preset === k ? 'true' : 'false', onclick: function () {
					s.preset = k;
					fam.querySelectorAll( '.gs-bk-family' ).forEach( function ( o ) {
						o.classList.toggle( 'is-on', o === tile );
						o.setAttribute( 'aria-checked', o === tile ? 'true' : 'false' );
					} );
					paintCustom();
					paintBig();
				} },
					k === 'custom' ? h( 'div', { class: 'gs-bk-family-custom' }, kit.icon( 'edit' ) ) : h( 'div', { class: 'gs-bk-family-demo' }, demo( h, base, k ) ),
					h( 'strong', null, C.styles[ k ][ 0 ] ),
					h( 'small', null, C.styles[ k ][ 1 ] ) );
				fam.appendChild( tile );
			} );
			customBox.appendChild( t.field( __( 'Style name', 'gend-society' ), null, t.text( s.customName, function ( x ) {
				s.customName = x;
			}, __( 'e.g. Aurora glass', 'gend-society' ) ) ) );
			paintCustom();

			// Detail traits.
			var traits = h( 'div', { class: 'gs-st-stack' } );
			Object.keys( C.traits ).forEach( function ( k ) {
				traits.appendChild( h( 'div', { class: 'gs-bk-trait-row' }, h( 'span', { class: 'gs-bk-trait-label' }, C.traits[ k ].label ), t.segments( C.traits[ k ].opts, s[ k ], function ( val ) {
					s[ k ] = val;
					paintBig();
				}, C.traits[ k ].label ) ) );
			} );
			paintBig();

			var bar = t.saveBar( 'style', function () {
				return s;
			} );
			var form = t.form( [
				t.group( __( 'Style family', 'gend-society' ), __( 'The overall look of screens, assets and campaigns. Pick one, or describe your own.', 'gend-society' ), h( 'div', { class: 'gs-st-stack' }, fam, customBox,
					t.field( __( 'In your own words', 'gend-society' ), __( 'What makes it ours: materials, light, mood, references.', 'gend-society' ), t.text( s.description, function ( x ) {
						s.description = x;
					}, __( 'Frosted panels over deep night gradients, neon green and sky accents, generous glow on actions…', 'gend-society' ), 3 ) ) ) ),
				t.group( __( 'Details', 'gend-society' ), __( 'The preview shows each choice as you make it.', 'gend-society' ), h( 'div', { class: 'gs-st-stack' }, big, traits ) ),
				t.group( __( 'Imagery & rules', 'gend-society' ), null, h( 'div', { class: 'gs-st-stack' },
					t.field( __( 'Imagery direction', 'gend-society' ), __( 'Subjects, lighting, colour grading, composition.', 'gend-society' ), t.text( s.imageryNote, function ( x ) {
						s.imageryNote = x;
					}, __( 'Real people at work, natural light, cool shadows, brand colour as an accent light…', 'gend-society' ), 3 ) ),
					t.field( __( 'Do', 'gend-society' ), null, t.chips( s.do, __( 'e.g. one primary action per screen', 'gend-society' ), 'is-use' ) ),
					t.field( __( 'Don’t', 'gend-society' ), null, t.chips( s.dont, __( 'e.g. stock photos with fake smiles', 'gend-society' ), 'is-avoid' ) ) ) ),
			], bar );
			return kit.panelLayout( hit, __( 'The visual style behind every screen and asset. It guides designers and AI image and layout work; it does not restyle your site by itself.', 'gend-society' ), form );
		} );
	};

	/* ── Cast Members ──────────────────────────────────────────────────── */
	window.gsStudioPanels[ 'brand-cast' ] = function ( hit, kit ) {
		return wait( hit, kit, function ( r ) {
			var h = kit.h;
			var t = tools( kit );
			var C = r.choices;
			var cast = clone( r.kit.cast );
			var openId = null;
			var list = h( 'div', { class: 'gs-bk-cast' } );

			var editor = function ( m ) {
				var portrait = h( 'button', { type: 'button', class: 'gs-bk-portrait', title: __( 'Choose an image', 'gend-society' ), onclick: function () {
					kit.pickImage( __( 'Cast member image', 'gend-society' ), function ( a ) {
						m.image = a.url;
						paint();
					} );
				} } );
				if ( m.image ) {
					portrait.style.setProperty( '--gs-img', 'url("' + m.image.replace( /["\\]/g, '' ) + '")' );
					portrait.classList.add( 'has-img' );
				} else {
					portrait.appendChild( kit.icon( 'format-image' ) );
				}
				var kind = h( 'select', { class: 'gs-st-input' } );
				Object.keys( C.kinds ).forEach( function ( k ) {
					kind.appendChild( h( 'option', { value: k, selected: m.kind === k ? true : null }, C.kinds[ k ] ) );
				} );
				kind.addEventListener( 'change', function () {
					m.kind = kind.value;
					paint();
				} );
				var chans = h( 'div', { class: 'gs-bk-seg is-multi' } );
				Object.keys( C.channels ).forEach( function ( k ) {
					var on = m.channels.indexOf( k ) !== -1;
					var b = h( 'button', { type: 'button', class: 'gs-bk-seg-opt' + ( on ? ' is-on' : '' ), 'aria-pressed': on ? 'true' : 'false', onclick: function () {
						var i = m.channels.indexOf( k );
						if ( i === -1 ) {
							m.channels.push( k );
						} else {
							m.channels.splice( i, 1 );
						}
						b.classList.toggle( 'is-on', i === -1 );
						b.setAttribute( 'aria-pressed', i === -1 ? 'true' : 'false' );
					} }, C.channels[ k ] );
					chans.appendChild( b );
				} );
				var check = function ( key, label, hint ) {
					var box = h( 'input', { type: 'checkbox', checked: m[ key ] ? true : null } );
					box.addEventListener( 'change', function () {
						m[ key ] = box.checked;
					} );
					return h( 'label', { class: 'gs-bk-check' }, box, h( 'span', null, h( 'strong', null, label ), hint ? h( 'small', null, hint ) : null ) );
				};
				return h( 'div', { class: 'gs-bk-editor' },
					h( 'div', { class: 'gs-bk-editor-top' }, portrait,
						h( 'div', { class: 'gs-st-stack' },
							t.field( __( 'Name', 'gend-society' ), null, t.text( m.name, function ( x ) {
								m.name = x;
							}, __( 'e.g. Nova', 'gend-society' ) ) ),
							h( 'div', { class: 'gs-bk-2col' },
								t.field( __( 'Type', 'gend-society' ), null, kind ),
								t.field( __( 'Role', 'gend-society' ), null, t.text( m.role, function ( x ) {
									m.role = x;
								}, __( 'e.g. Onboarding guide', 'gend-society' ) ) ) ) ) ),
					'member' === m.kind ? check( 'consent', __( 'Consent on file', 'gend-society' ), __( 'This person agreed to appear in brand materials. Without it they stay out of AI briefs.', 'gend-society' ) ) : null,
					h( 'div', { class: 'gs-bk-2col' },
						t.field( __( 'Personality', 'gend-society' ), null, t.text( m.personality, function ( x ) {
							m.personality = x;
						}, __( 'Curious, upbeat, never sarcastic…', 'gend-society' ), 3 ) ),
						t.field( __( 'Look', 'gend-society' ), __( 'How to draw, render or photograph them.', 'gend-society' ), t.text( m.look, function ( x ) {
							m.look = x;
						}, __( 'Small round robot, matte white, green visor glow…', 'gend-society' ), 3 ) ),
						t.field( __( 'Voice', 'gend-society' ), null, t.text( m.voice, function ( x ) {
							m.voice = x;
						}, __( 'Short sentences, first person, light humour…', 'gend-society' ), 3 ) ),
						t.field( __( 'Use when', 'gend-society' ), null, t.text( m.use, function ( x ) {
							m.use = x;
						}, __( 'Welcome emails, empty states, tutorials…', 'gend-society' ), 3 ) ) ),
					t.field( __( 'Avoid when', 'gend-society' ), null, t.text( m.avoid, function ( x ) {
						m.avoid = x;
					}, __( 'Billing problems, legal notices, bad news…', 'gend-society' ), 2 ) ),
					t.field( __( 'Channels', 'gend-society' ), null, chans ),
					h( 'div', { class: 'gs-bk-editor-foot' },
						check( 'active', __( 'In use', 'gend-society' ), __( 'Turn off to keep the brief without using it.', 'gend-society' ) ),
						h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm gs-bk-danger', onclick: function () {
							kit.confirmBox( __( 'Remove this cast member?', 'gend-society' ), sprintf( __( '“%s” leaves the brand guide and AI briefs once you save.', 'gend-society' ), m.name || __( 'Untitled', 'gend-society' ) ), [ { label: __( 'Cancel', 'gend-society' ), value: false }, { label: __( 'Remove', 'gend-society' ), value: true, primary: true } ] ).then( function ( ok ) {
								if ( ok ) {
									cast.splice( cast.indexOf( m ), 1 );
									openId = null;
									paint();
								}
							} );
						} }, kit.icon( 'trash' ), __( 'Remove', 'gend-society' ) ) ) );
			};

			var paint = function () {
				list.textContent = '';
				if ( ! cast.length ) {
					list.appendChild( h( 'p', { class: 'gs-st-muted gs-bk-empty' }, __( 'No cast yet. Add the characters, mascots, avatars, agents and people who represent the brand.', 'gend-society' ) ) );
				}
				cast.forEach( function ( m ) {
					var open = openId === m.id;
					var face = h( 'span', { class: 'gs-bk-face' + ( m.image ? ' has-img' : '' ) } );
					if ( m.image ) {
						face.style.setProperty( '--gs-img', 'url("' + m.image.replace( /["\\]/g, '' ) + '")' );
					} else {
						face.textContent = ( m.name || '?' ).charAt( 0 ).toUpperCase();
					}
					var head = h( 'button', { type: 'button', class: 'gs-bk-cast-head', 'aria-expanded': open ? 'true' : 'false', onclick: function () {
						openId = open ? null : m.id;
						paint();
					} },
						face,
						h( 'span', { class: 'gs-bk-cast-name' }, h( 'strong', null, m.name || __( 'Untitled', 'gend-society' ) ), h( 'small', null, C.kinds[ m.kind ] + ( m.role ? ' · ' + m.role : '' ) ) ),
						m.active ? null : h( 'span', { class: 'gs-st-badge' }, __( 'Not in use', 'gend-society' ) ),
						'member' === m.kind && ! m.consent ? h( 'span', { class: 'gs-st-badge is-missing' }, __( 'Consent pending', 'gend-society' ) ) : null,
						kit.icon( open ? 'arrow-up-alt2' : 'arrow-down-alt2' ) );
					list.appendChild( h( 'div', { class: 'gs-bk-cast-item' + ( open ? ' is-open' : '' ) }, head, open ? editor( m ) : null ) );
				} );
			};
			paint();

			var adders = h( 'div', { class: 'gs-bk-adders' } );
			Object.keys( C.kinds ).forEach( function ( k ) {
				adders.appendChild( h( 'button', { type: 'button', class: 'gs-st-btn is-ghost is-sm', onclick: function () {
					var m = { id: 'cast_' + Math.random().toString( 36 ).slice( 2, 10 ), name: '', kind: k, role: '', image: '', personality: '', look: '', voice: '', use: '', avoid: '', channels: [], consent: false, active: true };
					cast.push( m );
					openId = m.id;
					paint();
					var first = list.querySelector( '.is-open input[type="text"]' );
					if ( first ) {
						first.focus();
					}
				} }, kit.icon( 'plus-alt2' ), C.kinds[ k ] ) );
			} );

			var bar = t.saveBar( 'cast', function () {
				return cast;
			}, function ( saved ) {
				cast = clone( saved.cast );
				paint();
			} );
			var form = t.form( [
				t.group( __( 'Add to the cast', 'gend-society' ), __( 'Each brief says who they are, how they look and sound, and when they appear.', 'gend-society' ), adders ),
				t.group( __( 'Cast', 'gend-society' ), null, list ),
			], bar );
			return kit.panelLayout( hit, __( 'The recurring faces of the brand: characters, mascots, avatars, AI agents, spokespeople and real members. Designers and AI use these briefs to keep them consistent.', 'gend-society' ), form );
		} );
	};
}() );
