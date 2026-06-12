<?php
/**
 * Web Shell — Claude chat (Phase 25).
 *
 * SSE-streaming proxy from the Web Shell SPA → Anthropic Messages API.
 * Per-user API keys stored encrypted under GS_GCLOUD_CREDS_KEY (re-uses
 * the same AES-256-GCM key as the gcloud escrow — one key to rotate).
 *
 * Endpoints:
 *   GET    /gs/v1/web-shell/chat/status   → { connected, has_key, default_model }
 *   POST   /gs/v1/web-shell/chat/key      → encrypted save  { api_key }
 *   DELETE /gs/v1/web-shell/chat/key      → wipe the key
 *   POST   /gs/v1/web-shell/chat/stream   → SSE: forwards Anthropic's
 *                                            text/event-stream straight
 *                                            through to the browser
 *
 * Tool use is NOT wired in this phase — model and messages are passed
 * through, the response is the raw Anthropic stream. The next phase
 * (25b) bolts on a server-side tool loop with read_file + shell_exec
 * once the PTY service is reachable for shell_exec.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'gs_chat_register_rest' ) ) {

    function gs_chat_register_rest() {
        register_rest_route( 'gs/v1', '/web-shell/chat/status', array(
            'methods'             => 'GET',
            'callback'            => 'gs_chat_rest_status',
            'permission_callback' => function () { return is_user_logged_in(); },
        ) );
        register_rest_route( 'gs/v1', '/web-shell/chat/key', array(
            array(
                'methods'             => 'POST',
                'callback'            => 'gs_chat_rest_key_save',
                'permission_callback' => function () { return is_user_logged_in(); },
                'args' => array(
                    'api_key' => array( 'required' => true, 'type' => 'string' ),
                    'model'   => array( 'required' => false, 'type' => 'string' ),
                ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => 'gs_chat_rest_key_clear',
                'permission_callback' => function () { return is_user_logged_in(); },
            ),
        ) );
        register_rest_route( 'gs/v1', '/web-shell/chat/stream', array(
            'methods'             => 'POST',
            'callback'            => 'gs_chat_rest_stream',
            'permission_callback' => function () { return is_user_logged_in(); },
            'args' => array(
                'model'      => array( 'required' => false, 'type' => 'string' ),
                'system'     => array( 'required' => false, 'type' => 'string' ),
                'messages'   => array( 'required' => true,  'type' => 'array'  ),
                'max_tokens' => array( 'required' => false, 'type' => 'integer' ),
                'tools'      => array( 'required' => false, 'type' => 'array'  ),
            ),
        ) );
    }
    add_action( 'rest_api_init', 'gs_chat_register_rest' );
}

if ( ! function_exists( 'gs_chat_default_model' ) ) {
    function gs_chat_default_model() {
        // Bumped via `update_site_option('gs_chat_default_model', '...')`
        // without a code redeploy when Anthropic ships newer models.
        $opt = get_site_option( 'gs_chat_default_model', '' );
        if ( is_string( $opt ) && $opt !== '' ) return $opt;
        return 'claude-opus-4-7';
    }
}

if ( ! function_exists( 'gs_chat_rest_status' ) ) {

    function gs_chat_rest_status() {
        $uid = get_current_user_id();
        $cur = get_user_meta( $uid, '_gs_chat_anthropic_key', true );
        $key_ok = (bool) gs_gcloud_cfg( 'creds_key' ); // re-uses gcloud's encryption key constant
        return array(
            'connected'       => ! empty( $cur ),
            'has_encryption' => $key_ok,
            'default_model'  => gs_chat_default_model(),
            'preferred_model'=> (string) ( get_user_meta( $uid, '_gs_chat_model', true ) ?: '' ),
        );
    }
}

if ( ! function_exists( 'gs_chat_rest_key_save' ) ) {

    function gs_chat_rest_key_save( WP_REST_Request $req ) {
        if ( ! function_exists( 'gs_gcloud_encrypt' ) ) {
            return new WP_Error( 'no_crypto', 'Encryption helpers unavailable (gcloud module not loaded).', array( 'status' => 503 ) );
        }
        $key   = trim( (string) $req->get_param( 'api_key' ) );
        $model = sanitize_text_field( (string) $req->get_param( 'model' ) );
        if ( $key === '' || substr( $key, 0, 7 ) !== 'sk-ant-' ) {
            return new WP_Error( 'bad_key', 'API key must start with sk-ant-.', array( 'status' => 400 ) );
        }
        $cipher = gs_gcloud_encrypt( $key );
        if ( is_wp_error( $cipher ) ) return $cipher;
        $uid = get_current_user_id();
        update_user_meta( $uid, '_gs_chat_anthropic_key', $cipher );
        if ( $model !== '' ) update_user_meta( $uid, '_gs_chat_model', $model );
        return array( 'connected' => true, 'model' => $model ?: gs_chat_default_model() );
    }
}

if ( ! function_exists( 'gs_chat_rest_key_clear' ) ) {

    function gs_chat_rest_key_clear() {
        $uid = get_current_user_id();
        delete_user_meta( $uid, '_gs_chat_anthropic_key' );
        delete_user_meta( $uid, '_gs_chat_model' );
        return array( 'connected' => false );
    }
}

if ( ! function_exists( 'gs_chat_get_key_for_user' ) ) {

    function gs_chat_get_key_for_user( $user_id ) {
        $blob = get_user_meta( (int) $user_id, '_gs_chat_anthropic_key', true );
        if ( ! $blob || ! function_exists( 'gs_gcloud_decrypt' ) ) return new WP_Error( 'no_key', 'No API key on file.' );
        $plain = gs_gcloud_decrypt( $blob );
        if ( is_wp_error( $plain ) ) return $plain;
        return (string) $plain;
    }
}

if ( ! function_exists( 'gs_chat_rest_stream' ) ) {

    /**
     * SSE proxy. Reads the user's stored key, opens a streaming
     * connection to Anthropic's /v1/messages, and re-emits each
     * upstream `event:`/`data:` line to the browser as-is.
     *
     * We don't buffer or rewrite — the SPA gets the exact same stream
     * shape the official Anthropic SDK delivers, which means it can
     * use the published `content_block_delta` / `message_stop` events
     * without any translation layer.
     */
    function gs_chat_rest_stream( WP_REST_Request $req ) {
        $uid = get_current_user_id();
        $key = gs_chat_get_key_for_user( $uid );
        if ( is_wp_error( $key ) ) {
            return $key;
        }

        $model      = sanitize_text_field( (string) $req->get_param( 'model' ) );
        $system     = (string) $req->get_param( 'system' );
        $messages   = (array)  $req->get_param( 'messages' );
        $max_tokens = (int)    $req->get_param( 'max_tokens' );
        if ( $model === '' ) {
            $pref = (string) get_user_meta( $uid, '_gs_chat_model', true );
            $model = $pref !== '' ? $pref : gs_chat_default_model();
        }
        if ( $max_tokens <= 0 ) $max_tokens = 2048;

        // Sanitize messages to {role, content} shape — drop any extra
        // fields the client sends.
        $clean = array();
        foreach ( $messages as $m ) {
            if ( ! is_array( $m ) || empty( $m['role'] ) || ! isset( $m['content'] ) ) continue;
            $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
            $content = $m['content'];
            // Allow either a plain string or the Anthropic structured
            // block format (array of { type: text, text: ... }).
            if ( is_string( $content ) ) {
                $content = $content;
            } elseif ( is_array( $content ) ) {
                // Pass through if it's already structured.
                $content = $content;
            } else {
                continue;
            }
            $clean[] = array( 'role' => $role, 'content' => $content );
        }
        if ( empty( $clean ) ) return new WP_Error( 'no_messages', 'messages[] is empty.', array( 'status' => 400 ) );

        $body = array(
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'stream'     => true,
            'messages'   => $clean,
        );
        if ( $system !== '' ) $body['system'] = $system;

        // Tools — pass through unchanged. Client owns the registry so
        // we can add/remove tools without redeploying PHP. We
        // sanity-validate they're an array of objects with at least
        // {name, input_schema}.
        $tools_in = $req->get_param( 'tools' );
        if ( is_array( $tools_in ) && ! empty( $tools_in ) ) {
            $tools = array();
            foreach ( $tools_in as $t ) {
                if ( ! is_array( $t ) || empty( $t['name'] ) || empty( $t['input_schema'] ) ) continue;
                $tools[] = array(
                    'name'         => sanitize_text_field( (string) $t['name'] ),
                    'description'  => isset( $t['description'] ) ? (string) $t['description'] : '',
                    'input_schema' => $t['input_schema'],
                );
            }
            if ( ! empty( $tools ) ) $body['tools'] = $tools;
        }

        // SSE response headers. Disable nginx buffering with
        // X-Accel-Buffering so chunks reach the browser as they arrive.
        nocache_headers();
        @header_remove( 'Content-Encoding' );
        header( 'Content-Type: text/event-stream; charset=utf-8' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'X-Accel-Buffering: no' );
        @ini_set( 'zlib.output_compression', '0' );
        // Flush any output started by WP so we begin clean.
        while ( ob_get_level() > 0 ) ob_end_flush();
        @ob_implicit_flush( 1 );

        $ch = curl_init( 'https://api.anthropic.com/v1/messages' );
        curl_setopt_array( $ch, array(
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array(
                'x-api-key: '         . $key,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
                'accept: text/event-stream',
            ),
            CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) {
                // Re-emit upstream bytes verbatim. Anthropic already
                // formats them as SSE lines.
                echo $data;
                @flush();
                return strlen( $data );
            },
        ) );
        $ok  = curl_exec( $ch );
        $err = curl_error( $ch );
        $http = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        if ( ! $ok ) {
            // Stream wasn't established at all — emit a synthetic
            // error event so the client knows something failed.
            echo "event: error\ndata: " . wp_json_encode( array(
                'message' => $err ?: 'Upstream connection failed.',
                'http'    => $http,
            ) ) . "\n\n";
            @flush();
        }
        exit; // we've taken over the response
    }
}

/* ════════════════════════════════════════════════════════════════════
   TOOL USE — proxy endpoints called by the client tool-use loop.
   Each one mints a 10s service JWT (same key the PTY service uses for
   the WebSocket terminal + gcloud-ADC fetch) and forwards the call to
   shell.gend.me. Tools execute INSIDE the user's own ephemeral
   container — same isolation as the interactive terminal.
   ════════════════════════════════════════════════════════════════════ */

if ( ! function_exists( 'gs_chat_tool_register_rest' ) ) {

    function gs_chat_tool_register_rest() {
        register_rest_route( 'gs/v1', '/web-shell/tools/shell_exec', array(
            'methods'             => 'POST',
            'callback'            => 'gs_chat_tool_shell_exec',
            'permission_callback' => function () { return is_user_logged_in(); },
            'args' => array(
                'command' => array( 'required' => true, 'type' => 'string' ),
            ),
        ) );
        register_rest_route( 'gs/v1', '/web-shell/tools/read_file', array(
            'methods'             => 'POST',
            'callback'            => 'gs_chat_tool_read_file',
            'permission_callback' => function () { return is_user_logged_in(); },
            'args' => array(
                'path' => array( 'required' => true, 'type' => 'string' ),
            ),
        ) );
        register_rest_route( 'gs/v1', '/web-shell/tools/write_file', array(
            'methods'             => 'POST',
            'callback'            => 'gs_chat_tool_write_file',
            'permission_callback' => function () { return is_user_logged_in(); },
            'args' => array(
                'path'    => array( 'required' => true, 'type' => 'string' ),
                'content' => array( 'required' => true, 'type' => 'string' ),
            ),
        ) );
        // Git tools — thin shell_exec wrappers, named so Claude can
        // discover them in the registry without needing to know the
        // exact bash invocations.
        register_rest_route( 'gs/v1', '/web-shell/tools/git_status', array(
            'methods'             => 'POST',
            'callback'            => 'gs_chat_tool_git_status',
            'permission_callback' => function () { return is_user_logged_in(); },
            'args' => array(
                'cwd' => array( 'required' => false, 'type' => 'string' ),
            ),
        ) );
        register_rest_route( 'gs/v1', '/web-shell/tools/git_diff', array(
            'methods'             => 'POST',
            'callback'            => 'gs_chat_tool_git_diff',
            'permission_callback' => function () { return is_user_logged_in(); },
            'args' => array(
                'cwd'    => array( 'required' => false, 'type' => 'string' ),
                'staged' => array( 'required' => false, 'type' => 'boolean' ),
                'paths'  => array( 'required' => false, 'type' => 'array'   ),
            ),
        ) );
        register_rest_route( 'gs/v1', '/web-shell/tools/git_commit', array(
            'methods'             => 'POST',
            'callback'            => 'gs_chat_tool_git_commit',
            'permission_callback' => function () { return is_user_logged_in(); },
            'args' => array(
                'cwd'     => array( 'required' => false, 'type' => 'string' ),
                'message' => array( 'required' => true,  'type' => 'string' ),
                'add_all' => array( 'required' => false, 'type' => 'boolean' ),
            ),
        ) );
    }
    add_action( 'rest_api_init', 'gs_chat_tool_register_rest' );
}

if ( ! function_exists( 'gs_chat_tool_call' ) ) {

    function gs_chat_tool_call( $endpoint, $body ) {
        $shell_secret = defined( 'GS_SHELL_JWT_SECRET' ) ? (string) GS_SHELL_JWT_SECRET
                       : ( getenv( 'GS_SHELL_JWT_SECRET' ) ?: '' );
        $pty_base = defined( 'GS_SHELL_PTY_URL' ) ? (string) GS_SHELL_PTY_URL
                  : ( getenv( 'GS_SHELL_PTY_URL' ) ?: 'https://shell.gend.me' );
        if ( $shell_secret === '' ) {
            return new WP_Error( 'no_shared_secret', 'GS_SHELL_JWT_SECRET not configured.', array( 'status' => 503 ) );
        }
        $now = time();
        $token = gs_web_shell_jwt_encode( array(
            'user_id'    => (int) get_current_user_id(),
            'session_id' => 'tool-' . bin2hex( random_bytes( 6 ) ),
            'iss'        => 'web-shell-chat',
            'iat'        => $now,
            'exp'        => $now + 30, // 30s window for tool call wall-clock
            'jti'        => bin2hex( random_bytes( 8 ) ),
        ), $shell_secret );
        $payload = array_merge( array( 'token' => $token ), (array) $body );
        // Long-ish timeout: first call per user provisions a pod
        // (30-60s for image pull + PVC attach), then the command runs
        // (up to 30s wall-clock cap). Subsequent calls reuse the
        // existing pod and return in ms-to-seconds.
        $resp = wp_remote_post( rtrim( $pty_base, '/' ) . $endpoint, array(
            'timeout' => 110,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );
        if ( is_wp_error( $resp ) ) return $resp;
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $data ) ) $data = array( 'ok' => false, 'error' => 'bad-response' );
        if ( $code >= 200 && $code < 300 ) return $data;
        return new WP_Error( 'pty_' . $code, $data['error'] ?? 'pty-error', array( 'status' => $code, 'pty' => $data ) );
    }
}

if ( ! function_exists( 'gs_chat_tool_shell_exec' ) ) {

    function gs_chat_tool_shell_exec( WP_REST_Request $req ) {
        $cmd = (string) $req->get_param( 'command' );
        if ( trim( $cmd ) === '' ) return new WP_Error( 'no_command', 'command required.', array( 'status' => 400 ) );
        return gs_chat_tool_call( '/tools/shell_exec', array( 'command' => $cmd ) );
    }
}

if ( ! function_exists( 'gs_chat_tool_read_file' ) ) {

    function gs_chat_tool_read_file( WP_REST_Request $req ) {
        $path = (string) $req->get_param( 'path' );
        if ( trim( $path ) === '' ) return new WP_Error( 'no_path', 'path required.', array( 'status' => 400 ) );
        return gs_chat_tool_call( '/tools/read_file', array( 'path' => $path ) );
    }
}

if ( ! function_exists( 'gs_chat_tool_write_file' ) ) {

    function gs_chat_tool_write_file( WP_REST_Request $req ) {
        $path    = (string) $req->get_param( 'path' );
        $content = (string) $req->get_param( 'content' );
        if ( trim( $path ) === '' ) return new WP_Error( 'no_path', 'path required.', array( 'status' => 400 ) );
        return gs_chat_tool_call( '/tools/write_file', array( 'path' => $path, 'content' => $content ) );
    }
}

if ( ! function_exists( 'gs_chat_tool_git_shellescape' ) ) {
    // PHP-side single-quote shell escape, mirrors the Node helper.
    function gs_chat_tool_git_shellescape( $s ) {
        return "'" . str_replace( "'", "'\\''", (string) $s ) . "'";
    }
}

if ( ! function_exists( 'gs_chat_tool_git_status' ) ) {

    function gs_chat_tool_git_status( WP_REST_Request $req ) {
        $cwd = (string) ( $req->get_param( 'cwd' ) ?: '/workspace' );
        $cmd = 'cd ' . gs_chat_tool_git_shellescape( $cwd ) . ' && git status --short --branch';
        return gs_chat_tool_call( '/tools/shell_exec', array( 'command' => $cmd ) );
    }
}

if ( ! function_exists( 'gs_chat_tool_git_diff' ) ) {

    function gs_chat_tool_git_diff( WP_REST_Request $req ) {
        $cwd    = (string) ( $req->get_param( 'cwd' ) ?: '/workspace' );
        $staged = (bool) $req->get_param( 'staged' );
        $paths  = (array) ( $req->get_param( 'paths' ) ?: array() );
        $cmd = 'cd ' . gs_chat_tool_git_shellescape( $cwd ) . ' && git --no-pager diff'
             . ( $staged ? ' --cached' : '' )
             . ' --color=never';
        foreach ( $paths as $p ) {
            $cmd .= ' -- ' . gs_chat_tool_git_shellescape( $p );
        }
        return gs_chat_tool_call( '/tools/shell_exec', array( 'command' => $cmd ) );
    }
}

if ( ! function_exists( 'gs_chat_tool_git_commit' ) ) {

    function gs_chat_tool_git_commit( WP_REST_Request $req ) {
        $cwd     = (string) ( $req->get_param( 'cwd' ) ?: '/workspace' );
        $message = (string) $req->get_param( 'message' );
        $add_all = $req->get_param( 'add_all' );
        if ( $add_all === null ) $add_all = true; // default to true
        if ( trim( $message ) === '' ) return new WP_Error( 'no_message', 'message required.', array( 'status' => 400 ) );
        // Use the user's git identity from the pod's local config OR
        // fall back to the gend.me account email — set both as a
        // belt-and-suspenders so the first commit on a fresh PVC works.
        $email = sanitize_email( wp_get_current_user()->user_email );
        $name  = wp_get_current_user()->display_name ?: 'GenD Web Shell User';
        $cmd = 'cd ' . gs_chat_tool_git_shellescape( $cwd )
             . ' && git config user.email ' . gs_chat_tool_git_shellescape( $email )
             . ' && git config user.name '  . gs_chat_tool_git_shellescape( $name )
             . ( $add_all ? ' && git add -A' : '' )
             . ' && git commit -m ' . gs_chat_tool_git_shellescape( $message );
        return gs_chat_tool_call( '/tools/shell_exec', array( 'command' => $cmd ) );
    }
}

// Allowlist past the network REST gate.
add_filter( 'rest_authentication_errors', function ( $result ) {
    if ( ! is_wp_error( $result ) ) return $result;
    $route = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ( $route !== '' && strpos( $route, '/gs/v1/web-shell/chat/'  ) !== false ) return null;
    if ( $route !== '' && strpos( $route, '/gs/v1/web-shell/tools/' ) !== false ) return null;
    return $result;
}, 100 );
