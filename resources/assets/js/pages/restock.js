// ========================================
// PAGE: REFILL (สต็อกประจำรถ + เติมอุปกรณ์เข้ารถโดยตรง — ไม่มีคลังสต็อกกลาง)
// ========================================
// - อุปกรณ์ 1 ชนิด = การ์ดเดียว ภายในมีล็อตย่อย (วันหมดอายุ/จำนวน/เลขล็อต/วันรับเข้า/หมายเหตุ)
// - 1 ครั้งที่กดบันทึกการเติม = 1 client_token (server: refer_refill_batches.client_token UNIQUE)
//   ส่งซ้ำ/กดซ้ำ/เน็ตหลุดแล้วลองใหม่ด้วย token เดิม → ได้ผลเดิม ไม่เติมซ้ำ
// - ไม่มีการลบล็อต: ยกเลิก/จำหน่ายหมดอายุ/ปรับยอด เป็น movement ที่ย้อนดูได้

const REFILL_TYPE_LABEL = { box: '📦 กล่อง', individual: '🧩 รายชิ้น', non_expiring: '🩺 ไม่มีวันหมดอายุ' };
const UNIT_TYPES = ['non_expiring'];
const LOT_STATE_LABEL = { expired: 'หมดอายุแล้ว', soon: 'ใกล้หมดอายุ', ok: 'ใช้ได้', unknown: 'รอตรวจสอบวันหมดอายุ', na: 'ไม่มีวันหมดอายุ' };
const UNIT_STATUS_LABEL = { ready: '✅ พร้อมใช้', not_ready: '⛔ ไม่พร้อมใช้', no_power: '🔋 เปิดไม่ติด', pending: '⏳ รอตรวจ' };
const MOVEMENT_LABEL = {
  INIT: 'ยอดตั้งต้น', REFILL: 'รับเข้า/เติม', USE: 'ใช้ไป', ADJUST: 'ปรับยอด', REVERSAL: 'ย้อนผลรอบตรวจ',
  EXPIRE: 'จำหน่ายหมดอายุ', VOID: 'ยกเลิกล็อต', LOT_EDIT: 'แก้ข้อมูลล็อต', RESET: 'ล้างสต็อกเริ่มระบบใหม่',
};
const REQUEST_STATUS_LABEL = {
  PENDING: 'รอเติม', IN_PROGRESS: 'รอเติม (ระบบเดิม)', PARTIAL: 'เติมไม่ครบ', COMPLETED: 'เติมครบ', CANCELLED: 'ยกเลิก',
};

class RefillPage {
  constructor() {
    this.vehicles = [];
    this.requests = [];
    this.carId = '';
    this.sheet = null;
    this.drafts = {};            // item_id => {lots: [...], units: [...], retire: Set}
    this.openLots = new Set();   // item_id ที่ขยายรายละเอียดล็อตไว้
    this.openCards = new Set();  // item_id ที่ขยายการ์ดไว้ (ค่าเริ่มต้น = ย่อทุกใบ เหลือแถวสรุป)
    this.filter = 'need';
    this.requestFilter = 'open';
    this.clientToken = null;
    this.submitting = false;
    this.bind();
    this.load();
  }

  isAdmin() {
    if (this.sheet && typeof this.sheet.is_admin === 'boolean') return this.sheet.is_admin;
    const user = window.auth?.getUser?.();
    return !!(user && user.role === 'admin');
  }

  bind() {
    const list = document.getElementById('refillItems');
    if (list) {
      list.addEventListener('input', (e) => this.onInput(e));
      list.addEventListener('change', (e) => this.onInput(e));
      list.addEventListener('click', (e) => this.onClick(e));
      // toggle ของ <details> ไม่ bubble — ดักแบบ capture ที่ list ครั้งเดียว ใช้ได้กับการ์ดที่ render ใหม่
      list.addEventListener('toggle', (e) => {
        const el = e.target;
        if (!el.classList?.contains('refill-collapse')) return;
        if (el.open) this.openCards.add(el.dataset.id); else this.openCards.delete(el.dataset.id);
      }, true);
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
      if (linked && (linked !== this.carId || this.focusItem)) await this.selectCar(linked, linked === this.carId);
      else if (this.carId) await this.selectCar(this.carId, true);
      if (this.focusItem) this.openItemForRefill(this.focusItem);
    } catch (error) {
      window.toast.error('โหลดข้อมูลไม่สำเร็จ: ' + error.message);
    }
  }

  // ลิงก์จากหน้าแรก/ทะเบียนรถ: #restock?car=<id> หรือ #restock?request=<id> (ใช้ครั้งเดียวแล้วล้าง)
  linkedCarFromHash() {
    const hash = window.location.hash || '';
    if (!hash.startsWith('#restock?')) return '';
    const params = new URLSearchParams(hash.slice(hash.indexOf('?') + 1));
    history.replaceState(null, '', window.location.pathname + window.location.search);
    // มาจากปุ่ม "เติมของ" ของอุปกรณ์รายการหนึ่ง (หน้ารถ/อุปกรณ์) → เปิดรายการนั้นให้เติมได้ทันที
    this.focusItem = params.get('item') || null;
    if (params.get('car')) return params.get('car');
    const req = this.requests.find(r => r.id === params.get('request'));
    return req ? req.car_id : '';
  }

  /** แสดงทุกรายการ เลื่อนไปที่อุปกรณ์ที่เลือก และเปิดแถว "เพิ่มล็อต" (หรือ "เพิ่มเครื่อง/ถัง") ให้กรอกได้เลย */
  openItemForRefill(itemId) {
    this.focusItem = null;
    const item = this.sheet && this.itemById(itemId);
    if (!item) return;
    this.setFilter('all');
    const d = this.draftFor(itemId);
    if (UNIT_TYPES.includes(item.item_type)) {
      if (!d.units.length) d.units.push({ label: '', status: 'ready', battery_level: item.battery_max_bars, pressure_psi: '', note: '' });
    } else if (!d.lots.length) {
      d.lots.push({ lot_id: '', qty: '', expiry_date: '', lot_number: '', received_date: this.sheet.today, note: '' });
    }
    this.openCards.add(itemId);
    this.rerenderItem(itemId);
    const card = document.querySelector(`#refillItems .refill-card[data-id="${CSS.escape(itemId)}"]`);
    if (card) {
      card.classList.add('is-focus');
      card.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(() => card.querySelector('.lot-input-card input')?.focus({ preventScroll: true }), 400);
    }
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
    await this.reloadSheet();
  }

  async reloadSheet() {
    try {
      const res = await window.staffApi.getRefillSheet(this.carId);
      this.sheet = res.data;
      document.getElementById('refillSheet')?.classList.remove('hidden');
      this.renderSheet();
    } catch (error) {
      window.toast.error(error.message);
    }
  }

  draftFor(id) {
    if (!this.drafts[id]) this.drafts[id] = { lots: [], units: [], retire: new Set(), updates: {} };
    return this.drafts[id];
  }

  hasDraftLines() {
    return Object.values(this.drafts).some(d => d.lots.length || d.units.length || d.retire.size || Object.keys(d.updates).length);
  }

  fmt(v) {
    if (v === null || v === undefined || v === '') return '-';
    return String(Math.round(Number(v) * 100) / 100);
  }

  itemById(id) {
    return this.sheet.items.find(i => i.id === id);
  }

  itemMatches(item) {
    if (this.filter === 'all') return true;
    if (this.filter === 'expiry') return item.expiry && (item.expiry.state === 'expired' || item.expiry.state === 'soon');
    // ต้องเติม: ยอดพร้อมใช้ต่ำกว่าเป้าหมาย / มียอดค้างในรายการรอเติม / ยังไม่เคยนับ / มีของหมดอายุ
    // / เครื่องไม่พร้อมใช้ / แบตหรือแรงดันต่ำกว่าเกณฑ์ / มีรายการที่กำลังกรอก
    const d = this.drafts[item.id];
    return RefillPage.needsAction(item)
      || (d && (d.lots.length || d.units.length || d.retire.size || Object.keys(d.updates).length));
  }

  static needsAction(item) {
    return item.shortfall > 0 || item.requested > 0 || !item.counted || (item.stock && item.stock.expired > 0)
      || item.not_ready > 0 || item.low > 0;
  }

  renderSheet() {
    if (!this.sheet) return;
    const head = document.getElementById('refillHead');
    if (head) {
      head.innerHTML = `${window.vehicleChip(this.sheet.vehicle)} <span>รายการรอเติมที่เปิดอยู่ ${this.sheet.open_requests.length} ใบ</span>`;
    }
    const items = this.sheet.items;
    const set = (id, n) => { const el = document.getElementById(id); if (el) el.textContent = n; };
    set('refillCntAll', items.length);
    set('refillCntNeed', items.filter(i => RefillPage.needsAction(i)).length);
    set('refillCntExp', items.filter(i => i.expiry && (i.expiry.state === 'expired' || i.expiry.state === 'soon')).length);

    const shown = items.filter(item => this.itemMatches(item));
    const list = document.getElementById('refillItems');
    if (list) {
      list.innerHTML = shown.length
        ? shown.map(item => `<div class="refill-card" data-id="${UI.escape(item.id)}">${this.renderItem(item)}</div>`).join('')
        : (this.filter === 'all'
          ? '<p class="empty">ไม่พบรายการ</p>'
          : `<div class="empty">${this.filter === 'need' ? '✅ ไม่มีของขาด' : 'ไม่พบรายการ'}
              <button type="button" class="small-btn" onclick="setRefillFilter('all')">แสดงทั้งหมด (เติมรายการอื่น)</button></div>`);
    }
    this.renderSummary();
  }

  // สถานะที่ต้องเห็นทันที (ของขาด/หมดอายุ/ใกล้หมดอายุ) — แสดงบนการ์ดเสมอ ไม่ซ่อนในรายละเอียด
  statusChips(item, unit) {
    const chips = [];
    if (!item.counted) chips.push('<span class="result-chip result-pending">📭 ยังไม่ได้จัดขึ้นรถ</span>');
    if (item.not_ready > 0) chips.push(`<span class="result-chip result-anomaly">⛔ ไม่พร้อมใช้ ${item.not_ready} — ตรวจสอบ/เปลี่ยน</span>`);
    if (item.low > 0) chips.push(`<span class="result-chip result-needs_refill">🔋 แบต/แรงดันต่ำ ${item.low} — ชาร์จ/เติม</span>`);
    if (item.shortfall > 0) chips.push(`<span class="result-chip result-needs_refill">⚠️ ของขาด ${this.fmt(item.shortfall)}${unit}</span>`);
    if (item.stock && item.stock.expired > 0) chips.push(`<span class="result-chip result-anomaly">⛔ หมดอายุแล้ว ${this.fmt(item.stock.expired)}</span>`);
    else if (item.expiry && item.expiry.state === 'soon') chips.push('<span class="result-chip result-warn">⚠️ ใกล้หมดอายุ</span>');
    if (item.over_target) chips.push('<span class="result-chip result-pending">เกินมาตรฐาน</span>');
    return chips.length ? `<div class="chip-line">${chips.join('')}</div>` : '';
  }

  renderItem(item) {
    const unit = item.unit ? ' ' + UI.escape(item.unit) : '';
    const d = this.draftFor(item.id);
    const isUnit = UNIT_TYPES.includes(item.item_type);
    const added = isUnit
      ? d.units.length - d.retire.size
      : d.lots.reduce((s, l) => s + (Number(l.qty) > 0 ? Number(l.qty) : 0), 0);
    const after = Number(item.current_qty || 0) + added;
    const target = item.target_qty === null ? null : Number(item.target_qty);

    // แถวสรุป (กดเพื่อขยาย): ชื่อ • ยอด • สถานะที่ต้องเห็นทันที — ส่วนกรอกเติมของอยู่ในส่วนที่ขยาย
    const head = `
      <summary class="refill-summary">
        <div class="check-card-head">
          <div>
            <strong>${UI.escape(item.name)}</strong>
            <span class="type-badge type-${UI.escape(item.item_type)}">${REFILL_TYPE_LABEL[item.item_type] || ''}</span>
          </div>
        </div>
        <p class="qty-big">คงเหลือ <b>${item.counted ? this.fmt(item.current_qty) : '-'}</b>${target !== null ? ' / ' + this.fmt(target) : ''}${unit}
          ${added ? ` → <b class="txt-ok">${this.fmt(after)}</b>` : ''}</p>
        ${this.statusChips(item, unit)}
        ${item.requested > 0 ? `<p class="check-sub"><span class="req-badge">รอเติม ${this.fmt(item.requested)}${unit}</span></p>` : ''}
      </summary>`;

    return `<details class="refill-collapse" data-id="${UI.escape(item.id)}" ${this.openCards.has(item.id) ? 'open' : ''}>`
      + head + (isUnit ? this.renderUnitSection(item, d) : this.renderLotSection(item, d, unit))
      + '<div class="card-foot"><button type="button" class="small-btn gray" data-action="history">🕘 ประวัติ</button></div></details>';
  }

  // ---------- ล็อต (กล่อง) / รายชิ้น (1 แถว = 1 ชิ้น มีวันหมดอายุของตัวเอง) ----------
  renderLotSection(item, d, unit) {
    const lots = item.lots || [];
    const open = this.openLots.has(item.id) || lots.some(l => l.state === 'expired');
    const admin = this.isAdmin();
    const piece = item.item_type === 'individual';
    const rows = lots.map((lot, i) => `
      <div class="lot-row lot-${UI.escape(lot.state)}" data-lot="${UI.escape(lot.id)}">
        <div class="lot-main">
          <b>${piece ? 'ชิ้นที่' : 'ล็อต'} ${i + 1}${lot.lot_number ? ' · ' + UI.escape(lot.lot_number) : ''}</b>
          <span>${lot.expiry_date ? 'หมดอายุ ' + UI.escape(UI.formatDateThai(lot.expiry_date)) : 'ไม่ทราบวันหมดอายุ'}</span>
          <span>เหลือ <b>${this.fmt(lot.qty)}</b>${unit}</span>
          <span class="lot-state">${{ expired: '⛔ ', soon: '⚠️ ', unknown: '❓ ' }[lot.state] || ''}${LOT_STATE_LABEL[lot.state] || ''}</span>
        </div>
        <div class="lot-meta">${lot.received_date ? 'รับเข้า ' + UI.escape(UI.formatDateThai(lot.received_date)) : ''}${lot.note ? ' • ' + UI.escape(lot.note) : ''}</div>
        <div class="lot-actions">
          ${lot.state === 'expired' ? '<button type="button" class="small-btn danger" data-action="lot-expire">นำออก (หมดอายุ)</button>' : ''}
          ${admin ? `<button type="button" class="small-btn gray" data-action="lot-edit">แก้ไข</button>
            <button type="button" class="small-btn gray" data-action="lot-adjust">ปรับยอด</button>
            <button type="button" class="small-btn gray" data-action="lot-void">ยกเลิกล็อต</button>` : ''}
        </div>
      </div>`).join('');

    const lotBlock = lots.length
      ? `<details class="lot-details" data-role="lots" ${open ? 'open' : ''}>
          <summary>${piece ? `ดูรายชิ้น ${lots.length} ชิ้น` : `ดูล็อต ${lots.length} รายการ`}</summary>
          ${rows}
        </details>`
      : '';

    const today = this.sheet.today;
    // ล็อตที่เติมเพิ่มได้: ยังไม่หมดอายุ
    const topUpable = piece ? [] : lots.filter(l => l.state !== 'expired');
    const newRows = d.lots.map((lot, i) => {
      const existing = lot.lot_id ? lots.find(l => l.id === lot.lot_id) : null;
      const expired = piece ? (lot.expiries || []).some(e => e && e < today) : (lot.expiry_date && lot.expiry_date < today);
      const warn = !existing && expired ? '<p class="lot-warn">⛔ หมดอายุแล้ว — เติมไม่ได้</p>' : '';
      const fid = (f) => `rf-${UI.escape(item.id)}-${i}-${f}`;
      return `
      <div class="lot-input-card" data-row="${i}">
        <p class="lot-input-title">${existing ? '🔁 เติมล็อตเดิม' : (piece ? '➕ ชิ้นใหม่ (บันทึกแยกทีละชิ้น)' : '➕ ล็อตใหม่')}</p>
        <div class="lot-input-grid">
          ${existing ? `
          <div><label for="${fid('lot')}">ล็อต</label>
            <select id="${fid('lot')}" data-field="lot_id">
              ${topUpable.map(l => `<option value="${UI.escape(l.id)}" ${lot.lot_id === l.id ? 'selected' : ''}>${UI.escape(l.lot_number || 'ล็อต')} ${l.expiry_date ? '· ' + UI.escape(UI.formatDateThai(l.expiry_date)) : ''}</option>`).join('')}
            </select></div>` : ''}
          <div><label for="${fid('qty')}">${piece ? 'จำนวนชิ้น *' : 'จำนวนที่เติม *'}</label>
            ${UI.stepper(`id="${fid('qty')}" min="0" data-field="qty" value="${UI.escape(lot.qty)}"`, 'จำนวนที่เติม')}</div>
          ${existing ? '' : `
          ${piece ? `<div class="add-dates" data-role="piece-dates">${this.renderPieceDates(item, lot, i)}</div>` : `
          <div><label for="${fid('exp')}">วันหมดอายุ${item.requires_expiry ? ' *' : ' (ถ้ามี)'}</label>
            <input id="${fid('exp')}" type="date" min="${UI.escape(today)}" data-field="expiry_date" value="${UI.escape(lot.expiry_date)}"></div>`}
          <details class="more-fields"><summary>เลขล็อต / วันรับ / หมายเหตุ</summary>
            <label for="${fid('no')}">เลขล็อต</label>
            <input id="${fid('no')}" type="text" maxlength="100" data-field="lot_number" value="${UI.escape(lot.lot_number)}">
            <label for="${fid('rcv')}">วันที่รับเข้า</label>
            <input id="${fid('rcv')}" type="date" max="${UI.escape(today)}" data-field="received_date" value="${UI.escape(lot.received_date)}">
            <label for="${fid('note')}">หมายเหตุ</label>
            <input id="${fid('note')}" type="text" maxlength="200" data-field="note" value="${UI.escape(lot.note)}">
          </details>`}
        </div>
        ${warn}
        <button type="button" class="small-btn gray" data-action="remove-row">✕ ลบ</button>
      </div>`;
    }).join('');

    return lotBlock + newRows + `
      <div class="add-row">
        <button type="button" class="small-btn add-btn" data-action="add-lot">${piece ? '➕ เพิ่มชิ้น (วันหมดอายุ)' : '➕ เพิ่มล็อตใหม่'}</button>
        ${topUpable.length ? '<button type="button" class="small-btn add-btn" data-action="add-topup">🔁 เติมล็อตเดิม</button>' : ''}
      </div>`;
  }

  // ---------- ไม่มีวันหมดอายุ: รายเครื่อง (+แบต ถ้ามี) / รายถัง (+PSI ถ้าวัดแรงดัน) ----------
  unitFields(item, u, fid) {
    const statuses = ['ready', 'not_ready'].concat(item.has_battery ? ['no_power'] : []);
    return `
          <div><label for="${fid('status')}">สถานะ</label>
            <select id="${fid('status')}" data-field="status">${statuses.map(s => `<option value="${s}" ${u.status === s ? 'selected' : ''}>${UNIT_STATUS_LABEL[s].replace(/^\S+ /, '')}</option>`).join('')}</select></div>
          ${item.has_battery && u.status !== 'no_power' ? `<div><label for="${fid('bat')}">แบตเตอรี่</label>
            <select id="${fid('bat')}" data-field="battery_level">${Array.from({ length: item.battery_max_bars + 1 }, (_, k) => item.battery_max_bars - k)
              .map(l => `<option value="${l}" ${Number(u.battery_level) === l ? 'selected' : ''}>${l} จาก ${item.battery_max_bars} ขีด</option>`).join('')}</select></div>` : ''}
          ${item.has_pressure ? `<div><label for="${fid('psi')}">แรงดัน</label>
            <div class="psi-input"><input id="${fid('psi')}" type="number" min="0" max="10000" step="1" inputmode="numeric" data-field="pressure_psi" value="${UI.escape(u.pressure_psi ?? '')}"><span class="psi-unit">PSI</span></div></div>` : ''}
          <div><label for="${fid('note')}">สาเหตุ/หมายเหตุ${u.status !== 'ready' ? ' *' : ''}</label>
            <input id="${fid('note')}" type="text" maxlength="200" data-field="note" value="${UI.escape(u.note || '')}"></div>`;
  }

  renderUnitSection(item, d) {
    const word = item.has_pressure ? 'ถัง' : 'เครื่อง';
    const units = (item.units || []).map(u => {
      const upd = d.updates[u.id];
      const fid = (f) => `uu-${UI.escape(u.id)}-${f}`;
      return `
      <div class="unit-existing-wrap ${u.status !== 'ready' ? 'lot-expired' : ''}" data-unit-id="${UI.escape(u.id)}">
        <label class="unit-existing ${d.retire.has(u.id) ? 'retired' : ''}">
          <input type="checkbox" data-action="retire" data-unit="${UI.escape(u.id)}" ${d.retire.has(u.id) ? 'checked' : ''}>
          ${UI.escape(u.label || word)} — ${UNIT_STATUS_LABEL[u.status] || UI.escape(u.status)}
          ${item.has_battery && u.battery_level !== null && u.battery_level !== undefined ? ` • แบต ${u.battery_level}/${item.battery_max_bars}` : ''}
          ${item.has_pressure && u.pressure_psi !== null && u.pressure_psi !== undefined ? ` • ${u.pressure_psi} PSI` : ''}
          ${u.note ? ' • ' + UI.escape(u.note) : ''}
          <span class="text-xs">(ติ๊กเพื่อปลดออก)</span>
        </label>
        ${upd ? `<div class="lot-input-card" data-update="${UI.escape(u.id)}">
            <p class="lot-input-title">✏️ อัปเดต ${UI.escape(u.label || word)}</p>
            <div class="lot-input-grid">${this.unitFields(item, upd, fid)}</div>
            <button type="button" class="small-btn gray" data-action="cancel-update">✕ ไม่อัปเดต</button>
          </div>`
          : `<button type="button" class="small-btn gray" data-action="update-unit">✏️ อัปเดตสถานะ${item.has_battery ? '/แบต' : ''}${item.has_pressure ? '/แรงดัน' : ''}</button>`}
      </div>`;
    }).join('') || `<p class="text-sm text-slate-500">ยังไม่มี${word}บนรถ</p>`;
    const newUnits = d.units.map((u, i) => {
      const fid = (f) => `ru-${UI.escape(item.id)}-${i}-${f}`;
      return `
      <div class="lot-input-card" data-row="${i}">
        <p class="lot-input-title">➕ ${word}ใหม่</p>
        <div class="lot-input-grid">
          <div><label for="${fid('label')}">รหัส/เลข${word}</label>
            <input id="${fid('label')}" type="text" maxlength="100" data-field="label" value="${UI.escape(u.label)}"></div>
          ${this.unitFields(item, u, fid)}
        </div>
        <button type="button" class="small-btn gray" data-action="remove-row">✕ ลบ</button>
      </div>`;
    }).join('');
    return `<div class="unit-list">${units}</div>${newUnits}
      <div class="add-row"><button type="button" class="small-btn add-btn" data-action="add-unit">➕ เพิ่ม${word}</button></div>`;
  }

  /** ของแบบชิ้น: ช่องวันหมดอายุ 1 ช่องต่อ 1 ชิ้น ตามจำนวนชิ้นที่กรอก (ค่าที่กรอกแล้วเก็บใน lot.expiries ไม่หายเมื่อแก้จำนวน) */
  renderPieceDates(item, lot, row) {
    const n = Number(lot.qty);
    if (lot.qty === '' || !(n > 0)) return '<p class="text-sm text-slate-500">กรอกจำนวนชิ้น แล้วจะมีช่องให้ใส่วันหมดอายุของแต่ละชิ้น</p>';
    if (!Number.isInteger(n)) return '<p class="txt-bad">จำนวนชิ้นต้องเป็นจำนวนเต็ม</p>';
    if (n > 50) return '<p class="txt-bad">เติมได้ครั้งละไม่เกิน 50 ชิ้น</p>';
    const exp = lot.expiries || [];
    return `<p class="add-dates-title">วันหมดอายุของแต่ละชิ้น (${n} ชิ้น) *</p>
      <div class="add-dates-grid">${Array.from({ length: n }, (_, k) => `
        <label for="rf-${UI.escape(item.id)}-${row}-pexp-${k}">ชิ้นที่ ${k + 1}
          <input id="rf-${UI.escape(item.id)}-${row}-pexp-${k}" type="date" min="${UI.escape(this.sheet.today)}" data-field="piece_expiry" data-k="${k}" value="${UI.escape(exp[k] || '')}"></label>`).join('')}
      </div>
      ${n > 1 ? '<button type="button" class="small-btn gray" data-action="fill-dates">ใช้วันแรกกับช่องที่ว่าง</button>' : ''}`;
  }

  rerenderItem(id) {
    const card = document.querySelector(`#refillItems .refill-card[data-id="${CSS.escape(id)}"]`);
    const item = this.itemById(id);
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
      this.clientToken = null;
      this.rerenderItem(id);
      return;
    }
    const upd = el.closest('[data-update]');
    const row = el.closest('[data-row]');
    if ((!row && !upd) || !el.dataset.field) return;
    const item = this.itemById(id);
    const target = upd ? d.updates[upd.dataset.update]
      : (UNIT_TYPES.includes(item.item_type) ? d.units[Number(row.dataset.row)] : d.lots[Number(row.dataset.row)]);
    this.clientToken = null;   // ข้อมูลเปลี่ยน = การบันทึกครั้งใหม่
    if (el.dataset.field === 'piece_expiry') {
      (target.expiries = target.expiries || [])[Number(el.dataset.k)] = el.value;
      this.renderSummary();
      return;
    }
    target[el.dataset.field] = el.value;
    // ของแบบชิ้น: จำนวนชิ้นเปลี่ยน → วาดช่องวันหมดอายุใหม่เฉพาะส่วนนั้น (ไม่ render ทั้งการ์ด เคอร์เซอร์จะได้ไม่หลุดจากช่องจำนวน)
    if (el.dataset.field === 'qty' && item.item_type === 'individual' && !target.lot_id) {
      const box = row.querySelector('[data-role="piece-dates"]');
      if (box) box.innerHTML = this.renderPieceDates(item, target, Number(row.dataset.row));
    }
    // select / date เปลี่ยนโครงฟอร์ม (เช่น เลือกล็อตเดิม → ซ่อนช่องวันหมดอายุ) จึง render ใหม่เมื่อ change เท่านั้น
    if (event.type === 'change' && el.tagName === 'SELECT') this.rerenderItem(id); else this.renderSummary();
  }

  async onClick(event) {
    const btn = event.target.closest('[data-action]');
    const card = event.target.closest('.refill-card');
    if (!btn || !card || btn.dataset.action === 'retire') return;
    const id = card.dataset.id;
    const item = this.itemById(id);
    const d = this.draftFor(id);
    const action = btn.dataset.action;
    const lotId = btn.closest('[data-lot]')?.dataset.lot;

    if (action === 'add-lot' || action === 'add-topup') {
      // เพิ่มล็อตใหม่ = ของคนละวันหมดอายุ/คนละล็อต | เติมล็อตเดิม = ล็อตเดียวกัน วันหมดอายุเดียวกัน
      const suggest = Math.max(item.shortfall || 0, item.requested || 0);
      const firstLot = (item.lots || []).find(l => l.state !== 'expired');
      d.lots.push({
        lot_id: action === 'add-topup' && firstLot ? firstLot.id : '',
        qty: !d.lots.length && suggest > 0 ? String(suggest) : '',
        expiry_date: '', lot_number: '', received_date: this.sheet.today, note: '',
      });
    } else if (action === 'fill-dates') {
      const lot = d.lots[Number(btn.closest('[data-row]').dataset.row)];
      const exp = lot.expiries = lot.expiries || [];
      const first = exp.find(Boolean);
      if (!first) { window.toast.error('ใส่วันหมดอายุอย่างน้อย 1 ช่องก่อน'); return; }
      for (let k = 0; k < Number(lot.qty); k++) if (!exp[k]) exp[k] = first;
    } else if (action === 'add-unit') {
      d.units.push({ label: '', status: 'ready', battery_level: item.battery_max_bars, pressure_psi: '', note: '' });
    } else if (action === 'update-unit') {
      const u = item.units.find(x => x.id === btn.closest('[data-unit-id]').dataset.unitId);
      d.updates[u.id] = { status: 'ready', battery_level: item.battery_max_bars, pressure_psi: u.pressure_psi ?? '', note: '' };
    } else if (action === 'cancel-update') {
      delete d.updates[btn.closest('[data-update]').dataset.update];
    } else if (action === 'remove-row') {
      const i = Number(btn.closest('[data-row]').dataset.row);
      (UNIT_TYPES.includes(item.item_type) ? d.units : d.lots).splice(i, 1);
    } else if (action === 'history') {
      await this.showHistory(item);
      return;
    } else if (action.startsWith('lot-') && lotId) {
      this.openLots.add(id);
      await this.lotAction(item, item.lots.find(l => l.id === lotId), action.slice(4));
      return;
    }
    this.clientToken = null;
    this.rerenderItem(id);
  }

  // ---------- จัดการล็อต (server ตรวจสิทธิ์ซ้ำ: แก้/ปรับ/ยกเลิก = admin) ----------
  async lotAction(item, lot, action) {
    if (!lot) return;
    const unit = item.unit ? ' ' + item.unit : '';
    const label = `${item.name} — ล็อต${lot.lot_number ? ' ' + lot.lot_number : ''} ${lot.expiry_date ? '(หมดอายุ ' + UI.formatDateThai(lot.expiry_date) + ')' : ''}`;
    let payload = null;
    if (action === 'expire') {
      const ok = await window.confirmDialog(`จำหน่ายของหมดอายุออกจากรถ?\n${label}\nจำนวน ${this.fmt(lot.qty)}${unit} (บันทึกในประวัติ ไม่ลบข้อมูล)`, { confirmText: 'จำหน่าย' });
      if (!ok) return;
      payload = {};
    } else {
      payload = await this.lotForm(item, lot, action, label);
      if (!payload) return;
    }
    try {
      if (action === 'edit') await window.staffApi.editLot(lot.id, payload);
      else await window.staffApi.lotAction(lot.id, action, payload);
      window.toast.success({ edit: 'แก้ไขข้อมูลล็อตแล้ว', adjust: 'ปรับยอดล็อตแล้ว', void: 'ยกเลิกล็อตแล้ว', expire: 'จำหน่ายของหมดอายุแล้ว' }[action]);
      await this.reloadSheet();
    } catch (error) {
      window.toast.error(error.message, 8000);
    }
  }

  lotForm(item, lot, action, label) {
    return new Promise((resolve) => {
      const today = this.sheet.today;
      const fields = {
        edit: `
          <label>เลขล็อต <input name="lot_number" maxlength="100" value="${UI.escape(lot.lot_number)}"></label>
          <label>วันหมดอายุ <input name="expiry_date" type="date" value="${UI.escape(lot.expiry_date || '')}"></label>
          <label>วันที่รับเข้า <input name="received_date" type="date" max="${UI.escape(today)}" value="${UI.escape(lot.received_date || '')}"></label>
          <label>หมายเหตุ <input name="note" maxlength="200" value="${UI.escape(lot.note)}"></label>
          <label>เหตุผล (จำเป็นเมื่อแก้วันหมดอายุ) <input name="reason" maxlength="200"></label>`,
        adjust: `
          <p class="text-sm">ยอดปัจจุบัน ${this.fmt(lot.qty)} — กรอกจำนวนที่นับได้จริงของล็อตนี้ ระบบบันทึกเป็นรายการปรับยอด</p>
          <label>จำนวนที่นับได้จริง <input name="qty" type="number" min="0" step="any" value="${UI.escape(String(lot.qty))}"></label>
          <label>เหตุผล * <input name="reason" maxlength="200" required></label>`,
        void: `
          <p class="text-sm">ใช้เมื่อบันทึกล็อตผิด หรือของเสียหาย — ยอดที่เหลือ ${this.fmt(lot.qty)} จะถูกตัดออก ล็อตและประวัติยังอยู่ (ไม่ลบ)</p>
          <label>เหตุผล * <input name="reason" maxlength="200" required></label>`,
      }[action];
      const modal = new Modal({
        title: { edit: 'แก้ไขข้อมูลล็อต', adjust: 'ปรับยอดล็อต', void: 'ยกเลิกล็อต' }[action],
        content: `<p class="mb-2 text-sm font-semibold">${UI.escape(label)}</p><form class="lot-form">${fields}</form>`,
        size: 'sm',
        onClose: () => resolve(null),
      }).render();
      const actions = modal.element.querySelector('.sheet-actions');
      actions.innerHTML = `<button type="button" class="small-btn gray flex-1" data-action="cancel">ยกเลิก</button>
        <button type="button" class="flex-1" data-action="ok">${action === 'void' ? 'ยืนยันยกเลิกล็อต' : 'บันทึก'}</button>`;
      actions.querySelector('[data-action="cancel"]').onclick = () => { resolve(null); modal.close(); };
      actions.querySelector('[data-action="ok"]').onclick = () => {
        const data = Object.fromEntries(new FormData(modal.element.querySelector('form')).entries());
        if ((action === 'adjust' || action === 'void') && !String(data.reason || '').trim()) {
          window.toast.warning('กรุณาระบุเหตุผล');
          return;
        }
        resolve(data);
        modal.close();
      };
      modal.open();
    });
  }

  async showHistory(item) {
    try {
      const res = await window.staffApi.itemMovements(item.id);
      const rows = (res.data || []).map(m => `
        <tr><td>${UI.escape(UI.formatDateTimeThai(m.at))}</td>
          <td>${MOVEMENT_LABEL[m.type] || UI.escape(m.type)}</td>
          <td class="num ${Number(m.qty) < 0 ? 'txt-bad' : ''}">${Number(m.qty) > 0 ? '+' : ''}${this.fmt(m.qty)}</td>
          <td class="num">${this.fmt(m.balance_after)}</td>
          <td>${UI.escape(m.lot_number || '')} ${m.expiry_date ? UI.escape(UI.formatDateThai(m.expiry_date)) : ''}</td>
          <td>${UI.escape(m.by_name || '')}</td><td>${UI.escape(m.reason || '')}</td></tr>`).join('');
      const modal = new Modal({
        title: `ประวัติการเคลื่อนไหว — ${item.name}`,
        size: 'lg',
        content: rows
          ? `<div class="table-scroll"><table class="mv-table"><thead><tr><th>เวลา</th><th>ประเภท</th><th>จำนวน</th><th>คงเหลือรวม</th><th>ล็อต</th><th>ผู้บันทึก</th><th>เหตุผล</th></tr></thead><tbody>${rows}</tbody></table></div>`
          : '<p class="empty">ยังไม่มีประวัติ</p>',
      }).render();
      modal.open();
    } catch (error) {
      window.toast.error(error.message);
    }
  }

  // ---------- บันทึกการเติม ----------
  collectLines() {
    const lines = [];
    const errors = [];
    const today = this.sheet.today;
    for (const item of this.sheet.items) {
      const d = this.drafts[item.id];
      if (!d) continue;
      if (UNIT_TYPES.includes(item.item_type)) {
        const updates = Object.entries(d.updates).filter(([uid]) => !d.retire.has(uid));
        if (!d.units.length && !d.retire.size && !updates.length) continue;
        const reading = (u) => ({
          status: u.status, note: u.note || '',
          battery_level: item.has_battery && u.status !== 'no_power' ? Number(u.battery_level) : null,
          pressure_psi: item.has_pressure ? Number(u.pressure_psi) : null,
        });
        [...d.units, ...updates.map(([, u]) => u)].forEach(u => {
          if (u.status !== 'ready' && !String(u.note || '').trim()) errors.push(`${item.name}: ไม่พร้อมใช้/เปิดไม่ติด ต้องระบุสาเหตุ`);
          if (item.has_pressure && (u.pressure_psi === '' || !(Number(u.pressure_psi) >= 0))) errors.push(`${item.name}: กรอกแรงดัน (PSI)`);
        });
        lines.push({
          item_id: item.id,
          units: d.units.map(u => ({ label: u.label, ...reading(u) })),
          unit_updates: updates.map(([uid, u]) => ({ id: uid, ...reading(u) })),
          retire_unit_ids: [...d.retire],
        });
        continue;
      }
      if (item.item_type === 'individual' && d.lots.length) {
        // ของแบบชิ้น: ทุกชิ้นต้องมีวันหมดอายุ — ส่งเป็นรายชิ้น (จำนวนชิ้น = จำนวนวันหมดอายุ ตรวจซ้ำที่ server)
        const pieces = [];
        d.lots.forEach((lot, i) => {
          const label = `${item.name} (แถว ${i + 1})`;
          const n = Number(lot.qty);
          if (!(n > 0) || !Number.isInteger(n)) errors.push(`${label}: จำนวนชิ้นต้องเป็นจำนวนเต็มมากกว่า 0`);
          else if (n > 50) errors.push(`${label}: เติมได้ครั้งละไม่เกิน 50 ชิ้น`);
          const count = Number.isInteger(n) && n > 0 && n <= 50 ? n : 0;
          const exp = (lot.expiries || []).slice(0, count);
          const missing = Array.from({ length: count }, (_, k) => k).filter(k => !exp[k]);
          if (missing.length) errors.push(`${label}: ต้องระบุวันหมดอายุชิ้นที่ ${missing.map(k => k + 1).join(', ')}`);
          if (exp.some(e => e && e < today)) errors.push(`${label}: มีชิ้นที่หมดอายุแล้ว ไม่อนุญาตให้เติม`);
          for (let k = 0; k < count; k++) {
            pieces.push({ expiry_date: exp[k] || null, lot_number: lot.lot_number, received_date: lot.received_date, note: lot.note });
          }
        });
        lines.push({ item_id: item.id, qty: pieces.length, pieces });
        continue;
      }
      if (!d.lots.length) continue;
      d.lots.forEach((lot, i) => {
        const label = `${item.name} (แถว ${i + 1})`;
        if (!(Number(lot.qty) > 0)) errors.push(`${label}: จำนวนต้องมากกว่า 0`);
        if (lot.lot_id) return;
        if (!lot.expiry_date && item.requires_expiry) errors.push(`${label}: ต้องระบุวันหมดอายุ`);
        else if (lot.expiry_date && lot.expiry_date < today) errors.push(`${label}: ของหมดอายุแล้ว ไม่อนุญาตให้เติม`);
        if (lot.received_date && lot.received_date > today) errors.push(`${label}: วันที่รับเข้าเป็นวันในอนาคตไม่ได้`);
      });
      lines.push({
        item_id: item.id,
        lots: d.lots.map(l => l.lot_id
          ? { lot_id: l.lot_id, qty: Number(l.qty) }
          : { qty: Number(l.qty), expiry_date: l.expiry_date || null, lot_number: l.lot_number, received_date: l.received_date, note: l.note }),
      });
    }
    return { lines, errors };
  }

  renderSummary() {
    const el = document.getElementById('refillSummary');
    if (!el || !this.sheet) return;
    const { lines, errors } = this.collectLines();
    el.textContent = lines.length
      ? `พร้อมบันทึก ${lines.length} รายการ${errors.length ? ` • ต้องแก้ ${errors.length} จุด` : ''}`
      : 'ยังไม่ได้เลือกรายการ';
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
    const ok = await window.confirmDialog(`ยืนยันการบันทึก?\n${vehiclePlate(this.sheet.vehicle)} · ${lines.length} รายการ`, { confirmText: 'บันทึก' });
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
      // แสดงผลสำเร็จหลังเซิร์ฟเวอร์ยืนยัน commit แล้วเท่านั้น
      const data = res.data || {};
      const parts = (data.applied || []).slice(0, 4).map(a => `${a.name}: ${this.fmt(a.before)} → ${this.fmt(a.after)}`);
      window.mascotSuccess(data.duplicate ? 'บันทึกไปแล้ว (ไม่เติมซ้ำ)' : 'บันทึกการเติมสต๊อกเรียบร้อยแล้ว', parts.join('\n'));
      (data.warnings || []).forEach(w => window.toast.warning(w, 9000));
      this.drafts = {};
      this.openCards.clear();   // บันทึกแล้ว → ย่อทุกการ์ดกลับเป็นรายการ
      this.clientToken = null;
      const noteEl = document.getElementById('refillNote');
      if (noteEl) noteEl.value = '';
      await this.load();
    } catch (error) {
      // token เดิมยังใช้ต่อได้: ถ้าครั้งก่อนบันทึกสำเร็จแต่คำตอบหาย การกดใหม่จะได้ผลเดิม ไม่เติมซ้ำ
      window.toast.error('บันทึกไม่สำเร็จ ลองอีกครั้ง — ' + error.message, 9000);
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
      const lines = (r.items || []).map(i => (i.line_type === 'replace'
        ? `<li>${UI.escape(i.name)} — 🔧 ตรวจสอบ/เปลี่ยน ${Number(i.qty_filled) >= Number(i.qty_requested) ? '✅ เสร็จแล้ว' : ''}${i.note ? ` <small>(${UI.escape(i.note)})</small>` : ''}</li>`
        : `<li>${UI.escape(i.name)} — ต้องเติม ${UI.escape(i.qty_requested || '0')} • เติมแล้ว ${UI.escape(i.qty_filled || '0')}${i.note ? ` <small>(${UI.escape(i.note)})</small>` : ''}</li>`)).join('');
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
