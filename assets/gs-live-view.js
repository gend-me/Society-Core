/**
 * GenD Society — Backend / Frontend tabs above the block editor.
 * Frontend shows what the editor holds right now (unsaved changes included):
 * the blocks are rendered on the server (POST gend-society/v1/live-view/render,
 * blocks + shortcodes only, no theme header/footer) and shown in a frame with
 * the editor canvas's own stylesheets. Nothing is saved, the page is not loaded.
 */

(function (wp) {
    'use strict';

    const { registerPlugin } = wp.plugins;
    const { createPortal, useState, useEffect, useRef } = wp.element;
    const { useSelect } = wp.data;
    const el = wp.element.createElement;

    const ICONS = {
        desktop: el('svg', { viewBox: '0 0 24 24' },
            el('path', { d: 'M21 2H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h7l-2 3v1h8v-1l-2-3h7c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H3V4h18v12z' })
        ),
        tablet: el('svg', { viewBox: '0 0 24 24' },
            el('path', { d: 'M18.5 0h-13C3.57 0 2 1.57 2 3.5v17C2 22.43 3.57 24 5.5 24h13c1.93 0 3.5-1.57 3.5-3.5v-17C22 1.57 20.43 0 18.5 0zM12 22c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm8-4H4V3.5C4 2.67 4.67 2 5.5 2h13c.83 0 1.5.67 1.5 1.5V18z' })
        ),
        mobile: el('svg', { viewBox: '0 0 24 24' },
            el('path', { d: 'M17 1.01L7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z' })
        )
    };

    // The editor canvas's stylesheets (theme, global styles, blocks) and body classes.
    function canvasStyles() {
        const canvas = document.querySelector('iframe[name="editor-canvas"]');
        const doc = canvas && canvas.contentDocument && canvas.contentDocument.head ? canvas.contentDocument : document;
        let css = '';
        doc.querySelectorAll('link[rel="stylesheet"], style').forEach((node) => {
            css += node.outerHTML;
        });
        return { css, bodyClass: doc === document ? 'editor-styles-wrapper' : (doc.body.className || 'editor-styles-wrapper') };
    }

    function frameDoc(html) {
        const s = canvasStyles();
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base target="_blank">' +
            s.css + '<style>html,body{margin:0;min-height:100%;}body{padding:0 !important;}</style></head>' +
            '<body class="' + s.bodyClass.replace(/"/g, '') + '"><div class="is-root-container is-layout-flow wp-block-post-content">' + html + '</div></body></html>';
    }

    const TabbedLiveView = () => {
        const [activeTab, setActiveTab] = useState('backend');
        const [viewport, setViewport] = useState('desktop');
        const [isLoading, setIsLoading] = useState(false);
        const [doc, setDoc] = useState('');
        const [container, setContainer] = useState(null);
        const ask = useRef(0);

        const { postId, permalink } = useSelect(select => ({
            postId: select('core/editor').getCurrentPostId(),
            permalink: select('core/editor').getPermalink()
        }));

        useEffect(() => {
            const findContainer = () => {
                const found = document.querySelector('.interface-interface-skeleton__content');
                if (found) {
                    setContainer(found);
                } else {
                    setTimeout(findContainer, 500);
                }
            };
            findContainer();
        }, []);

        useEffect(() => {
            if (!container) return;
            container.classList.toggle('gs-hide-editor-canvas', activeTab !== 'backend');
        }, [activeTab, container]);

        const render = () => {
            const n = ++ask.current;
            setIsLoading(true);
            const content = wp.data.select('core/editor').getEditedPostContent();
            wp.apiFetch({ path: '/gend-society/v1/live-view/render', method: 'POST', data: { id: postId, content } }).then((r) => {
                if (n === ask.current) {
                    setDoc(frameDoc(r.html || ''));
                }
            }).catch((e) => {
                if (n === ask.current) {
                    setDoc(frameDoc('<p style="padding:24px">' + String((e && e.message) || 'Could not render the preview.').replace(/</g, '&lt;') + '</p>'));
                }
            });
        };

        const handleTabSwitch = (tab) => {
            if (tab === activeTab) return;
            setActiveTab(tab);
            if (tab === 'frontend') {
                render();
            }
        };

        // Templates and template parts have no permalink: they have no page view.
        if (!container || !permalink) return null;

        const tabButton = (tab, label) => el('button', {
            className: 'gs-tab-item ' + (activeTab === tab ? 'is-active' : ''),
            onClick: () => handleTabSwitch(tab)
        }, label);
        const viewButton = (v, title) => el('button', {
            className: 'gs-viewport-item ' + (viewport === v ? 'is-active' : ''),
            onClick: () => setViewport(v),
            title
        }, ICONS[v]);

        return createPortal(
            el('div', { className: 'gs-tabbed-view-container' },
                el('div', { className: 'gs-tab-bar' },
                    el('div', { className: 'gs-tab-switcher' }, tabButton('backend', 'Backend'), tabButton('frontend', 'Frontend')),
                    activeTab === 'frontend' && el('div', { className: 'gs-viewport-switcher' },
                        viewButton('desktop', 'Desktop View'),
                        viewButton('tablet', 'Tablet View (768px)'),
                        viewButton('mobile', 'Mobile View (375px)')
                    )
                ),
                el('div', { className: 'gs-frontend-view ' + (activeTab === 'frontend' ? 'is-visible' : '') },
                    isLoading && el('div', { className: 'gs-tabbed-loading' },
                        el('div', { className: 'gs-tabbed-spinner' }),
                        el('p', { style: { color: '#94a3b8', fontWeight: '600' } }, 'Rendering…')
                    ),
                    // Mounted only while Frontend is open: nothing loads in the background.
                    activeTab === 'frontend' && doc && el('div', { className: 'gs-iframe-wrapper viewport-' + viewport },
                        el('iframe', {
                            srcDoc: doc,
                            className: 'gs-live-view-iframe',
                            onLoad: () => setIsLoading(false),
                            title: 'Frontend view'
                        })
                    ),
                    activeTab === 'frontend' && el('button', {
                        className: 'gs-refresh-floating',
                        onClick: render,
                        title: 'Refresh View'
                    }, '🔄')
                )
            ),
            container
        );
    };

    registerPlugin('gs-tabbed-live-view', {
        render: TabbedLiveView
    });

})(window.wp);
