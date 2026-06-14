/*!
 * gend-society — Native gend video embed controller (Phase 30 / Plan 30-03 / VID-04 + VID-05).
 *
 * Vanilla JS IIFE — no framework, no library, no build step (zero dependencies).
 *
 * Loads external_api.js ONLY from https://meet.gend.me/external_api.js
 * (NEVER a third-party host — Pitfall 11 + supply-chain protection + version skew safety).
 * Calls POST /gs/v1/calendar/meetings/{id}/jitsi-jwt (Plan 30-02 contract).
 * Renders a glassmorphic lobby countdown card on 403 too_early (server-supplied
 * starts_at_utc + unlocks_at_utc drive the math — never client clock).
 * Renders a graceful fallback card with Try Again on any other error.
 * Calls JitsiMeetExternalAPI.dispose() on close so no zombie iframes leak.
 *
 * Mounted via delegated click handler on [data-gs-jitsi-join="{meeting_id}"]
 * — any surface (Phase 26 calendar event-detail popover, Phase 29 confirmation
 * page, wallet meeting feed) can render a Join button by adding that attribute.
 *
 * TOOLBAR_BUTTONS explicit allowlist — recording / livestreaming / sharedvideo
 * intentionally absent (v7.0 Out-of-Scope PR-guard).
 */
( function () {
    'use strict';

    if ( typeof window === 'undefined' || typeof document === 'undefined' ) { return; }
    if ( ! window.gsJitsiData || ! window.gsJitsiData.restUrl ) { return; }

    var DOMAIN = ( window.gsJitsiData.domain || 'meet.gend.me' );
    // External API source — fixed to the meet.gend.me ingress; never a third-party host.
    var EXTERNAL_API_URL          = 'https://' + DOMAIN + '/external_api.js';
    // Lobby auto-retry cadence — 30 sec is the floor (Pitfall 17 cache hygiene +
    // Pitfall 9 server load — never less, the UI tick handles the 1 sec feel).
    var LOBBY_POLL_INTERVAL_MS    = 30000;
    var EXTERNAL_API_LOAD_TIMEOUT = 15000;

    // ---- Module state ---------------------------------------------------
    var modalEl          = null;   // outer backdrop overlay
    var shellEl          = null;   // glass card holding iframe / lobby / fallback
    var apiInst          = null;   // JitsiMeetExternalAPI instance
    var pollTimer        = null;   // setInterval handle for too_early auto-retry
    var countdownTimer   = null;   // setInterval handle for 1 sec UI tick
    var escListenerBound = false;
    var currentMeetingId = null;

    // ---- Public mount API (delegated click listener) --------------------
    function mountClickDelegation() {
        document.addEventListener( 'click', function ( ev ) {
            var btn = ev.target && ev.target.closest && ev.target.closest( '[data-gs-jitsi-join]' );
            if ( ! btn ) { return; }
            ev.preventDefault();
            var raw = btn.getAttribute( 'data-gs-jitsi-join' );
            var mid = parseInt( raw, 10 );
            if ( ! mid || mid < 1 ) { return; }
            openEmbed( mid );
        }, false );
    }

    // ---- Embed lifecycle -------------------------------------------------
    function openEmbed( meetingId ) {
        // Idempotent re-open — close any prior instance first so no zombie iframes.
        if ( modalEl ) { closeEmbed(); }
        currentMeetingId = meetingId;
        buildModal();
        // Initial placeholder while the first JWT request flies.
        renderLobby( { code: 'loading', message: 'Connecting to your meeting...' } );
        requestJwt( meetingId );
    }

    function closeEmbed() {
        if ( pollTimer )      { clearInterval( pollTimer ); pollTimer = null; }
        if ( countdownTimer ) { clearInterval( countdownTimer ); countdownTimer = null; }
        if ( apiInst && typeof apiInst.dispose === 'function' ) {
            try { apiInst.dispose(); } catch ( e ) { /* swallow — best-effort tear-down */ }
        }
        apiInst = null;
        if ( escListenerBound ) {
            document.removeEventListener( 'keydown', escListener, true );
            escListenerBound = false;
        }
        if ( modalEl && modalEl.parentNode ) {
            modalEl.parentNode.removeChild( modalEl );
        }
        modalEl          = null;
        shellEl          = null;
        currentMeetingId = null;
    }

    function buildModal() {
        if ( modalEl ) { return; }
        modalEl = document.createElement( 'div' );
        modalEl.className = 'gs-jitsi-modal';
        modalEl.setAttribute( 'role', 'dialog' );
        modalEl.setAttribute( 'aria-modal', 'true' );
        modalEl.setAttribute( 'aria-label', 'Native gend video meeting' );
        modalEl.addEventListener( 'click', function ( ev ) {
            // Backdrop click closes. Iframe clicks don't bubble through the iframe
            // boundary so this only fires on the backdrop layer itself.
            if ( ev.target === modalEl ) { closeEmbed(); }
        } );

        shellEl = document.createElement( 'div' );
        shellEl.className = 'gs-jitsi-shell';

        var closeBtn = document.createElement( 'button' );
        closeBtn.type      = 'button';
        closeBtn.className = 'gs-jitsi-close';
        closeBtn.setAttribute( 'aria-label', 'Close meeting' );
        closeBtn.textContent = '×'; // multiplication sign — visible × across all fonts
        closeBtn.addEventListener( 'click', closeEmbed );
        shellEl.appendChild( closeBtn );

        var body = document.createElement( 'div' );
        body.className = 'gs-jitsi-body';
        shellEl.appendChild( body );

        modalEl.appendChild( shellEl );
        document.body.appendChild( modalEl );

        document.addEventListener( 'keydown', escListener, true );
        escListenerBound = true;
    }

    function escListener( ev ) {
        if ( ev.key === 'Escape' && modalEl ) {
            ev.stopPropagation();
            closeEmbed();
        }
    }

    function getBodyEl() {
        if ( ! shellEl ) { return null; }
        return shellEl.querySelector( '.gs-jitsi-body' );
    }

    // ---- JWT fetch (Plan 30-02 contract) --------------------------------
    function requestJwt( meetingId ) {
        var url = window.gsJitsiData.restUrl.replace( /\/$/, '' )
            + '/calendar/meetings/' + encodeURIComponent( meetingId ) + '/jitsi-jwt';
        fetch( url, {
            method:      'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   ( window.gsJitsiData.nonce || '' )
            }
        } )
        .then( function ( res ) {
            return res.json().then(
                function ( body ) { return { status: res.status, body: body }; },
                function ()       { return { status: res.status, body: null  }; }
            );
        } )
        .then( function ( result ) {
            if ( result.status === 200 && result.body && result.body.jwt && result.body.room ) {
                renderEmbed( result.body );
                return;
            }
            if ( result.status === 403 && result.body && result.body.code === 'too_early' ) {
                var data = ( result.body.data || {} );
                renderLobby( {
                    code:           'too_early',
                    message:        result.body.message || 'This meeting starts soon.',
                    starts_at_utc:  data.starts_at_utc,
                    unlocks_at_utc: data.unlocks_at_utc
                } );
                schedulePoll();   // auto-retry every 30 sec
                return;
            }
            // Anything else — render the fallback (catalogue: 401, 403 forbidden / too_late,
            // 404 not_found, 410 cancelled, 422 not_a_*, 500 jwt_secret_missing / internal).
            renderFallback(
                result.body || { code: 'unknown', message: 'Unknown error.' },
                result.status
            );
        } )
        .catch( function ( err ) {
            renderFallback(
                { code: 'network', message: ( err && err.message ) || 'Network error contacting meeting server.' },
                0
            );
        } );
    }

    function schedulePoll() {
        if ( pollTimer ) { clearInterval( pollTimer ); }
        pollTimer = setInterval( function () {
            if ( ! currentMeetingId ) {
                clearInterval( pollTimer );
                pollTimer = null;
                return;
            }
            requestJwt( currentMeetingId );
        }, LOBBY_POLL_INTERVAL_MS );
    }

    // ---- Render: lobby (initial + too_early) ----------------------------
    function renderLobby( state ) {
        var body = getBodyEl();
        if ( ! body ) { return; }
        body.innerHTML  = '';
        body.className  = 'gs-jitsi-body gs-jitsi-lobby';

        var heading = document.createElement( 'h2' );
        heading.className = 'gs-jitsi-lobby-heading';
        heading.textContent = ( state.code === 'too_early' )
            ? 'Meeting starts soon'
            : 'Connecting...';
        body.appendChild( heading );

        if ( state.starts_at_utc ) {
            // Server gives 'YYYY-MM-DD HH:MM:SS' (Plan 30-02). Convert to a real UTC
            // epoch by swapping space→T and appending Z. NEVER trust the client
            // clock for the actual time gate — the server decides that with the
            // 30 sec auto-retry — we only render a cosmetic countdown.
            var startsTs = Date.parse(
                String( state.starts_at_utc ).replace( ' ', 'T' ) + 'Z'
            );
            if ( ! isNaN( startsTs ) ) {
                var countdown = document.createElement( 'div' );
                countdown.className = 'gs-jitsi-lobby-countdown';
                countdown.setAttribute( 'data-starts-ts', String( startsTs ) );
                body.appendChild( countdown );
                renderCountdown( countdown, startsTs );
                if ( countdownTimer ) { clearInterval( countdownTimer ); }
                countdownTimer = setInterval( function () {
                    if ( ! document.body.contains( countdown ) ) {
                        clearInterval( countdownTimer );
                        countdownTimer = null;
                        return;
                    }
                    renderCountdown( countdown, startsTs );
                }, 1000 );
            }
        }

        var msg = document.createElement( 'p' );
        msg.className = 'gs-jitsi-lobby-msg';
        msg.textContent = state.message || '';
        body.appendChild( msg );

        if ( state.code === 'too_early' ) {
            var hint = document.createElement( 'p' );
            hint.className   = 'gs-jitsi-lobby-hint';
            hint.textContent = 'You will be joined automatically when the meeting starts.';
            body.appendChild( hint );
        }
    }

    function renderCountdown( el, startsTs ) {
        var deltaSec = Math.max( 0, Math.round( ( startsTs - Date.now() ) / 1000 ) );
        var h = Math.floor( deltaSec / 3600 );
        var m = Math.floor( ( deltaSec % 3600 ) / 60 );
        var s = deltaSec % 60;
        var pad = function ( n ) { return ( n < 10 ) ? ( '0' + n ) : String( n ); };
        el.textContent = ( ( h > 0 ) ? ( h + 'h ' ) : '' ) + pad( m ) + 'm ' + pad( s ) + 's';
    }

    // ---- Render: fallback (any error) -----------------------------------
    function renderFallback( errBody, status ) {
        var body = getBodyEl();
        if ( ! body ) { return; }
        if ( pollTimer )      { clearInterval( pollTimer ); pollTimer = null; }
        if ( countdownTimer ) { clearInterval( countdownTimer ); countdownTimer = null; }
        body.innerHTML = '';
        body.className = 'gs-jitsi-body gs-jitsi-fallback';

        var heading = document.createElement( 'h2' );
        heading.textContent = 'Unable to join meeting';
        body.appendChild( heading );

        var p = document.createElement( 'p' );
        p.textContent = ( errBody && errBody.message ) || 'An unknown error occurred.';
        body.appendChild( p );

        var codeRow = document.createElement( 'p' );
        codeRow.className   = 'gs-jitsi-fallback-code';
        codeRow.textContent = 'Error: '
            + ( ( errBody && errBody.code ) || 'unknown' )
            + ' (HTTP ' + status + ')';
        body.appendChild( codeRow );

        var retry = document.createElement( 'button' );
        retry.type        = 'button';
        retry.className   = 'gs-jitsi-retry';
        retry.textContent = 'Try Again';
        retry.addEventListener( 'click', function () {
            if ( currentMeetingId ) {
                renderLobby( { code: 'loading', message: 'Retrying...' } );
                requestJwt( currentMeetingId );
            }
        } );
        body.appendChild( retry );
    }

    // ---- Render: embed (Jitsi iframe) -----------------------------------
    function renderEmbed( payload ) {
        if ( pollTimer )      { clearInterval( pollTimer ); pollTimer = null; }
        if ( countdownTimer ) { clearInterval( countdownTimer ); countdownTimer = null; }
        var body = getBodyEl();
        if ( ! body ) { return; }
        body.innerHTML = '';
        body.className = 'gs-jitsi-body gs-jitsi-iframe-host';

        // Load external_api.js from meet.gend.me ONLY — never a third-party host.
        // Reasons (Pitfall 11 + CONTEXT.md): (1) supply-chain — a third-party
        // origin compromise would inject malware into every gend.me member's
        // session; (2) version skew — the external_api.js JS contract must match
        // the meet.gend.me server version (stable-11031 from Plan 30-01); (3) CSP —
        // meet.gend.me allows itself; a third-party origin would need a separate allow-rule.
        loadExternalApi( function ( loaded ) {
            if ( ! loaded ) {
                renderFallback(
                    {
                        code:    'external_api_load_failed',
                        message: 'Could not load video runtime from ' + EXTERNAL_API_URL + '. Check your connection and try again.'
                    },
                    0
                );
                return;
            }
            if ( typeof window.JitsiMeetExternalAPI !== 'function' ) {
                renderFallback(
                    { code: 'jitsi_api_missing', message: 'Video runtime loaded but JitsiMeetExternalAPI is unavailable.' },
                    0
                );
                return;
            }
            try {
                apiInst = new window.JitsiMeetExternalAPI( payload.domain || DOMAIN, {
                    roomName:   payload.room,   // exact Plan 30-02 resolved value (meta.jitsi_room or gsmeet-{id})
                    jwt:        payload.jwt,
                    parentNode: body,
                    width:      '100%',
                    height:     '100%',
                    configOverwrite: {
                        // Skip Jitsi's prejoin page — we already have our own glass shell.
                        prejoinPageEnabled:           false,
                        // Never deep-link to the native Jitsi mobile app from inside our embed.
                        disableDeepLinking:           true,
                        // Suppress the verbal "recording is on" notification (recording is
                        // disabled at the infra layer per Plan 30-01; this is belt-and-braces).
                        disableRecordAudioNotification: true,
                        enableNoisyMicDetection:      true,
                        disableInviteFunctions:       true
                    },
                    interfaceConfigOverwrite: {
                        // VID-04 deliverables: mic, camera, screenshare (desktop), chat.
                        // Recording / live-streaming / shared-video buttons intentionally
                        // omitted (v7.0 Out-of-Scope PR-guard — see allowlist below).
                        TOOLBAR_BUTTONS: [
                            'microphone', 'camera', 'desktop', 'chat',
                            'tileview', 'hangup', 'raisehand', 'fullscreen', 'settings'
                        ]
                    }
                } );
                // User-driven exits — both fire dispose+remove via closeEmbed.
                apiInst.addEventListener( 'readyToClose',           closeEmbed );
                apiInst.addEventListener( 'videoConferenceLeft',    closeEmbed );
            } catch ( e ) {
                renderFallback(
                    {
                        code:    'jitsi_instantiate_failed',
                        message: ( e && e.message ) || 'Failed to start the video runtime.'
                    },
                    0
                );
            }
        } );
    }

    // ---- external_api.js loader (idempotent) ----------------------------
    function loadExternalApi( cb ) {
        // Already loaded — fire immediately.
        if ( typeof window.JitsiMeetExternalAPI === 'function' ) {
            cb( true );
            return;
        }
        // Already in flight — piggy-back on the existing tag.
        var existing = document.querySelector( 'script[data-gs-jitsi-external-api="1"]' );
        if ( existing ) {
            existing.addEventListener( 'load',  function () { cb( true );  }, { once: true } );
            existing.addEventListener( 'error', function () { cb( false ); }, { once: true } );
            return;
        }
        var s = document.createElement( 'script' );
        s.src         = EXTERNAL_API_URL;
        s.async       = true;
        s.crossOrigin = 'anonymous';
        s.setAttribute( 'data-gs-jitsi-external-api', '1' );
        var done    = false;
        var timeout = setTimeout( function () {
            if ( done ) { return; }
            done = true;
            cb( false );
        }, EXTERNAL_API_LOAD_TIMEOUT );
        s.onload  = function () {
            if ( done ) { return; }
            done = true;
            clearTimeout( timeout );
            cb( true );
        };
        s.onerror = function () {
            if ( done ) { return; }
            done = true;
            clearTimeout( timeout );
            cb( false );
        };
        document.head.appendChild( s );
    }

    // ---- Boot -----------------------------------------------------------
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', mountClickDelegation, { once: true } );
    } else {
        mountClickDelegation();
    }

    // ---- SPA-safe teardown on page unload --------------------------------
    window.addEventListener( 'beforeunload', function () {
        if ( apiInst || modalEl ) { closeEmbed(); }
    } );

} )();
