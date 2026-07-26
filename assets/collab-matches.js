/**
 * gend-society — GenD Match "Matches" management controller (v12.0, Tier A).
 *
 * The second half of the Match tab: after the swipe deck forms mutual matches,
 * THIS surface lets a group admin actually USE them — see who they matched with,
 * open the intro-thread conversation, and drive the collaboration-contract
 * lifecycle (propose → accept / decline, or forfeit / mutual-cancel a live one).
 *
 * Consumes window.gsCollabData = { restUrl:'…/wp-json/gs/v1', nonce, groupId } and
 * the DOM contract rendered by GS_Group_Tab_Collab::display():
 *   .gs-collab-viewnav [data-gs-view=deck|matches]   the Discover/Matches toggle
 *   [data-gs-view-panel=deck|matches]                 the two view panels
 *   #gs-collab-matches[data-group-id]                 the matches mount
 *
 * REST (all send X-WP-Nonce + same-origin — gend.me 401s all /wp-json logged-out):
 *   GET  /collab/matches?group_id                              → { matches:[…] }
 *   POST /collab/match/{id}/contract/propose  {group_id,payer_group,payee_group,escrow_model,credits}
 *   POST /collab/match/{id}/contract/accept   {group_id}       (we are the counterparty)
 *   POST /collab/match/{id}/contract/decline  {group_id,mode:'declined'|'cancelled'}
 *   POST /collab/match/{id}/contract/forfeit  {group_id}
 *   POST /collab/match/{id}/contract/cancel/propose  {group_id}
 *
 * The contract accept path moves real DGEN escrow (Phase 84) — the UI only
 * triggers it; all money-safety lives server-side.
 *
 * @package gend-society
 */
( function () {
	'use strict';

	if ( ! window.gsCollabData ) {
		return;
	}
	var matchesEl = document.getElementById( 'gs-collab-matches' );
	var nav       = document.querySelector( '.gs-collab-viewnav' );
	if ( ! matchesEl || ! nav ) {
		return;
	}

	var cfg     = window.gsCollabData;
	var restUrl = ( cfg.restUrl || '' ).replace( /\/$/, '' );
	var nonce   = cfg.nonce || '';
	var groupId = parseInt( cfg.groupId || matchesEl.getAttribute( 'data-group-id' ) || '0', 10 );

	var loaded = false;
	var busy   = false;

	function api( path, opts ) {
		opts = opts || {};
		return fetch( restUrl + path, {
			method:      opts.method || 'GET',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body:        opts.body ? JSON.stringify( opts.body ) : undefined
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

	// ---------------------------------------------------------------------
	// View toggle (Discover deck  <->  Matches).
	// ---------------------------------------------------------------------
	var viewBtns = nav.querySelectorAll( '[data-gs-view]' );
	var panels   = document.querySelectorAll( '[data-gs-view-panel]' );

	function showView( view ) {
		viewBtns.forEach( function ( b ) {
			var on = b.getAttribute( 'data-gs-view' ) === view;
			b.classList.toggle( 'is-active', on );
			b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		panels.forEach( function ( p ) {
			p.hidden = p.getAttribute( 'data-gs-view-panel' ) !== view;
		} );
		if ( view === 'matches' && ! loaded ) {
			loadMatches();
		}
	}

	viewBtns.forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			showView( b.getAttribute( 'data-gs-view' ) );
		} );
	} );

	function updateCount( n ) {
		var c = document.querySelector( '[data-gs-collab-match-count]' );
		if ( ! c ) { return; }
		if ( n > 0 ) { c.textContent = '(' + n + ')'; c.hidden = false; } else { c.hidden = true; }
	}

	// ---------------------------------------------------------------------
	// Fetch + render.
	// ---------------------------------------------------------------------
	function loadMatches() {
		matchesEl.innerHTML = '<p class="gs-collab-mnote">Loading your matches…</p>';
		api( '/collab/matches?group_id=' + encodeURIComponent( groupId ) ).then( function ( res ) {
			loaded = true;
			if ( ! res.ok || ! res.data || ! Array.isArray( res.data.matches ) ) {
				matchesEl.innerHTML = '<p class="gs-collab-mnote">Could not load your matches. Please try again.</p>';
				return;
			}
			render( res.data.matches );
		} );
	}

	function render( matches ) {
		updateCount( matches.length );
		if ( ! matches.length ) {
			matchesEl.innerHTML =
				'<div class="gs-collab-mempty">' +
					'<p style="color:#fff; font-weight:600; margin:0 0 6px;">No matches yet.</p>' +
					'<p style="color:#cbd5f5; margin:0; font-size:0.9rem; line-height:1.5;">When you and another business BOTH swipe “Interested” in Discover, the match appears here with a private conversation and the option to start a collaboration contract.</p>' +
				'</div>';
			return;
		}
		matchesEl.innerHTML = matches.map( cardHtml ).join( '' );
		wire();
	}

	function chipsHtml( tags ) {
		return [ tags.category, tags.industry, tags.location ].filter( Boolean )
			.map( function ( t ) { return '<span class="gs-collab-chip">' + esc( t ) + '</span>'; } ).join( '' );
	}

	function cardHtml( m ) {
		var cp     = m.counterparty || {};
		var tags   = cp.tags || {};
		var avatar = cp.avatar
			? '<img class="gs-collab-mavatar" src="' + esc( cp.avatar ) + '" alt="">'
			: '<div class="gs-collab-mavatar gs-collab-avatar-placeholder" aria-hidden="true">' + esc( ( cp.name || '?' ).charAt( 0 ) ) + '</div>';
		var nameHtml = cp.permalink
			? '<a class="gs-collab-mname" href="' + esc( cp.permalink ) + '">' + esc( cp.name ) + '</a>'
			: '<span class="gs-collab-mname">' + esc( cp.name ) + '</span>';
		var msg = m.message_link
			? '<a class="gs-collab-mbtn gs-collab-mbtn-msg" href="' + esc( m.message_link ) + '">Message</a>'
			: '';

		return '<article class="gs-collab-mcard" data-match-id="' + parseInt( m.match_id, 10 ) + '" data-counter-id="' + parseInt( cp.group_id, 10 ) + '">' +
				'<div class="gs-collab-mhead">' + avatar +
					'<div class="gs-collab-minfo">' + nameHtml +
						'<div class="gs-collab-chips">' + chipsHtml( tags ) + '</div>' +
					'</div>' +
				'</div>' +
				'<div class="gs-collab-mactions">' + msg + statusHtml( m ) + '</div>' +
				'<div class="gs-collab-mform" hidden></div>' +
				'<div class="gs-collab-mstatus" data-mstatus role="status" aria-live="polite"></div>' +
			'</article>';
	}

	function statusHtml( m ) {
		var p = m.proposal || {};
		switch ( m.phase ) {
			case 'proposal_sent':
				return '<span class="gs-collab-mstate">Proposal sent · ' + parseInt( p.credits || 0, 10 ) + ' DGEN escrow · awaiting them</span>' +
					'<button type="button" class="gs-collab-mbtn gs-collab-mbtn-warn" data-act="cancel-proposal">Cancel proposal</button>';
			case 'proposal_received':
				var weFund = ( parseInt( p.payer_group, 10 ) === groupId );
				return '<span class="gs-collab-mstate">They proposed a collaboration · ' + parseInt( p.credits || 0, 10 ) + ' DGEN escrow' + ( weFund ? ' (you fund)' : ' (they fund)' ) + '</span>' +
					'<button type="button" class="gs-collab-mbtn gs-collab-mbtn-go" data-act="accept">Accept' + ( weFund && p.credits > 0 ? ' — escrow ' + parseInt( p.credits, 10 ) + ' DGEN' : '' ) + '</button>' +
					'<button type="button" class="gs-collab-mbtn gs-collab-mbtn-warn" data-act="decline">Decline</button>';
			case 'contracted':
				return '<span class="gs-collab-mstate gs-collab-mstate-live">● Active collaboration</span>' +
					'<button type="button" class="gs-collab-mbtn" data-act="cancel-request">Request cancel</button>' +
					'<button type="button" class="gs-collab-mbtn gs-collab-mbtn-warn" data-act="forfeit">Forfeit</button>';
			case 'resolved':
				var label = m.outcome === 'success' ? 'Completed ✓' : ( m.outcome === 'void' ? 'Cancelled' : 'Ended' );
				return '<span class="gs-collab-mstate gs-collab-mstate-done">' + label + '</span>';
			default: // matched
				return '<button type="button" class="gs-collab-mbtn gs-collab-mbtn-go" data-act="propose">Start collaboration</button>';
		}
	}

	// ---------------------------------------------------------------------
	// Wire the per-card action buttons.
	// ---------------------------------------------------------------------
	function wire() {
		matchesEl.querySelectorAll( '[data-act]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { onAction( btn ); } );
		} );
	}

	function cardOf( el ) { return el.closest( '.gs-collab-mcard' ); }
	function setCardStatus( card, msg, isError ) {
		var s = card.querySelector( '[data-mstatus]' );
		if ( s ) { s.textContent = msg || ''; s.style.color = isError ? '#ffb4b4' : '#9fb6e6'; }
	}

	function onAction( btn ) {
		var act  = btn.getAttribute( 'data-act' );
		var card = cardOf( btn );
		if ( ! card ) { return; }
		var mid     = parseInt( card.getAttribute( 'data-match-id' ), 10 );
		var counter = parseInt( card.getAttribute( 'data-counter-id' ), 10 );

		if ( act === 'propose' ) { return openProposeForm( card, counter ); }
		if ( act === 'cancel-form' ) { var f = card.querySelector( '.gs-collab-mform' ); f.hidden = true; f.innerHTML = ''; return; }
		if ( act === 'submit-propose' ) { return submitPropose( card, mid, counter ); }

		if ( busy ) { return; }
		var path, body = { group_id: groupId }, confirmMsg = '';
		switch ( act ) {
			case 'accept':          path = '/collab/match/' + mid + '/contract/accept'; break;
			case 'decline':         path = '/collab/match/' + mid + '/contract/decline'; body.mode = 'declined'; break;
			case 'cancel-proposal': path = '/collab/match/' + mid + '/contract/decline'; body.mode = 'cancelled'; break;
			case 'forfeit':         path = '/collab/match/' + mid + '/contract/forfeit'; confirmMsg = 'Forfeit this collaboration? This ends the contract as a failure and cannot be undone.'; break;
			case 'cancel-request':  path = '/collab/match/' + mid + '/contract/cancel/propose'; break;
			default: return;
		}
		if ( confirmMsg && ! window.confirm( confirmMsg ) ) { return; }
		busy = true;
		setCardStatus( card, 'Working…', false );
		api( path, { method: 'POST', body: body } ).then( function ( res ) {
			busy = false;
			if ( res.ok && res.data && ( res.data.ok || res.status < 300 ) ) {
				loaded = false;
				loadMatches();
			} else {
				setCardStatus( card, ( res.data && res.data.message ) ? res.data.message : 'Action failed. Please try again.', true );
			}
		} );
	}

	function openProposeForm( card, counter ) {
		var f = card.querySelector( '.gs-collab-mform' );
		if ( ! f ) { return; }
		f.hidden = false;
		f.innerHTML =
			'<div class="gs-collab-mform-row">' +
				'<label>Escrow (DGEN)<input type="number" min="0" step="1" value="100" data-prop-credits></label>' +
				'<label>Who funds the escrow?<select data-prop-fund>' +
					'<option value="we">We fund it</option>' +
					'<option value="they">They fund it</option>' +
					'<option value="both">Both fund it</option>' +
				'</select></label>' +
			'</div>' +
			'<p class="gs-collab-mform-note">A proposal doesn’t move any DGEN. Escrow is only funded when the other business accepts.</p>' +
			'<div class="gs-collab-mform-actions">' +
				'<button type="button" class="gs-collab-mbtn gs-collab-mbtn-go" data-act="submit-propose">Send proposal</button>' +
				'<button type="button" class="gs-collab-mbtn" data-act="cancel-form">Cancel</button>' +
			'</div>';
		f.querySelectorAll( '[data-act]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { onAction( b ); } );
		} );
	}

	function submitPropose( card, mid, counter ) {
		if ( busy ) { return; }
		var f       = card.querySelector( '.gs-collab-mform' );
		var credits = parseInt( ( f.querySelector( '[data-prop-credits]' ) || {} ).value || '0', 10 );
		var fund    = ( f.querySelector( '[data-prop-fund]' ) || {} ).value || 'we';
		if ( isNaN( credits ) || credits < 0 ) { credits = 0; }

		var payer, payee, model;
		if ( fund === 'they' )      { payer = counter; payee = groupId; model = 'one_sided'; }
		else if ( fund === 'both' ) { payer = groupId; payee = counter; model = 'both_sided'; }
		else                        { payer = groupId; payee = counter; model = 'one_sided'; }

		busy = true;
		setCardStatus( card, 'Sending proposal…', false );
		api( '/collab/match/' + mid + '/contract/propose', {
			method: 'POST',
			body: { group_id: groupId, payer_group: payer, payee_group: payee, escrow_model: model, credits: credits }
		} ).then( function ( res ) {
			busy = false;
			if ( res.ok && res.data && ( res.data.ok || res.status < 300 ) ) {
				loaded = false;
				loadMatches();
			} else {
				setCardStatus( card, ( res.data && res.data.message ) ? res.data.message : 'Could not send the proposal.', true );
			}
		} );
	}

	// Deep-link support: open on the Matches view if the URL asks for it.
	if ( /[?&#]matches\b/.test( window.location.href ) ) {
		showView( 'matches' );
	}

} )();
