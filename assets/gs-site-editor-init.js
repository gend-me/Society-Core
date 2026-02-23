/* GenD Society — Site Editor AI Init */
(function () {
    'use strict';

    function initAI() {
        if (!window.wp || !window.wp.data || !window.GS_TemplateModal) {
            setTimeout(initAI, 500);
            return;
        }

        const urlParams = new URLSearchParams(window.location.search);
        let workflow = 'content_writer';

        // Detect Site Editor context (using decoded values)
        const postType = urlParams.get('postType');
        const postId = urlParams.get('postId') ? decodeURIComponent(urlParams.get('postId')) : '';
        const categoryId = urlParams.get('categoryId') || '';
        const pParam = urlParams.get('p') ? decodeURIComponent(urlParams.get('p')) : '';

        if (postType === 'wp_template_part' || postId.includes('//header') || postId.includes('//footer') || categoryId === 'header' || categoryId === 'footer' || pParam.includes('wp_template_part') || pParam.includes('header') || pParam.includes('footer')) {
            if (postId.includes('header') || categoryId === 'header' || pParam.includes('header')) {
                workflow = 'header_designer';
            } else if (postId.includes('footer') || categoryId === 'footer' || pParam.includes('footer')) {
                workflow = 'footer_designer';
            }
        } else if (postType === 'wp_template' || pParam.includes('wp_template')) {
            workflow = 'content_writer';
        }

        console.log('[GS AI] Detected Site Editor Context:', { postType, postId, categoryId, pParam, finalWorkflow: workflow });

        // The main wrapper for the site editor
        const layout = document.querySelector('.edit-site-layout');
        if (!layout) {
            setTimeout(initAI, 500);
            return;
        }

        // Ensure layout is flex
        layout.style.display = 'flex';
        layout.style.flexDirection = 'row';
        layout.style.height = 'calc(100vh - 56px)';
        layout.style.overflow = 'hidden';
        layout.style.boxSizing = 'border-box';

        // Only open once per load
        if (!document.querySelector('.gs-template-panel-root')) {
            window.GS_TemplateModal.openInline('.edit-site-layout', workflow);

            // Re-order DOM to put AI panel on the right side if it isn't already
            const panel = document.querySelector('.gs-template-panel-root');
            const skeleton = layout.querySelector('.interface-interface-skeleton');

            if (skeleton && panel) {
                skeleton.style.flex = '1';
                skeleton.style.minWidth = '0'; // Prevent flex blowout
                // Ensure panel is after skeleton
                layout.appendChild(panel);
            }
        }
    }

    // Monitor URL changes, as React handles routing without a full page reload
    let lastUrl = location.href;
    new MutationObserver(() => {
        const url = location.href;
        if (url !== lastUrl) {
            lastUrl = url;
            // Clear existing panel if URL changed to re-init with new workflow context
            const existingPanel = document.querySelector('.gs-template-panel-root');
            if (existingPanel) {
                existingPanel.remove();
            }
            setTimeout(() => {
                if (document.querySelector('.edit-site-layout')) {
                    initAI();
                }
            }, 500);
        }
    }).observe(document, { subtree: true, childList: true });

    // Start
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAI);
    } else {
        initAI();
    }
})();
