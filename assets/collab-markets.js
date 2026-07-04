/**
 * gend-society — GenD Match Collaborations controller (Phase 87-03, v12.0, STAKE-02/05).
 *
 * Vanilla JS, no framework, no build step (mirrors the Phase-82 collab-swipe.js house style).
 * Drives the DOM contract rendered by member-markets.php gs_markets_profile_screen_content():
 *
 *   .gs-collab-markets-root                     wrapper
 *   #gs-collab-markets[data-loading]            markets list mount (live odds + buy/sell + inline position)
 *   #gs-collab-portfolio                        private portfolio panel mount
 *   #gs-collab-pl-chart <canvas>                Chart.js P/L (shared 'chartjs' handle, window.Chart)
 *
 * Contract: window.GSCollabMarkets = { root:'…/wp-json/gs/v1', nonce, uid }.
 * Every fetch sends header 'X-WP-Nonce': GSCollabMarkets.nonce and credentials:'same-origin'
 * (gend.me 401s all /wp-json for logged-out users).
 *
 * REST contract:
 *   GET  {root}/portfolio                        -> { positions:[ {market_id,match_id,outcome,shares,
 *                                                    cost_dgen,avg_cost,p_yes,p_no,mark_dgen,
 *                                                    unrealized_pl,realized_pl,rake_bps,state} ], totals }
 *                                                    PRIVATE — only ever the caller's own rows (GATE-02).
 *   GET  {root}/market/{id}                       -> { market_id,state,resolve_by,implied_probability:{yes,no} }
 *   GET  {root}/market/{id}/quote?outcome&delta   -> { cost_dgen, p_yes, p_no }  (advisory, pre-stake)
 *   POST {root}/market/{id}/bet  { outcome,direction:'buy'|'sell',amount, idempotency_key }
 *                                                 -> 200 { cost_dgen|refund_dgen, q_yes,q_no, escrow_dgen,
 *                                                    p_yes,p_no, position, rake_bps, disclosure }
 *                                                    (402/422/403/409/500 -> WP_Error message)
 *
 * STAKE-05 rake disclosure is rendered near the buy/sell controls (always visible BEFORE a stake
 * is confirmed): "Platform fee: {rake_bps/100}% — skimmed from the losing pool at resolution
 * (not charged now)."
 *
 * Neutral copy only (no bet/odds/wager/payout). All server strings are escaped before injecting.
 * Because Phase 86 ships no list-all-markets route, the member's known markets are derived from the
 * PRIVATE portfolio payload (the markets they already hold a position in), then re-fetched for live
 * odds — plus any open market ids the screen embeds via data-gs-market-ids on the root.
 *
 * @package gend-society
 */
( function () {
	'use strict';

	var CFG = window.GSCollabMarkets;
	if ( ! CFG || ! CFG.root ) {
		return;
	}

	var listEl  = document.getElementById( 'gs-collab-markets' );
	var pfEl    = document.getElementById( 'gs-collab-portfolio' );
	var chartEl = document.getElementById( 'gs-collab-pl-chart' );
	if ( ! listEl && ! pfEl ) {
		return;
	}

	var root  = String( CFG.root ).replace( /\/+$/, '' );
	var nonce = CFG.nonce || '';
	var chartInstance = null;

	// --- helpers ---------------------------------------------------------------

	function esc( v ) {
		var d = document.createElement( 'div' );
		d.textContent = ( v === null || v === undefined ) ? '' : String( v );
		return d.innerHTML;
	}

	function headers( withJson ) {
		var h = { 'X-WP-Nonce': nonce };
		if ( withJson ) {
			h['Content-Type'] = 'application/json';
		}
		return h;
	}

	function uuid() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		// Fallback UUID-v4-ish (still per-submit unique so a retry replays, never double-debits).
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0;
			var v = c === 'x' ? r : ( ( r & 0x3 ) | 0x8 );
			return v.toString( 16 );
		} );
	}

	function getJson( url ) {
		return fetch( url, { headers: headers( false ), credentials: 'same-origin' } )
			.then( function ( res ) {
				return res.json().then( function ( body ) {
					return { ok: res.ok, status: res.status, body: body };
				} );
			} );
	}

	function pct( p ) {
		// implied-probability fixed-point string (0..1) -> percent, 1 dp.
		var n = parseFloat( p );
		if ( isNaN( n ) ) { return '0.0'; }
		return ( n * 100 ).toFixed( 1 );
	}

	function rakeLine( rakeBps ) {
		var bps = parseInt( rakeBps, 10 );
		if ( isNaN( bps ) || bps < 0 ) { bps = 0; }
		var rate = ( bps / 100 ).toFixed( 2 );
		return 'Platform fee: ' + esc( rate ) + '% — skimmed from the losing pool at resolution (not charged now).';
	}

	// --- markets list ----------------------------------------------------------

	// Derive the market ids to render: the member's held markets (from the private portfolio) plus
	// any open ids the screen embeds on the root. Phase 86 has no list-all-markets route.
	function marketIds( positions ) {
		var ids = {};
		( positions || [] ).forEach( function ( p ) {
			if ( p && p.market_id ) { ids[ parseInt( p.market_id, 10 ) ] = true; }
		} );
		var rootEl = document.querySelector( '.gs-collab-markets-root' );
		var embedded = rootEl ? ( rootEl.getAttribute( 'data-gs-market-ids' ) || '' ) : '';
		embedded.split( ',' ).forEach( function ( s ) {
			var id = parseInt( s, 10 );
			if ( id > 0 ) { ids[ id ] = true; }
		} );
		return Object.keys( ids ).map( function ( s ) { return parseInt( s, 10 ); } );
	}

	function positionFor( positions, marketId, outcome ) {
		var found = null;
		( positions || [] ).forEach( function ( p ) {
			if ( parseInt( p.market_id, 10 ) === marketId && p.outcome === outcome ) { found = p; }
		} );
		return found;
	}

	function renderMarketCard( market, positions ) {
		var id = parseInt( market.market_id, 10 );
		var prob = market.implied_probability || { yes: '0', no: '0' };
		var posYes = positionFor( positions, id, 'yes' );
		var posNo  = positionFor( positions, id, 'no' );

		var card = document.createElement( 'div' );
		card.className = 'gs-collab-market-card';
		card.setAttribute( 'data-market-id', String( id ) );

		var yourPos = '';
		if ( posYes ) { yourPos += '<span class="gs-collab-inline-pos">YES ' + esc( posYes.shares ) + ' @ ' + esc( posYes.avg_cost ) + '</span>'; }
		if ( posNo )  { yourPos += '<span class="gs-collab-inline-pos">NO ' + esc( posNo.shares ) + ' @ ' + esc( posNo.avg_cost ) + '</span>'; }

		card.innerHTML =
			'<div class="gs-collab-market-head">'
			+ '<span class="gs-collab-market-id">Collaboration #' + esc( id ) + '</span>'
			+ '<span class="gs-collab-market-state">' + esc( market.state || '' ) + '</span>'
			+ '</div>'
			+ '<div class="gs-collab-odds">'
			+ '<span class="gs-collab-odd gs-collab-odd-yes">YES ' + esc( pct( prob.yes ) ) + '%</span>'
			+ '<span class="gs-collab-odd gs-collab-odd-no">NO ' + esc( pct( prob.no ) ) + '%</span>'
			+ '</div>'
			+ ( yourPos ? '<div class="gs-collab-your-pos">' + yourPos + '</div>' : '' )
			+ '<div class="gs-collab-controls">'
			+ '<select class="gs-collab-outcome"><option value="yes">YES</option><option value="no">NO</option></select>'
			+ '<select class="gs-collab-direction"><option value="buy">Buy</option><option value="sell">Sell</option></select>'
			+ '<input class="gs-collab-amount" type="number" min="1" step="1" placeholder="shares" />'
			+ '<button class="gs-collab-quote-btn" type="button">Preview</button>'
			+ '<button class="gs-collab-stake-btn" type="button">Confirm</button>'
			+ '</div>'
			+ '<div class="gs-collab-quote-out" role="status"></div>'
			+ '<div class="gs-collab-rake">' + rakeLine( market.rake_bps ) + '</div>'
			+ '<div class="gs-collab-stake-status" role="status"></div>';

		wireCard( card, id );
		return card;
	}

	function wireCard( card, marketId ) {
		var outcomeEl = card.querySelector( '.gs-collab-outcome' );
		var dirEl     = card.querySelector( '.gs-collab-direction' );
		var amtEl     = card.querySelector( '.gs-collab-amount' );
		var quoteOut  = card.querySelector( '.gs-collab-quote-out' );
		var statusOut = card.querySelector( '.gs-collab-stake-status' );

		card.querySelector( '.gs-collab-quote-btn' ).addEventListener( 'click', function () {
			var amt = ( amtEl.value || '' ).trim();
			if ( ! /^[0-9]+$/.test( amt ) || amt === '0' ) {
				quoteOut.textContent = 'Enter a whole number of shares.';
				return;
			}
			quoteOut.textContent = 'Previewing…';
			var url = root + '/market/' + marketId + '/quote?outcome=' + encodeURIComponent( outcomeEl.value )
				+ '&delta=' + encodeURIComponent( amt );
			getJson( url ).then( function ( r ) {
				if ( ! r.ok ) {
					quoteOut.textContent = ( r.body && r.body.message ) ? r.body.message : 'Preview unavailable.';
					return;
				}
				quoteOut.innerHTML = 'Cost: ' + esc( r.body.cost_dgen ) + ' DGEN · after: YES '
					+ esc( pct( r.body.p_yes ) ) + '% / NO ' + esc( pct( r.body.p_no ) ) + '%';
			} ).catch( function () { quoteOut.textContent = 'Preview failed.'; } );
		} );

		card.querySelector( '.gs-collab-stake-btn' ).addEventListener( 'click', function () {
			var amt = ( amtEl.value || '' ).trim();
			if ( ! /^[0-9]+$/.test( amt ) || amt === '0' ) {
				statusOut.textContent = 'Enter a whole number of shares.';
				return;
			}
			statusOut.textContent = 'Submitting…';
			// Fresh per-submit idempotency key so a retried submit REPLAYS (never a double-debit).
			var payload = {
				outcome: outcomeEl.value,
				direction: dirEl.value,
				amount: amt,
				idempotency_key: uuid()
			};
			fetch( root + '/market/' + marketId + '/bet', {
				method: 'POST',
				headers: headers( true ),
				credentials: 'same-origin',
				body: JSON.stringify( payload )
			} ).then( function ( res ) {
				return res.json().then( function ( body ) {
					return { ok: res.ok, status: res.status, body: body };
				} );
			} ).then( function ( r ) {
				if ( ! r.ok ) {
					statusOut.textContent = ( r.body && r.body.message ) ? r.body.message : ( 'Stake failed (' + r.status + ').' );
					return;
				}
				var moved = ( r.body.refund_dgen !== undefined && r.body.refund_dgen !== null )
					? ( 'Refunded ' + esc( r.body.refund_dgen ) + ' DGEN' )
					: ( 'Staked ' + esc( r.body.cost_dgen ) + ' DGEN' );
				statusOut.textContent = moved + '. Updating…';
				// Refresh odds + the private portfolio after a successful trade.
				loadAll();
			} ).catch( function () { statusOut.textContent = 'Stake failed.'; } );
		} );
	}

	// --- private portfolio + P/L chart -----------------------------------------

	function renderPortfolio( data ) {
		if ( ! pfEl ) { return; }
		var positions = ( data && data.positions ) || [];
		var totals = ( data && data.totals ) || {};

		var rows = positions.map( function ( p ) {
			return '<tr>'
				+ '<td>#' + esc( p.market_id ) + '</td>'
				+ '<td>' + esc( ( p.outcome || '' ).toUpperCase() ) + '</td>'
				+ '<td>' + esc( p.shares ) + '</td>'
				+ '<td>' + esc( p.avg_cost ) + '</td>'
				+ '<td>' + esc( p.mark_dgen ) + '</td>'
				+ '<td>' + esc( p.unrealized_pl ) + '</td>'
				+ '<td>' + esc( p.realized_pl ) + '</td>'
				+ '</tr>';
		} ).join( '' );

		var canvas = '<canvas id="gs-collab-pl-chart" class="gs-collab-pl-chart" height="120"></canvas>';
		pfEl.innerHTML =
			canvas
			+ '<h3 class="gs-collab-pf-title">Your positions</h3>'
			+ ( positions.length
				? ( '<table class="gs-collab-pf-table"><thead><tr>'
					+ '<th>Market</th><th>Side</th><th>Shares</th><th>Avg cost</th>'
					+ '<th>Mark</th><th>Unrealized</th><th>Realized</th></tr></thead><tbody>'
					+ rows + '</tbody></table>' )
				: '<p class="gs-collab-empty">No positions yet.</p>' )
			+ '<div class="gs-collab-pf-totals">'
			+ 'Cost: ' + esc( totals.cost_dgen || '0' ) + ' · Mark: ' + esc( totals.mark_dgen || '0' )
			+ ' · Unrealized: ' + esc( totals.unrealized_pl || '0' )
			+ ' · Realized: ' + esc( totals.realized_pl || '0' )
			+ '</div>';

		renderChart( positions );
	}

	function renderChart( positions ) {
		var cvs = document.getElementById( 'gs-collab-pl-chart' );
		if ( ! cvs || typeof window.Chart !== 'function' ) { return; }
		if ( chartInstance ) { chartInstance.destroy(); chartInstance = null; }

		var labels = positions.map( function ( p ) { return '#' + p.market_id + ' ' + ( p.outcome || '' ).toUpperCase(); } );
		var vals = positions.map( function ( p ) { return parseFloat( p.unrealized_pl ) || 0; } );

		chartInstance = new window.Chart( cvs.getContext( '2d' ), {
			type: 'line',
			data: {
				labels: labels,
				datasets: [ {
					label: 'Unrealized P/L (DGEN)',
					data: vals,
					borderColor: 'rgba(120,180,255,0.9)',
					backgroundColor: 'rgba(120,180,255,0.15)',
					fill: true,
					tension: 0.25
				} ]
			},
			options: {
				responsive: true,
				plugins: { legend: { labels: { color: '#dfe6f0' } } },
				scales: {
					x: { ticks: { color: '#aab4c4' }, grid: { color: 'rgba(255,255,255,0.06)' } },
					y: { ticks: { color: '#aab4c4' }, grid: { color: 'rgba(255,255,255,0.06)' } }
				}
			}
		} );
	}

	// --- orchestration ---------------------------------------------------------

	function loadAll() {
		getJson( root + '/portfolio' ).then( function ( r ) {
			var data = r.ok ? r.body : { positions: [], totals: {} };
			var positions = ( data && data.positions ) || [];
			renderPortfolio( data );

			var ids = marketIds( positions );
			if ( listEl ) {
				listEl.setAttribute( 'data-loading', '0' );
				if ( ! ids.length ) {
					listEl.innerHTML = '<p class="gs-collab-empty">No open collaborations to show.</p>';
					return;
				}
				listEl.innerHTML = '';
				ids.forEach( function ( id ) {
					getJson( root + '/market/' + id ).then( function ( mr ) {
						if ( ! mr.ok || ! mr.body ) { return; }
						listEl.appendChild( renderMarketCard( mr.body, positions ) );
					} ).catch( function () {} );
				} );
			}
		} ).catch( function () {
			if ( listEl ) {
				listEl.setAttribute( 'data-loading', '0' );
				listEl.innerHTML = '<p class="gs-collab-empty">Could not load collaborations.</p>';
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', loadAll );
	} else {
		loadAll();
	}
} )();
