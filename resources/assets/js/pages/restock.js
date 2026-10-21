// ========================================
// PAGE: REFILL (เติมอุปกรณ์เข้ารถโดยตรง — ไม่มีคลังสต็อกกลาง)
// ========================================
// 1 ครั้งที่กดบันทึก = 1 client_token (server: refer_refill_batches.client_token UNIQUE)
// ส่งซ้ำ/กดซ้ำ/เน็ตหลุดแล้วลองใหม่ด้วย token เดิม → ได้ผลเดิม ไม่เติมซ้ำ

const REFILL_TYPE_LABEL = { box: '📦 กล่อง', piece: '🧩 ชิ้น', electric: '🔌 ไฟฟ้า' };
const REQUEST_STATUS_LABEL = {
  PENDING: 'รอเติม', IN_PROGRESS: 'รอเติม (ระบบเดิม)', PARTIAL: 'เติมบางส่วน', COMPLETED: 'เติมครบ', CANCELLED: 'ยกเลิก',
};

class RefillPage {
  constructor() {
    this.vehicles = [];
    this.requests = [];
    this.carId = '';
    this.sheet = null;
    this.drafts = {};            // item_id => {lots: [{qty, expiry_date}], units: [...], retire: Set}
    this.filter = 'need';
    this.requestFilter = 'open';
    this.clientToken = null;
    this.submitting = false;
    this.bind();
    this.load();
  }

  isAdmin() {
    const user = window.auth?.getUser?.();
    return user && user.role === 'admin';
  }

  bind() {
    const list = document.getElementById('refillItems');
    if (list) {
      list.addEventListener('input', (e) => this.onInput(e));
      list.addEventListener('click', (e) => this.onClick(e));
      list.addEventListener('change', (e) => this.onInput(e));
    }
    document.getElementById('restockList')?.addEventListener('click', (e) => this.onRequestClick(e));
  }

  async load() {
    try {
      const [vehiclesRes, requestsRes] = await Promise.all([
        window.vehicleApi.getAll(),
        this.isAdmin() ? window.restockApi.getAll() : window.staffApi.getRestockRequests(),
      ]);
      this.vehicles = (vehiclesRes.data || []).filter(v => v.status !== 'disabled');
      window.vehicleRef.remember(vehiclesRes.data || []);
      this.requests = requestsRes.data || [];
      this.renderCars();
      this.renderRequests();
      const linked = this.linkedCarFromHash();
      if (linked && linked !== this.carId) await this.selectCar(linked);
      else if (this.carId) await this.selectCar(this.carId, true);
    } catch (error) {
      window.toast.error('โหลดข้อมูลไม่สำเร็จ: ' + error.message);
    }
  }

  // ลิงก์จากหน้าแรก: #restock?car=<id> หรือ #restock?request=<id> (ใช้ครั้งเดียวแล้วล้าง)
  linkedCarFromHash() {
    const hash = window.location.hash || '';
    if (!hash.startsWith('#restock?')) return '';
    const params = new URLSearchParams(hash.slice(hash.indexOf('?') + 1));
    history.replaceState(null, '', window.location.pathname + window.location.search);
    if (params.get('car')) return params.get('car');
    const req = this.requests.find(r => r.id === params.get('request'));
    return req ? req.car_id : '';
  }

  openCountFor(carId) {
    return this.requests.filter(r => r.car_id === carId && ['PENDING', 'IN_PROGRESS', 'PARTIAL'].includes(r.status)).length;
  }

  renderCars() {
    const grid = document.getElementById('refillCarGrid');
    if (!grid) return;
    grid.innerHTML = this.vehicles.map(v => {
      const color = window.vehicleColor(v);
      const open = this.openCountFor(v.id);
      return `<button type="button" class="car-symbol" data-car-id="${UI.escape(v.id)}" aria-pressed="${v.id === this.carId}"
        style="border-color:${color};box-shadow: inset 0 6px 0 ${color}" onclick="refillPage.selectCar('${UI.escape(v.id)}')">
        ${window.vehicleChip(v)}
        <span class="car-stat">${open ? `รอเติม ${open} รายการ` : 'ไม่มีรายการรอเติม'}</span>
      </button>`;
    }).join('') || '<p class="empty">ยังไม่มีรถในระบบ</p>';
  }

  async selectCar(carId, keepDrafts = false) {
    if (!keepDrafts && this.carId && this.carId !== carId && this.hasDraftLines()) {
      const ok = await window.confirmDialog('มีรายการเติมที่ยังไม่บันทึกของรถคันเดิม — เปลี่ยนรถและทิ้งรายการนั้น?', { confirmText: 'เปลี่ยนรถ' });
      if (!ok) return;
    }
    if (!keepDrafts) this.drafts = {};
    this.carId = carId;
    this.clientToken = null;
    document.querySelectorAll('#refillCarGrid .car-symbol').forEach(b => b.setAttribute('aria-pressed', b.dataset.carId === carId));
    try {
      const res = await window.staffApi.getRefillSheet(carId);
      this.sheet = res.data;
      document.getElementById('refillSheet')?.classList.remove('hidden');
      this.renderSheet();
    } catch (error) {
      window.toast.error(error.message);
    }
  }

  draftFor(id) {
    if (!this.drafts[id]) this.drafts[id] = { lots: [], units: [], retire: new Set() };
    return this.drafts[id];
  }

  hasDraftLines() {
    return Object.values(this.drafts).some(d => d.lots.length || d.units.length || d.retire.size);
  }

  fmt(v) {
    if (v === null || v === undefined || v === '') return '-';
    return String(Math.round(Number(v) * 100) / 100);
  }

  itemMatches(item) {
    if (this.filter === 'all') return true;
    if (this.filter === 'expiry') return item.expiry && (item.expiry.state === 'expired' || item.expiry.state === 'soon');
    // ต้องเติม: ต่ำกว่าเป้าหมาย / มียอดค้างในรายการรอเติม / ยังไม่เคยนับ / มีรายการที่กำลังกรอก
    const d = this.drafts[item.id];
    return item.shortfall > 0 || item.requested > 0 || !item.counted || (d && (d.lots.length || d.units.length || d.retire.size));
  }

  renderSheet() {
    if (!this.sheet) return;
    const v = this.sheet.vehicle;
    const head = document.getElementById('refillHead');
    if (head) {
      head.innerHTML = `${window.vehicleChip(v)} <span>รายการรอเติมที่เปิดอยู่ ${this.sheet.open_requests.length} ใบ</span>`;
    }
    const items = this.sheet.items;
    const set = (id, n) => { const el = document.getElementById(id); if (el) el.textContent = n; };
    set('refillCntAll', items.length);
    set('refillCntNeed', items.filter(i => i.shortfall > 0 || i.requested > 0 || !i.counted).length);
    set('refillCntExp', items.filter(i => i.expiry && (i.expiry.state === 'expired' || i.expiry.state === 'soon')).length);

    const shown = items.filter(item => this.itemMatches(item));
    const list = document.getElementById('refillItems');
    if (list) {
      list.innerHTML = shown.length
        ? shown.map(item => `<div class="refill-card" data-id="${UI.escape(item.id)}">${this.renderItem(item)}</div>`).join('')
        : '<p class="empty">ไม่มีรายการในตัวกรองนี้ 🎉</p>';
    }
    this.renderSummary();
  }

  renderItem(item) {
    const unit = item.unit ? ' ' + UI.escape(item.unit) : '';
    const d = this.draftFor(item.id);
    const added = item.item_type === 'electric'
      ? d.units.length - d.retire.size
      : d.lots.reduce((s, l) => s + (Number(l.qty) > 0 ? Number(l.qty) : 0), 0);
    const current = item.current_qty === null ? null : Number(item.current_qty);
    const after = (current || 0) + added;

    const head = `
      <div class="check-card-head">
        <div>
          <strong>${UI.escape(item.name)}</strong>
          <span class="type-badge type-${UI.escape(item.item_type)}">${REFILL_TYPE_LABEL[item.item_type] || ''}</span>
          <div class="check-sub">
            คงเหลือ <b>${item.counted ? this.fmt(current) + unit : 'ยังไม่เคยตรวจนับ'}</b>
            • เป้าหมาย ${item.target_qty === null ? UI.escape(item.standard || '-') : this.fmt(item.target_qty) + unit}
            ${item.requested > 0 ? ` • <span class="req-badge">รอเติม ${this.fmt(item.requested)} (${UI.escape(item.request_no || '')})</span>` : ''}
          </div>
        </div>
        ${added ? `<span class="result-chip result-complete">หลังเติม ${this.fmt(after)}${unit}</span>` : ''}
      </div>`;

    if (item.item_type === 'electric') {
      const units = (item.units || []).map(u => `
        <label class="unit-existing ${d.retire.has(u.id) ? 'retired' : ''}">
          <input type="checkbox" data-action="retire" data-unit="${UI.escape(u.id)}" ${d.retire.has(u.id) ? 'checked' : ''}>
          ${UI.escape(u.label || 'เครื่อง')} — ${u.status === 'ready' ? '✅ พร้อมใช้' : '⛔ ไม่พร้อมใช้'}
          ${u.battery_level ? ` • แบต ${u.battery_level}/4` : ''} ${u.note ? ' • ' + UI.escape(u.note) : ''}
          <span class="text-xs">(ติ๊กเพื่อปลดออก/เปลี่ยนเครื่อง)</span>
        </label>`).join('') || '<p class="text-sm text-slate-500">ยังไม่มีเครื่องลงทะเบียน</p>';
      const newUnits = d.units.map((u, i) => `
        <div class="lot-input unit-input" data-row="${i}">
          <input type="text" maxlength="100" data-field="label" value="${UI.escape(u.label)}" placeholder="รหัส/เลขเครื่อง">
          <select data-field="status">
            <option value="ready" ${u.status === 'ready' ? 'selected' : ''}>พร้อมใช้งาน</option>
            <option value="not_ready" ${u.status === 'not_ready' ? 'selected' : ''}>ไม่พร้อมใช้งาน</option>
          </select>
          <select data-field="battery_level" aria-label="ระดับแบต">
            ${[4, 3, 2, 1].map(l => `<option value="${l}" ${Number(u.battery_level) === l ? 'selected' : ''}>แบต ${l} ขีด</option>`).join('')}
          </select>
          <input type="text" maxlength="200" data-field="note" value="${UI.escape(u.note)}" placeholder="หมายเหตุ/สาเหตุ (จำเป็นถ้าไม่พร้อมใช้)">
          <button type="button" class="small-btn gray" data-action="remove-row" aria-label="ลบแถว">✕</button>
        </div>`).join('');
      return head + `<div class="unit-list">${units}</div>${newUnits}
        <button type="button" class="small-btn" data-action="add-unit">+ เพิ่มเครื่อง (ตรวจรับ)</button>`;
    }

    const lots = (item.lots || []).map(lot => `
      <span class="lot lot-${UI.escape(lot.state)}">${lot.expiry_date ? 'หมดอายุ ' + UI.escape(UI.formatDateThai(lot.expiry_date)) : 'ไม่ทราบวันหมดอายุ'} · ${this.fmt(lot.qty)}${unit}</span>`).join('');
    const today = this.sheet.today;
    const rows = d.lots.map((lot, i) => {
      const warn = lot.expiry_date && lot.expiry_date < today ? '<span class="lot-warn">⛔ หมดอายุแล้ว — บันทึกไม่ได้</span>' : '';
      return `
      <div class="lot-input" data-row="${i}">
        <input type="number" min="0" step="any" inputmode="decimal" data-field="qty" value="${UI.escape(lot.qty)}" placeholder="จำนวน${item.item_type === 'box' ? ' (กล่อง/หน่วย)' : ''}" aria-label="จำนวนที่เติม">
        <input type="date" min="${UI.escape(today)}" data-field="expiry_date" value="${UI.escape(lot.expiry_date)}" aria-label="วันหมดอายุ">
        <button type="button" class="small-btn gray" data-action="remove-row" aria-label="ลบแถว">✕</button>
        ${warn}
      </div>`;
    }).join('');
    const suggest = Math.max(item.shortfall || 0, item.requested || 0);
    return head + `<div class="lot-list">${lots || '<span class="lot lot-unknown">ยังไม่มีของบนรถ</span>'}</div>${rows}
      <button type="button" class="small-btn" data-action="add-lot">+ เพิ่มจำนวน/วันหมดอายุ${suggest > 0 && !d.lots.length ? ` (แนะนำ ${this.fmt(suggest)})` : ''}</button>`;
  }

  rerenderItem(id) {
    const card = document.querySelector(`#refillItems .refill-card[data-id="${CSS.escape(id)}"]`);
    const item = this.sheet.items.find(i => i.id === id);
    if (card && item) card.innerHTML = this.renderItem(item);
    this.renderSummary();
  }

  onInput(event) {
    const el = event.target;
    const card = el.closest('.refill-card');
    if (!card) return;
    const id = card.dataset.id;
    const d = this.draftFor(id);
    if (el.dataset.action === 'retire') {
      if (el.checked) d.retire.add(el.dataset.unit); else d.retire.delete(el.dataset.unit);
      this.rerenderItem(id);
      return;
    }
    const row = el.closest('[data-row]');
    if (!row || !el.dataset.field) return;
    const item = this.sheet.items.find(i => i.id === id);
    const target = item.item_type === 'electric' ? d.units[Number(row.dataset.row)] : d.lots[Number(row.dataset.row)];
    target[el.dataset.field] = el.value;
    this.clientToken = null;   // ข้อมูลเปลี่ยน = การบันทึกครั้งใหม่
    if (event.type === 'change') this.rerenderItem(id); else this.renderSummary();
  }

  onClick(event) {
    const btn = event.target.closest('[data-action]');
    const card = event.target.closest('.refill-card');
    if (!btn || !card || btn.dataset.action === 'retire') return;
    const id = card.dataset.id;
    const d = this.draftFor(id);
    const item = this.sheet.items.find(i => i.id === id);
    if (btn.dataset.action === 'add-lot') {
      const suggest = Math.max(item.shortfall || 0, item.requested || 0);
      d.lots.push({ qty: !d.lots.length && suggest > 0 ? String(suggest) : '', expiry_date: '' });
    } else if (btn.dataset.action === 'add-unit') {
      d.units.push({ label: '', status: 'ready', battery_level: 4, note: '' });
    } else if (btn.dataset.action === 'remove-row') {
      const i = Number(btn.closest('[data-row]').dataset.row);
      (item.item_type === 'electric' ? d.units : d.lots).splice(i, 1);
    }
    this.clientToken = null;
    this.rerenderItem(id);
    card.querySelector('.lot-input:last-of-type input')?.focus();
  }

  collectLines() {
    const lines = [];
    const errors = [];
    const today = this.sheet.today;
    for (const item of this.sheet.items) {
      const d = this.drafts[item.id];
      if (!d) continue;
      if (item.item_type === 'electric') {
        if (!d.units.length && !d.retire.size) continue;
        d.units.forEach(u => {
          if (u.status === 'not_ready' && !String(u.note || '').trim()) errors.push(`${item.name}: เครื่องไม่พร้อมใช้งานต้องระบุสาเหตุ`);
        });
        lines.push({ item_id: item.id, units: d.units.map(u => ({ ...u, battery_level: Number(u.battery_level) })), retire_unit_ids: [...d.retire] });
        continue;
      }
      if (!d.lots.length) continue;
      d.lots.forEach((lot, i) => {
        const label = `${item.name} (แถว ${i + 1})`;
        if (!(Number(lot.qty) > 0)) errors.push(`${label}: จำนวนต้องมากกว่า 0`);
        if (!lot.expiry_date) errors.push(`${label}: ต้องระบุวันหมดอายุ`);
        else if (lot.expiry_date < today) errors.push(`${label}: ของหมดอายุแล้ว ไม่อนุญาตให้เติม`);
      });
      lines.push({ item_id: item.id, lots: d.lots.map(l => ({ qty: Number(l.qty), expiry_date: l.expiry_date })) });
    }
    return { lines, errors };
  }

  renderSummary() {
    const el = document.getElementById('refillSummary');
    if (!el || !this.sheet) return;
    const { lines, errors } = this.collectLines();
    el.textContent = lines.length
      ? `พร้อมบันทึก ${lines.length} รายการ${errors.length ? ` • ต้องแก้ ${errors.length} จุด` : ''}`
      : 'ยังไม่มีรายการที่จะเติม — กด “+ เพิ่มจำนวน/วันหมดอายุ” ที่รายการที่ต้องการ';
  }

  async submit() {
    if (this.submitting || !this.sheet) return;   // กันกดซ้ำระหว่างส่ง
    const { lines, errors } = this.collectLines();
    if (!lines.length) {
      window.toast.warning('ยังไม่มีรายการที่จะเติม');
      return;
    }
    if (errors.length) {
      window.toast.error(errors.slice(0, 4).join('\n'), 8000);
      return;
    }
    const ok = await window.confirmDialog(`บันทึกการเติมของเข้ารถ ${vehiclePlate(this.sheet.vehicle)} จำนวน ${lines.length} รายการ?`, { confirmText: 'บันทึกการเติม' });
    if (!ok) return;

    if (!this.clientToken) {
      this.clientToken = (window.crypto && crypto.randomUUID) ? crypto.randomUUID().replace(/-/g, '') : UI.uid() + UI.uid();
    }
    this.submitting = true;
    const btn = document.getElementById('refillSubmitBtn');
    UI.setLoading(btn, true);
    try {
      const res = await window.staffApi.refill(this.carId, {
        client_token: this.clientToken,
        note: document.getElementById('refillNote')?.value || '',
        lines,
      });
      const data = res.data || {};
      const parts = (data.applied || []).slice(0, 4).map(a => `${a.name}: ${this.fmt(a.before)} → ${this.fmt(a.after)}`);
      window.toast.success((data.duplicate ? 'รายการนี้ถูกบันทึกไปแล้ว (ไม่เติมซ้ำ)' : 'บันทึกการเติมเรียบร้อย') + (parts.length ? '\n' + parts.join('\n') : ''), 8000);
      (data.warnings || []).forEach(w => window.toast.warning(w, 9000));
      this.drafts = {};
      this.clientToken = null;
      const noteEl = document.getElementById('refillNote');
      if (noteEl) noteEl.value = '';
      await this.load();
    } catch (error) {
      // token เดิมยังใช้ต่อได้: ถ้าครั้งก่อนบันทึกสำเร็จแต่คำตอบหาย การกดใหม่จะได้ผลเดิม ไม่เติมซ้ำ
      window.toast.error(error.message, 9000);
    } finally {
      this.submitting = false;
      UI.setLoading(btn, false);
    }
  }

  setFilter(filter) {
    this.filter = filter;
    ['Need', 'All', 'Exp'].forEach(n => document.getElementById('refillFilter' + n)?.classList.toggle('on',
      { Need: 'need', All: 'all', Exp: 'expiry' }[n] === filter));
    this.renderSheet();
  }

  // ---------------- รายการรอเติม ----------------

  setRequestFilter(filter) {
    this.requestFilter = filter;
    ['Open', 'Completed', 'Cancelled', 'All'].forEach(n => document.getElementById('restockFilter' + n)?.classList.toggle('on',
      { Open: 'open', Completed: 'COMPLETED', Cancelled: 'CANCELLED', All: 'all' }[n] === filter));
    this.renderRequests();
  }

  renderRequests() {
    const list = document.getElementById('restockList');
    if (!list) return;
    const f = this.requestFilter;
    const rows = this.requests.filter(r => f === 'all'
      || (f === 'open' && ['PENDING', 'IN_PROGRESS', 'PARTIAL'].includes(r.status))
      || r.status === f);
    list.innerHTML = rows.length ? rows.map(r => {
      const lines = (r.items || []).map(i => `<li>${UI.escape(i.name)} — ขอ ${UI.escape(i.qty_requested || '0')} • เติมแล้ว ${UI.escape(i.qty_filled || '0')}</li>`).join('');
      const open = ['PENDING', 'IN_PROGRESS', 'PARTIAL'].includes(r.status);
      return `<div class="request-card" data-id="${UI.escape(r.id)}">
        <div class="check-card-head">
          <div>${window.vehicleChip({ id: r.car_id, name: r.car_name })} <b>${UI.escape(r.no)}</b>
            <div class="check-sub">${UI.escape(UI.formatDateTimeThai(r.created_at))} • ผู้แจ้ง ${UI.escape(r.created_name || '-')}</div></div>
          <span class="result-chip status-${UI.escape(r.status)}">${REQUEST_STATUS_LABEL[r.status] || UI.escape(r.status)}</span>
        </div>
        <ul class="request-lines">${lines}</ul>
        ${open ? `<div class="btn-row">
          <button type="button" class="small-btn" data-action="fill" data-car="${UI.escape(r.car_id)}">🧰 เติมรถคันนี้</button>
          <button type="button" class="small-btn gray" data-action="cancel">ยกเลิกรายการ</button></div>` : ''}
      </div>`;
    }).join('') : '<p class="empty">ไม่มีรายการ</p>';
  }

  async onRequestClick(event) {
    const btn = event.target.closest('[data-action]');
    const card = event.target.closest('.request-card');
    if (!btn || !card) return;
    if (btn.dataset.action === 'fill') {
      this.filter = 'need';
      await this.selectCar(btn.dataset.car);
      document.getElementById('refillSheet')?.scrollIntoView({ behavior: 'smooth' });
    } else if (btn.dataset.action === 'cancel') {
      const reason = UI.prompt('เหตุผลการยกเลิกรายการรอเติมนี้ (จำเป็น)');
      if (!reason || !reason.trim()) return;
      try {
        await window.staffApi.cancelRestock(card.dataset.id, { reason: reason.trim() });
        window.toast.success('ยกเลิกรายการแล้ว');
        await this.load();
      } catch (error) {
        window.toast.error(error.message);
      }
    }
  }
}

let refillPage;
window.initRestock = () => {
  if (!refillPage) {
    refillPage = new RefillPage();
    window.refillPage = refillPage;
  } else {
    refillPage.load();
  }
};
window.setRestockFilter = (filter) => refillPage?.setRequestFilter(filter);
window.setRefillFilter = (filter) => refillPage?.setFilter(filter);
window.submitRefill = () => refillPage?.submit();
