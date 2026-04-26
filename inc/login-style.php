<?php
/**
 * Custom Login Page Styling for GenD Society
 *
 * Applies the futuristic glassmorphic aesthetic to the WordPress login page.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'login_enqueue_scripts', 'gs_login_styling' );
add_action( 'wp_enqueue_scripts', 'gs_register_styling' );

function gs_login_styling() {
    gs_render_glass_css( 'login' );
}

function gs_register_styling() {
    if ( function_exists( 'bp_is_register_page' ) && bp_is_register_page() ) {
        gs_render_glass_css( 'register' );
    }
}

/**
 * Shared Futuristic Glassmorphic CSS
 */
function gs_render_glass_css( $context = 'login' ) {
    ?>
    <style type="text/css">
        /* ── Design Tokens ──────────────────────────────────────────────────── */
        :root {
            --gs-bg:      #0b0e14;
            --gs-glass:   rgba(255, 255, 255, 0.03);
            --gs-border:  rgba(255, 255, 255, 0.1);
            --gs-accent:  #b608c9; /* Magenta */
            --gs-accent2: #00ff88; /* Green */
            --gs-blue:    #89C2E0;
            --gs-text:    #ffffff;
        }

        /* ── Global Background ───────────────────────────────────────────────── */
        body.login, body.registration, body.bp-registration, .page-id-build {
            background-color: var(--gs-bg) !important;
            background-image: 
                radial-gradient(circle at 20% 20%, rgba(182, 8, 201, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 80% 80%, rgba(0, 255, 136, 0.05) 0%, transparent 40%) !important;
            font-family: "Inter", sans-serif !important;
        }

        /* ── Registration Page Layout Fixes ─────────────────────────────────── */
        <?php if ( $context === 'register' ) : ?>
        #buddypress {
            max-width: 1000px;
            margin: 60px auto !important;
            padding: 0 20px;
        }

        #buddypress h2 {
            color: #fff !important;
            font-size: 32px !important;
            text-align: center;
            margin-bottom: 40px !important;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 800;
        }

        /* The Glass Form */
        #registration-form, #signup_form {
            background: var(--gs-glass) !important;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--gs-border) !important;
            border-radius: 32px !important;
            padding: 50px !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important;
        }

        .register-section {
            margin-bottom: 40px !important;
        }

        .register-section h4 {
            color: var(--gs-blue) !important;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--gs-border);
            padding-bottom: 10px;
            margin-bottom: 25px !important;
        }

        /* Labels */
        #buddypress label {
            color: rgba(255, 255, 255, 0.8) !important;
            font-size: 14px !important;
            margin-bottom: 10px !important;
            display: block;
        }

        /* Input Fields */
        #buddypress input[type="text"],
        #buddypress input[type="password"],
        #buddypress input[type="email"],
        #buddypress textarea {
            background: rgba(255, 255, 255, 0.05) !important;
            border: 1px solid var(--gs-border) !important;
            border-radius: 12px !important;
            color: #fff !important;
            padding: 14px 18px !important;
            font-size: 16px !important;
            width: 100% !important;
            transition: all 0.3s ease !important;
            margin-bottom: 20px !important;
        }

        #buddypress input:focus, #buddypress textarea:focus {
            background: rgba(255, 255, 255, 0.08) !important;
            border-color: var(--gs-accent) !important;
            box-shadow: 0 0 15px rgba(182, 8, 201, 0.15) !important;
            outline: none !important;
        }

        /* Submit Button */
        #signup_submit, .submit input[type="submit"] {
            background: linear-gradient(135deg, var(--gs-accent) 0%, #7e058a 100%) !important;
            border: none !important;
            border-radius: 14px !important;
            padding: 18px 36px !important;
            height: auto !important;
            font-size: 16px !important;
            font-weight: 700 !important;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #fff !important;
            cursor: pointer;
            transition: all 0.3s ease !important;
            box-shadow: 0 8px 20px -6px rgba(182, 8, 201, 0.4) !important;
            margin-top: 20px !important;
        }

        #signup_submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px -6px rgba(182, 8, 201, 0.6) !important;
        }

        /* Error/Notice Boxes */
        #buddypress div.error, #buddypress div.updated {
            background: rgba(204, 0, 0, 0.1) !important;
            border: 1px solid rgba(204, 0, 0, 0.3) !important;
            border-radius: 14px !important;
            color: #ff8888 !important;
            padding: 15px !important;
            margin-bottom: 25px !important;
        }

        /* Clearfix for BuddyPress register columns */
        #buddypress .register-section {
            overflow: hidden;
            clear: both;
        }

        /* Registration Animations */
        @keyframes slideUpFade {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        #signup_form {
            opacity: 0;
            animation: slideUpFade 1s cubic-bezier(0.2, 0.8, 0.2, 1) 0.1s forwards;
        }

        #buddypress h2 {
            opacity: 0;
            animation: slideUpFade 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
        }

        .register-section {
            opacity: 0;
            animation: slideUpFade 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
        }

        .register-section:nth-child(1) { animation-delay: 0.3s; }
        .register-section:nth-child(2) { animation-delay: 0.5s; }
        .submit { 
            opacity: 0;
            animation: slideUpFade 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) 0.7s forwards;
        }

        <?php else : ?>
        /* ── WP Login Page Specifics ────────────────────────────────────────── */
        @keyframes slideUpFade {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        body.login {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow-x: hidden;
        }

        #login {
            width: 100%;
            max-width: 440px;
            padding: 20px;
            margin: 0 auto !important;
        }

        /* Staggered Entrance Animations */
        .login h1 { 
            opacity: 0;
            animation: slideUpFade 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; 
        }

        #loginform { 
            opacity: 0;
            animation: slideUpFade 1s cubic-bezier(0.2, 0.8, 0.2, 1) 0.15s forwards; 
        }

        #loginform p {
            opacity: 0;
            animation: slideUpFade 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
        }

        /* Target individual form elements for staggered delay */
        #loginform p:nth-child(1) { animation-delay: 0.3s; } /* Username */
        #loginform p:nth-child(2) { animation-delay: 0.4s; } /* Password */
        #loginform p:nth-child(3) { animation-delay: 0.5s; } /* Remember Me */
        #loginform p:nth-child(4) { animation-delay: 0.6s; } /* Submit Button */

        #nav, #backtoblog { 
            opacity: 0;
            animation: slideUpFade 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) 0.8s forwards; 
        }

        .login h1 a {
            background-size: contain !important;
            width: 100% !important;
            height: 80px !important;
            margin-bottom: 30px !important;
            filter: drop-shadow(0 0 15px rgba(182, 8, 201, 0.3));
            transition: transform 0.3s ease;
        }
        .login h1 a:hover {
            transform: scale(1.05);
        }

        #loginform {
            background: var(--gs-glass) !important;
            backdrop-filter: blur(20px);
            border: 1px solid var(--gs-border) !important;
            border-radius: 24px !important;
            padding: 40px !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important;
        }

        .login label {
            color: rgba(255, 255, 255, 0.7) !important;
            text-transform: uppercase;
            font-size: 13px !important;
            margin-bottom: 8px !important;
        }

        .login input[type="text"], .login input[type="password"], .login input[type="email"] {
            background: rgba(255, 255, 255, 0.05) !important;
            border: 1px solid var(--gs-border) !important;
            border-radius: 12px !important;
            color: #fff !important;
            padding: 12px 16px !important;
            margin-bottom: 20px !important;
        }

        #wp-submit {
            background: linear-gradient(135deg, var(--gs-accent) 0%, #7e058a 100%) !important;
            border: none !important;
            border-radius: 12px !important;
            padding: 12px 24px !important;
            font-weight: 600 !important;
            text-transform: uppercase;
            width: 100% !important;
            box-shadow: 0 8px 20px -6px rgba(182, 8, 201, 0.4) !important;
        }

        #nav a, #backtoblog a { color: rgba(255, 255, 255, 0.5) !important; }
        #nav a:hover { color: var(--gs-accent) !important; }

        <?php endif; ?>
    </style>
    <?php
}

// Custom Login Logo URL
add_filter( 'login_headerurl', 'gs_login_logo_url' );
function gs_login_logo_url() {
    return home_url();
}

// Custom Login Logo Title
add_filter( 'login_headertext', 'gs_login_logo_title' );
function gs_login_logo_title() {
    return get_bloginfo( 'name' );
}
