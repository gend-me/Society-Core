<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
    <style>
        /* Force-hide common distractions even if they are in wp_head/footer */
        #wpadminbar, 
        .site-header, 
        .site-footer, 
        .admin-bar,
        #main-3d-header,
        .header-anchor-wrap,
        header,
        footer,
        .wp-block-template-part.header,
        .wp-block-template-part.footer { 
            display: none !important; 
        }
        
        html { margin-top: 0 !important; padding-top: 0 !important; }
        
        body { 
            background: #fff; 
            margin: 0; 
            padding: 0;
            overflow-x: hidden;
        }

        #gs-live-view-wrapper {
            padding: 20px;
            max-width: 100%;
            margin: 0 auto;
        }

        /* Ensure images and blocks fit the container */
        img { max-width: 100%; height: auto; }
        .wp-block-group { max-width: 100%; }
    </style>
</head>
<body <?php body_class(); ?>>
    <div id="gs-live-view-wrapper">
        <?php 
        if ( have_posts() ) {
            while ( have_posts() ) {
                the_post();
                the_content();
            }
        }
        ?>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
