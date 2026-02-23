/**
 * Store Template Modal
 * Opens WordPress block editor pages in a modal with AI chat assistant
 * Adapted from AI Project Assistant widget-app.js openEditModal function
 */

(function () {
  'use strict';

  class GS_TemplateModal {
    constructor() {
      this.modal = null;
      this.modal = null;
      // Safely access global config
      const modalConfig = window.GS_TEMPLATE_MODAL || {};
      let url = modalConfig.rest_url || '/wp-json';
      this.restBase = url.replace(/\/+$/, ''); // Remove ALL trailing slashes
      this.nonce = modalConfig.nonce || '';
    }

    /**
     * Get workflow-specific welcome message
     */
    getWelcomeMessage(workflow) {
      if (workflow === 'header_designer') {
        return "👋 Hi! I'm your header design assistant. I can help you build beautiful, functional headers for your WordPress site. Just describe what you need, and I'll create it directly in the Block Editor!";
      } else if (workflow === 'footer_designer') {
        return "👋 Hi! I'm your footer design assistant. I can help you build stunning footers with:<br>• Bento Grid layouts<br>• Fat Footer navigation<br>• Dynamic content (Recent Posts)<br>• Social icons & copyright<br>• Sticky or reveal effects<br><br>Just describe your footer, and I'll create it directly in the Block Editor!";
      } else if (workflow === 'blog_architect') {
        return "👋 Hi! I'm your Blog Architect. I can help you write and design engaging blog posts. I can help you:<br>• Generate outlines and full drafts<br>• Create Bento Grid layouts for visual interest<br>• Add engaging introductions and conclusions<br>• Suggest relevant images<br><br>Just tell me what you want to write about!";
      } else if (workflow === 'email_designer') {
        return "👋 Hi! I'm your Email Architect. I build high-performance HTML emails compatible with Outlook & Gmail.<br><br><strong>I can help you with:</strong><br>• <strong>Ghost Tables:</strong> Hybrid layouts that work everywhere.<br>• <strong>VML Buttons:</strong> Bulletproof calls-to-action.<br>• <strong>Neo-brutalism:</strong> Bold, high-contrast designs.<br><br>Describe your campaign or ask for a template!";
      } else {
        // Enhanced content_writer workflow
        return `
          <strong>👋 Hi! I'm your WordPress Page Architect</strong><br><br>
          I can build high-converting pages with modern WordPress 6.9+ features:<br><br>
          <strong>🏗️ Layout Capabilities:</strong><br>
          • Hero sections with video backgrounds<br>
          • Pricing tables with interactive toggles<br>
          • Dynamic content (testimonials, portfolios)<br>
          • Bento Grid & advanced layouts<br>
          • Scroll animations & fluid typography<br><br>
          <strong>Quick Start:</strong><br>
          <button class="gdc-chat-chip" data-msg="Build a landing page using the AIDA framework">📄 Build Landing Page</button>
          <button class="gdc-chat-chip" data-msg="Create a hero section with full height and centered content">🎬 Add Hero Section</button>
          <button class="gdc-chat-chip" data-msg="Add a 3-tier pricing table">💰 Create Pricing Table</button><br><br>
          Or tell me: <strong>What type of page are you building?</strong> (SaaS, E-commerce, Agency, etc.)
        `;
      }
    }

    /**
     * Open modal with page editor and AI chat
     */
    open(pageId, pageTitle, editUrl, workflow = 'content_writer') {
      // Close any existing modal first
      this.close();
      this.currentWorkflow = workflow;
      this.isInline = false;

      this.originalBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';

      console.log('[GDC Template Modal] Opening:', { pageId, pageTitle, editUrl, workflow });
      console.log('[GDC Template Modal] API Base:', this.restBase); // Debug log

      // Create modal overlay
      this.modal = document.createElement('div');
      this.modal.className = 'gs-template-modal';
      this.modal.style.cssText = `
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.9); z-index: 2147483647;
        display: flex; flex-direction: column;
      `;

      this.modal.innerHTML = `
        <style>
          .gs-template-modal .bubble-wrap { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 10px; animation: msgSlideIn 0.3s ease; }
          .gs-template-modal .bubble-wrap.me { flex-direction: row-reverse; }
          .gs-template-modal .bubble-avatar { 
            width: 32px; height: 32px; min-width: 32px; border-radius: 50%; 
            background-size: cover; background-position: center; flex-shrink: 0;
            box-shadow: 0 0 10px rgba(34,211,238,0.3); border: 1px solid rgba(99,102,241,0.4);
          }
          .gs-template-modal .bubble { 
            max-width: 80%; padding: 10px 12px; border-radius: 12px; font-size: 13px; color: #e5e7eb;
          }
          .gs-template-modal .bubble.me { background: rgba(99,102,241,0.2); border: 1px solid rgba(99,102,241,0.3); }
          .gs-template-modal .bubble.them { background: rgba(34,211,238,0.1); border: 1px solid rgba(34,211,238,0.2); }
          .gdc-chat-chip {
            display: inline-block; padding: 6px 12px; margin: 4px 4px 4px 0;
            background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.3);
            border-radius: 16px; color: #a5b4fc; font-size: 11px; cursor: pointer;
            transition: all 0.2s;
          }
          .gdc-chat-chip:hover { background: rgba(99,102,241,0.25); color: #fff; transform: translateY(-1px); }

          .gs-template-modal iframe { --hide-back: 1; --hide-chat: 1; }
          @keyframes msgSlideIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        </style>
        <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:linear-gradient(135deg, #1e293b, #0f172a); border-bottom:1px solid rgba(99,102,241,0.3);">
          <div style="display:flex; align-items:center; gap:12px;">
            <span style="font-size:20px;">&#9998;</span>
            <span style="color:#e5e7eb; font-weight:700; font-size:16px;">Edit: ${pageTitle} <span style="font-weight:400; opacity:0.7; font-size:12px;">(ID: ${pageId})</span></span>
          </div>
          <button class="gdc-modal-close" style="
            background:rgba(239,68,68,0.2); border:1px solid rgba(239,68,68,0.4);
            color:#f87171; padding:8px 16px; border-radius:8px; cursor:pointer; font-weight:600;
          ">✕ Close</button>
        </div>
        <div style="flex:1; display:flex; overflow:hidden;">
          <div style="flex:1; background:#fff; position:relative;">
            <iframe src="${editUrl}" style="width:100%; height:100%; border:none;"></iframe>
            <div class="gdc-iframe-loading" style="
              position:absolute; top:0; left:0; right:0; bottom:0;
              background:linear-gradient(135deg, #1e293b, #0f172a);
              display:flex; align-items:center; justify-content:center;
              color:#e5e7eb; font-size:16px;
            ">
              <div style="text-align:center;">
                <div style="font-size:32px; margin-bottom:12px;">⏳</div>
                <div>Loading editor...</div>
              </div>
            </div>
          </div>
          <div class="gdc-ai-panel" style="
            width:350px; background:linear-gradient(135deg, #1e293b, #0f172a);
            border-left:1px solid rgba(99,102,241,0.3); display:flex; flex-direction:column;
          ">
            <div style="padding:12px; border-bottom:1px solid rgba(99,102,241,0.2);">
              <div style="display:flex; align-items:center; gap:8px; color:#e5e7eb; font-weight:600;">
                <span style="font-size:18px;">💬</span><span>AI Assistant</span>
              </div>
            </div>
             <div class="gdc-ai-chat-messages" style="flex:1; padding:12px; overflow-y:auto; display:flex; flex-direction:column;">
              <div class="bubble-wrap them">
                <div class="bubble-avatar" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); display: flex; align-items: center; justify-content: center; font-size: 18px;">🤖</div>
                <div class="bubble them" id="gdc-ai-welcome-msg">
                  ${this.getWelcomeMessage(workflow)}
                </div>
              </div>

            </div>
            <div style="padding:12px; border-top:1px solid rgba(99,102,241,0.2);">
              <div style="display:flex; gap:8px;">
                <input type="text" class="gdc-ai-input" placeholder="Ask AI to help..." style="
                  flex:1; padding:10px 12px; background:rgba(255,255,255,0.05);
                  border:1px solid rgba(99,102,241,0.3); border-radius:8px;
                  color:#e5e7eb; font-size:13px;
                ">
                <button class="gdc-ai-send" style="
                  padding:10px 16px; background:linear-gradient(135deg, #6366f1, #8b5cf6);
                  border:none; border-radius:8px; color:white; cursor:pointer; font-weight:600;
                ">Send</button>
              </div>

            </div>
          </div>
        </div>
      `;

      document.body.appendChild(this.modal);

      // Setup event handlers
      this.setupHandlers();
    }

    /**
     * Setup event handlers for modal
     */
    setupHandlers() {
      if (!this.modal) return;

      const iframe = this.modal.querySelector('iframe');
      const loading = this.modal.querySelector('.gdc-iframe-loading');
      const closeBtn = this.modal.querySelector('.gdc-modal-close');
      const aiInput = this.modal.querySelector('.gdc-ai-input');
      const aiSend = this.modal.querySelector('.gdc-ai-send');
      const aiMessages = this.modal.querySelector('.gdc-ai-chat-messages');

      // Hide loading when iframe loads
      iframe.addEventListener('load', () => {
        loading.style.display = 'none';
        // Inject CSS to hide WordPress admin bar and chat widget in iframe
        try {
          const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
          const hideStyle = iframeDoc.createElement('style');
          hideStyle.textContent = `
            :root, body { --gs-header-h: 0px !important; }
            #gs-admin-header, #wpadminbar, #adminmenumain, aipa-widget { display: none !important; }
            html, body, #wpwrap, #wpcontent { margin-top: 0 !important; padding-top: 0 !important; margin-left: 0 !important; padding-left: 0 !important; }
            .interface-interface-skeleton { top: 0 !important; }
          `;
          iframeDoc.head.appendChild(hideStyle);
        } catch (e) {
          console.log('[GDC Template Modal] Could not inject iframe CSS:', e);
        }
      });

      // Close button
      closeBtn.addEventListener('click', () => this.close());

      // ESC key to close
      const escHandler = (e) => {
        if (e.key === 'Escape') {
          this.close();
          document.removeEventListener('keydown', escHandler);
        }
      };
      document.addEventListener('keydown', escHandler);

      // Chip buttons
      aiMessages.addEventListener('click', (e) => {
        if (e.target.matches('.gdc-chat-chip')) {
          const msg = e.target.getAttribute('data-msg');
          aiInput.value = msg;
          this.sendAIMessage(aiInput, aiMessages, iframe);
        }
      });

      // Send button
      aiSend.addEventListener('click', () => this.sendAIMessage(aiInput, aiMessages, iframe));

      // Enter key in input
      aiInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
          this.sendAIMessage(aiInput, aiMessages, iframe);
        }
      });
    }

    /**
     * Open AI chat inline in the provided container
     */
    openInline(containerSelector, workflow = 'content_writer') {
      this.close();
      this.currentWorkflow = workflow;
      this.isInline = true;

      console.log('[GS Template Modal] Opening Inline:', { containerSelector, workflow });

      const container = document.querySelector(containerSelector);
      if (!container) {
        console.error('[GS Template Modal] Container not found:', containerSelector);
        return;
      }

      this.panel = document.createElement('div');
      this.panel.className = 'gs-template-panel-root';
      this.panel.style.cssText = `
        display: flex; flex-direction: column; width: 350px; height: 100%; max-height: 100%;
        border-left: 1px solid rgba(99,102,241,0.3); z-index: 100000;
        background: linear-gradient(135deg, #1e293b, #0f172a);
        overflow: hidden; box-sizing: border-box; flex-shrink: 0;
      `;

      this.panel.innerHTML = `
        <style>
          .gs-template-panel-root .bubble-wrap { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 10px; animation: msgSlideIn 0.3s ease; }
          .gs-template-panel-root .bubble-wrap.me { flex-direction: row-reverse; }
          .gs-template-panel-root .bubble-avatar { 
            width: 32px; height: 32px; min-width: 32px; border-radius: 50%; 
            background-size: cover; background-position: center; flex-shrink: 0;
            box-shadow: 0 0 10px rgba(34,211,238,0.3); border: 1px solid rgba(99,102,241,0.4);
          }
          .gs-template-panel-root .bubble { 
            max-width: 80%; padding: 10px 12px; border-radius: 12px; font-size: 13px; color: #e5e7eb;
          }
          .gs-template-panel-root .bubble.me { background: rgba(99,102,241,0.2); border: 1px solid rgba(99,102,241,0.3); }
          .gs-template-panel-root .bubble.them { background: rgba(34,211,238,0.1); border: 1px solid rgba(34,211,238,0.2); }
          .gs-template-panel-root .gdc-chat-chip {
            display: inline-block; padding: 6px 12px; margin: 4px 4px 4px 0;
            background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.3);
            border-radius: 16px; color: #a5b4fc; font-size: 11px; cursor: pointer;
            transition: all 0.2s;
          }
          .gs-template-panel-root .gdc-chat-chip:hover { background: rgba(99,102,241,0.25); color: #fff; transform: translateY(-1px); }
        </style>
        <div class="gdc-ai-panel" style="
          width:100%; height:100%;
          display:flex; flex-direction:column;
        ">
          <div style="padding:12px; border-bottom:1px solid rgba(99,102,241,0.2); display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:8px; color:#e5e7eb; font-weight:600;">
              <span style="font-size:18px;">💬</span><span>AI Assistant</span>
            </div>
            <button class="gdc-modal-close" style="
              background:rgba(239,68,68,0.2); border:1px solid rgba(239,68,68,0.4);
              color:#f87171; padding:4px 8px; border-radius:4px; font-size:11px; cursor:pointer; font-weight:600;
            ">✕ Close</button>
          </div>
           <div class="gdc-ai-chat-messages" style="flex:1; padding:12px; overflow-y:auto; display:flex; flex-direction:column;">
            <div class="bubble-wrap them">
              <div class="bubble-avatar" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); display: flex; align-items: center; justify-content: center; font-size: 18px;">🤖</div>
              <div class="bubble them" id="gdc-ai-welcome-msg">
                ${this.getWelcomeMessage(workflow)}
              </div>
            </div>

          </div>
          <div style="padding:12px; border-top:1px solid rgba(99,102,241,0.2);">
            <div style="display:flex; gap:8px;">
              <input type="text" class="gdc-ai-input" placeholder="Ask AI to help..." style="
                flex:1; padding:10px 12px; background:rgba(255,255,255,0.05);
                border:1px solid rgba(99,102,241,0.3); border-radius:8px;
                color:#e5e7eb; font-size:13px;
              ">
              <button class="gdc-ai-send" style="
                padding:10px 16px; background:linear-gradient(135deg, #6366f1, #8b5cf6);
                border:none; border-radius:8px; color:white; cursor:pointer; font-weight:600;
              ">Send</button>
            </div>
          </div>
        </div>
      `;

      container.appendChild(this.panel);

      const aiInput = this.panel.querySelector('.gdc-ai-input');
      const aiSend = this.panel.querySelector('.gdc-ai-send');
      const aiMessages = this.panel.querySelector('.gdc-ai-chat-messages');
      const closeBtn = this.panel.querySelector('.gdc-modal-close');

      closeBtn.addEventListener('click', () => {
        this.close();
        // Fire custom event so parent orchestrator can react
        document.dispatchEvent(new CustomEvent('gs-ai-panel-closed'));
      });

      // Setup handlers specifically for inline panel
      aiMessages.addEventListener('click', (e) => {
        if (e.target.matches('.gdc-chat-chip')) {
          const msg = e.target.getAttribute('data-msg');
          aiInput.value = msg;
          this.sendAIMessage(aiInput, aiMessages, null); // Pass null for iframe in inline mode
        }
      });

      aiSend.addEventListener('click', () => this.sendAIMessage(aiInput, aiMessages, null));

      aiInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
          this.sendAIMessage(aiInput, aiMessages, null);
        }
      });
    }

    /**
     * Send AI message and handle response
     */
    async sendAIMessage(inputEl, messagesEl, iframe) {
      const message = inputEl.value.trim();
      if (!message) return;

      // Add user message to chat
      const userWrap = document.createElement('div');
      userWrap.className = 'bubble-wrap me';
      userWrap.innerHTML = `
        <div class="bubble-avatar" style="background: linear-gradient(135deg, #3b82f6, #2563eb); display: flex; align-items: center; justify-content: center; font-size: 14px; color: white;">U</div>
        <div class="bubble me">${this.escapeHtml(message)}</div>
      `;
      messagesEl.appendChild(userWrap);
      messagesEl.scrollTop = messagesEl.scrollHeight;

      // Clear input
      inputEl.value = '';

      // Get page context from iframe or window
      let pageContext = '';
      let currentBlocks = null;

      const win = this.isInline ? window : (iframe ? iframe.contentWindow : null);

      if (win && win.wp && win.wp.data) {
        try {
          const { select } = win.wp.data;
          const blocks = select('core/block-editor').getBlocks();

          // For header_designer, footer_designer, content_writer, blog_architect AND email_designer workflows, send full block tree for iteration
          if ((this.currentWorkflow === 'header_designer' || this.currentWorkflow === 'footer_designer' || this.currentWorkflow === 'content_writer' || this.currentWorkflow === 'blog_architect' || this.currentWorkflow === 'email_designer') && blocks && blocks.length > 0) {
            currentBlocks = blocks.map(block => ({
              blockName: block.name,
              attrs: block.attributes,
              innerBlocks: this.serializeInnerBlocks(block.innerBlocks || [])
            }));
          } else if (blocks && blocks.length > 0) {
            // For other workflows, send text summary
            pageContext = '\n\nCURRENT PAGE STRUCTURE:\n';
            blocks.forEach((block, idx) => {
              const blockName = block.name || 'unknown';
              const content = block.attributes?.content || block.attributes?.text || '';
              const cleanContent = content.replace(/<[^>]*>/g, '').substring(0, 100);
              pageContext += `${idx}. [${blockName}] ${cleanContent}\n`;
            });
          }
        } catch (e) {
          console.log('[GDC Template Modal] Could not read page structure:', e);
        }
      }

      // Send to AI (using Gemini API similar to widget-app.js)
      try {
        const requestBody = {
          message: message + pageContext,
          workflow: this.currentWorkflow || 'content_writer',
          enable_tools: true
        };

        // Add current blocks for header_designer iterations
        if (currentBlocks) {
          requestBody.current_blocks = currentBlocks;
        }

        const response = await fetch(`${this.restBase}/aipa/v1/chat-gemini`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': this.nonce
          },
          body: JSON.stringify(requestBody)
        });

        if (response.ok) {
          const data = await response.json();

          // Parse AI response - could be action-based or regular chat
          let chatMessage = '';
          let actionData = null;


          // Check if response contains an action (new tool-based format)
          if (data.reply) {
            try {
              // Strip markdown code blocks if present
              let cleanReply = data.reply.trim();
              const codeBlockMatch = cleanReply.match(/```(?:json)?\s*\n?([\s\S]*?)\n?```/);
              if (codeBlockMatch) {
                cleanReply = codeBlockMatch[1].trim();
              }

              // Try to parse reply as JSON action
              const parsed = JSON.parse(cleanReply);
              if (parsed.action && parsed.chat_response) {
                actionData = parsed;
                chatMessage = parsed.chat_response;
              } else {
                // Not an action, just regular chat
                chatMessage = data.reply;
              }
            } catch (e) {
              // Not JSON, just regular text
              chatMessage = data.reply;
            }
          } else {
            chatMessage = data.response || data.message || 'I\'m here to help!';
          }

          // Add AI response to chat
          const aiWrap = document.createElement('div');
          aiWrap.className = 'bubble-wrap them';
          aiWrap.innerHTML = `
            <div class="bubble-avatar" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); display: flex; align-items: center; justify-content: center; font-size: 18px;">🤖</div>
            <div class="bubble them">${this.formatMessage(chatMessage)}</div>
          `;
          messagesEl.appendChild(aiWrap);
          messagesEl.scrollTop = messagesEl.scrollHeight;

          // Execute action if present
          if (actionData) {
            await this.executeAction(actionData, iframe);
          }

          // Execute any tool calls if present (legacy format)
          if (data.tool_calls && Array.isArray(data.tool_calls)) {
            for (const toolCall of data.tool_calls) {
              await this.executeToolCall(toolCall, iframe);
            }
          }
        } else {
          this.showError(messagesEl, 'Sorry, I couldn\'t process that request. Please try again.');
        }
      } catch (error) {
        console.error('[GDC Template Modal] AI request failed:', error);
        this.showError(messagesEl, 'Connection error. Please check your internet connection and try again.');
      }
    }

    /**
     * Execute AI tool call on the iframe's block editor
     */
    async executeToolCall(toolCall, iframe) {
      const win = this.isInline ? window : (iframe ? iframe.contentWindow : null);
      if (!win || !win.wp || !win.wp.data || !win.wp.blocks) {
        console.error('[GDC Template Modal] WordPress Editor API not available.');
        return;
      }

      const { dispatch, select } = win.wp.data;
      const { createBlock } = win.wp.blocks;

      console.log('[GDC Template Modal] Executing tool:', toolCall);

      try {
        const action = toolCall.action || toolCall.name;

        if (action === 'insert_block') {
          const blockType = toolCall.block_type || 'core/paragraph';
          const attrs = toolCall.attributes || {};
          const newBlock = createBlock(blockType, attrs);

          let index = undefined;
          const position = toolCall.position;

          if (position === 'top' || position === 0) {
            index = 0;
          } else if (typeof position === 'number' && position > 0) {
            index = position;
          }

          dispatch('core/block-editor').insertBlocks(newBlock, index);
          console.log('[GDC Template Modal] Inserted block:', blockType);
        }
        // Add more tool call handlers as needed (update_block_attribute, delete_block, etc.)

      } catch (e) {
        console.error('[GDC Template Modal] Tool execution error:', e);
      }
    }

    /**
     * Execute action from AI (new tool-based format)
     */
    async executeAction(actionData, iframe) {
      const win = this.isInline ? window : (iframe ? iframe.contentWindow : null);
      if (!win || !win.wp || !win.wp.data || !win.wp.blocks) {
        console.error('[GDC Template Modal] WordPress Editor API not available.');
        return;
      }

      const { dispatch, select } = win.wp.data;
      const { parse } = win.wp.blocks;

      console.log('[GDC Template Modal] Executing action:', actionData.action);

      try {
        if (actionData.action === 'replace_all') {
          // Replace entire header content
          const blocks = actionData.blocks || [];

          // Parse blocks from serialized format if needed
          let parsedBlocks = [];
          if (typeof blocks === 'string') {
            parsedBlocks = parse(blocks);
          } else if (Array.isArray(blocks)) {
            // Convert from block objects to wp blocks
            parsedBlocks = this.convertToWPBlocks(blocks, win.wp.blocks);
          }

          // Clear existing blocks
          const existingBlocks = select('core/block-editor').getBlocks();
          const existingIds = existingBlocks.map(block => block.clientId);
          dispatch('core/block-editor').removeBlocks(existingIds);

          // Insert new blocks
          dispatch('core/block-editor').insertBlocks(parsedBlocks);
          console.log('[GDC Template Modal] Replaced all blocks with', parsedBlocks.length, 'new block(s)');
        }
        else if (actionData.action === 'insert_block') {
          // Insert single block at position
          const block = actionData.block;
          const position = actionData.position || 'bottom';

          let wpBlock;
          if (typeof block === 'string') {
            [wpBlock] = parse(block);
          } else {
            wpBlock = this.convertToWPBlocks([block], win.wp.blocks)[0];
          }

          const existingBlocks = select('core/block-editor').getBlocks();
          const index = position === 'top' ? 0 : existingBlocks.length;

          dispatch('core/block-editor').insertBlocks(wpBlock, index);
          console.log('[GDC Template Modal] Inserted block at', position);
        }
        else if (actionData.action === 'update_block') {
          // Update existing block attributes
          const blockIndex = actionData.block_index || 0;
          const updates = actionData.updates || {};

          const existingBlocks = select('core/block-editor').getBlocks();
          if (existingBlocks[blockIndex]) {
            const targetBlock = existingBlocks[blockIndex];
            const clientId = targetBlock.clientId;

            // Update block attributes
            if (updates.attrs) {
              dispatch('core/block-editor').updateBlockAttributes(clientId, updates.attrs);
              console.log('[GDC Template Modal] Updated block attributes at index', blockIndex);
            }
          }
        }
        else if (actionData.action === 'generate_image') {
          // Generate image via Vertex AI Imagen
          console.log('[GDC Template Modal] Generating image:', actionData.prompt);

          try {
            const response = await fetch(`${this.restBase}/aipa/v1/generate-image`, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': this.nonce
              },
              body: JSON.stringify({
                prompt: actionData.prompt,
                aspect_ratio: actionData.aspect_ratio || '1:1'
              })
            });

            if (response.ok) {
              const result = await response.json();
              if (result.success && result.image_url) {
                console.log('[GDC Template Modal] Image generated:', result.image_url);

                // Insert image block based on insert_as type
                const insertAs = actionData.insert_as || 'image';
                const existingBlocks = select('core/block-editor').getBlocks();

                if (insertAs === 'logo') {
                  // Find existing logo block and replace it
                  let logoReplaced = false;

                  for (let i = 0; i < existingBlocks.length; i++) {
                    const block = existingBlocks[i];

                    // Check if this block is a site-logo
                    if (block.name === 'core/site-logo') {
                      dispatch('core/block-editor').updateBlockAttributes(block.clientId, {
                        url: result.image_url,
                        id: result.attachment_id
                      });
                      console.log('[GDC Template Modal] Replaced existing site logo');
                      logoReplaced = true;
                      break;
                    }

                    // Check innerBlocks for nested logos
                    if (block.innerBlocks && block.innerBlocks.length > 0) {
                      for (let j = 0; j < block.innerBlocks.length; j++) {
                        const innerBlock = block.innerBlocks[j];
                        if (innerBlock.name === 'core/site-logo') {
                          dispatch('core/block-editor').updateBlockAttributes(innerBlock.clientId, {
                            url: result.image_url,
                            id: result.attachment_id
                          });
                          console.log('[GDC Template Modal] Replaced existing nested site logo');
                          logoReplaced = true;
                          break;
                        }
                      }
                    }
                    if (logoReplaced) break;
                  }

                  // If no logo found, insert new one at top
                  if (!logoReplaced) {
                    const imageBlock = win.wp.blocks.createBlock('core/site-logo', {
                      url: result.image_url,
                      id: result.attachment_id
                    });
                    dispatch('core/block-editor').insertBlocks(imageBlock, 0);
                    console.log('[GDC Template Modal] Inserted new site logo at top');
                  }

                } else if (insertAs === 'background') {
                  const imageBlock = win.wp.blocks.createBlock('core/cover', {
                    url: result.image_url,
                    id: result.attachment_id
                  });
                  dispatch('core/block-editor').insertBlocks(imageBlock, existingBlocks.length);
                  console.log('[GDC Template Modal] Inserted cover block as background');

                } else {
                  const imageBlock = win.wp.blocks.createBlock('core/image', {
                    url: result.image_url,
                    id: result.attachment_id
                  });
                  dispatch('core/block-editor').insertBlocks(imageBlock, existingBlocks.length);
                  console.log('[GDC Template Modal] Inserted image block');
                }
              }
            }
          } catch (error) {
            console.error('[GDC Template Modal] Image generation failed:', error);
          }
        }
      } catch (e) {
        console.error('[GDC Template Modal] Action execution error:', e);
      }
    }

    /**
     * Convert block objects to WordPress blocks
     */
    convertToWPBlocks(blocks, wpBlocksAPI) {
      const { createBlock } = wpBlocksAPI;

      return blocks.map(block => {
        const innerBlocks = block.innerBlocks ? this.convertToWPBlocks(block.innerBlocks, wpBlocksAPI) : [];
        return createBlock(
          block.blockName || block.name || 'core/paragraph',
          block.attrs || block.attributes || {},
          innerBlocks
        );
      });
    }

    /**
     * Serialize inner blocks recursively
     */
    serializeInnerBlocks(blocks) {
      return blocks.map(block => ({
        blockName: block.name,
        attrs: block.attributes,
        innerBlocks: block.innerBlocks ? this.serializeInnerBlocks(block.innerBlocks) : []
      }));
    }

    /**
     * Show error message in chat
     */
    showError(messagesEl, errorText) {
      const errorWrap = document.createElement('div');
      errorWrap.className = 'bubble-wrap them';
      errorWrap.innerHTML = `
        <div class="bubble-avatar" style="background: linear-gradient(135deg, #ef4444, #dc2626); display: flex; align-items: center; justify-content: center; font-size: 18px;">⚠️</div>
        <div class="bubble them" style="border-color: rgba(239,68,68,0.3);">${this.escapeHtml(errorText)}</div>
      `;
      messagesEl.appendChild(errorWrap);
      messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    /**
     * Format message with markdown-like formatting
     */
    formatMessage(msg) {
      return msg
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/\n/g, '<br>');
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    /**
     * Close the modal
     */
    close() {
      if (this.modal) {
        this.modal.remove();
        this.modal = null;
        if (this.originalBodyOverflow !== undefined) {
          document.body.style.overflow = this.originalBodyOverflow;
        }
      }
      if (this.panel) {
        this.panel.remove();
        this.panel = null;
      }
      this.isInline = false;
    }
  }

  // Make available globally
  window.GS_TemplateModal = new GS_TemplateModal();

  // Wire up button clicks when DOM is ready
  document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.gs-open-template-modal');
      if (btn) {
        const pageId = btn.dataset.pageId;
        const pageTitle = btn.dataset.pageTitle;
        const editUrl = btn.dataset.editUrl;
        const workflow = btn.dataset.workflow || 'content_writer';

        if (pageId && editUrl) {
          window.GS_TemplateModal.open(pageId, pageTitle, editUrl, workflow);
        }
      }
    });
  });

})();
