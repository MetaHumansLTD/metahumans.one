<?php
// Security: Prevent direct access to this widget file
if (!defined('CUE_CORE_LOADED') && !headers_sent()) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('403 Forbidden: Widget files cannot be accessed directly.');
}

// CUE framework include (secure path handling)
if (!function_exists('getSecureFilePath')) {
    $cuePath = dirname(dirname(__DIR__)) . '/.cue/cue.php';
    if (!file_exists($cuePath)) {
        $cuePath = dirname(__DIR__, 3) . '/.cue/cue.php';
    }
    if (file_exists($cuePath)) {
        require_once $cuePath;
    }
}
?>
<style id="dragdrop-widget-styles">
.ddw-container{position:relative}
.ddw-item{display:flex;align-items:center;gap:8px;padding:8px 10px;margin:6px 0;background:rgba(255,255,255,0.04);border-radius:6px;border:1px solid rgba(255,255,255,0.08);cursor:grab;transition:box-shadow 150ms ease,transform 150ms ease}
.ddw-item:active{cursor:grabbing}
.ddw-item.dragging{opacity:.95;box-shadow:0 10px 24px rgba(0,0,0,.35);z-index:1000}
.ddw-handle{width:14px;height:14px;border-radius:2px;background:linear-gradient(to bottom, rgba(255,255,255,.3) 40%, rgba(255,255,255,.05));margin-right:6px}
.ddw-label{flex:1;color:var(--light-text,#e5e7eb);font-weight:600}
.ddw-url{color:var(--gray-text,#a3a3a3);font-size:.85rem}
.ddw-actions{display:flex;gap:6px;margin-left:auto}
.ddw-drop-indicator{height:0;border-top:2px dashed #4A90E2;margin:6px 0}
</style>
<script nonce="<?php echo function_exists('cspNonce') ? cspNonce() : ''; ?>">
(function(){
  class DragDropWidget {
    constructor(container, opts={}){
      this.container = container;
      this.opts = Object.assign({
        items: [],
        instanceId: 'ddw:'+Math.random().toString(36).slice(2),
        persist: true,
        itemSelector: '.ddw-item',
        idAttr: 'data-id'
      }, opts);
      this._listeners = {};
      this.dropIndicator = document.createElement('div');
      this.dropIndicator.className = 'ddw-drop-indicator';
      this.draggingEl = null;
      this._init();
    }

    static mount(container, items, options){
      if (!container) return null;
      const inst = new DragDropWidget(container, Object.assign({}, options, { items: items || [] }));
      container.dataset.dragdropWidget = 'true';
      return inst;
    }

    on(event, handler){
      (this._listeners[event] ||= []).push(handler);
    }
    off(event, handler){
      const arr = this._listeners[event] || []; this._listeners[event] = arr.filter(h => h !== handler);
    }
    emit(event, detail){
      (this._listeners[event]||[]).forEach(h => { try { h(detail); } catch(e){ console.error(e);} });
      try { this.container.dispatchEvent(new CustomEvent('dragdrop:'+event, { detail })); } catch(e){}
    }

    _storageKey(){ return 'widget:'+ (this.opts.instanceId||''); }
    _readOrder(){ try{ const raw=localStorage.getItem(this._storageKey()); return raw?JSON.parse(raw):[]; }catch(_){return [];} }
    _writeOrder(ids){ if (!this.opts.persist) return; try{ localStorage.setItem(this._storageKey(), JSON.stringify(ids||[])); }catch(_){} }

    _normalizeFromDOM(){
      const nodes = Array.from(this.container.querySelectorAll('.social-link-item'));
      return nodes.map(n => ({ id: n.getAttribute('data-social-id')||'', name: (n.querySelector('span')?.textContent||'').trim(), url: (n.querySelector('a.social-url')?.getAttribute('href')||'').trim(), _el: n }));
    }

    _renderItems(items){
      // If container already has social-link-item nodes, adopt them rather than re-render to preserve handlers
      const existing = this._normalizeFromDOM();
      if (existing.length && !items.length) { this.items = existing; return; }
      this.container.classList.add('ddw-container');
      this.container.innerHTML = '';
      this.items = items.map(it => Object.assign({}, it));
      const frag = document.createDocumentFragment();
      this.items.forEach(it => {
        const el = document.createElement('div');
        el.className = 'ddw-item';
        el.setAttribute('draggable','true');
        el.setAttribute('tabindex','0');
        el.setAttribute('role','option');
        el.setAttribute('data-id', it.id);
        el.innerHTML = `
          <div class="ddw-handle" aria-hidden="true"></div>
          <div class="ddw-label">${(it.name||'').replace(/</g,'&lt;')}</div>
          <a class="ddw-url" href="${(it.url||'#').replace(/"/g,'&quot;')}" target="_blank" rel="noopener">${(it.url||'').replace(/</g,'&lt;')}</a>
          <div class="ddw-actions"></div>`;
        it._el = el;
        frag.appendChild(el);
      });
      this.container.appendChild(frag);
    }

    _applyStoredOrder(){
      const order = this._readOrder(); if (!order.length) return;
      const byId = {}; this.items.forEach(it => byId[it.id]=it);
      const sorted = []; order.forEach(id => { if (byId[id]) sorted.push(byId[id]); });
      this.items.forEach(it => { if (!order.includes(it.id)) sorted.push(it); });
      this.items = sorted;
      // Apply to DOM
      this.items.forEach(it => { if (it._el) this.container.appendChild(it._el); });
    }

    _init(){
      const items = Array.isArray(this.opts.items) ? this.opts.items : [];
      this._renderItems(items);
      this._applyStoredOrder();
      this._bind();
      this.emit('ready', { count: this.items.length });
    }

    _bind(){
      const isInteractiveTarget = (t)=> !!(t.closest && t.closest('button, a, input, textarea, select'));
      const itemSelector = this.opts.itemSelector;
      this.container.addEventListener('dragstart', (e)=>{
        const el = e.target.closest(itemSelector); if (!el) return;
        if (isInteractiveTarget(e.target)) { e.preventDefault(); return; }
        this.draggingEl = el; el.classList.add('dragging');
        if (e.dataTransfer){ e.dataTransfer.effectAllowed='move'; e.dataTransfer.setData('text/plain', el.getAttribute(this.opts.idAttr)||''); }
        this.emit('moveStart', { id: el.getAttribute(this.opts.idAttr) });
      });
      this.container.addEventListener('dragover', (e)=>{
        e.preventDefault(); if (!this.draggingEl) return;
        const after = this._getElementAfterY(e.clientY);
        if (after==null){ this.container.appendChild(this.dropIndicator); } else { this.container.insertBefore(this.dropIndicator, after); }
      });
      this.container.addEventListener('drop', (e)=>{
        e.preventDefault(); if (!this.draggingEl) return;
        if (this.dropIndicator.parentNode===this.container){ this.container.insertBefore(this.draggingEl,this.dropIndicator); this.dropIndicator.remove(); this._persistFromDOM(); }
        this.emit('drop', { id: this.draggingEl.getAttribute(this.opts.idAttr) });
      });
      this.container.addEventListener('dragend', ()=>{
        if (this.draggingEl){ this.draggingEl.classList.remove('dragging'); this.draggingEl=null; }
        if (this.dropIndicator.parentNode) this.dropIndicator.remove();
      });

      // Keyboard
      this.container.addEventListener('keydown', (e)=>{
        const el = e.target.closest(itemSelector); if (!el) return;
        const key = e.key;
        if (key===' '||key==='Spacebar'){ e.preventDefault(); this.draggingEl = el; el.classList.add('dragging'); this.emit('moveStart',{id:el.getAttribute(this.opts.idAttr)}); }
        else if (key==='Escape'){ if (this.draggingEl){ this.draggingEl.classList.remove('dragging'); this.draggingEl=null; } }
        else if (key==='ArrowUp'&&this.draggingEl===el){ e.preventDefault(); const prev=el.previousElementSibling; if(prev){ el.parentNode.insertBefore(el,prev); this._persistFromDOM(); el.focus(); } }
        else if (key==='ArrowDown'&&this.draggingEl===el){ e.preventDefault(); const next=el.nextElementSibling; if(next){ el.parentNode.insertBefore(next,el); this._persistFromDOM(); el.focus(); } }
        else if (key==='Enter'&&this.draggingEl===el){ e.preventDefault(); el.classList.remove('dragging'); this.draggingEl=null; this.emit('drop',{id:el.getAttribute(this.opts.idAttr)}); }
      });

      // Touch via Pointer Events
      this.container.addEventListener('pointerdown',(e)=>{ const el=e.target.closest(itemSelector); if(!el) return; if(e.pointerType==='touch'){ this.draggingEl=el; el.classList.add('dragging'); el.setPointerCapture&&el.setPointerCapture(e.pointerId); this.emit('moveStart',{id:el.getAttribute(this.opts.idAttr)}); } });
      this.container.addEventListener('pointermove',(e)=>{ if(!this.draggingEl||e.pointerType!=='touch') return; const after=this._getElementAfterY(e.clientY); if(after==null){ this.container.appendChild(this.dropIndicator);} else { this.container.insertBefore(this.dropIndicator,after);} });
      this.container.addEventListener('pointerup',(e)=>{ if(this.draggingEl&&e.pointerType==='touch'){ if(this.dropIndicator.parentNode===this.container){ this.container.insertBefore(this.draggingEl,this.dropIndicator); this.dropIndicator.remove(); this._persistFromDOM(); } this.draggingEl.classList.remove('dragging'); this.draggingEl=null; this.emit('drop',{}); } });
    }

    _getElementAfterY(y){
      const els = Array.from(this.container.querySelectorAll(`${this.opts.itemSelector}:not(.dragging)`));
      for (let el of els){ const rect=el.getBoundingClientRect(); const mid=rect.top+rect.height/2; if(y<mid) return el; }
      return null;
    }

    _persistFromDOM(){
      const ids = Array.from(this.container.querySelectorAll(this.opts.itemSelector)).map(el=>el.getAttribute(this.opts.idAttr)).filter(Boolean);
      this._writeOrder(ids);
      this.emit('orderChange', { order: ids });
    }

    setItems(items){ this.opts.items = Array.isArray(items)?items:[]; this._renderItems(this.opts.items); this._applyStoredOrder(); }
    getOrder(){ return Array.from(this.container.querySelectorAll(this.opts.itemSelector)).map(el=>el.getAttribute(this.opts.idAttr)).filter(Boolean); }
    destroy(){ try{ this.container.removeEventListener('dragstart',()=>{});}catch(_){}} // noop; kept for API symmetry
  }
  window.DragDropWidget = DragDropWidget;
})();
</script>

