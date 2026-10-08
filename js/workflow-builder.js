/**
 * CUBOIDPILOT / CAI — VISUAL AI WORKFLOW AUTOMATION BUILDER (js/workflow-builder.js)
 * Standalone, high-performance canvas engine with Intercom-inspired design language.
 * Node drag-and-drop, SVG bezier routing, multi-branching, dynamic inspector,
 * interactive sandbox chat simulation, and template management.
 */

window.CaiWorkflowBuilder = {
  // State
  currentWorkflow: null,
  activeView: 'list', // 'list' | 'editor'
  selectedNodeId: null,
  isConnecting: false,
  connectionSource: null, // { nodeId, handleId }
  zoom: 1.0,
  pan: { x: 40, y: 40 },
  isPanning: false,
  startPan: { x: 0, y: 0 },
  undoStack: [],
  redoStack: [],
  catalogContext: { products: [], assets: [], knowledge_sources: [] },
  simulationState: { activeExecutionId: null, sessionHistory: [] },

  // Node Type Definitions across all 8 Categories
  nodeDefinitions: {
    // CATEGORY A — TRIGGERS
    'trigger_chat_start': { title: 'New Chat Started', cat: 'triggers', icon: 'message-square', desc: 'Triggered when visitor opens widget', defaultData: { label: 'New Chat Started' } },
    'trigger_new_visitor': { title: 'New Visitor / Lead Created', cat: 'triggers', icon: 'user-plus', desc: 'First-time website visitor ingestion', defaultData: { label: 'New Visitor Intake' } },
    'trigger_visitor_returns': { title: 'Visitor Returns', cat: 'triggers', icon: 'repeat', desc: 'Known visitor returns to site', defaultData: { label: 'Visitor Returns' } },
    'trigger_customer_message': { title: 'Customer Message Received', cat: 'triggers', icon: 'message-circle', desc: 'Inbound message from prospect', defaultData: { label: 'Message Received' } },
    'trigger_intent_detected': { title: 'Intent Detected', cat: 'triggers', icon: 'zap', desc: 'Commercial intent threshold matched', defaultData: { label: 'Intent Detected' } },
    'trigger_product_selected': { title: 'Product Selected', cat: 'triggers', icon: 'shopping-bag', desc: 'Prospect clicks product in catalog', defaultData: { label: 'Product Selected' } },
    'trigger_payment_initiated': { title: 'Payment Initiated', cat: 'triggers', icon: 'credit-card', desc: 'Checkout link opened by user', defaultData: { label: 'Payment Initiated' } },
    'trigger_payment_confirmed': { title: 'Payment Confirmed', cat: 'triggers', icon: 'check-circle-2', desc: 'Verified payment webhook received', defaultData: { label: 'Payment Confirmed' } },
    'trigger_appointment_created': { title: 'Appointment Created', cat: 'triggers', icon: 'calendar', desc: 'Consultation or demo slot booked', defaultData: { label: 'Appointment Created' } },
    'trigger_scheduled': { title: 'Scheduled Recurring Trigger', cat: 'triggers', icon: 'clock', desc: 'Time-based schedule or lookahead', defaultData: { label: 'Scheduled Trigger' } },

    // CATEGORY B — AI INTELLIGENCE
    'ai_response_generator': { title: 'AI Response Generator', cat: 'ai', icon: 'sparkles', desc: 'Grounded Gemini answer with company knowledge', defaultData: { label: 'AI Response', prompt: 'Answer the customer helpfully using verified knowledge.', temperature: 0.3 } },
    'ai_intent_detection': { title: 'AI Intent Detection', cat: 'ai', icon: 'git-branch', desc: 'Multi-intent routing with fallback', defaultData: { label: 'Intent Detection', intents: { 'inquiry': 'General inquiry', 'pricing': 'Fee and pricing' } } },
    'ai_product_recommendation': { title: 'AI Product Recommendation', cat: 'ai', icon: 'compass', desc: 'Personalized product matching from catalog', defaultData: { label: 'Recommend Products', category: 'course' } },
    'ai_qualification': { title: 'AI Lead Qualification', cat: 'ai', icon: 'clipboard-check', desc: 'Qualify requirements and extract contact info', defaultData: { label: 'Qualify Prospect', question: 'Which program or skill are you looking to master?' } },
    'ai_decision': { title: 'AI Structured Decision', cat: 'ai', icon: 'split', desc: 'Structured branch routing via validated JSON', defaultData: { label: 'AI Decision', criteria: 'Evaluate buyer intent' } },
    'ai_summary': { title: 'AI Conversation Summary', cat: 'ai', icon: 'file-text', desc: 'Generate executive briefing for CRM handoff', defaultData: { label: 'Summarize Chat' } },

    // CATEGORY C — CUSTOMER MESSAGES
    'msg_plain_text': { title: 'Plain Text Message', cat: 'messages', icon: 'align-left', desc: 'Send static or personalized text', defaultData: { label: 'Send Text', message: 'Hi {{customer.name}}! How can we help you today?' } },
    'msg_quick_replies': { title: 'Quick Reply Buttons', cat: 'messages', icon: 'list', desc: 'Interactive response chips', defaultData: { label: 'Quick Replies', message: 'Choose your preferred option:', options: ['Explore Courses', 'Fee & EMI Plans', 'Speak with Counselor'] } },
    'msg_course_carousel': { title: 'Course Carousel', cat: 'messages', icon: 'layout-grid', desc: 'Interactive program cards from catalog', defaultData: { label: 'Course Carousel', title: 'Explore our flagship cohorts:', category: 'course', limit: 4 } },
    'msg_product_carousel': { title: 'Product Carousel', cat: 'messages', icon: 'grid', desc: 'Product cards with pricing and details', defaultData: { label: 'Product Carousel', category: '', limit: 4 } },
    'msg_emi_card': { title: 'EMI Options Card', cat: 'messages', icon: 'calculator', desc: 'Zero-cost 3-month installment breakdown', defaultData: { label: '0% EMI Calculator' } },
    'msg_payment_link': { title: 'Payment Link Card', cat: 'messages', icon: 'link', desc: 'Verified checkout card with 1-tap payment', defaultData: { label: 'Payment Checkout Card' } },
    'msg_appointment_card': { title: 'Appointment Booking Card', cat: 'messages', icon: 'calendar-days', desc: 'Interactive Google Meet slot picker', defaultData: { label: 'Calendar Slot Picker' } },
    'msg_brochure_download': { title: 'Brochure Download Card', cat: 'messages', icon: 'download', desc: 'Deliver verified syllabus or brochure PDF', defaultData: { label: 'Deliver Brochure' } },

    // CATEGORY D — CONDITIONS & LOGIC
    'logic_if_else': { title: 'If / Else Condition', cat: 'logic', icon: 'git-commit', desc: 'Binary conditional branching', defaultData: { label: 'Condition Check', variable: 'customer.interest', operator: 'is_set', value: '' } },
    'logic_emi_availability': { title: 'EMI Availability Check', cat: 'logic', icon: 'percent', desc: 'Branch on 0% EMI financing eligibility', defaultData: { label: 'EMI Check' } },
    'logic_response_match': { title: 'Customer Response Match', cat: 'logic', icon: 'search', desc: 'Regex or keyword response matcher', defaultData: { label: 'Match Response', pattern: 'yes|sure|okay' } },

    // CATEGORY E — CRM & BUSINESS ACTIONS
    'crm_create_lead': { title: 'Auto Create Lead in CRM', cat: 'crm', icon: 'user-plus', desc: 'Instantly creates lead in CRM with status and tags', defaultData: { label: 'Auto Create & Tag Lead', stage: 'QUALIFIED', tags: 'website_visitor,ai_engaged' } },
    'crm_update_lead': { title: 'Update Lead in CRM', cat: 'crm', icon: 'user-check', desc: 'Advance pipeline stage or priority', defaultData: { label: 'Update Lead', stage: 'QUALIFIED' } },
    'crm_request_handoff': { title: 'Request Human Handoff', cat: 'crm', icon: 'headphones', desc: 'Transfer conversation to human counselor', defaultData: { label: 'Human Handoff' } },

    // CATEGORY G — REMINDERS & AUTOMATION
    'comm_channel_dispatch': { title: 'Multichannel Dispatch', cat: 'reminders', icon: 'send', desc: 'Option to dispatch complete details to Email or WhatsApp', defaultData: { label: 'Multichannel Dispatch (Email / WhatsApp)', message: 'Would you like us to email you the complete curriculum and invoice, or send it directly on WhatsApp?' } },
    'comm_schedule_reminder': { title: 'Schedule Reminder', cat: 'reminders', icon: 'bell', desc: 'Scheduled WhatsApp reminder follow-up', defaultData: { label: 'Schedule Reminder', delay_minutes: 1440, message: 'Hi {{customer.name}}, following up on your inquiry!' } },

    // CATEGORY H — WORKFLOW CONTROL
    'control_wait_reply': { title: 'Wait for Customer Reply', cat: 'control', icon: 'pause', desc: 'Pause journey until customer answers', defaultData: { label: 'Wait for Reply' } },
    'control_end': { title: 'End Workflow', cat: 'control', icon: 'stop-circle', desc: 'Terminate workflow execution', defaultData: { label: 'Workflow End' } }
  },

  init: function() {
    this.bindDomEvents();
    this.loadCatalogContext();
    this.loadWorkflowsList();
  },

  // Switch between List View and Visual Canvas Editor
  switchView: function(view, workflowId = null) {
    this.activeView = view;
    const listView = document.getElementById('view-workflow-list');
    const editorView = document.getElementById('view-workflow-editor');

    if (view === 'editor') {
      if (listView) listView.classList.add('hidden');
      if (editorView) editorView.classList.remove('hidden');
      if (workflowId) {
        this.loadWorkflow(workflowId);
      } else {
        this.createNewWorkflow();
      }
    } else {
      if (editorView) editorView.classList.add('hidden');
      if (listView) listView.classList.remove('hidden');
      this.loadWorkflowsList();
    }
  },

  // 1. Load Workflows List
  loadWorkflowsList: async function(filter = 'all') {
    const listContainer = document.getElementById('automations-list-container');
    if (!listContainer) return;

    try {
      const res = await fetch('../api/automations.php?action=list');
      const data = await res.json();

      if (!data.success) {
        listContainer.innerHTML = `<div class="p-8 text-center text-xs text-red-500">Error loading workflows: ${data.error}</div>`;
        return;
      }

      // Update counters
      const metrics = data.metrics || {};
      const countAll = document.getElementById('auto-tab-count-all');
      const countActive = document.getElementById('auto-tab-count-active');
      const countDraft = document.getElementById('auto-tab-count-draft');
      const countPaused = document.getElementById('auto-tab-count-paused');

      if (countAll) countAll.textContent = metrics.total || 0;
      if (countActive) countActive.textContent = metrics.active || 0;
      if (countDraft) countDraft.textContent = metrics.draft || 0;
      if (countPaused) countPaused.textContent = metrics.paused || 0;

      // Update metrics strip
      const metricTotal = document.getElementById('metric-total-workflows');
      const metricActive = document.getElementById('metric-active-workflows');
      const metricRuns = document.getElementById('metric-total-executions');
      if (metricTotal) metricTotal.textContent = metrics.total || 0;
      if (metricActive) metricActive.textContent = metrics.active || 0;
      if (metricRuns) metricRuns.textContent = Number(metrics.total_executions || 0).toLocaleString();

      const rules = data.rules || [];
      let filtered = rules;
      if (filter !== 'all') {
        filtered = rules.filter(r => r.status === filter);
      }

      if (filtered.length === 0) {
        listContainer.innerHTML = `
          <div class="p-12 text-center bg-white border border-[#e7e5de] rounded-[6px] space-y-3">
            <div class="w-10 h-10 rounded-full bg-stone-100 border border-stone-200 text-stone-500 flex items-center justify-center mx-auto">
              <i data-lucide="git-branch" class="w-5 h-5"></i>
            </div>
            <h3 class="text-sm font-semibold text-stone-900">No workflows found</h3>
            <p class="text-xs text-stone-500 max-w-sm mx-auto">Create a visual AI automation or start from our ready-to-use production templates.</p>
            <div class="pt-2 flex items-center justify-center gap-2">
              <button onclick="CaiWorkflowBuilder.openTemplatesModal()" class="btn-secondary btn-sm text-xs py-1.5 px-3">Browse Templates</button>
              <button onclick="CaiWorkflowBuilder.switchView('editor')" class="btn-primary btn-sm text-xs py-1.5 px-3">Create from Scratch</button>
            </div>
          </div>
        `;
        if (window.lucide) lucide.createIcons();
        return;
      }

      let html = '';
      filtered.forEach(wf => {
        const statusBadge = wf.status === 'active'
          ? `<span class="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded text-[11px] font-medium"><span class="w-1.5 h-1.5 rounded-full bg-emerald-600 animate-pulse"></span> Active</span>`
          : (wf.status === 'draft'
            ? `<span class="inline-flex items-center gap-1 px-2 py-0.5 bg-amber-50 text-amber-800 border border-amber-200 rounded text-[11px] font-medium">Draft</span>`
            : `<span class="inline-flex items-center gap-1 px-2 py-0.5 bg-stone-100 text-stone-600 border border-stone-200 rounded text-[11px] font-medium">Paused</span>`);

        html += `
          <div class="p-4 bg-white border border-[#e7e5de] hover:border-stone-400 rounded-[6px] transition-all flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="space-y-1.5 flex-1 cursor-pointer" onclick="CaiWorkflowBuilder.switchView('editor', ${wf.id})">
              <div class="flex items-center gap-2.5">
                <span class="text-xs font-semibold text-stone-900 hover:underline">${this.escapeHtml(wf.name)}</span>
                ${statusBadge}
                <span class="text-[10px] px-1.5 py-0.5 bg-stone-100 text-stone-600 rounded border border-stone-200 font-mono">v${wf.version || 1}</span>
              </div>
              <p class="text-xs text-stone-500 line-clamp-1">${this.escapeHtml(wf.description)}</p>
              <div class="flex items-center gap-4 text-[11px] text-stone-400 pt-1">
                <span class="inline-flex items-center gap-1"><i data-lucide="zap" class="w-3 h-3 text-stone-500"></i> ${this.escapeHtml(wf.trigger_label)}</span>
                <span class="inline-flex items-center gap-1"><i data-lucide="layers" class="w-3 h-3 text-stone-500"></i> ${wf.node_count || 3} Nodes</span>
                <span class="inline-flex items-center gap-1"><i data-lucide="play" class="w-3 h-3 text-stone-500"></i> ${wf.execution_count || 0} Runs</span>
                <span class="inline-flex items-center gap-1"><i data-lucide="clock" class="w-3 h-3 text-stone-500"></i> Last Run: ${wf.last_executed}</span>
              </div>
            </div>

            <div class="flex items-center gap-2 self-start md:self-center">
              <button onclick="CaiWorkflowBuilder.openSimulatorModal(${wf.id})" class="btn-secondary btn-sm text-xs py-1 px-2.5" title="Test in interactive chat simulator">
                <i data-lucide="play" class="w-3.5 h-3.5 mr-1 text-emerald-600"></i> Test
              </button>
              <button onclick="CaiWorkflowBuilder.switchView('editor', ${wf.id})" class="btn-secondary btn-sm text-xs py-1 px-2.5" title="Open visual canvas">
                <i data-lucide="edit-2" class="w-3.5 h-3.5 mr-1"></i> Edit
              </button>
              <button onclick="CaiWorkflowBuilder.duplicateWorkflow(${wf.id})" class="btn-secondary btn-sm text-xs py-1 px-2" title="Duplicate workflow">
                <i data-lucide="copy" class="w-3.5 h-3.5"></i>
              </button>
              <button onclick="CaiWorkflowBuilder.toggleWorkflowState(${wf.id})" class="btn-secondary btn-sm text-xs py-1 px-2" title="${wf.status === 'active' ? 'Pause' : 'Activate'}">
                <i data-lucide="${wf.status === 'active' ? 'pause' : 'play'}" class="w-3.5 h-3.5"></i>
              </button>
              <button onclick="CaiWorkflowBuilder.deleteWorkflow(${wf.id})" class="btn-secondary btn-sm text-xs py-1 px-2 text-stone-400 hover:text-red-600" title="Delete">
                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
              </button>
            </div>
          </div>
        `;
      });

      listContainer.innerHTML = html;
      if (window.lucide) lucide.createIcons();
    } catch (e) {
      listContainer.innerHTML = `<div class="p-8 text-center text-xs text-red-500">Failed to load workflows: ${e.message}</div>`;
    }
  },

  // 2. Load Single Workflow into Canvas
  loadWorkflow: async function(id) {
    try {
      const res = await fetch(`../api/automations.php?action=get&id=${id}`);
      const data = await res.json();
      if (!data.success) {
        alert(data.error || 'Failed to load workflow');
        return;
      }

      this.currentWorkflow = data.workflow;
      this.renderWorkflowMeta();
      this.fitToScreen();
    } catch (e) {
      alert('Error loading workflow: ' + e.message);
    }
  },

  createNewWorkflow: function() {
    this.currentWorkflow = {
      id: 0,
      name: 'New Customer Journey',
      description: 'Visual AI workflow designed with Cai.',
      status: 'draft',
      version: 1,
      trigger_type: 'trigger_chat_start',
      graph: {
        mode: 'guided',
        nodes: [
          {
            id: 'node_start',
            type: 'trigger_chat_start',
            category: 'triggers',
            position: { x: 100, y: 180 },
            data: { label: 'New Chat Started', description: 'Triggered when visitor opens widget' }
          },
          {
            id: 'node_reply',
            type: 'ai_response_generator',
            category: 'ai',
            position: { x: 420, y: 180 },
            data: { label: 'AI Greeting & Consultation', prompt: 'Greet the visitor and ask which program they are interested in.', wait_for_reply: true }
          }
        ],
        edges: [
          { id: 'edge_start_reply', source: 'node_start', target: 'node_reply' }
        ]
      }
    };

    this.renderWorkflowMeta();
    this.renderCanvas();
  },

  renderWorkflowMeta: function() {
    const titleInput = document.getElementById('canvas-workflow-title');
    const statusBadge = document.getElementById('canvas-workflow-status');
    const versionBadge = document.getElementById('canvas-workflow-version');

    if (titleInput) titleInput.value = this.currentWorkflow.name || 'Untitled';
    if (statusBadge) {
      statusBadge.textContent = (this.currentWorkflow.status || 'draft').toUpperCase();
      statusBadge.className = this.currentWorkflow.status === 'active'
        ? 'px-2 py-0.5 bg-emerald-50 text-emerald-800 border border-emerald-200 text-[10.5px] rounded font-medium'
        : 'px-2 py-0.5 bg-amber-50 text-amber-800 border border-amber-200 text-[10.5px] rounded font-medium';
    }
    if (versionBadge) {
      versionBadge.textContent = `v${this.currentWorkflow.version || 1}`;
    }
  },

  // 3. Canvas Rendering & Interactions
  renderCanvas: function() {
    const canvasContainer = document.getElementById('canvas-nodes-container');
    const svgContainer = document.getElementById('canvas-svg');
    if (!canvasContainer || !svgContainer) return;

    // Apply viewport zoom and pan
    canvasContainer.style.transform = `translate(${this.pan.x}px, ${this.pan.y}px) scale(${this.zoom})`;
    svgContainer.style.transform = `translate(${this.pan.x}px, ${this.pan.y}px) scale(${this.zoom})`;

    const nodes = this.currentWorkflow.graph.nodes || [];
    const edges = this.currentWorkflow.graph.edges || [];

    // Render Nodes DOM
    let nodesHtml = '';
    nodes.forEach(node => {
      const def = this.nodeDefinitions[node.type] || { title: node.type, icon: 'box', cat: 'actions' };
      const label = node.data?.label || def.title;
      const isSelected = this.selectedNodeId === node.id;
      const x = node.position?.x || 100;
      const y = node.position?.y || 100;

      // Color tags per category
      const nodeCat = def.cat || 'actions';
      const catColor = nodeCat === 'triggers' ? 'border-amber-400 bg-amber-50 text-amber-800'
        : (nodeCat === 'ai' ? 'border-purple-400 bg-purple-50 text-purple-800'
        : (nodeCat === 'messages' ? 'border-blue-400 bg-blue-50 text-blue-800'
        : (nodeCat === 'logic' ? 'border-emerald-400 bg-emerald-50 text-emerald-800'
        : (nodeCat === 'crm' ? 'border-stone-400 bg-stone-100 text-stone-800' : 'border-stone-300 bg-stone-50 text-stone-700'))));

      // Render connectors
      const isTrigger = nodeCat === 'triggers' || node.type.startsWith('trigger_');
      const inputPort = !isTrigger ? `<div class="canvas-port canvas-port-input" data-node="${node.id}" data-type="input" title="Input connection"></div>` : '';

      // Output ports: single or multiple for condition/intent
      let outputPorts = '';
      if (node.type === 'logic_emi_availability') {
        outputPorts = `
          <div class="flex items-center justify-between px-3 py-1 bg-stone-50 border-t border-stone-200 text-[10px] text-stone-500">
            <span class="flex items-center gap-1">EMI Available <div class="canvas-port canvas-port-output ml-1" data-node="${node.id}" data-handle="emi_available"></div></span>
            <span class="flex items-center gap-1">Full Pay <div class="canvas-port canvas-port-output ml-1" data-node="${node.id}" data-handle="full_payment_only"></div></span>
          </div>
        `;
      } else if (node.type === 'ai_intent_detection' && node.data?.intents) {
        outputPorts = `<div class="grid grid-cols-2 gap-1 p-2 bg-stone-50 border-t border-stone-200 text-[9.5px] text-stone-600">`;
        Object.keys(node.data.intents).forEach(k => {
          outputPorts += `<div class="flex items-center justify-between"><span>${this.escapeHtml(k)}</span><div class="canvas-port canvas-port-output" data-node="${node.id}" data-handle="${k}"></div></div>`;
        });
        outputPorts += `</div>`;
      } else {
        outputPorts = `<div class="canvas-port canvas-port-output" data-node="${node.id}" data-handle="default" title="Output connection"></div>`;
      }

      nodesHtml += `
        <div class="cai-node ${isSelected ? 'selected' : ''}" id="node-${node.id}" data-id="${node.id}" style="left: ${x}px; top: ${y}px;">
          ${inputPort}
          <div class="cai-node-header flex items-center justify-between p-2.5 border-b border-stone-200">
            <div class="flex items-center gap-2">
              <div class="w-5 h-5 rounded-[3px] bg-stone-900 text-white flex items-center justify-center">
                <i data-lucide="${def.icon || 'box'}" class="w-3 h-3"></i>
              </div>
              <span class="text-xs font-semibold text-stone-900 line-clamp-1">${this.escapeHtml(label)}</span>
            </div>
            <span class="text-[9.5px] px-1.5 py-0.2 rounded border font-mono ${catColor}">${nodeCat.toUpperCase()}</span>
          </div>
          <div class="p-2.5 text-[11px] text-stone-600 bg-white">
            <p class="line-clamp-2 leading-relaxed">${this.escapeHtml(node.data?.description || node.data?.prompt || node.data?.question || def.desc)}</p>
          </div>
          ${outputPorts}
        </div>
      `;
    });

    canvasContainer.innerHTML = nodesHtml;

    // Render SVG Edges
    this.renderEdges();
    this.bindNodeInteractions();
    if (window.lucide) lucide.createIcons();
  },

  renderEdges: function() {
    const svgContainer = document.getElementById('canvas-svg');
    if (!svgContainer) return;

    const edges = this.currentWorkflow.graph.edges || [];
    const nodes = this.currentWorkflow.graph.nodes || [];

    let svgHtml = `
      <defs>
        <marker id="arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
          <path d="M 0 1 L 10 5 L 0 9 z" fill="#78716c"/>
        </marker>
      </defs>
    `;

    edges.forEach(edge => {
      const srcNode = nodes.find(n => n.id === edge.source);
      const tgtNode = nodes.find(n => n.id === edge.target);
      if (!srcNode || !tgtNode) return;

      // Approximate connector port positions
      const srcX = (srcNode.position?.x || 100) + 120;
      const srcY = (srcNode.position?.y || 100) + 110;
      const tgtX = (tgtNode.position?.x || 300) + 120;
      const tgtY = (tgtNode.position?.y || 100);

      // Smooth Cubic Bezier
      const dx = Math.abs(tgtX - srcX) * 0.5;
      const dy = Math.max(50, Math.abs(tgtY - srcY) * 0.5);
      const d = `M ${srcX} ${srcY} C ${srcX} ${srcY + dy}, ${tgtX} ${tgtY - dy}, ${tgtX} ${tgtY}`;

      svgHtml += `
        <g class="cai-edge-group" data-id="${edge.id}">
          <path class="cai-edge-path-bg" d="${d}" onclick="CaiWorkflowBuilder.deleteEdge('${edge.id}')" />
          <path class="cai-edge-path" d="${d}" marker-end="url(#arrow)" />
          ${edge.label ? `<text x="${(srcX + tgtX) / 2}" y="${(srcY + tgtY) / 2 - 8}" text-anchor="middle" class="cai-edge-label">${this.escapeHtml(edge.label)}</text>` : ''}
        </g>
      `;
    });

    svgContainer.innerHTML = svgHtml;
  },

  bindNodeInteractions: function() {
    const nodesContainer = document.getElementById('canvas-nodes-container');
    if (!nodesContainer) return;

    // Node click & Drag
    const nodeEls = nodesContainer.querySelectorAll('.cai-node');
    nodeEls.forEach(el => {
      const nodeId = el.getAttribute('data-id');

      // Click to select & open inspector
      el.addEventListener('click', (e) => {
        if (e.target.classList.contains('canvas-port')) return;
        this.selectNode(nodeId);
      });

      // Drag node
      let isDragging = false;
      let startX, startY;

      el.addEventListener('mousedown', (e) => {
        if (e.target.classList.contains('canvas-port')) return;
        isDragging = true;
        startX = e.clientX;
        startY = e.clientY;
        e.stopPropagation();

        const onMouseMove = (moveEv) => {
          if (!isDragging) return;
          const dx = (moveEv.clientX - startX) / this.zoom;
          const dy = (moveEv.clientY - startY) / this.zoom;
          startX = moveEv.clientX;
          startY = moveEv.clientY;

          const nodeObj = this.currentWorkflow.graph.nodes.find(n => n.id === nodeId);
          if (nodeObj) {
            nodeObj.position.x += dx;
            nodeObj.position.y += dy;
            el.style.left = `${nodeObj.position.x}px`;
            el.style.top = `${nodeObj.position.y}px`;
            this.renderEdges();
          }
        };

        const onMouseUp = () => {
          isDragging = false;
          window.removeEventListener('mousemove', onMouseMove);
          window.removeEventListener('mouseup', onMouseUp);
        };

        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('mouseup', onMouseUp);
      });
    });

    // Port connection drag
    const outputPorts = nodesContainer.querySelectorAll('.canvas-port-output');
    outputPorts.forEach(port => {
      port.addEventListener('mousedown', (e) => {
        e.stopPropagation();
        const srcNodeId = port.getAttribute('data-node');
        const handleId = port.getAttribute('data-handle') || 'default';
        this.startConnecting(srcNodeId, handleId);
      });
    });

    const inputPorts = nodesContainer.querySelectorAll('.canvas-port-input');
    inputPorts.forEach(port => {
      port.addEventListener('mouseup', (e) => {
        e.stopPropagation();
        const tgtNodeId = port.getAttribute('data-node');
        this.finishConnecting(tgtNodeId);
      });
    });
  },

  startConnecting: function(sourceNodeId, handleId) {
    this.isConnecting = true;
    this.connectionSource = { sourceNodeId, handleId };
  },

  finishConnecting: function(targetNodeId) {
    if (!this.isConnecting || !this.connectionSource) return;
    const { sourceNodeId, handleId } = this.connectionSource;

    if (sourceNodeId !== targetNodeId) {
      // Check if edge already exists
      const edges = this.currentWorkflow.graph.edges || [];
      const exists = edges.some(e => e.source === sourceNodeId && e.target === targetNodeId && (e.sourceHandle || 'default') === handleId);

      if (!exists) {
        const newEdge = {
          id: `e_${sourceNodeId}_${targetNodeId}_${Date.now()}`,
          source: sourceNodeId,
          target: targetNodeId,
          sourceHandle: handleId !== 'default' ? handleId : null,
          label: handleId !== 'default' ? handleId : null
        };
        edges.push(newEdge);
        this.renderCanvas();
      }
    }

    this.isConnecting = false;
    this.connectionSource = null;
  },

  deleteEdge: function(edgeId) {
    if (confirm('Delete this connection?')) {
      this.currentWorkflow.graph.edges = (this.currentWorkflow.graph.edges || []).filter(e => e.id !== edgeId);
      this.renderCanvas();
    }
  },

  // 4. Node Inspector Panel
  selectNode: function(nodeId) {
    this.selectedNodeId = nodeId;
    const inspector = document.getElementById('canvas-inspector-panel');
    const nodes = this.currentWorkflow.graph.nodes || [];
    const node = nodes.find(n => n.id === nodeId);

    if (!node || !inspector) return;

    this.renderCanvas(); // updates selected styling
    inspector.classList.remove('hidden');

    const def = this.nodeDefinitions[node.type] || { title: node.type, cat: 'actions', icon: 'box' };
    const label = node.data?.label || def.title;

    let dynamicFieldsHtml = '';

    // Specialized form fields per node type
    if (node.type === 'ai_response_generator') {
      dynamicFieldsHtml = `
        <div class="space-y-3">
          <div>
            <label class="block font-medium text-stone-700 mb-1">Custom Prompt / AI Instructions</label>
            <textarea id="insp-input-prompt" rows="3" class="app-textarea w-full text-xs font-mono" placeholder="Provide instructions to Gemini...">${this.escapeHtml(node.data?.prompt || '')}</textarea>
            <div class="flex items-center gap-1 mt-1 text-[10.5px] text-stone-500">Insert: 
              <button type="button" onclick="CaiWorkflowBuilder.insertVarIntoPrompt('{{customer.name}}')" class="underline text-stone-700">name</button>,
              <button type="button" onclick="CaiWorkflowBuilder.insertVarIntoPrompt('{{session.lastMessage}}')" class="underline text-stone-700">lastMessage</button>
            </div>
          </div>
          <div>
            <label class="block font-medium text-stone-700 mb-1">Response Temperature</label>
            <input type="range" id="insp-input-temp" min="0" max="1" step="0.05" value="${node.data?.temperature ?? 0.3}" class="w-full accent-stone-900" oninput="document.getElementById('temp-val').textContent = this.value">
            <div class="flex justify-between text-[10px] text-stone-400"><span>Precise (0.0)</span><span id="temp-val">${node.data?.temperature ?? 0.3}</span><span>Creative (1.0)</span></div>
          </div>
          <div class="flex items-center gap-2">
            <input type="checkbox" id="insp-input-wait" ${node.data?.wait_for_reply ? 'checked' : ''} class="rounded text-stone-900">
            <label for="insp-input-wait" class="text-xs text-stone-700">Wait for customer response after sending</label>
          </div>
        </div>
      `;
    } else if (node.type === 'msg_course_carousel' || node.type === 'msg_product_carousel') {
      dynamicFieldsHtml = `
        <div class="space-y-3">
          <div>
            <label class="block font-medium text-stone-700 mb-1">Carousel Header Message</label>
            <input type="text" id="insp-input-title" value="${this.escapeHtml(node.data?.title || 'Explore our verified courses:')}" class="app-input w-full text-xs">
          </div>
          <div>
            <label class="block font-medium text-stone-700 mb-1">Maximum Cards to Display</label>
            <select id="insp-input-limit" class="app-select w-full text-xs">
              <option value="2" ${node.data?.limit == 2 ? 'selected' : ''}>2 Cards</option>
              <option value="3" ${node.data?.limit == 3 ? 'selected' : ''}>3 Cards</option>
              <option value="4" ${node.data?.limit == 4 ? 'selected' : ''}>4 Cards</option>
              <option value="6" ${node.data?.limit == 6 ? 'selected' : ''}>6 Cards</option>
            </select>
          </div>
          <div class="p-2.5 bg-stone-50 border border-stone-200 rounded text-[11px] text-stone-600">
            <strong>Catalog Data:</strong> Displays real items from company product database.
          </div>
        </div>
      `;
    } else if (node.type === 'msg_plain_text') {
      dynamicFieldsHtml = `
        <div>
          <label class="block font-medium text-stone-700 mb-1">Message Text</label>
          <textarea id="insp-input-message" rows="3" class="app-textarea w-full text-xs" placeholder="Message to display...">${this.escapeHtml(node.data?.message || '')}</textarea>
        </div>
      `;
    } else if (node.type === 'logic_if_else') {
      dynamicFieldsHtml = `
        <div class="space-y-3">
          <div>
            <label class="block font-medium text-stone-700 mb-1">Variable Path</label>
            <input type="text" id="insp-input-var" value="${this.escapeHtml(node.data?.variable || 'customer.interest')}" class="app-input w-full text-xs font-mono">
          </div>
          <div>
            <label class="block font-medium text-stone-700 mb-1">Operator</label>
            <select id="insp-input-op" class="app-select w-full text-xs font-mono">
              <option value="equals">equals</option>
              <option value="contains">contains</option>
              <option value="is_set">is_set</option>
              <option value="greater_than">greater_than</option>
            </select>
          </div>
          <div>
            <label class="block font-medium text-stone-700 mb-1">Expected Value</label>
            <input type="text" id="insp-input-val" value="${this.escapeHtml(node.data?.value || '')}" class="app-input w-full text-xs">
          </div>
        </div>
      `;
    } else {
      dynamicFieldsHtml = `
        <div>
          <label class="block font-medium text-stone-700 mb-1">Node Description / Purpose</label>
          <textarea id="insp-input-desc" rows="2" class="app-textarea w-full text-xs">${this.escapeHtml(node.data?.description || '')}</textarea>
        </div>
      `;
    }

    inspector.innerHTML = `
      <div class="p-4 border-b border-stone-200 flex items-center justify-between">
        <div class="flex items-center gap-2">
          <div class="w-6 h-6 rounded bg-stone-900 text-white flex items-center justify-center">
            <i data-lucide="${def.icon}" class="w-3.5 h-3.5"></i>
          </div>
          <div>
            <h4 class="text-xs font-semibold text-stone-900">${this.escapeHtml(def.title)}</h4>
            <span class="text-[10px] text-stone-400 font-mono">ID: ${node.id}</span>
          </div>
        </div>
        <button onclick="CaiWorkflowBuilder.closeInspector()" class="text-stone-400 hover:text-stone-700">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <div class="p-4 space-y-4 text-xs overflow-y-auto max-h-[calc(100vh-180px)]">
        <div>
          <label class="block font-medium text-stone-700 mb-1">Step Label</label>
          <input type="text" id="insp-input-label" value="${this.escapeHtml(label)}" class="app-input w-full text-xs">
        </div>

        ${dynamicFieldsHtml}

        <div class="pt-3 border-t border-stone-200 flex items-center justify-between gap-2">
          <button type="button" onclick="CaiWorkflowBuilder.deleteNode('${node.id}')" class="btn-secondary btn-sm text-xs py-1.5 px-3 text-red-600 hover:bg-red-50">
            <i data-lucide="trash-2" class="w-3.5 h-3.5 mr-1"></i> Delete
          </button>
          <button type="button" onclick="CaiWorkflowBuilder.saveInspectorNode()" class="btn-primary btn-sm text-xs py-1.5 px-4">
            Apply Changes
          </button>
        </div>
      </div>
    `;

    if (window.lucide) lucide.createIcons();
  },

  closeInspector: function() {
    const inspector = document.getElementById('canvas-inspector-panel');
    if (inspector) inspector.classList.add('hidden');
    this.selectedNodeId = null;
    this.renderCanvas();
  },

  saveInspectorNode: function() {
    if (!this.selectedNodeId) return;
    const node = this.currentWorkflow.graph.nodes.find(n => n.id === this.selectedNodeId);
    if (!node) return;

    const labelInput = document.getElementById('insp-input-label');
    if (labelInput) node.data.label = labelInput.value.trim();

    const promptInput = document.getElementById('insp-input-prompt');
    if (promptInput) node.data.prompt = promptInput.value;

    const tempInput = document.getElementById('insp-input-temp');
    if (tempInput) node.data.temperature = parseFloat(tempInput.value);

    const waitInput = document.getElementById('insp-input-wait');
    if (waitInput) node.data.wait_for_reply = waitInput.checked;

    const titleInput = document.getElementById('insp-input-title');
    if (titleInput) node.data.title = titleInput.value;

    const msgInput = document.getElementById('insp-input-message');
    if (msgInput) node.data.message = msgInput.value;

    const descInput = document.getElementById('insp-input-desc');
    if (descInput) node.data.description = descInput.value;

    this.renderCanvas();
    this.closeInspector();
  },

  insertVarIntoPrompt: function(variableStr) {
    const promptInput = document.getElementById('insp-input-prompt');
    if (!promptInput) return;
    promptInput.value += ' ' + variableStr;
    promptInput.focus();
  },

  addNodeToCanvas: function(nodeType) {
    const def = this.nodeDefinitions[nodeType];
    if (!def) return;

    const newId = `node_${Date.now()}`;
    const x = Math.round((-this.pan.x + 300) / this.zoom);
    const y = Math.round((-this.pan.y + 200) / this.zoom);

    const newNode = {
      id: newId,
      type: nodeType,
      category: def.cat,
      position: { x, y },
      data: JSON.parse(JSON.stringify(def.defaultData || { label: def.title }))
    };

    this.currentWorkflow.graph.nodes.push(newNode);
    this.renderCanvas();
    this.selectNode(newId);
  },

  deleteNode: function(nodeId) {
    if (confirm('Delete this node and its connections?')) {
      this.currentWorkflow.graph.nodes = this.currentWorkflow.graph.nodes.filter(n => n.id !== nodeId);
      this.currentWorkflow.graph.edges = this.currentWorkflow.graph.edges.filter(e => e.source !== nodeId && e.target !== nodeId);
      this.closeInspector();
      this.renderCanvas();
    }
  },

  // 5. Save & Publish
  saveWorkflow: async function(status = 'draft') {
    const titleInput = document.getElementById('canvas-workflow-title');
    if (titleInput) this.currentWorkflow.name = titleInput.value.trim();

    try {
      const res = await fetch('../api/automations.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: this.currentWorkflow.id,
          name: this.currentWorkflow.name,
          description: this.currentWorkflow.description,
          status: status,
          trigger_type: this.currentWorkflow.trigger_type,
          graph: this.currentWorkflow.graph
        })
      });
      const data = await res.json();
      if (data.success) {
        this.currentWorkflow.id = data.id;
        this.currentWorkflow.status = status;
        this.renderWorkflowMeta();
        alert('Workflow saved successfully!');
      } else {
        alert(data.error || 'Failed to save');
      }
    } catch (e) {
      alert('Error saving workflow: ' + e.message);
    }
  },

  publishWorkflow: async function() {
    await this.saveWorkflow('draft');
    if (!this.currentWorkflow.id) return;

    try {
      const res = await fetch('../api/automations.php?action=publish', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: this.currentWorkflow.id })
      });
      const data = await res.json();
      if (data.success) {
        this.currentWorkflow.status = 'active';
        this.renderWorkflowMeta();
        alert('Workflow published & activated!');
      } else {
        if (data.validation_errors) {
          alert('Publishing validation failed:\n• ' + data.validation_errors.join('\n• '));
        } else {
          alert(data.error || 'Failed to publish');
        }
      }
    } catch (e) {
      alert('Error publishing workflow: ' + e.message);
    }
  },

  // 6. Interactive Simulator (Test Workflow)
  openSimulatorModal: function(workflowId = null) {
    const wId = workflowId || this.currentWorkflow?.id;
    if (!wId) {
      alert('Please save the workflow first before running tests.');
      return;
    }

    const modal = document.getElementById('workflow-simulator-modal');
    if (!modal) return;
    modal.classList.remove('hidden');

    this.simulationState = {
      workflowId: wId,
      activeExecutionId: null,
      sessionHistory: []
    };

    const chatBox = document.getElementById('sim-chat-messages');
    if (chatBox) chatBox.innerHTML = '';

    // Trigger initial simulation greeting
    this.sendSimulatorMessage('Hello');
  },

  closeSimulatorModal: function() {
    const modal = document.getElementById('workflow-simulator-modal');
    if (modal) modal.classList.add('hidden');
    // Clear node execution highlights
    document.querySelectorAll('.cai-node').forEach(n => n.classList.remove('executing-highlight'));
  },

  sendSimulatorMessage: async function(customText = null) {
    const input = document.getElementById('sim-chat-input');
    const msgText = customText || (input ? input.value.trim() : '');
    if (!msgText) return;
    if (input) input.value = '';

    const chatBox = document.getElementById('sim-chat-messages');
    if (!chatBox) return;

    // Append Visitor message
    const userBubble = document.createElement('div');
    userBubble.className = 'flex justify-end';
    userBubble.innerHTML = `<div class="p-2.5 max-w-[80%] bg-stone-900 text-white rounded-[6px] text-xs">${this.escapeHtml(msgText)}</div>`;
    chatBox.appendChild(userBubble);
    chatBox.scrollTop = chatBox.scrollHeight;

    // Typing indicator
    const typingBubble = document.createElement('div');
    typingBubble.id = 'sim-typing';
    typingBubble.className = 'flex justify-start';
    typingBubble.innerHTML = `<div class="p-2 bg-stone-100 text-stone-500 rounded-[6px] text-xs animate-pulse">Cai is thinking...</div>`;
    chatBox.appendChild(typingBubble);
    chatBox.scrollTop = chatBox.scrollHeight;

    try {
      const res = await fetch('../api/automations.php?action=test_simulate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          workflow_id: this.simulationState.workflowId,
          execution_id: this.simulationState.activeExecutionId,
          message: msgText
        })
      });
      const data = await res.json();
      const typingEl = document.getElementById('sim-typing');
      if (typingEl) typingEl.remove();

      if (!data.success) {
        alert(data.error || 'Simulation error');
        return;
      }

      this.simulationState.activeExecutionId = data.execution_id;

      // Highlight active executing node on canvas
      if (data.current_node_id) {
        document.querySelectorAll('.cai-node').forEach(n => n.classList.remove('executing-highlight'));
        const activeNodeEl = document.getElementById(`node-${data.current_node_id}`);
        if (activeNodeEl) activeNodeEl.classList.add('executing-highlight');
      }

      // Append AI Reply
      const aiBubble = document.createElement('div');
      aiBubble.className = 'flex justify-start flex-col gap-2';

      let cardsHtml = '';
      if (data.product_cards && data.product_cards.length > 0) {
        cardsHtml = `<div class="grid grid-cols-2 gap-2 mt-2">` + data.product_cards.map(p => `
          <div class="p-2.5 bg-white border border-stone-200 rounded-[4px] shadow-2xs text-[11px]">
            <div class="font-semibold text-stone-900">${this.escapeHtml(p.name)}</div>
            <div class="font-mono text-stone-700 mt-0.5">₹${Number(p.price_inr).toLocaleString('en-IN')}</div>
            <button onclick="CaiWorkflowBuilder.sendSimulatorMessage('Choose ${this.escapeHtml(p.name)}')" class="mt-2 w-full py-1 bg-stone-900 text-white rounded text-[10px]">Select</button>
          </div>
        `).join('') + `</div>`;
      }

      if (data.emi_plans) {
        cardsHtml += `
          <div class="p-2.5 bg-purple-50 border border-purple-200 rounded text-[11px] text-purple-900 mt-2">
            <div class="font-semibold">0% Zero-Cost EMI Schedule:</div>
            <div class="text-[10.5px] mt-1">3 monthly installments of ₹${Number(data.emi_plans.starting_at_inr).toLocaleString('en-IN')}/mo</div>
          </div>
        `;
      }

      if (data.payment_link) {
        cardsHtml += `
          <div class="p-2.5 bg-emerald-50 border border-emerald-200 rounded text-[11px] text-emerald-900 mt-2">
            <div class="font-semibold">Payment Link Ready:</div>
            <div class="text-[10.5px]">Amount: ₹${Number(data.payment_link.amount).toLocaleString('en-IN')}</div>
            <button onclick="CaiWorkflowBuilder.sendSimulatorMessage('Verified payment completed')" class="mt-2 w-full py-1 bg-emerald-700 text-white rounded text-[10px]">Simulate Verified Payment</button>
          </div>
        `;
      }

      aiBubble.innerHTML = `
        <div class="p-2.5 max-w-[85%] bg-stone-100 text-stone-900 rounded-[6px] text-xs whitespace-pre-wrap">${this.escapeHtml(data.reply)}</div>
        ${cardsHtml}
      `;
      chatBox.appendChild(aiBubble);
      chatBox.scrollTop = chatBox.scrollHeight;

      // Update Inspector Step Logs
      this.renderSimulationLogs(data.logs || []);
    } catch (e) {
      const typingEl = document.getElementById('sim-typing');
      if (typingEl) typingEl.remove();
      alert('Error during simulation: ' + e.message);
    }
  },

  renderSimulationLogs: function(logs) {
    const logsBox = document.getElementById('sim-step-logs');
    if (!logsBox) return;

    if (logs.length === 0) {
      logsBox.innerHTML = '<div class="text-[11px] text-stone-400">No steps recorded yet.</div>';
      return;
    }

    logsBox.innerHTML = logs.map(l => `
      <div class="p-2 bg-stone-50 border border-stone-200 rounded text-[10.5px] space-y-0.5">
        <div class="flex items-center justify-between text-stone-700 font-semibold">
          <span>${this.escapeHtml(l.node_title || l.node_type)}</span>
          <span class="text-[9.5px] font-mono text-stone-400">${l.time_str}</span>
        </div>
        <div class="text-[10px] text-stone-500 font-mono">Node ID: ${l.node_id} • Status: ${l.status}</div>
      </div>
    `).join('');
  },

  // 7. Templates
  openTemplatesModal: async function() {
    const modal = document.getElementById('workflow-templates-modal');
    if (!modal) return;
    modal.classList.remove('hidden');

    try {
      const res = await fetch('../api/automations.php?action=templates');
      const data = await res.json();
      const grid = document.getElementById('templates-grid');
      if (!grid) return;

      grid.innerHTML = (data.templates || []).map(t => `
        <div class="p-4 bg-white border border-[#e7e5de] hover:border-stone-900 rounded-[6px] space-y-2 flex flex-col justify-between transition-all">
          <div class="space-y-1.5">
            <div class="flex items-center justify-between">
              <span class="text-xs font-semibold text-stone-900">${this.escapeHtml(t.name)}</span>
              <span class="text-[9.5px] px-1.5 py-0.5 bg-stone-100 text-stone-600 rounded font-mono">${t.category}</span>
            </div>
            <p class="text-[11px] text-stone-500 line-clamp-3">${this.escapeHtml(t.description)}</p>
            <div class="text-[10px] text-stone-400 font-mono">${t.nodes.length} Nodes • ${t.mode.toUpperCase()} MODE</div>
          </div>
          <button onclick="CaiWorkflowBuilder.applyTemplate('${t.id}')" class="btn-primary btn-sm text-xs py-1.5 px-3 w-full mt-3">
            Use This Template
          </button>
        </div>
      `).join('');
    } catch (e) {
      alert('Failed to load templates: ' + e.message);
    }
  },

  closeTemplatesModal: function() {
    const modal = document.getElementById('workflow-templates-modal');
    if (modal) modal.classList.add('hidden');
  },

  applyTemplate: async function(templateId) {
    try {
      const res = await fetch('../api/automations.php?action=apply_template', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ template_id: templateId })
      });
      const data = await res.json();
      if (data.success) {
        this.closeTemplatesModal();
        this.switchView('editor', data.id);
      } else {
        alert(data.error || 'Failed to apply template');
      }
    } catch (e) {
      alert('Error applying template: ' + e.message);
    }
  },

  restoreDefaultTemplate: async function() {
    if (!confirm('Restore the default 5-Phase Universal Customer Journey for your company? This will set up the knowledge-grounded flagship workflow.')) {
      return;
    }
    try {
      const res = await fetch('../api/automations.php?action=restore_default', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
      });
      const data = await res.json();
      if (data.success) {
        if (this.activeView === 'editor') {
          this.loadWorkflow(data.id);
        } else {
          this.loadWorkflowsList();
        }
        alert(data.message || 'Universal Customer Journey restored successfully!');
      } else {
        alert(data.error || 'Failed to restore default template');
      }
    } catch (e) {
      alert('Error restoring default template: ' + e.message);
    }
  },

  // 8. Canvas Controls (Zoom / Pan / Auto-Layout)
  zoomIn: function() {
    this.zoom = Math.min(2.0, this.zoom + 0.15);
    this.renderCanvas();
  },

  zoomOut: function() {
    this.zoom = Math.max(0.4, this.zoom - 0.15);
    this.renderCanvas();
  },

  resetZoom: function() {
    this.zoom = 1.0;
    this.pan = { x: 40, y: 40 };
    this.renderCanvas();
  },

  fitToScreen: function() {
    const nodes = this.currentWorkflow?.graph?.nodes || [];
    if (nodes.length === 0) {
      this.resetZoom();
      return;
    }

    const viewport = document.getElementById('canvas-viewport');
    const vw = viewport ? (viewport.clientWidth || 900) : 900;
    const vh = viewport ? (viewport.clientHeight || 600) : 600;

    let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
    nodes.forEach(n => {
      const x = n.position?.x || 100;
      const y = n.position?.y || 100;
      minX = Math.min(minX, x);
      maxX = Math.max(maxX, x + 260);
      minY = Math.min(minY, y);
      maxY = Math.max(maxY, y + 140);
    });

    const graphWidth = maxX - minX + 100;
    const graphHeight = maxY - minY + 100;

    const scaleX = (vw - 80) / graphWidth;
    const scaleY = (vh - 80) / graphHeight;
    const fitZoom = Math.max(0.45, Math.min(1.0, Math.min(scaleX, scaleY)));

    this.zoom = fitZoom;
    this.pan = {
      x: Math.round(40 - minX * fitZoom),
      y: Math.round(40 - minY * fitZoom)
    };
    this.renderCanvas();
  },

  autoArrangeNodes: function() {
    const nodes = this.currentWorkflow?.graph?.nodes || [];
    if (nodes.length === 0) return;

    // Grid layout: 4 nodes per row so wide multi-step workflows stay compact and readable
    const cols = 4;
    const colSpacing = 300;
    const rowSpacing = 220;

    nodes.forEach((n, idx) => {
      const col = idx % cols;
      const row = Math.floor(idx / cols);
      n.position = {
        x: 60 + col * colSpacing,
        y: 60 + row * rowSpacing
      };
    });

    this.fitToScreen();
  },

  // 9. Load Catalog Context
  loadCatalogContext: async function() {
    try {
      const res = await fetch('../api/automations.php?action=inspector_context');
      const data = await res.json();
      if (data.success) {
        this.catalogContext = data;
      }
    } catch (e) {}
  },

  toggleWorkflowState: async function(id) {
    try {
      const res = await fetch(`../api/automations.php?action=toggle&id=${id}`, { method: 'POST' });
      const data = await res.json();
      if (data.success) {
        this.loadWorkflowsList();
      }
    } catch (e) {}
  },

  duplicateWorkflow: async function(id) {
    try {
      const res = await fetch('../api/automations.php?action=duplicate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });
      const data = await res.json();
      if (data.success) {
        this.loadWorkflowsList();
      }
    } catch (e) {}
  },

  deleteWorkflow: async function(id) {
    if (confirm('Are you sure you want to delete this workflow? This cannot be undone.')) {
      try {
        const res = await fetch(`../api/automations.php?action=delete&id=${id}`, { method: 'POST' });
        const data = await res.json();
        if (data.success) {
          this.loadWorkflowsList();
        }
      } catch (e) {}
    }
  },

  bindDomEvents: function() {
    // Pan canvas with mouse drag on background
    const canvasWrap = document.getElementById('canvas-viewport');
    if (canvasWrap) {
      canvasWrap.addEventListener('mousedown', (e) => {
        if (e.target.closest('.cai-node') || e.target.closest('.canvas-controls-bar')) return;
        this.isPanning = true;
        this.startPan = { x: e.clientX - this.pan.x, y: e.clientY - this.pan.y };

        const onMouseMove = (moveEv) => {
          if (!this.isPanning) return;
          this.pan.x = moveEv.clientX - this.startPan.x;
          this.pan.y = moveEv.clientY - this.startPan.y;
          this.renderCanvas();
        };

        const onMouseUp = () => {
          this.isPanning = false;
          window.removeEventListener('mousemove', onMouseMove);
          window.removeEventListener('mouseup', onMouseUp);
        };

        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('mouseup', onMouseUp);
      });

      // Mouse wheel zoom
      canvasWrap.addEventListener('wheel', (e) => {
        e.preventDefault();
        const delta = e.deltaY > 0 ? -0.08 : 0.08;
        this.zoom = Math.max(0.4, Math.min(2.0, this.zoom + delta));
        this.renderCanvas();
      }, { passive: false });
    }

    // Node library search
    const nodeSearch = document.getElementById('node-library-search');
    if (nodeSearch) {
      nodeSearch.addEventListener('input', (e) => {
        const q = e.target.value.toLowerCase();
        document.querySelectorAll('.lib-node-item').forEach(item => {
          const text = item.textContent.toLowerCase();
          item.style.display = text.includes(q) ? 'flex' : 'none';
        });
      });
    }
  },

  toggleNodePalette: function() {
    const palette = document.getElementById('wf-node-palette');
    const expandBtn = document.getElementById('wf-palette-expand-btn');
    if (!palette) return;

    const isCollapsed = palette.classList.contains('hidden') || palette.classList.contains('w-0');
    if (isCollapsed) {
      palette.classList.remove('hidden', 'w-0', 'p-0', 'opacity-0', 'border-none', 'overflow-hidden');
      palette.classList.add('w-64');
      if (expandBtn) expandBtn.classList.add('hidden');
    } else {
      palette.classList.add('hidden', 'w-0', 'p-0', 'opacity-0', 'border-none', 'overflow-hidden');
      palette.classList.remove('w-64');
      if (expandBtn) expandBtn.classList.remove('hidden');
    }
    if (window.lucide) window.lucide.createIcons();
  },

  togglePaletteSection: function(btn) {
    const group = btn.nextElementSibling;
    const chevron = btn.querySelector('svg, i');
    if (!group) return;
    const isHidden = group.classList.toggle('hidden');
    if (chevron) {
      chevron.style.transform = isHidden ? 'rotate(-90deg)' : 'rotate(0deg)';
    }
  },

  escapeHtml: function(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }
};
