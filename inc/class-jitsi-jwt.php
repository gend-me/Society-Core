<?php
/**
 * Hand-rolled HS256 JWT minter for Jitsi prosody mod_token_verification.
 *
 * NO third-party JWT library is permitted — hash_hmac directly.
 * Secret resolved ONLY via wp-config.php define -> getenv('GS_JITSI_JWT_SECRET').
 *
 * Cross-phase invariants honored:
 *   - Hand-rolled HS256 via hash_hmac('sha256', ..., $secret, true) — NO third-party JWT lib (Pitfall 10).
 *   - Secret read ONLY from wp-config.php constant + getenv() shim — NEVER from the options table,
 *     NEVER hardcoded (mirrors gend-hub-credentials Secret-shim pattern per
 *     project_hub_credentials_secret memory).
 *   - Room name AUTHORED upstream by Phase 29-02 booking allocator and STORED on the meeting row
 *     as meeting_meta_json.jitsi_room ('gend-meet-{host}-{16char}'); Plan 30-02 binds the JWT
 *     `room` claim to that exact value via resolve_room() (legacy fallback: gsmeet-{id}).
 *   - moderator claim is the STRING 'true'/'false' (prosody mod_auth_token expects string form).
 *
 * Phase 30 / Plan 30-02 / VID-02.
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Gend_GS_Jitsi_JWT {

    const ISS                  = 'gend-society';
    const AUD                  = 'jitsi';
    const SUB                  = 'meet.gend.me';
    const ROOM_PREFIX          = 'gsmeet-';   // LEGACY FALLBACK ONLY — used by resolve_room() when meta.jitsi_room is empty.
    const NBF_LEAD_GRACE_SEC   = 300;         // 5 min before start, prosody admits.
    const EXP_TAIL_GRACE_SEC   = 1800;        // 30 min after end, prosody still admits.

    /**
     * Mint an HS256 JWT for a meeting.
     *
     * @param string $room          RESOLVED room name (caller passes meta.jitsi_room, or resolve_room() output).
     * @param int    $meeting_id    PK of wp_gs_member_meetings (kept for context/legacy fallback only — NOT the room).
     * @param int    $member_id     The CALLER (WP user) — becomes context.user.id.
     * @param string $display_name  User display name for context.user.name (fallback "user-{id}" upstream).
     * @param bool   $is_moderator  true iff caller is host (member_id) of the meeting.
     * @param int    $nbf_ts        Server-clock not-before (Unix epoch sec).
     * @param int    $exp_ts        Server-clock expiry (Unix epoch sec).
     * @return string|WP_Error      Compact JWT (header.payload.sig) OR WP_Error('jwt_secret_missing', 500).
     */
    public static function mint( $room, $meeting_id, $member_id, $display_name, $is_moderator, $nbf_ts, $exp_ts ) {
        $secret = self::get_secret();
        if ( is_wp_error( $secret ) ) {
            return $secret;
        }

        $header  = array(
            'alg' => 'HS256',
            'typ' => 'JWT',
        );
        $payload = array(
            'iss'     => self::ISS,
            'aud'     => self::AUD,
            'sub'     => self::SUB,
            'room'    => (string) $room,   // RESOLVED meta.jitsi_room (fallback gsmeet-{id}) — see resolve_room().
            'nbf'     => (int) $nbf_ts,
            'exp'     => (int) $exp_ts,
            'iat'     => time(),
            'context' => array(
                'user' => array(
                    'id'        => (string) (int) $member_id,
                    'name'      => (string) $display_name,
                    'moderator' => $is_moderator ? 'true' : 'false',   // STRING form — Jitsi prosody expects 'true'/'false', not PHP booleans.
                ),
            ),
        );

        $b64h          = self::b64url_encode( wp_json_encode( $header ) );
        $b64p          = self::b64url_encode( wp_json_encode( $payload ) );
        $signing_input = $b64h . '.' . $b64p;

        // hash_hmac with raw_output=true → 32 raw bytes → base64url encode.
        // DO NOT base64-encode a hex digest — that would produce the wrong signature and prosody would reject.
        $sig  = hash_hmac( 'sha256', $signing_input, $secret, true );
        $b64s = self::b64url_encode( $sig );

        return $signing_input . '.' . $b64s;
    }

    /**
     * Resolve the JWT signing secret. ONLY via wp-config.php constant (set by getenv() shim).
     *
     * Resolution order:
     *   1. defined('GS_JITSI_JWT_SECRET') AND non-empty → use the constant value.
     *   2. getenv('GS_JITSI_JWT_SECRET') non-empty → use the env value (covers deploys where
     *      the wp-config.php shim hasn't landed yet but the K8s Secret is mounted via envFrom).
     *   3. Otherwise → WP_Error('jwt_secret_missing', 500) — fail-closed. NEVER silently mint
     *      with an empty secret (would produce a deterministic-signature JWT anyone can replicate).
     *
     * NEVER reads the WordPress options table. NEVER reads $wpdb. NEVER hardcodes a fallback value.
     *
     * @return string|WP_Error 64-hex secret OR WP_Error('jwt_secret_missing', 500).
     */
    public static function get_secret() {
        // Constant set in wp-config.php via:
        //   define('GS_JITSI_JWT_SECRET', getenv('GS_JITSI_JWT_SECRET') ?: '');
        if ( defined( 'GS_JITSI_JWT_SECRET' ) && '' !== GS_JITSI_JWT_SECRET ) {
            return (string) GS_JITSI_JWT_SECRET;
        }
        // Fallback: read getenv directly in case wp-config.php shim was not yet added.
        $env = getenv( 'GS_JITSI_JWT_SECRET' );
        if ( is_string( $env ) && '' !== $env ) {
            return $env;
        }
        return new WP_Error(
            'jwt_secret_missing',
            'GS_JITSI_JWT_SECRET is not defined. Operator must add wp-config.php shim and provision the gend-jitsi-credentials K8s Secret per Plan 30-01 runbook.',
            array( 'status' => 500 )
        );
    }

    /**
     * Resolve the room name for a meeting.
     *
     * RETURNS meta.jitsi_room VERBATIM when present — this is the value Phase 29-02's booking
     * allocator authored ('gend-meet-{host}-{16char}') and Phase 29-03 ALREADY emailed guests
     * as https://meet.gend.me/{jitsi_room}. The JWT `room` claim MUST equal that value or
     * prosody's secure-domain auth refuses the join.
     *
     * Falls back to ROOM_PREFIX . {meeting_id} ('gsmeet-{id}') ONLY for legacy rows with
     * empty/missing jitsi_room (pre-29-02 backfill).
     *
     * @param array|null $meta        Decoded meeting_meta_json (may be null/array/non-array).
     * @param int        $meeting_id  PK for the legacy fallback.
     * @return string                 Resolved room name.
     */
    public static function resolve_room( $meta, $meeting_id ) {
        if ( is_array( $meta ) && ! empty( $meta['jitsi_room'] ) && is_string( $meta['jitsi_room'] ) ) {
            return $meta['jitsi_room'];   // DEPLOYED Phase 29 contract — guests hold this exact value.
        }
        return self::ROOM_PREFIX . (int) $meeting_id;   // Legacy-row fallback only.
    }

    /**
     * Base64URL encode per RFC 7515 §2 — strip padding, replace `+ /` with `- _`.
     *
     * @param string $bytes Raw bytes to encode (header JSON, payload JSON, or raw HMAC digest).
     * @return string       Base64URL string (no `=` padding).
     */
    public static function b64url_encode( $bytes ) {
        return rtrim( strtr( base64_encode( (string) $bytes ), '+/', '-_' ), '=' );
    }

    /**
     * Helper for Plan 30-04 UAT — re-derive the signature for a header.payload pair and
     * compare via hash_equals (constant-time) to detect tampered tokens.
     *
     * Used by the cross-cutting UAT script to assert that flipping a single byte in a
     * payload invalidates the JWT (no signature-stripping attack vector).
     *
     * @param string $compact_jwt A complete `header.payload.sig` compact JWT string.
     * @return bool               true iff the signature matches a freshly-derived HMAC over header.payload.
     */
    public static function verify_signature( $compact_jwt ) {
        $parts = explode( '.', (string) $compact_jwt );
        if ( count( $parts ) !== 3 ) {
            return false;
        }
        $secret = self::get_secret();
        if ( is_wp_error( $secret ) ) {
            return false;
        }
        $signing_input = $parts[0] . '.' . $parts[1];
        $expected      = self::b64url_encode( hash_hmac( 'sha256', $signing_input, $secret, true ) );
        return hash_equals( $expected, $parts[2] );
    }
}
