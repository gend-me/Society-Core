/**
 * GenD Society — Tabbed & Responsive Live View Plugin
 * Adds "Backend" and "Frontend" tabs above the editor body to switch between editing and preview.
 * Includes responsive viewport switching for Frontend mode.
 * Optimized for performance with Turbo mode and Quick View.
 */

(function (wp) {
    'use strict';

    const { registerPlugin } = wp.plugins;
    const { createPortal, useState, useEffect, useCallback, useRef } = wp.element;
    const { useSelect, useDispatch } = wp.data;
    const { __ } = wp.i18n;

    const ICONS = {
        desktop: wp.element.createElement('svg', { viewBox: '0 0 24 24' }, 
            wp.element.createElement('path', { d: 'M21 2H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h7l-2 3v1h8v-1l-2-3h7c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H3V4h18v12z' })
        ),
        tablet: wp.element.createElement('svg', { viewBox: '0 0 24 24' }, 
            wp.element.createElement('path', { d: 'M18.5 0h-13C3.57 0 2 1.57 2 3.5v17C2 22.43 3.57 24 5.5 24h13c1.93 0 3.5-1.57 3.5-3.5v-17C22 1.57 20.43 0 18.5 0zM12 22c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm8-4H4V3.5C4 2.67 4.67 2 5.5 2h13c.83 0 1.5.67 1.5 1.5V18z' })
        ),
        mobile: wp.element.createElement('svg', { viewBox: '0 0 24 24' }, 
            wp.element.createElement('path', { d: 'M17 1.01L7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z' })
        )
    };

    const TabbedLiveView = () => {
        const [activeTab, setActiveTab] = useState('backend'); // 'backend', 'frontend', 'quick'
        const [viewport, setViewport] = useState('desktop');   // 'desktop', 'tablet', 'mobile'
        const [isLoading, setIsLoading] = useState(false);
        const [iframeKey, setIframeKey] = useState(0);
        const [container, setContainer] = useState(null);

        const { permalink, isDirty } = useSelect(select => ({
            permalink: select('core/editor').getPermalink(),
            isDirty: select('core/editor').isEditedPostDirty()
        }));

        const { savePost } = useDispatch('core/editor');

        // Locate the Gutenberg content area
        useEffect(() => {
            const findContainer = () => {
                const el = document.querySelector('.interface-interface-skeleton__content');
                if (el) {
                    setContainer(el);
                } else {
                    setTimeout(findContainer, 500);
                }
            };
            findContainer();
        }, []);

        // Manage hiding the block editor canvas
        useEffect(() => {
            if (!container) return;

            if (activeTab !== 'backend') {
                container.classList.add('gs-hide-editor-canvas');
            } else {
                container.classList.remove('gs-hide-editor-canvas');
            }
        }, [activeTab, container]);

        const handleTabSwitch = async (tab, skipSave = false) => {
            if (tab === activeTab && !skipSave) return;

            if (tab === 'frontend' || tab === 'quick') {
                setIsLoading(true);
                if (tab === 'frontend' && isDirty) {
                    await savePost();
                }
                // If quick mode, we skip save and just set the tab
            }
            setActiveTab(tab);
        };

        const handleRefresh = () => {
            setIsLoading(true);
            setIframeKey(prev => prev + 1);
        };

        const handleIframeLoad = (e) => {
            setIsLoading(false);
            try {
                const iframe = e.target;
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                const style = iframeDoc.createElement('style');
                style.textContent = `
                    #wpadminbar { display: none !important; }
                    html { margin-top: 0 !important; }
                    #main-3d-header, .header-anchor-wrap { display: none !important; }
                    .gs-refresh-floating { display: none !important; }
                `;
                iframeDoc.head.appendChild(style);
            } catch (err) {
                console.log('Cross-domain iframe - css injection limited.');
            }
        };

        if (!container) return null;

        // Construct preview URL with performance bypass param
        const previewUrl = permalink + (permalink.indexOf('?') > -1 ? '&' : '?') + 'gs_live_view=1';

        return createPortal(
            wp.element.createElement(
                'div',
                { className: 'gs-tabbed-view-container' },
                // Tab Bar
                wp.element.createElement(
                    'div',
                    { className: 'gs-tab-bar' },
                    wp.element.createElement(
                        'div',
                        { className: 'gs-tab-switcher' },
                        wp.element.createElement(
                            'button',
                            {
                                className: `gs-tab-item ${activeTab === 'backend' ? 'is-active' : ''}`,
                                onClick: () => handleTabSwitch('backend')
                            },
                            'Backend'
                        ),
                        wp.element.createElement(
                            'button',
                            {
                                className: `gs-tab-item ${activeTab === 'frontend' ? 'is-active' : ''}`,
                                onClick: () => handleTabSwitch('frontend')
                            },
                            'Frontend'
                        ),
                        wp.element.createElement(
                            'button',
                            {
                                className: `gs-tab-item gs-quick-tab ${activeTab === 'quick' ? 'is-active' : ''}`,
                                onClick: () => handleTabSwitch('quick', true),
                                title: 'Instant Preview (Skips Syncing)'
                            },
                            '⚡ Quick'
                        )
                    ),

                    // Viewport Switcher (Only visible in Frontend/Quick mode)
                    activeTab !== 'backend' && wp.element.createElement(
                        'div',
                        { className: 'gs-viewport-switcher' },
                        wp.element.createElement(
                            'button',
                            {
                                className: `gs-viewport-item ${viewport === 'desktop' ? 'is-active' : ''}`,
                                onClick: () => setViewport('desktop'),
                                title: 'Desktop View'
                            },
                            ICONS.desktop
                        ),
                        wp.element.createElement(
                            'button',
                            {
                                className: `gs-viewport-item ${viewport === 'tablet' ? 'is-active' : ''}`,
                                onClick: () => setViewport('tablet'),
                                title: 'Tablet View (768px)'
                            },
                            ICONS.tablet
                        ),
                        wp.element.createElement(
                            'button',
                            {
                                className: `gs-viewport-item ${viewport === 'mobile' ? 'is-active' : ''}`,
                                onClick: () => setViewport('mobile'),
                                title: 'Mobile View (375px)'
                            },
                            ICONS.mobile
                        )
                    )
                ),
                
                // Frontend Content Area (Shared by Frontend and Quick)
                wp.element.createElement(
                    'div',
                    { className: `gs-frontend-view ${activeTab !== 'backend' ? 'is-visible' : ''}` },
                    isLoading && wp.element.createElement(
                        'div',
                        { className: 'gs-tabbed-loading' },
                        wp.element.createElement('div', { className: 'gs-tabbed-spinner' }),
                        wp.element.createElement('p', { style: {color: '#94a3b8', fontWeight: '600'} }, activeTab === 'quick' ? 'Instant Loading...' : 'Syncing Content...')
                    ),
                    wp.element.createElement(
                        'div',
                        { className: `gs-iframe-wrapper viewport-${viewport}` },
                        wp.element.createElement('iframe', {
                            key: iframeKey,
                            src: previewUrl,
                            className: 'gs-live-view-iframe',
                            onLoad: handleIframeLoad,
                            title: 'Live View'
                        })
                    ),
                    
                    // Floating Refresh
                    wp.element.createElement(
                        'button',
                        {
                            className: 'gs-refresh-floating',
                            onClick: handleRefresh,
                            title: 'Refresh View'
                        },
                        '🔄'
                    )
                )
            ),
            container
        );
    };

    registerPlugin('gs-tabbed-live-view', {
        render: TabbedLiveView
    });

})(window.wp);
