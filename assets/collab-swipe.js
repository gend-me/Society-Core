/**
 * gend-society — GenD Match swipe-deck controller (Phase 82-04, v12.0).
 *
 * Vanilla JS, no dependencies, no build step. Hand-rolled Pointer Events
 * (NOT Hammer.js — CDN/CSP violation + unmaintained). Drives the DOM contract
 * rendered by Plan 82-03's GS_Group_Tab_Collab::display():
 *
 *   #gs-collab-deck[data-group-id]                 card-stack mount
 *   [data-gs-collab-pass] / [data-gs-collab-like]  on-screen swipe buttons
 *   .gs-collab-empty[hidden]                        SWIPE-06 empty-state node
 *   #gs-collab-tag-form                             inline TAG-01 editor
 *     [data-gs-collab-category]  <select>
 *     [data-gs-collab-industry]  <select>
 *     [data-gs-collab-location]  <input>
 *     [data-gs-collab-optin]     <input type=checkbox>
 *     [data-gs-collab-save]      <button>
 *     [data-gs-collab-status]    <span role=status>   feedback slot
 *
 * Contract: window.gsCollabData = { restUrl:'…/wp-json/gs/v1', nonce, groupId }.
 * Every gs/v1/collab/* fetch sends header 'X-WP-Nonce': gsCollabData.nonce and
 * credentials:'same-origin' (gend.me 401s all /wp-json for logged-out users).
 *
 * REST contract (Plan 82-02):
 *   GET  {restUrl}/collab/deck?group_id&offset[&category&industry&location]
 *        → { cards:[ {group_id,name,avatar,tagline,permalink,tags:{…},score} ], has_more }
 *   POST {restUrl}/collab/swipe  {group_id, to_group, decision:'right'|'left'} → { ok:true }
 *   GET  {restUrl}/collab/tags?group_id → { category,industry,location,optin,categories,industries }
 *   POST {restUrl}/collab/tags   {group_id,category,industry,location,optin} → updated tags
 *
 * SWIPE-01: four input modes (touch drag, mouse drag, on-screen buttons,
 *   ArrowLeft/ArrowRight keys) all route through commit().
 * SWIPE-02: each card renders name, avatar, tagline, and category/industry/
 *   location tags.
 * SWIPE-06: an exhausted deck reveals .gs-collab-empty once and stops — no
 *   loop, no re-fetch, no re-show of decided cards. A failed swipe POST does
 *   NOT advance the queue (the server ledger is authoritative + idempotent, so
 *   a later retry is safe).
 *
 * @package gend-society
 */
( function () {
	'use strict';

	// --- Bail gracefully if the surface isn't present (SWIPE controller is
	// enqueued only on the Match tab, but guard defensively). ---
	if ( ! window.gsCollabData ) {
		return;
	}
	var deckEl = document.getElementById( 'gs-collab-deck' );
	if ( ! deckEl ) {
		return;
	}

	var cfg      = window.gsCollabData;
	var restUrl  = ( cfg.restUrl || '' ).replace( /\/$/, '' );
	var nonce    = cfg.nonce || '';
	var groupId  = parseInt( cfg.groupId || deckEl.getAttribute( 'data-group-id' ) || '0', 10 );

	// --- Related DOM nodes (all optional except the deck mount). ---
	var passBtn  = document.querySelector( '[data-gs-collab-pass]' );
	var likeBtn  = document.querySelector( '[data-gs-collab-like]' );
	var undoBtn  = document.querySelector( '[data-gs-collab-undo]' );
	var emptyEl  = document.querySelector( '.gs-collab-empty' );
	var tagForm  = document.getElementById( 'gs-collab-tag-form' );
	var catSel   = document.querySelector( '[data-gs-collab-category]' );
	var indSel   = document.querySelector( '[data-gs-collab-industry]' );
	var locInput = document.querySelector( '[data-gs-collab-location]' );
	var optinBox = document.querySelector( '[data-gs-collab-optin]' );
	var saveBtn  = document.querySelector( '[data-gs-collab-save]' );
	var statusEl = document.querySelector( '[data-gs-collab-status]' );

	// --- Deck state. ---
	var queue    = [];     // undecided cards not yet swiped
	var offset   = 0;      // server pagination cursor
	var hasMore  = true;   // false once the server reports no further pages
	var busy     = false;  // guards concurrent commit()/loadMore()
	var facets   = {};     // active facet filters (TAG-03), if any controls exist
	var lastSwiped = null; // {card, decision} of the last committed swipe (SWIPE-07 undo)

	var SWIPE_THRESHOLD = 90; // px, or 25% of card width — see pointerup

	// ---------------------------------------------------------------------
	// Tiny fetch helper. Sends the WP REST nonce + same-origin credentials on
	// every call. Returns { ok, status, data } — never throws uncaught; a
	// network/parse failure resolves to { ok:false }.
	// ---------------------------------------------------------------------
	function api( path, opts ) {
		opts = opts || {};
		var url = restUrl + path;
		return fetch( url, {
			method:      opts.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   nonce
			},
			body: opts.body ? JSON.stringify( opts.body ) : undefined
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				return { ok: r.ok, status: r.status, data: data };
			} ).catch( function () {
				return { ok: r.ok, status: r.status, data: null };
			} );
		} ).catch( function () {
			return { ok: false, status: 0, data: null };
		} );
	}

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function setStatus( msg, isError ) {
		if ( ! statusEl ) {
			return;
		}
		statusEl.textContent = msg || '';
		statusEl.style.color = isError ? '#ffb4b4' : '#9fb6e6';
	}

	function showDeckError( msg ) {
		deckEl.innerHTML = '<div class="gs-collab-deck-error" role="alert">' + esc( msg ) + '</div>';
	}

	// ---------------------------------------------------------------------
	// Facet controls (TAG-03) — read from optional filter inputs if the tab
	// ever renders them; omit params otherwise. Kept forward-compatible.
	// ---------------------------------------------------------------------
	function readFacets() {
		var f = {};
		var fc = document.querySelector( '[data-gs-collab-facet-category]' );
		var fi = document.querySelector( '[data-gs-collab-facet-industry]' );
		var fl = document.querySelector( '[data-gs-collab-facet-location]' );
		if ( fc && fc.value ) { f.category = fc.value; }
		if ( fi && fi.value ) { f.industry = fi.value; }
		if ( fl && fl.value ) { f.location = fl.value; }
		return f;
	}

	// ---------------------------------------------------------------------
	// Deck fetch + render.
	// ---------------------------------------------------------------------
	function loadMore() {
		if ( ! hasMore || busy ) {
			return;
		}
		busy = true;
		var qs = '/collab/deck?group_id=' + encodeURIComponent( groupId ) + '&offset=' + encodeURIComponent( offset );
		if ( facets.category ) { qs += '&category=' + encodeURIComponent( facets.category ); }
		if ( facets.industry ) { qs += '&industry=' + encodeURIComponent( facets.industry ); }
		if ( facets.location ) { qs += '&location=' + encodeURIComponent( facets.location ); }

		api( qs ).then( function ( res ) {
			busy = false;
			if ( ! res.ok || ! res.data ) {
				showDeckError( 'Could not load the deck. Please try again.' );
				return;
			}
			var cards = Array.isArray( res.data.cards ) ? res.data.cards : [];
			for ( var i = 0; i < cards.length; i++ ) {
				queue.push( cards[ i ] );
			}
			offset += cards.length;
			hasMore = !! res.data.has_more;
			renderTop();
		} );
	}

	function showEmpty() {
		deckEl.innerHTML = '';
		if ( emptyEl ) {
			emptyEl.hidden = false;
			emptyEl.removeAttribute( 'hidden' );
		}
		toggleControls( false );
	}

	function toggleControls( enabled ) {
		if ( passBtn ) { passBtn.disabled = ! enabled; }
		if ( likeBtn ) { likeBtn.disabled = ! enabled; }
	}

	function renderTop() {
		// SWIPE-06: exhausted + no more pages → reveal empty-state once, stop.
		if ( ! queue.length && ! hasMore ) {
			showEmpty();
			return;
		}
		// Empty locally but the server has more → page in the next batch.
		if ( ! queue.length && hasMore ) {
			loadMore();
			return;
		}
		if ( emptyEl ) {
			emptyEl.hidden = true;
			emptyEl.setAttribute( 'hidden', 'hidden' );
		}
		toggleControls( true );

		var card = queue[ 0 ];
		var tags = card.tags || {};
		var chips = '';
		[ tags.category, tags.industry, tags.location ].forEach( function ( t ) {
			if ( t ) {
				chips += '<span class="gs-collab-chip">' + esc( t ) + '</span>';
			}
		} );

		var avatarHtml = card.avatar
			? '<img class="gs-collab-avatar" src="' + esc( card.avatar ) + '" alt="' + esc( card.name ) + '">'
			: '<div class="gs-collab-avatar gs-collab-avatar-placeholder" aria-hidden="true">' + esc( ( card.name || '?' ).charAt( 0 ) ) + '</div>';

		deckEl.innerHTML =
			'<article class="gs-collab-card" tabindex="0" aria-label="' + esc( card.name ) + '">' +
				'<span class="gs-collab-badge gs-collab-badge-like" aria-hidden="true">INTERESTED</span>' +
				'<span class="gs-collab-badge gs-collab-badge-pass" aria-hidden="true">PASS</span>' +
				avatarHtml +
				'<h4 class="gs-collab-name">' + esc( card.name ) + '</h4>' +
				( card.tagline ? '<p class="gs-collab-tagline">' + esc( card.tagline ) + '</p>' : '' ) +
				( chips ? '<div class="gs-collab-chips">' + chips + '</div>' : '' ) +
			'</article>';

		attachDrag( deckEl.querySelector( '.gs-collab-card' ) );
	}

	// ---------------------------------------------------------------------
	// Pointer-Events drag (SWIPE-01, touch + mouse + pen unified). The card
	// has touch-action:none in CSS so a touch drag isn't stolen by page
	// scroll; setPointerCapture keeps events flowing to the card.
	// ---------------------------------------------------------------------
	function attachDrag( card ) {
		if ( ! card ) {
			return;
		}
		var dragging = false;
		var startX   = 0;
		var likeBadge = card.querySelector( '.gs-collab-badge-like' );
		var passBadge = card.querySelector( '.gs-collab-badge-pass' );

		card.addEventListener( 'pointerdown', function ( e ) {
			if ( busy ) {
				return;
			}
			dragging = true;
			startX   = e.clientX;
			try { card.setPointerCapture( e.pointerId ); } catch ( err ) {}
			card.classList.add( 'gs-collab-dragging' );
		} );

		card.addEventListener( 'pointermove', function ( e ) {
			if ( ! dragging ) {
				return;
			}
			var dx = e.clientX - startX;
			card.style.transform = 'translateX(' + dx + 'px) rotate(' + ( dx * 0.05 ) + 'deg)';
			var mag = Math.min( Math.abs( dx ) / SWIPE_THRESHOLD, 1 );
			if ( likeBadge ) { likeBadge.style.opacity = dx > 0 ? mag : 0; }
			if ( passBadge ) { passBadge.style.opacity = dx < 0 ? mag : 0; }
		} );

		function endDrag( e ) {
			if ( ! dragging ) {
				return;
			}
			dragging = false;
			card.classList.remove( 'gs-collab-dragging' );
			var dx = e.clientX - startX;
			if ( Math.abs( dx ) > SWIPE_THRESHOLD ) {
				commit( dx > 0 ? 'right' : 'left' );
			} else {
				// Spring back.
				card.style.transform = '';
				if ( likeBadge ) { likeBadge.style.opacity = 0; }
				if ( passBadge ) { passBadge.style.opacity = 0; }
			}
		}

		card.addEventListener( 'pointerup', endDrag );
		card.addEventListener( 'pointercancel', function () {
			dragging = false;
			card.classList.remove( 'gs-collab-dragging' );
			card.style.transform = '';
			if ( likeBadge ) { likeBadge.style.opacity = 0; }
			if ( passBadge ) { passBadge.style.opacity = 0; }
		} );
	}

	// ---------------------------------------------------------------------
	// commit(decision) — animate a fly-off, POST the decision, and advance
	// ONLY on a successful write. A failed POST rolls the card back and does
	// NOT shift the queue (so the card can be retried — the server ledger is
	// authoritative + idempotent). A double-tap while busy is ignored.
	// ---------------------------------------------------------------------
	function commit( decision ) {
		if ( busy || ! queue.length ) {
			return;
		}
		busy = true;
		var card = queue[ 0 ];
		var cardEl = deckEl.querySelector( '.gs-collab-card' );

		if ( cardEl ) {
			cardEl.classList.add( decision === 'right' ? 'gs-collab-fly-right' : 'gs-collab-fly-left' );
		}

		api( '/collab/swipe', {
			method: 'POST',
			body: { group_id: groupId, to_group: card.group_id, decision: decision }
		} ).then( function ( res ) {
			if ( res.ok && res.data && res.data.ok ) {
				// SWIPE-07: remember this card so the Undo button can pop it back.
				lastSwiped = { card: card, decision: decision };
				if ( res.data.matched === true ) {
					// A match formed — the server will refuse an undo, so pre-disable
					// it for a snappier UX (server still enforces the refusal).
					lastSwiped = null;
					if ( undoBtn ) { undoBtn.disabled = true; }
					setStatus( 'It’s a match!', false );
				} else if ( undoBtn ) {
					undoBtn.disabled = false;
				}
				// Committed — drop the card and advance.
				queue.shift();
				busy = false;
				renderTop();
			} else {
				// Failed — roll back the fly-off, keep the card for retry.
				busy = false;
				if ( cardEl ) {
					cardEl.classList.remove( 'gs-collab-fly-right', 'gs-collab-fly-left' );
					cardEl.style.transform = '';
				}
				setStatus( 'Swipe failed — please try again.', true );
			}
		} );
	}

	// --- Buttons (SWIPE-01). ---
	if ( passBtn ) {
		passBtn.addEventListener( 'click', function () { commit( 'left' ); } );
	}
	if ( likeBtn ) {
		likeBtn.addEventListener( 'click', function () { commit( 'right' ); } );
	}

	// --- Undo (SWIPE-07): single-step undo of the last swipe. Pops the last card
	// back to the front of the deck on success; on a 409 (match formed / nothing to
	// undo) it surfaces the server message and leaves the card gone. ---
	if ( undoBtn ) {
		undoBtn.addEventListener( 'click', function () {
			if ( busy || undoBtn.disabled ) {
				return;
			}
			busy = true;
			api( '/collab/undo', { method: 'POST', body: { group_id: groupId } } ).then( function ( res ) {
				busy = false;
				if ( res.ok && res.data && res.data.ok ) {
					if ( lastSwiped && lastSwiped.card ) {
						queue.unshift( lastSwiped.card ); // card returns to deck front
					}
					lastSwiped = null;
					undoBtn.disabled = true; // single-step: disable until next swipe
					setStatus( 'Undone.', false );
					renderTop();
				} else {
					// 409 gs_collab_undo_after_match / nothing-to-undo — surface, card stays gone.
					setStatus( ( res.data && res.data.message ) || 'Nothing to undo.', true );
					undoBtn.disabled = true;
				}
			} );
		} );
	}

	// --- Keyboard (SWIPE-01 a11y): only act when a card is rendered and not
	// busy, and not while the user is typing in the tag editor. ---
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' ) {
			return;
		}
		var t = e.target;
		if ( t && ( t.tagName === 'INPUT' || t.tagName === 'SELECT' || t.tagName === 'TEXTAREA' || t.isContentEditable ) ) {
			return;
		}
		if ( busy || ! queue.length || ! deckEl.querySelector( '.gs-collab-card' ) ) {
			return;
		}
		e.preventDefault();
		commit( e.key === 'ArrowRight' ? 'right' : 'left' );
	} );

	// ---------------------------------------------------------------------
	// Tag editor (drives TAG-01 from Plan 82-03's form). Hydrate the selects
	// from the enum label maps, preselect current values, wire Save.
	// ---------------------------------------------------------------------
	function fillSelect( sel, map, current ) {
		if ( ! sel || ! map ) {
			return;
		}
		// Only rebuild if the server-rendered options are absent/stale — the
		// PHP already pre-populated best-effort, so preserve the placeholder.
		var placeholder = sel.options.length ? sel.options[ 0 ] : null;
		sel.innerHTML = '';
		if ( placeholder ) {
			sel.appendChild( placeholder );
		}
		Object.keys( map ).forEach( function ( key ) {
			var opt = document.createElement( 'option' );
			opt.value = key;
			opt.textContent = map[ key ];
			if ( key === current ) {
				opt.selected = true;
			}
			sel.appendChild( opt );
		} );
	}

	function loadTags() {
		return api( '/collab/tags?group_id=' + encodeURIComponent( groupId ) ).then( function ( res ) {
			if ( ! res.ok || ! res.data ) {
				return;
			}
			var d = res.data;
			fillSelect( catSel, d.categories, d.category );
			fillSelect( indSel, d.industries, d.industry );
			if ( locInput && typeof d.location === 'string' ) { locInput.value = d.location; }
			if ( optinBox ) { optinBox.checked = !! d.optin; }
		} );
	}

	function saveTags() {
		var category = catSel ? catSel.value : '';
		var industry = indSel ? indSel.value : '';
		var location = locInput ? locInput.value : '';
		var optin    = optinBox ? ( optinBox.checked ? 1 : 0 ) : 0;

		// Mirror the server opt-in gate: can't be discoverable without both
		// a category and an industry.
		if ( optin && ( ! category || ! industry ) ) {
			setStatus( 'Set a category and industry before opening to collaboration.', true );
			if ( optinBox ) { optinBox.checked = false; }
			return;
		}

		if ( saveBtn ) { saveBtn.disabled = true; }
		setStatus( 'Saving…', false );

		api( '/collab/tags', {
			method: 'POST',
			body: { group_id: groupId, category: category, industry: industry, location: location, optin: optin }
		} ).then( function ( res ) {
			if ( saveBtn ) { saveBtn.disabled = false; }
			if ( res.ok && res.data && res.data.ok ) {
				if ( optinBox ) { optinBox.checked = !! res.data.optin; }
				setStatus( 'Saved.', false );
				// Discoverability/tags changed — reset and reload the deck.
				queue   = [];
				offset  = 0;
				hasMore = true;
				busy    = false;
				facets  = readFacets();
				// SWIPE-07: a reloaded deck starts with undo disabled.
				lastSwiped = null;
				if ( undoBtn ) { undoBtn.disabled = true; }
				if ( emptyEl ) {
					emptyEl.hidden = true;
					emptyEl.setAttribute( 'hidden', 'hidden' );
				}
				loadMore();
			} else {
				var msg = ( res.data && res.data.message ) ? res.data.message : 'Could not save tags.';
				setStatus( msg, true );
			}
		} );
	}

	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			saveTags();
		} );
	}
	if ( tagForm ) {
		tagForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			saveTags();
		} );
	}

	// ---------------------------------------------------------------------
	// Init: hydrate the tag editor first, then load the first deck page.
	// ---------------------------------------------------------------------
	facets = readFacets();
	loadTags().then( function () {
		loadMore();
	} );

} )();
