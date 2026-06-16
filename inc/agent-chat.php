<?php
/**
 * Container-side AI-agent chat reply hook (Phase 35, CHAT-02 / CHAT-03).
 *
 * When a Web App member sends a BuddyPress private message to an Organization
 * agent (a real container WP user provisioned by inc/agent-provision.php with
 * role `ai_agent` + persona meta `_aipa_agent_system_prompt`), this listener
 * generates a persona-driven reply through the existing container -> hub LEO
 * proxy (the same rail as GS_AI_Proxy::route_chat -> hub aipa/v1/ai-proxy) and
 * posts it back AS the agent in the same thread. Single-turn for v8.0 (no
 * thread history).
 *
 * COEXISTENCE / DISJOINTNESS NOTE:
 * email-manager/inc/personas.php ALSO hooks `messages_message_sent`
 * (EM_Personas::maybe_auto_reply, priority 20) and keys on `_em_persona_id`.
 * That subsystem is DISJOINT from Phase 32 agents: this file keys STRICTLY on
 * `_aipa_is_agent` / the `ai_agent` role and runs at a LATER priority (30).
 * We DO NOT modify personas.php. Our anti-loop guard (sender-is-agent -> bail)
 * is the FIRST check, which also protects against any cross-subsystem loop.
 *
 * SELF-CONTAINED: the live container runs an OLDER gend-society; nothing here
 * may depend on newer gend-society includes. Every cross-plugin / BuddyPress
 * symbol is function_exists/class_exists/method_exists guarded so an absent
 * dependency degrades gracefully instead of fataling. (php -l cannot catch an
 * undefined-symbol fatal — these guards are the real safety net.)
 *
 * @package gend-society
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is $user_id a container AI agent?
 *
 * Detects the agent identically to how agent-provision.php marks it. Resolves
 * by ID ONLY — get_user_by('email') is unreliable on these installs because of
 * vendor-app-manager's fix_user_query rewrite.
 *
 * @param int $user_id
 * @return bool
 */
function gs_user_is_agent( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}
	if ( get_user_meta( $user_id, '_aipa_is_agent', true ) ) {
		return true;
	}
	$u = get_user_by( 'id', $user_id ); // NEVER by email here.
	return ( $u && in_array( 'ai_agent', (array) $u->roles, true ) );
}

/**
 * messages_message_sent listener: reply as the agent when a member messages it.
 *
 * Registered at priority 30 (after personas.php at 20), accepted_args 1.
 *
 * @param object $message BP_Messages_Message (->sender_id, ->thread_id,
 *                        ->subject, ->message, ->recipients[]->user_id).
 * @return void
 */
function gs_agent_chat_maybe_reply( $message ) {

	// Basic validity needed to even read the sender.
	if ( ! is_object( $message ) ) {
		return;
	}

	$sender_id = (int) ( isset( $message->sender_id ) ? $message->sender_id : 0 );

	// ── ANTI-LOOP GUARD — MUST BE THE FIRST LOGIC CHECK. ──
	// The reply we post below ALSO fires messages_message_sent with the agent
	// as sender. If the sender is an agent, this is our own reply re-entering
	// the hook — bail immediately. Without this: infinite message loop.
	if ( gs_user_is_agent( $sender_id ) ) {
		return;
	}

	// CHAT-03 gate: do nothing when social messaging is unavailable.
	if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'messages' ) ) {
		return;
	}

	// Remaining validity.
	if ( $sender_id <= 0 || empty( $message->recipients ) ) {
		return;
	}

	foreach ( (array) $message->recipients as $rcp ) {
		$agent_id = is_object( $rcp ) ? (int) $rcp->user_id : (int) $rcp;

		// Skip self and invalid recipients.
		if ( $agent_id <= 0 || $agent_id === $sender_id ) {
			continue;
		}
		// Recipient must be an agent.
		if ( ! gs_user_is_agent( $agent_id ) ) {
			continue;
		}
		// Deactivated agent -> silent no-reply (MAIL-03 parity).
		if ( get_user_meta( $agent_id, '_aipa_agent_disabled', true ) ) {
			continue;
		}

		$persona = (string) get_user_meta( $agent_id, '_aipa_agent_system_prompt', true );
		$reply   = gs_agent_chat_generate_reply(
			$agent_id,
			$persona,
			(string) ( isset( $message->message ) ? $message->message : '' ),
			(int) ( isset( $message->thread_id ) ? $message->thread_id : 0 )
		);

		// GRACEFUL DEGRADE: on any proxy error / non-200 / 402 / empty reply,
		// post a short placeholder so the thread isn't dead, and log. Never
		// fatal, never silent-drop the member's message.
		if ( '' === $reply ) {
			$reply = __( "Thanks for your message — I'll get back to you shortly.", 'gend-society' );
			error_log( '[gs-agent-chat] empty/failed reply for agent ' . $agent_id );
		}

		// Post the reply AS the agent, in the SAME thread.
		if ( function_exists( 'messages_new_message' ) ) {
			$res = messages_new_message( array(
				'sender_id'  => $agent_id,
				'thread_id'  => (int) ( isset( $message->thread_id ) ? $message->thread_id : 0 ),
				'recipients' => array( $sender_id ),
				'subject'    => 'Re: ' . (string) ( isset( $message->subject ) ? $message->subject : '' ),
				'content'    => $reply,
				'error_type' => 'wp_error',
			) );

			if ( is_wp_error( $res ) ) {
				error_log( '[gs-agent-chat] messages_new_message failed for agent ' . $agent_id . ': ' . $res->get_error_message() );
			}
		}
	}
}
add_action( 'messages_message_sent', 'gs_agent_chat_maybe_reply', 30, 1 );

/**
 * Generate the agent's reply text via the hub LEO proxy.
 *
 * Mirrors GS_AI_Proxy::route_chat — a direct server-side wp_remote_post to the
 * hub's aipa/v1/ai-proxy (NOT a REST loopback, NOT AIPA_REST_Gemini which is
 * HUB-ONLY and absent on the container). The persona is passed as a `system`
 * role message; the hub maps it to systemInstruction. Single-turn for v8.0.
 *
 * @param int    $agent_id    Container agent user id.
 * @param string $persona     The agent's system prompt (_aipa_agent_system_prompt).
 * @param string $member_text The member's inbound message text.
 * @param int    $thread_id   Thread id (unused in single-turn; kept for parity).
 * @return string Reply text, or '' on any error / no bearer / empty reply.
 */
function gs_agent_chat_generate_reply( $agent_id, $persona, $member_text, $thread_id ) {

	// The hook runs inside the MEMBER's message-send request, so resolve the
	// member's gend.me bearer (same source as GS_AI_Proxy::bearer()). Guarded:
	// an absent OAuth client degrades to '' rather than fataling.
	$uid    = get_current_user_id();
	$bearer = ( class_exists( 'Gend_CP_OAuth_Client' ) && method_exists( 'Gend_CP_OAuth_Client', 'bearer_for' ) )
		? (string) Gend_CP_OAuth_Client::bearer_for( $uid )
		: '';

	// Not connected to gend.me -> no bearer -> caller posts the placeholder.
	if ( '' === $bearer ) {
		return '';
	}

	$messages = array();
	if ( '' !== $persona ) {
		$messages[] = array( 'role' => 'system', 'content' => $persona );
	}
	$messages[] = array( 'role' => 'user', 'content' => $member_text );

	// Resolve hub base via the proven GS_AI_Proxy helper when present.
	$hub = class_exists( 'GS_AI_Proxy' ) ? GS_AI_Proxy::hub_base() : 'https://gend.me';

	$r = wp_remote_post( $hub . '/wp-json/aipa/v1/ai-proxy', array(
		'timeout' => 60,
		'headers' => array(
			'Authorization' => 'Bearer ' . $bearer,
			'X-Gend-Token'  => $bearer,
			'Content-Type'  => 'application/json',
		),
		'body'    => wp_json_encode( array(
			'model'    => (string) get_user_meta( $agent_id, '_aipa_agent_default_model', true ),
			'messages' => $messages,
		) ),
	) );

	if ( is_wp_error( $r ) ) {
		error_log( '[gs-agent-chat] hub unreachable: ' . $r->get_error_message() );
		return '';
	}

	$code = (int) wp_remote_retrieve_response_code( $r );
	$body = json_decode( wp_remote_retrieve_body( $r ), true );

	// Covers 402 insufficient_credits, 502, malformed body, etc.
	if ( 200 !== $code || ! is_array( $body ) ) {
		error_log( '[gs-agent-chat] hub HTTP ' . $code . ' ' . substr( (string) wp_remote_retrieve_body( $r ), 0, 200 ) );
		return '';
	}

	// hub aipa/v1/ai-proxy returns {reply: <text>}; accept {response} too.
	return (string) ( isset( $body['reply'] ) ? $body['reply'] : ( isset( $body['response'] ) ? $body['response'] : '' ) );
}
