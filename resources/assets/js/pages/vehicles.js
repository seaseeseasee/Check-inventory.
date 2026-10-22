// ========================================
// PAGE: VEHICLES / STOCK
// ========================================

const VEHICLE_STATUS_LABEL = {
  available: 'พร้อมใช้งาน',
  maintenance: 'กำลังซ่อมบำรุง',
  disabled: 'งดใช้งาน',
};

const VEHICLE_STATUS_CHIP = {
  available: 'chip-ok',
  maintenance: 'chip-warn',
  disabled: 'chip-off',
};

class VehiclesPage {
  constructor() {
    this.vehicles = [];
    this.currentVehicleId = null;
    this.currentVehicle = null;
    this.items = [];
    this.showAddForm = false;
    this.editingItemId = null;
    this.copyStaging = [];
    this.copySourceLabel = '';
    this._vehicleModal = null;
    this.init();
  }

  async init() {
    await this.loadVehicles();
    this.bindEvents();
    this.renderVehicleSelect();
  }

  async loadVehicles() {
    try {
      // เส้นทาง admin ให้ตัวเลขประกอบที่จำเป็นต้องใช้ตอนตัดสินใจ (จำนวนอุปกรณ์
      // /ประวัติตรวจ/งานเติมค้าง) ซึ่ง مسیر /api/vehicles ปกติไม่มีให้
      const res = window.auth && window.auth.isAdmin && window.auth.isAdmin()
        ? await window.adminApi.getVehicles()
        : await window.vehicleApi.getAll();
      this.vehicles = res.data || [];
    } catch (error) {
      console.error('Failed to load vehicles:', error);
      // ยังใช้ข้อมูลพื้นฐานต่อได้ แม้ endpoint admin จะตอบผิด (เช่น session หมดอายุ)
      try {
        const fallback = await window.vehicleApi.getAll();
        this.vehicles = fallback.data || [];
      } catch (inner) {
        this.vehicles = [];
        window.toast.error('โหลดข้อมูลรถไม่สำเร็จ');
      }
    }

    // ให้ helper ทะเบียนรถกลางรู้ค่า plate ล่าสุด (โหลดซ้ำทุกครั้งที่เพิ่ม/แก้/ลบรถ)
    window.vehicleRef.remember(this.vehicles);
  }

  bindEvents() {
    // Vehicle select
    document.getElementById('stockCar')?.addEventListener('change', () => this.onVehicleChange());

    // Vehicle CRUD
    document.getElementById('btnEditVehicle')?.addEventListener('click', () => this.showEditVehicleForm());
    document.getElementById('btnToggleVehicleStatus')?.addEventListener('click', () => this.handleToggleVehicleStatus());
    document.getElementById('btnDeleteVehicle')?.addEventListener('click', () => this.handleDeleteVehicle());

    // Add item form
    document.getElementById('stockAddForm')?.addEventListener('submit', (e) => this.handleAddItem(e));

    // Copy items from another vehicle
    document.getElementById('btnCopyStart')?.addEventListener('click', () => this.handleCopyStart());
    document.getElementById('btnCopyConfirm')?.addEventListener('click', () => this.handleCopyConfirm());
    document.getElementById('btnCopyCancel')?.addEventListener('click', () => this.handleCopyCancel());
  }

  renderVehicleSelect() {
    const select = document.getElementById('stockCar');
    if (!select) return;

    // จำคันที่เลือกไว้ก่อน rebuild — ไม่อย่างนั้นหลังแก้/เพิ่มรถ หน้าจะเด้งกลับไปคันแรก
    const previous = this.currentVehicleId || select.value || '';

    select.innerHTML = this.vehicles.map(v => {
      const status = VEHICLE_STATUS_LABEL[v.status] ? ` • ${VEHICLE_STATUS_LABEL[v.status]}` : '';
      return `<option value="${UI.escape(v.id)}">${UI.escape(vehiclePlate(v))}${UI.escape(status)}</option>`;
    }).join('');

    if (previous && this.vehicles.some(v => String(v.id) === String(previous))) {
      select.value = previous;
    }

    this.renderCopySourceSelect();
    this.onVehicleChange();
  }

  renderCopySourceSelect() {
    const select = document.getElementById('copySourceCar');
    const btn = document.getElementById('btnCopyStart');
    if (!select) return;

    const sources = this.vehicles.filter(v => String(v.id) !== String(this.currentVehicleId));
    select.innerHTML = sources.length
      ? sources.map(v => `<option value="${UI.escape(v.id)}">${UI.escape(vehiclePlate(v))}</option>`).join('')
      : '<option value="">— ไม่มีรถคันอื่นให้คัดลอก —</option>';
    select.disabled = sources.length === 0;
    if (btn) btn.disabled = sources.length === 0;
  }

  async onVehicleChange() {
    const vehicleId = document.getElementById('stockCar')?.value;
    const hadStaging = this.copyStaging.length > 0;
    this.currentVehicleId = vehicleId;
    this.currentVehicle = this.vehicles.find(v => String(v.id) === String(vehicleId)) || null;
    this.clearCopyStaging();
    if (hadStaging) {
      window.toast.info('สลับรถแล้ว — ล้างรายการรอคัดลอกที่ค้างอยู่');
    }
    this.renderCopySourceSelect();
    this.renderVehicleMeta();
    await this.loadItems(vehicleId);
  }

  /** แถบสรุปข้อมูลรถที่เลือก — บอกสถานะ + สิ่งที่ผูกอยู่ (ใช้ตอนจะลบรถ) */
  renderVehicleMeta() {
    const el = document.getElementById('stockCarMeta');
    this.updateToggleStatusButton();
    if (!el) return;
    const v = this.currentVehicle;
    if (!v) {
      el.innerHTML = 'ยังไม่มีรถในระบบ — กด “เพิ่มรถใหม่” เพื่อเริ่ม';
      return;
    }

    const status = VEHICLE_STATUS_LABEL[v.status] || v.status || '-';
    const chip = VEHICLE_STATUS_CHIP[v.status] || 'chip-off';
    window.vehicleRef.remember([v]);
    const bits = [window.vehicleChip(v), `<span class="chip ${chip}">${UI.escape(status)}</span>`];

    if (v.active_item_count !== undefined) {
      bits.push(`<span class="chip chip-info">อุปกรณ์ใช้งาน ${UI.escape(String(v.active_item_count))} รายการ</span>`);
    }
    if (v.check_count !== undefined) {
      bits.push(`<span class="chip chip-pending">ประวัติตรวจ ${UI.escape(String(v.check_count))} ครั้ง</span>`);
    }
    if (v.open_restock_count !== undefined) {
      const cls = Number(v.open_restock_count) > 0 ? 'chip-warn' : 'chip-pending';
      bits.push(`<span class="chip ${cls}">งานเติมค้าง ${UI.escape(String(v.open_restock_count))}</span>`);
    }

    el.innerHTML = bits.join('');
  }

  /** ปุ่มเดียวสลับ "ปิดใช้งาน" ↔ "เปิดใช้งาน" ตามสถานะปัจจุบันของรถที่เลือก */
  updateToggleStatusButton() {
    const btn = document.getElementById('btnToggleVehicleStatus');
    if (!btn) return;
    const v = this.currentVehicle;
    if (!v) {
      btn.disabled = true;
      btn.textContent = '🚫 ปิดใช้งานรถ';
      return;
    }
    btn.disabled = false;
    if (v.status === 'disabled') {
      btn.textContent = '✅ เปิดใช้งานรถ';
      btn.classList.remove('danger');
    } else {
      btn.textContent = '🚫 ปิดใช้งานรถ';
      btn.classList.add('danger');
    }
  }

  /**
   * สลับสถานะรถแบบคลิกเดียว — ไม่ต้องเปิดฟอร์มแก้ไขทั้งหมดเพื่อแค่เปลี่ยนสถานะ
   * เปิดใช้งานกลับ: ตั้งเป็น "พร้อมใช้งาน" ตรง ๆ
   * ปิดใช้งาน: ใช้ endpoint เดียวกับปุ่มลบ (mode=disable) เพราะเซิร์ฟเวอร์ตรวจประวัติผูกให้แล้ว
   */
  async handleToggleVehicleStatus() {
    const vehicle = this.currentVehicle;
    const id = this.currentVehicleId;
    if (!vehicle || !id) {
      window.toast.warning('กรุณาเลือกรถก่อน');
      return;
    }

    const label = vehiclePlate(vehicle);
    const btn = document.getElementById('btnToggleVehicleStatus');

    if (vehicle.status === 'disabled') {
      const ok = await window.confirmDialog(
        `เปิดใช้งานรถ "${label}" กลับหรือไม่? รถจะกลับมาเลือกตรวจเช็คได้ตามปกติ`,
        { title: 'เปิดใช้งานรถ', confirmText: 'เปิดใช้งาน' }
      );
      if (!ok) return;

      if (btn) UI.setLoading(btn, true);
      try {
        await window.vehicleApi.update(id, { status: 'available' });
        window.toast.success(`เปิดใช้งานรถ "${label}" แล้ว`);
        await this.loadVehicles();
        this.currentVehicleId = id;
        this.renderVehicleSelect();
      } catch (error) {
        window.toast.error('เปิดใช้งานไม่สำเร็จ: ' + (error.message || 'ไม่ทราบสาเหตุ'));
      } finally {
        if (btn) UI.setLoading(btn, false);
      }
      return;
    }

    const ok = await window.confirmDialog(
      `ปิดใช้งานรถ "${label}" หรือไม่? รถจะหายจากเมนูเลือกสำหรับหน้าตรวจของผู้ใช้งานทั่วไป `
      + 'แต่ประวัติเดิมยังอยู่ครบ และเปิดใช้งานกลับได้ทุกเมื่อ',
      { title: 'ปิดใช้งานรถ', confirmText: 'ปิดใช้งาน' }
    );
    if (!ok) return;

    if (btn) UI.setLoading(btn, true);
    try {
      await this.removeVehicle(label, 'disable');
    } finally {
      if (btn) UI.setLoading(btn, false);
    }
  }

  async loadItems(vehicleId) {
    if (!vehicleId) {
      this.items = [];
      this.renderItems();
      return;
    }
    try {
      // ใช้ endpoint admin เพื่อเห็นแถวที่ "พักใช้งาน" ด้วย (เปิดกลับ/ลบถาวรได้)
      if (window.auth && window.auth.isAdmin && window.auth.isAdmin()) {
        const res = await window.adminApi.getVehicle(vehicleId);
        this.items = res.data?.items || [];
        this.currentVehicle = Object.assign(
          {},
          this.currentVehicle || {},
          this.vehicles.find(v => String(v.id) === String(vehicleId)) || {},
          res.data || {}
        );
        this.renderVehicleMeta();
      } else {
        const res = await window.vehicleApi.get(vehicleId);
        this.items = res.data?.items || [];
      }
      this.renderItems();
    } catch (error) {
      console.error('Failed to load items:', error);
      try {
        const res = await window.vehicleApi.get(vehicleId);
        this.items = res.data?.items || [];
        this.renderItems();
      } catch (inner) {
        this.items = [];
        this.renderItems();
        window.toast.error('โหลดอุปกรณ์ไม่สำเร็จ: ' + (inner.message || ''));
      }
    }
  }

  renderItems() {
    const container = document.getElementById('stockTable');
    if (!container) return;

    container.innerHTML = this.items.map((item) => {
      const counted = item.stock_updated_at ? String(item.stock_updated_at) : '';
      const stamp = counted
        ? `<span class="stock-stamp">ยอดล่าสุด ${UI.escape(this.fmtStamp(counted))}</span>`
        : '<span class="stock-stamp">ยังไม่ได้จัดขึ้นรถ</span>';

      // ยอดคงเหลือ + วันหมดอายุมาจาก Lot / รายเครื่อง (แก้ผ่านการเติมของ/การตรวจเช็คเท่านั้น)
      const type = item.item_type || (item.is_battery ? 'non_expiring' : 'individual');
      const unitWord = item.has_pressure ? ' ถัง' : ' เครื่อง';
      const viewLabel = type === 'non_expiring' ? (item.has_pressure ? '🔧 จัดการถัง' : '🔧 จัดการเครื่อง') : (type === 'individual' ? '📦 ดูรายชิ้น' : '📦 ดูล็อต');
      const expiry = String(item.expiry_text ?? '');
      const qtyText = counted
        ? `${UI.escape(String(Math.round(Number(item.current_qty || 0) * 100) / 100))}${type === 'non_expiring' ? unitWord : ''}`
        : 'ยังไม่ได้จัดขึ้นรถ';

      return `
      <article class="stock-card${item.active ? '' : ' is-off'}" data-item-id="${item.id}">
        <div class="stock-top">
          <input type="text" class="stock-name-input" value="${UI.escape(item.name)}" data-item-id="${item.id}" data-field="name" aria-label="ชื่ออุปกรณ์">
          <span class="chip ${item.active ? 'chip-ok' : 'chip-off'}">
            ${item.active ? 'ใช้งาน' : 'พัก'}
          </span>
        </div>
        <div class="stock-fields">
          <div>
            <label for="std-${item.id}">ขั้นต่ำที่ต้องมี (มาตรฐาน)</label>
            <input id="std-${item.id}" type="text" value="${UI.escape(item.standard)}" data-item-id="${item.id}" data-field="standard" placeholder="เช่น 5 หรือ 1 ชุด">
          </div>
          <div>
            <label>ประเภท</label>
            <select data-item-id="${item.id}" data-field="item_type" style="min-height: 44px;">
              <option value="individual" ${type === 'individual' ? 'selected' : ''}>รายชิ้น (วันหมดอายุรายชิ้น)</option>
              <option value="box" ${type === 'box' ? 'selected' : ''}>กล่อง/ล็อต (วันหมดอายุทั้งกล่อง)</option>
              <option value="non_expiring" ${type === 'non_expiring' ? 'selected' : ''}>ไม่มีวันหมดอายุ (รายเครื่อง)</option>
            </select>
          </div>
          ${this.renderThresholdFields(item, type)}
          ${type === 'non_expiring' ? `
          <div class="flag-row">
            <label><input type="checkbox" data-item-id="${item.id}" data-field="is_battery" ${item.is_battery ? 'checked' : ''}> 🔋 มีแบตเตอรี่</label>
            <label><input type="checkbox" data-item-id="${item.id}" data-field="has_pressure" ${item.has_pressure ? 'checked' : ''}> 🫧 วัดแรงดัน (PSI)</label>
          </div>` : ''}
          ${type === 'non_expiring' ? `
          <div>
            <label>มีบนรถตอนนี้</label>
            <div class="readonly-field">${qtyText} <small>(เพิ่ม/นำออก/อัปเดตที่ปุ่ม “${viewLabel}”)</small></div>
          </div>` : `
          <div>
            <label for="onb-${item.id}">มีบนรถตอนนี้</label>
            ${UI.stepper(`id="onb-${item.id}" min="0" data-item-id="${item.id}" data-field="onboard_qty" value="${counted ? UI.escape(String(Math.round(Number(item.current_qty || 0) * 100) / 100)) : ''}" placeholder="${counted ? '' : 'ยังไม่ได้จัด'}"`, 'ยอดบนรถ')}
          </div>

          <div class="add-dates" data-role="add-dates" data-item-id="${item.id}">${this.renderAddDates(item, 0)}</div>`}
          <div>
            <label>หมดอายุเร็วสุด</label>
            <div class="readonly-field">${type === 'non_expiring' ? '—' : (expiry ? UI.escape(UI.formatDateThai(expiry)) : 'ไม่มีข้อมูล')}</div>
          </div>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; margin-top: 6px;">${stamp}</div>
        <div class="item-lots-actions">
          <button type="button" class="small-btn secondary" data-action="lots" data-item-id="${item.id}" aria-expanded="false" data-label="${viewLabel}">${viewLabel}</button>
        </div>
        <div class="item-lots hidden" data-role="lots" aria-live="polite"></div>
        <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px;">
          <button type="button" class="small-btn" data-action="save" data-item-id="${item.id}">บันทึก</button>
          <button type="button" class="small-btn gray" data-action="toggle" data-item-id="${item.id}">
            ${item.active ? 'พักใช้งาน' : 'เปิดใช้งาน'}
          </button>
          <button type="button" class="small-btn danger" data-action="delete" data-item-id="${item.id}">ลบ</button>
        </div>
      </article>
    `;
    }).join('') || '<p class="empty">ยังไม่มีอุปกรณ์ในรถคันนี้</p>';

    // Bind events
    container.querySelectorAll('[data-action="save"]').forEach(btn => {
      btn.addEventListener('click', () => this.saveItem(btn.dataset.itemId));
    });

    container.querySelectorAll('[data-action="toggle"]').forEach(btn => {
      btn.addEventListener('click', () => this.toggleItemActive(btn.dataset.itemId));
    });

    // ดูล็อต/รายชิ้น/เครื่อง = เปิดในการ์ดนี้เลย แก้ เพิ่ม นำออก ได้ที่นี่ (ไม่ย้ายหน้า)
    container.querySelectorAll('[data-action="lots"]').forEach(btn => {
      btn.addEventListener('click', () => this.toggleLots(btn));
    });
    // ยอดบนรถเพิ่มขึ้น → แสดงช่องวันหมดอายุ (กล่อง: 1 ช่อง | รายชิ้น: 1 ช่องต่อ 1 ชิ้นที่เพิ่ม)
    container.querySelectorAll('[data-field="onboard_qty"]').forEach(input => {
      input.addEventListener('input', () => this.refreshAddDates(input.dataset.itemId));
    });
    if (!container.dataset.boundDates) {   // container อยู่ถาวร — ผูกครั้งเดียว
      container.dataset.boundDates = '1';
      container.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action="fill-dates"]');
        if (!btn) return;
        const inputs = [...btn.closest('[data-role="add-dates"]').querySelectorAll('input[type="date"]')];
        const first = inputs.find(i => i.value);
        if (first) inputs.forEach(i => { if (!i.value) i.value = first.value; });
      });
    }
    // แก้/นำออกรายล็อต-รายชิ้น ในการ์ดเลย
    container.querySelectorAll('[data-role="lots"]').forEach(box => {
      box.addEventListener('click', (e) => this.onLotViewClick(e, box));
    });
    this.lotSheet = null;   // ข้อมูลล็อตโหลดใหม่ทุกครั้งที่ render รายการ (ยอดอาจเปลี่ยนจากการตรวจ/เติม)

    container.querySelectorAll('[data-action="delete"]').forEach(btn => {
      btn.addEventListener('click', () => this.deleteItem(btn.dataset.itemId));
    });
  }

  // ---------- ช่องวันหมดอายุของที่เพิ่ม (หน้ารถ) ----------
  renderAddDates(item, add) {
    if (!(add > 0)) {
      return '<p class="text-sm text-slate-500">เพิ่มจำนวนที่ “มีบนรถตอนนี้” แล้วจะมีช่องให้ใส่วันหมดอายุ</p>';
    }
    if (item.item_type !== 'individual') {
      return `<label for="onbexp-${item.id}">วันหมดอายุของที่เพิ่ม (${add}) *</label>
        <input id="onbexp-${item.id}" type="date" min="${UI.escape(this.todayIso())}" data-item-id="${item.id}" data-field="onboard_expiry">`;
    }
    if (!Number.isInteger(add)) return '<p class="txt-bad">ของแบบชิ้นต้องเป็นจำนวนเต็ม</p>';
    if (add > 50) return '<p class="txt-bad">เพิ่มได้ครั้งละไม่เกิน 50 ชิ้น</p>';
    return `<p class="add-dates-title">วันหมดอายุของแต่ละชิ้นที่เพิ่ม (${add} ชิ้น) *</p>
      <div class="add-dates-grid">${Array.from({ length: add }, (_, k) => `
        <label for="pexp-${item.id}-${k}">ชิ้นที่ ${k + 1}
          <input id="pexp-${item.id}-${k}" type="date" min="${UI.escape(this.todayIso())}" data-item-id="${item.id}" data-field="piece_expiry"></label>`).join('')}
      </div>
      ${add > 1 ? '<button type="button" class="small-btn gray" data-action="fill-dates">ใช้วันแรกกับช่องที่ว่าง</button>' : ''}`;
  }

  todayIso() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  /** จำนวนที่จะเพิ่มจากช่อง "มีบนรถตอนนี้" (0 ถ้าไม่เพิ่ม) */
  addCountFor(itemId) {
    const item = this.items.find(i => i.id === itemId) || {};
    const el = document.querySelector(`[data-item-id="${itemId}"][data-field="onboard_qty"]`);
    if (!el || el.value.trim() === '') return 0;
    const current = String(item.stock_updated_at || '').trim() !== '' ? Number(item.current_qty || 0) : 0;
    return Math.round((Number(el.value) - current) * 100) / 100;
  }

  refreshAddDates(itemId) {
    const item = this.items.find(i => i.id === itemId);
    const box = document.querySelector(`[data-role="add-dates"][data-item-id="${itemId}"]`);
    if (!item || !box) return;
    const keep = [...box.querySelectorAll('input[type="date"]')].map(i => i.value);   // ไม่ล้างวันที่กรอกไว้แล้ว
    box.innerHTML = this.renderAddDates(item, this.addCountFor(itemId));
    box.querySelectorAll('input[type="date"]').forEach((i, k) => { if (keep[k]) i.value = keep[k]; });
  }

  // ---------- ล็อต/รายชิ้น/เครื่องของอุปกรณ์ในการ์ด — แก้ไขและนำออกได้ที่นี่เลย ----------
  async toggleLots(btn) {
    const card = btn.closest('.stock-card');
    const box = card?.querySelector('[data-role="lots"]');
    if (!box) return;
    const open = box.classList.contains('hidden');
    box.classList.toggle('hidden', !open);
    btn.setAttribute('aria-expanded', String(open));
    btn.textContent = open ? '📦 ซ่อน' : (btn.dataset.label || '📦 ดูล็อต');
    if (open) await this.loadLotView(box, btn.dataset.itemId);
  }

  async loadLotView(box, itemId, reload = false) {
    box.innerHTML = '<p class="text-sm text-slate-500">กำลังโหลด…</p>';
    try {
      if (reload || !this.lotSheet || this.lotSheet.carId !== this.currentVehicleId) {
        const res = await window.staffApi.getRefillSheet(this.currentVehicleId);
        this.lotSheet = { carId: this.currentVehicleId, items: (res.data && res.data.items) || [] };
      }
      box.dataset.itemId = itemId;
      box.innerHTML = this.renderLotView(this.lotSheet.items.find(i => i.id === itemId));
    } catch (error) {
      box.innerHTML = `<p class="txt-bad">โหลดไม่สำเร็จ — ${UI.escape(error.message)}</p>`;
    }
  }

  renderLotView(item) {
    if (!item) return '<p class="text-sm text-slate-500">ไม่พบข้อมูล (อุปกรณ์อาจถูกพักใช้งาน)</p>';
    const fmt = (v) => String(Math.round(Number(v) * 100) / 100);
    const unit = item.unit ? ' ' + UI.escape(item.unit) : '';
    if (item.item_type === 'non_expiring') {
      const word = item.has_pressure ? 'ถัง' : 'เครื่อง';
      const label = { ready: '✅ พร้อมใช้', not_ready: '⛔ ไม่พร้อม', no_power: '🔋 เปิดไม่ติด', pending: '⏳ รอตรวจ' };
      const rows = (item.units || []).map((u, i) => `
        <li class="${u.status !== 'ready' ? 'lv-expired' : ''}" data-unit="${UI.escape(u.id)}">
          <div><b>${UI.escape(u.label || `${word}ที่ ${i + 1}`)}</b> — ${label[u.status] || UI.escape(u.status)}
            ${item.has_battery && u.battery_level !== null && u.battery_level !== undefined ? ` • แบต ${u.battery_level}/${item.battery_max_bars}` : ''}
            ${item.has_pressure && u.pressure_psi !== null && u.pressure_psi !== undefined ? ` • ${u.pressure_psi} PSI` : ''}
            ${u.note ? ` • ${UI.escape(u.note)}` : ''}</div>
          <div class="lot-actions">
            <button type="button" class="small-btn gray" data-unit-action="update">✏️ อัปเดตสถานะ${item.has_battery ? '/แบต' : ''}${item.has_pressure ? '/แรงดัน' : ''}</button>
            <button type="button" class="small-btn danger" data-unit-action="retire">🗑 นำ${word}นี้ออก</button>
          </div></li>`).join('');
      return (rows ? `<ul class="lot-view">${rows}</ul>` : `<p class="text-sm text-slate-500">ยังไม่มี${word}บนรถ</p>`)
        + `<button type="button" class="small-btn add-btn" data-unit-action="add">➕ เพิ่ม${word}</button>`;
    }
    const piece = item.item_type === 'individual';
    const state = { expired: '⛔ หมดอายุแล้ว', soon: '⚠️ ใกล้หมดอายุ', unknown: '❓ ไม่ทราบวันหมดอายุ', ok: '', na: '' };
    const rows = (item.lots || []).map((lot, i) => `
      <li class="lv-${UI.escape(lot.state)}" data-lot="${UI.escape(lot.id)}">
        <div><b>${piece ? 'ชิ้นที่' : 'ล็อต'} ${i + 1}${lot.lot_number ? ' · ' + UI.escape(lot.lot_number) : ''}</b>
          — ${lot.expiry_date ? 'หมดอายุ ' + UI.escape(UI.formatDateThai(lot.expiry_date)) : 'ไม่ทราบวันหมดอายุ'}
          ${piece ? '' : `• เหลือ <b>${fmt(lot.qty)}</b>${unit}`} ${state[lot.state] ? `<span class="lot-state">${state[lot.state]}</span>` : ''}</div>
        <div class="lot-actions">
          <button type="button" class="small-btn gray" data-lot-action="edit">✏️ แก้วันหมดอายุ</button>
          ${piece ? '' : '<button type="button" class="small-btn gray" data-lot-action="adjust">🔢 แก้จำนวน</button>'}
          ${lot.state === 'expired'
            ? '<button type="button" class="small-btn danger" data-lot-action="expire">นำออก (หมดอายุ)</button>'
            : `<button type="button" class="small-btn danger" data-lot-action="void">🗑 นำ${piece ? 'ชิ้นนี้' : 'ล็อตนี้'}ออก</button>`}
        </div></li>`).join('');
    return rows
      ? `<ul class="lot-view">${rows}</ul><p class="text-sm">รวม <b>${fmt(item.current_qty || 0)}</b>${unit} • ทุกการแก้บันทึกในประวัติ</p>`
      : '<p class="text-sm text-slate-500">ยังไม่มีของบนรถ — ตั้งจำนวนที่ช่อง “มีบนรถตอนนี้” แล้วกดบันทึก</p>';
  }

  /** หลังบันทึก: โหลดการ์ดใหม่ (ยอดรวมเปลี่ยน) แล้วเปิดรายการล็อต/เครื่องของอุปกรณ์เดิมค้างไว้ */
  async reopenLotView(itemId) {
    await this.loadItems(this.currentVehicleId);
    const card = document.querySelector(`.stock-card[data-item-id="${CSS.escape(itemId)}"]`);
    const btnView = card?.querySelector('[data-action="lots"]');
    const box = card?.querySelector('[data-role="lots"]');
    if (!btnView || !box) return;
    box.classList.remove('hidden');
    btnView.setAttribute('aria-expanded', 'true');
    btnView.textContent = '📦 ซ่อน';
    await this.loadLotView(box, itemId, true);
  }

  // ---------- ไม่มีวันหมดอายุ: เพิ่ม / อัปเดตสถานะ / นำเครื่องออก (บันทึกผ่าน API เดียวกับการเติมของ — กันบันทึกซ้ำด้วย token) ----------
  async onUnitAction(btn, box) {
    const item = (this.lotSheet?.items || []).find(i => i.id === box.dataset.itemId);
    if (!item) return;
    const word = item.has_pressure ? 'ถัง' : 'เครื่อง';
    const action = btn.dataset.unitAction;
    const unitId = btn.closest('[data-unit]')?.dataset.unit;
    const unit = unitId ? (item.units || []).find(u => u.id === unitId) : null;
    const name = unit ? (unit.label || word) : '';
    let line;
    if (action === 'retire') {
      if (!(await window.confirmDialog(`นำ${word} "${name}" ออกจากรถ ${item.name}?\n(เก็บไว้ในประวัติ ไม่ลบข้อมูล)`, { confirmText: 'นำออก', danger: true }))) return;
      line = { item_id: item.id, retire_unit_ids: [unit.id] };
    } else {
      const data = await this.unitForm(item, unit, word);
      if (!data) return;
      line = action === 'add'
        ? { item_id: item.id, units: [{ label: data.label, ...data.reading }] }
        : { item_id: item.id, unit_updates: [{ id: unit.id, ...data.reading }] };
    }
    try {
      const token = (window.crypto && crypto.randomUUID) ? crypto.randomUUID().replace(/-/g, '') : UI.uid() + UI.uid();
      await window.staffApi.refill(this.currentVehicleId, { client_token: token, note: 'จัดการเครื่องจากหน้ารถ', lines: [line] });
      window.toast.success({ add: `เพิ่ม${word}แล้ว`, update: 'อัปเดตสถานะแล้ว', retire: `นำ${word}ออกแล้ว` }[action]);
      await this.reopenLotView(item.id);
    } catch (error) {
      window.toast.error(error.message, 8000);
    }
  }

  unitForm(item, unit, word) {
    return new Promise((resolve) => {
      const statuses = [['ready', 'พร้อมใช้'], ['not_ready', 'ไม่พร้อมใช้']].concat(item.has_battery ? [['no_power', 'เปิดไม่ติด']] : []);
      const bat = unit && unit.battery_level !== null && unit.battery_level !== undefined ? Number(unit.battery_level) : item.battery_max_bars;
      const content = `<form class="lot-form">
        ${unit ? `<p class="mb-2 text-sm font-semibold">${UI.escape(item.name)} — ${UI.escape(unit.label || word)}</p>`
          : `<label>รหัส/เลข${word} <input name="label" maxlength="100" placeholder="เช่น ${word} 1"></label>`}
        <label>สถานะ <select name="status">${statuses.map(([v, l]) => `<option value="${v}" ${(unit ? unit.status : 'ready') === v ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
        ${item.has_battery ? `<label>แบตเตอรี่ (ขีด) <select name="battery_level">${Array.from({ length: item.battery_max_bars + 1 }, (_, k) => item.battery_max_bars - k)
          .map(l => `<option value="${l}" ${bat === l ? 'selected' : ''}>${l} จาก ${item.battery_max_bars}</option>`).join('')}</select></label>` : ''}
        ${item.has_pressure ? `<label>แรงดัน (PSI) <input name="pressure_psi" type="number" min="0" max="10000" step="1" inputmode="numeric" value="${unit && unit.pressure_psi !== null && unit.pressure_psi !== undefined ? UI.escape(String(unit.pressure_psi)) : ''}"></label>` : ''}
        <label>สาเหตุ/หมายเหตุ (จำเป็นเมื่อไม่พร้อมใช้) <input name="note" maxlength="200" value="${unit ? UI.escape(unit.note || '') : ''}"></label>
      </form>`;
      const modal = new Modal({
        title: unit ? `อัปเดต${word}` : `เพิ่ม${word}`,
        content,
        size: 'sm',
        onClose: () => resolve(null),
      }).render();
      const actions = modal.element.querySelector('.sheet-actions');
      actions.innerHTML = `<button type="button" class="small-btn gray flex-1" data-action="cancel">ยกเลิก</button>
        <button type="button" class="flex-1" data-action="ok">บันทึก</button>`;
      actions.querySelector('[data-action="cancel"]').onclick = () => { resolve(null); modal.close(); };
      actions.querySelector('[data-action="ok"]').onclick = () => {
        const f = Object.fromEntries(new FormData(modal.element.querySelector('form')).entries());
        if (f.status !== 'ready' && !String(f.note || '').trim()) {
          window.toast.warning('ไม่พร้อมใช้ ต้องระบุสาเหตุ');
          return;
        }
        if (item.has_pressure && (f.pressure_psi === '' || !(Number(f.pressure_psi) >= 0))) {
          window.toast.warning('กรอกแรงดัน (PSI)');
          return;
        }
        resolve({
          label: String(f.label || '').trim(),
          reading: {
            status: f.status,
            note: String(f.note || '').trim(),
            battery_level: item.has_battery && f.status !== 'no_power' ? Number(f.battery_level) : null,
            pressure_psi: item.has_pressure ? Number(f.pressure_psi) : null,
          },
        });
        modal.close();
      };
      modal.open();
    });
  }

  async onLotViewClick(event, box) {
    const unitBtn = event.target.closest('[data-unit-action]');
    if (unitBtn) {
      await this.onUnitAction(unitBtn, box);
      return;
    }
    const btn = event.target.closest('[data-lot-action]');
    if (!btn) return;
    const item = (this.lotSheet?.items || []).find(i => i.id === box.dataset.itemId);
    const lot = item && (item.lots || []).find(l => l.id === btn.closest('[data-lot]').dataset.lot);
    if (!lot) return;
    const action = btn.dataset.lotAction;
    const piece = item.item_type === 'individual';
    const label = `${item.name} — ${piece ? 'ชิ้น' : 'ล็อต'}${lot.lot_number ? ' ' + lot.lot_number : ''}${lot.expiry_date ? ' (หมดอายุ ' + UI.formatDateThai(lot.expiry_date) + ')' : ''}`;
    let payload;
    if (action === 'expire') {
      if (!(await window.confirmDialog(`นำของหมดอายุออกจากรถ?\n${label}\n(บันทึกในประวัติ ไม่ลบข้อมูล)`, { confirmText: 'นำออก' }))) return;
      payload = {};
    } else {
      payload = await this.lotForm(lot, action, label, piece);
      if (!payload) return;
    }
    try {
      if (action === 'edit') await window.staffApi.editLot(lot.id, payload);
      else await window.staffApi.lotAction(lot.id, action, payload);
      window.toast.success({ edit: 'แก้วันหมดอายุแล้ว', adjust: 'แก้จำนวนแล้ว', void: 'นำออกจากรถแล้ว', expire: 'นำของหมดอายุออกแล้ว' }[action]);
      await this.reopenLotView(item.id);   // ยอดรวม/วันหมดอายุเร็วสุดบนการ์ดเปลี่ยน
    } catch (error) {
      window.toast.error(error.message, 8000);
    }
  }

  lotForm(lot, action, label, piece) {
    return new Promise((resolve) => {
      const fields = {
        edit: `
          <label>วันหมดอายุ <input name="expiry_date" type="date" value="${UI.escape(lot.expiry_date || '')}"></label>
          ${piece ? '' : `<label>เลขล็อต <input name="lot_number" maxlength="100" value="${UI.escape(lot.lot_number || '')}"></label>`}
          <label>เหตุผล * <input name="reason" maxlength="200" placeholder="เช่น กรอกวันผิด"></label>`,
        adjust: `
          <p class="text-sm">ตอนนี้ ${UI.escape(String(lot.qty))} — กรอกจำนวนที่มีจริงของล็อตนี้</p>
          <label>จำนวนที่มีจริง <input name="qty" type="number" min="0" step="any" value="${UI.escape(String(lot.qty))}"></label>
          <label>เหตุผล * <input name="reason" maxlength="200"></label>`,
        void: `
          <p class="text-sm">นำ${piece ? 'ชิ้นนี้' : `ล็อตนี้ (${UI.escape(String(lot.qty))})`}ออกจากรถ — ประวัติยังอยู่ ไม่ลบข้อมูล</p>
          <label>เหตุผล * <input name="reason" maxlength="200" placeholder="เช่น ชำรุด, บันทึกผิด"></label>`,
      }[action];
      const modal = new Modal({
        title: { edit: 'แก้วันหมดอายุ', adjust: 'แก้จำนวนในล็อต', void: piece ? 'นำชิ้นนี้ออก' : 'นำล็อตนี้ออก' }[action],
        content: `<p class="mb-2 text-sm font-semibold">${UI.escape(label)}</p><form class="lot-form">${fields}</form>`,
        size: 'sm',
        onClose: () => resolve(null),
      }).render();
      const actions = modal.element.querySelector('.sheet-actions');
      actions.innerHTML = `<button type="button" class="small-btn gray flex-1" data-action="cancel">ยกเลิก</button>
        <button type="button" class="flex-1" data-action="ok">${action === 'void' ? 'ยืนยันนำออก' : 'บันทึก'}</button>`;
      actions.querySelector('[data-action="cancel"]').onclick = () => { resolve(null); modal.close(); };
      actions.querySelector('[data-action="ok"]').onclick = () => {
        const data = Object.fromEntries(new FormData(modal.element.querySelector('form')).entries());
        if (!String(data.reason || '').trim()) {
          window.toast.warning('กรุณาระบุเหตุผล');
          return;
        }
        resolve(data);
        modal.close();
      };
      modal.open();
    });
  }

  // เกณฑ์เตือนรายอุปกรณ์ (หน่วยงานกำหนด — ค่าว่าง = ไม่เตือน / ใช้ค่าระบบ)
  renderThresholdFields(item, type) {
    const val = (v) => (v === null || v === undefined ? '' : UI.escape(String(v)));
    const field = (key, label, value, placeholder, attrs) => `
          <div>
            <label>${label}</label>
            <input type="number" inputmode="numeric" step="1" ${attrs} data-item-id="${item.id}" data-field="${key}" value="${value}" placeholder="${placeholder}">
          </div>`;
    if (type === 'non_expiring') {
      return (item.is_battery
        ? field('battery_max_bars', 'แบตเต็มกี่ขีด', val(item.battery_max_bars ?? 4), '4', 'min="1" max="10"')
          + field('battery_low_bars', 'เตือนเมื่อแบต ≤ (ขีด)', val(item.battery_low_bars), 'ว่าง = ไม่เตือน', 'min="0" max="10"') : '')
        + (item.has_pressure ? field('o2_low_psi', 'เตือนเมื่อแรงดัน < (PSI)', val(item.o2_low_psi), 'ว่าง = ยังไม่กำหนดเกณฑ์', 'min="0" max="10000"') : '');
    }
    if (type === 'box' || type === 'individual') {
      return field('expiry_warn_days', 'เตือนใกล้หมดอายุ (วัน)', val(item.expiry_warn_days), 'ว่าง = 30 วัน', 'min="0" max="3650"');
    }
    return '';
  }

  fmtStamp(raw) {
    // stock_updated_at เก็บเป็น ISO (มี .u และ Z) — แสดงเฉพาะ วัน/เดือน/ชั่วโมง:นาที
    const m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(String(raw || ''));
    if (!m) return String(raw || '');
    return `${Number(m[3])}/${Number(m[2])}/${Number(m[1]) + 543} ${m[4]}:${m[5]}`;
  }

  showAddItemForm() {
    this.showAddForm = true;
    document.getElementById('addItemForm').classList.remove('hidden');
    document.getElementById('stockName').focus();
  }

  hideAddItemForm() {
    this.showAddForm = false;
    document.getElementById('addItemForm').classList.add('hidden');
    document.getElementById('stockName').value = '';
    document.getElementById('stockStandard').value = '';
    const typeEl = document.getElementById('stockItemType');
    if (typeEl) typeEl.value = 'individual';
    const minEl = null;
    if (minEl) minEl.value = '';
  }

  async handleAddItem(event) {
    event.preventDefault();

    const name = document.getElementById('stockName')?.value?.trim();
    const standard = document.getElementById('stockStandard')?.value?.trim();
    const itemType = document.getElementById('stockItemType')?.value || 'individual';
    const vehicleId = this.currentVehicleId;

    if (!name || !standard) {
      window.toast.error('กรอกชื่อและจำนวน');
      return;
    }

    if (!vehicleId) {
      window.toast.error('เลือกรถก่อน');
      return;
    }

    const payload = { name, standard, item_type: itemType };

    try {
      await window.vehicleApi.addItem(vehicleId, payload);
      this.hideAddItemForm();
      window.toast.success('เพิ่มอุปกรณ์เรียบร้อย — เพิ่มของ/วันหมดอายุได้ที่เมนู “เติมอุปกรณ์”');
      await this.loadItems(vehicleId);
    } catch (error) {
      window.toast.error('เพิ่มไม่สำเร็จ: ' + error.message);
    }
  }

  /**
   * รวมค่าจากการ์ด 1 ใบเป็น payload (ส่งเฉพาะฟิลด์ที่ผู้ใช้แก้จริง)
   * เพื่อบันทึกหลายช่องใน request เดียว — ลดโอกาสข้อมูลคนละ version กันบนหน้าจอ
   */
  collectItemPatch(itemId) {
    const item = this.items.find(i => i.id === itemId) || {};
    const field = (key) => document.querySelector(`[data-item-id="${itemId}"][data-field="${key}"]`);
    const data = {};

    const nameEl = field('name');
    if (nameEl) {
      const v = nameEl.value.trim();
      if (v === '') {
        window.toast.error('ชื่ออุปกรณ์ห้ามว่าง');
        return null;
      }
      if (v !== String(item.name || '')) data.name = v;
    }

    const standardEl = field('standard');
    if (standardEl) {
      const v = standardEl.value.trim();
      if (v === '') {
        window.toast.error('จำนวนมาตรฐานห้ามว่าง');
        return null;
      }
      if (v !== String(item.standard || '')) data.standard = v;
    }

    const typeEl = field('item_type');
    const currentType = item.item_type || (item.is_battery ? 'non_expiring' : 'individual');
    if (typeEl && typeEl.value !== currentType) data.item_type = typeEl.value;
    // ธงของประเภทไม่มีวันหมดอายุ: มีแบตเตอรี่ / วัดแรงดัน
    ['is_battery', 'has_pressure'].forEach((key) => {
      const el = field(key);
      if (el && el.checked !== !!item[key]) data[key] = el.checked;
    });

    // เกณฑ์เตือน: ส่งเฉพาะช่องที่แก้จริง ('' = ล้างเป็นค่าว่าง — server ตรวจช่วงค่าซ้ำ)
    ['battery_max_bars', 'battery_low_bars', 'o2_low_psi', 'expiry_warn_days'].forEach((key) => {
      const el = field(key);
      if (!el) return;
      const before = item[key] === null || item[key] === undefined ? '' : String(item[key]);
      if (el.value.trim() !== before) data[key] = el.value.trim();
    });

    // ยอดบนรถ: ส่งเมื่อเปลี่ยนจริง (server ปรับผ่านล็อต + บันทึกเป็นรายการปรับยอด)
    const onboardEl = field('onboard_qty');
    if (onboardEl && onboardEl.value.trim() !== '') {
      const n = Number(onboardEl.value);
      if (!Number.isFinite(n) || n < 0) {
        window.toast.error('ยอดบนรถต้องเป็นตัวเลขไม่ติดลบ');
        return null;
      }
      const counted = String(item.stock_updated_at || '').trim() !== '';
      const current = Math.round(Number(item.current_qty || 0) * 100) / 100;
      if (!counted || Math.round(n * 100) / 100 !== current) {
        data.onboard_qty = Math.round(n * 100) / 100;
        if (item.item_type === 'individual' && !Number.isInteger(data.onboard_qty)) {
          window.toast.error('ของแบบชิ้นต้องเป็นจำนวนเต็ม');
          return null;
        }
        if (data.onboard_qty > current) {
          const today = this.todayIso();
          if (item.item_type === 'individual') {
            // 1 ช่อง = 1 ชิ้น — ทุกชิ้นต้องมีวันหมดอายุ
            const inputs = [...document.querySelectorAll(`[data-item-id="${itemId}"][data-field="piece_expiry"]`)];
            const empty = inputs.find(i => !i.value);
            if (!inputs.length || empty) {
              window.toast.error('กรุณาใส่วันหมดอายุให้ครบทุกชิ้นที่เพิ่ม');
              (empty || field('onboard_qty'))?.focus();
              return null;
            }
            if (inputs.some(i => i.value < today)) {
              window.toast.error('มีชิ้นที่หมดอายุแล้ว — เพิ่มของหมดอายุไม่ได้');
              return null;
            }
            data.onboard_expiries = inputs.map(i => i.value);
          } else {
            const exp = field('onboard_expiry');
            if (!exp || !exp.value) {
              window.toast.error('กรุณาระบุวันหมดอายุของที่เพิ่ม');
              exp?.focus();
              return null;
            }
            if (exp.value < today) {
              window.toast.error('วันหมดอายุผ่านไปแล้ว — เพิ่มของหมดอายุไม่ได้');
              return null;
            }
            data.onboard_expiry = exp.value;
          }
        }
      }
    }

    return data;
  }

  async saveItem(itemId) {
    const data = this.collectItemPatch(itemId);
    if (data === null) return;
    if (Object.keys(data).length === 0) {
      window.toast.info('ไม่มีรายการแก้ไข');
      return;
    }

    if (data.onboard_qty !== undefined) {
      const item = this.items.find(i => i.id === itemId) || {};
      const before = Math.round(Number(item.current_qty || 0) * 100) / 100;
      const ok = await window.confirmDialog(
        `ตั้งยอดบนรถ ${item.name}: ${before} → ${data.onboard_qty}?\n` +
        (data.onboard_qty > before
          ? (data.onboard_expiries
            ? `เพิ่ม ${data.onboard_expiries.length} ชิ้น หมดอายุ: ${data.onboard_expiries.map(d => UI.formatDateThai(d)).join(', ')}`
            : `ของที่เพิ่มหมดอายุ ${UI.formatDateThai(data.onboard_expiry)}`)
          : 'ตัดยอดจากล็อตที่หมดอายุก่อน') + '\n(บันทึกในประวัติเป็นการปรับยอด)',
        { confirmText: 'บันทึกยอด' }
      );
      if (!ok) return;
    }

    const btn = document.querySelector(`[data-action="save"][data-item-id="${itemId}"]`);
    if (btn) UI.setLoading(btn, true);
    try {
      const res = await window.vehicleApi.updateItem(itemId, data);
      let message = 'บันทึกสำเร็จ';
      if (data.standard) message += ' • ปรับขั้นต่ำแล้ว';
      if (res?.data?.onboard) message += ` • ยอดบนรถ ${res.data.onboard.after}`;
      window.toast.success(message);
      await this.loadItems(this.currentVehicleId);
    } catch (error) {
      window.toast.error('อัปเดตไม่สำเร็จ: ' + error.message);
      await this.loadItems(this.currentVehicleId);
    } finally {
      if (btn) UI.setLoading(btn, false);
    }
  }

  async toggleItemActive(itemId) {
    const item = this.items.find(i => i.id === itemId);
    if (!item) return;

    try {
      await window.vehicleApi.updateItem(itemId, { active: !item.active });
      window.toast.success(item.active ? 'พักใช้งานแล้ว' : 'เปิดใช้งานแล้ว');
      await this.loadItems(this.currentVehicleId);
    } catch (error) {
      window.toast.error('อัปเดตไม่สำเร็จ: ' + error.message);
      await this.loadItems(this.currentVehicleId);
    }
  }

  async deleteItem(itemId) {
    const item = this.items.find(i => i.id === itemId);
    if (!item) return;

    const ok = await window.confirmDialog(
      `ต้องการลบ “${item.name}” ออกจากรถคันนี้? ถ้าอุปกรณ์นี้เคยถูกใช้ตรวจหรือมีงานเติมค้างอยู่ ระบบจะพักใช้งานแทน เพื่อไม่ให้ประวัติย้อนหลังขาด`,
      { title: 'ลบอุปกรณ์', confirmText: 'ลบ' }
    );
    if (!ok) return;

    try {
      const res = await window.vehicleApi.deleteItem(itemId, { mode: 'auto' });
      const mode = res?.data?.mode;
      if (mode === 'deleted') {
        window.toast.success('ลบอุปกรณ์แล้ว');
      } else {
        window.toast.warning('อุปกรณ์นี้มีประวัติ/งานค้างผูกอยู่ — พักใช้งานแทน (ข้อมูลตรวจย้อนหลังยังครบ)');
      }
      await this.loadItems(this.currentVehicleId);
    } catch (error) {
      window.toast.error('ลบไม่สำเร็จ: ' + error.message);
    }
  }

  // ---------- Copy items from another vehicle ----------

  async handleCopyStart() {
    const sourceId = document.getElementById('copySourceCar')?.value || '';
    if (!sourceId) return;
    if (String(sourceId) === String(this.currentVehicleId)) {
      window.toast.error('รถต้นทางต้องไม่ใช่คันเดียวกับรถที่กำลังแก้ไข');
      return;
    }

    const source = this.vehicles.find(v => String(v.id) === String(sourceId));
    const target = this.vehicles.find(v => String(v.id) === String(this.currentVehicleId));
    const sourceLabel = vehiclePlate(source);
    const targetLabel = vehiclePlate(target);

    let sourceItems;
    try {
      // แอดมินดึงผ่านทะเบียนรถ (ได้แถวที่ "พักใช้งาน" ด้วย) เพื่อคัดลอกสถานะ active มาครบ
      const isAdmin = window.auth && typeof window.auth.isAdmin === 'function' && window.auth.isAdmin();
      const res = isAdmin ? await window.adminApi.getVehicle(sourceId) : await window.vehicleApi.get(sourceId);
      sourceItems = res.data?.items || [];
    } catch (error) {
      window.toast.error('ดึงข้อมูลรถต้นทางไม่สำเร็จ: ' + error.message);
      return;
    }

    if (sourceItems.length === 0) {
      await window.alertDialog(`รถคัน ${sourceLabel} ยังไม่มีอุปกรณ์ในทะเบียนให้คัดลอก`);
      return;
    }

    const stockCount = sourceItems.filter(item => String(item.stock_updated_at || '').trim() !== '').length;

    const confirmed = await this.confirmCopyDialog(
      sourceLabel, targetLabel, sourceItems.length, this.copyStaging.length > 0, stockCount
    );
    if (!confirmed) return;

    this.copyStaging = sourceItems.map(item => {
      const counted = String(item.stock_updated_at || '').trim() !== '';
      return {
        name: item.name || '',
        standard: item.standard || '',
        expiry_text: item.expiry_text || '',
        is_battery: !!item.is_battery,
        item_type: item.item_type || '',
        has_pressure: !!item.has_pressure,
        active: item.active !== false && item.active !== 0,
        // ยอดคงเหลือติดมาเฉพาะแถวที่ต้นทาง "เคยตรวจนับจริง" — แถวที่ไม่เคยนับต้องว่างไว้
        // เพราะ 0 กับ "ยังไม่เคยตรวจนับ" ความหมายคนละอย่างบนหน้ารอเติม
        current_qty: counted ? String(item.current_qty ?? '') : '',
        counted,
        stock_stamp: counted ? this.fmtStamp(item.stock_updated_at) : '',
      };
    });
    this.copySourceLabel = sourceLabel;
    this.renderCopyPreview();
    window.toast.info(
      `ดึงรายการจาก ${sourceLabel} จำนวน ${sourceItems.length} รายการ `
      + '— คัดลอกเฉพาะรายการ/มาตรฐาน/ประเภท (ไม่คัดลอกยอดคงเหลือ เพราะของจริงไม่ได้ย้ายรถ)'
    );
  }

  confirmCopyDialog(sourceLabel, targetLabel, count, replacing = false, stockCount = 0) {
    return new Promise((resolve) => {
      const modal = new Modal({
        title: 'ยืนยันการคัดลอกอุปกรณ์',
        size: 'sm',
        content: `
          <p class="mb-2">คุณต้องการคัดลอกรายการอุปกรณ์จากรถคัน <strong>${UI.escape(sourceLabel)}</strong> ใช่หรือไม่?</p>
          <p class="mb-2">ระบบจะนำอุปกรณ์ทั้งหมด <strong>${count} รายการ</strong> มาแสดงในรถคัน <strong>${UI.escape(targetLabel)}</strong> เพื่อให้ตรวจสอบ/แก้ไขก่อนบันทึก — ยังไม่มีการบันทึกจนกว่าจะกด “ยืนยันคัดลอก”</p>
          <p class="mb-2 text-sm">สิ่งที่คัดลอกมาด้วย: ชื่อ, จำนวนมาตรฐาน, ประเภทอุปกรณ์ และสถานะใช้งาน/พัก — <strong>ไม่คัดลอกยอดคงเหลือ/Lot</strong> (รถปลายทางเริ่มเป็น "ยังไม่เคยตรวจนับ" จนกว่าจะเติมของหรือตรวจนับ)</p>
          ${replacing ? '<p class="mb-2 text-sm" style="color: var(--warn);">⚠️ มีรายการรอคัดลอกอยู่ก่อนแล้ว — รายการชุดใหม่นี้จะแทนที่ชุดเดิม</p>' : ''}
        `,
        onClose: () => resolve(false),
      }).render();

      const actions = modal.element.querySelector('.sheet-actions');
      actions.innerHTML = `
        <button type="button" class="small-btn gray flex-1" data-action="cancel">ยกเลิก</button>
        <button type="button" class="flex-1" data-action="confirm">ยืนยันการคัดลอก</button>
      `;

      actions.querySelector('[data-action="cancel"]').onclick = () => {
        resolve(false);
        modal.close();
      };
      actions.querySelector('[data-action="confirm"]').onclick = () => {
        resolve(true);
        modal.close();
      };

      modal.open();
    });
  }

  renderCopyPreview() {
    const panel = document.getElementById('copyPreview');
    const list = document.getElementById('copyPreviewList');
    const countChip = document.getElementById('copyPreviewCount');
    const fromEl = document.getElementById('copyPreviewFrom');
    const confirmBtn = document.getElementById('btnCopyConfirm');
    if (!panel || !list) return;

    if (this.copyStaging.length === 0) {
      panel.classList.add('hidden');
      list.innerHTML = '';
      return;
    }

    const existing = new Set(this.items.map(i => String(i.name || '').trim().toLowerCase()));
    list.innerHTML = this.copyStaging.map((row, idx) => {
      const dup = existing.has(row.name.trim().toLowerCase());
      return `
        <div class="copy-row" data-idx="${idx}">
          <div class="copy-row-top">
            <span class="copy-row-no">${idx + 1}</span>
            <input type="text" data-copy-field="name" value="${UI.escape(row.name)}" placeholder="ชื่ออุปกรณ์" aria-label="ชื่ออุปกรณ์รายการที่ ${idx + 1}">
            <span class="chip chip-warn ${dup ? '' : 'hidden'}" data-copy-dup>⚠️ มีอยู่แล้วในรถนี้</span>
            <button type="button" class="small-btn gray copy-row-remove" data-copy-action="remove" title="เอาออกจากรายการรอคัดลอก">✕ ลบ</button>
          </div>
          <div class="copy-row-fields">
            <div>
              <label>จำนวนมาตรฐาน</label>
              <input type="text" data-copy-field="standard" value="${UI.escape(row.standard)}">
            </div>
            <div>
              <label>วันหมดอายุ</label>
              ${/^\d{4}-\d{2}-\d{2}$/.test(String(row.expiry_text || ''))
                ? `<input type="date" data-copy-field="expiry_text" value="${UI.escape(row.expiry_text)}">`
                : `<input type="text" data-copy-field="expiry_text" value="${UI.escape(row.expiry_text)}" placeholder="ข้อความวันหมดอายุ เช่น 10 / 15/12/2569">`}
            </div>
            <div>
              <label>ยอดคงเหลือบนรถ</label>
              <input type="number" min="0" step="any" inputmode="decimal" data-copy-field="current_qty"
                value="${UI.escape(String(row.current_qty ?? ''))}"
                placeholder="${row.counted ? 'เว้นว่าง = ไม่คัดลอกยอด' : 'ยังไม่เคยตรวจนับ'}"
                aria-label="ยอดคงเหลือบนรถของรายการที่ ${idx + 1}">
              <p class="copy-row-hint">${row.counted
                ? `คัดลอกจากต้นทาง • ยอดล่าสุด ${UI.escape(row.stock_stamp || '')}`
                : 'ต้นทางยังไม่เคยตรวจนับ — กรอกตัวเลขถ้าได้ตรวจนับของจริงบนคันนี้แล้ว'}</p>
            </div>
            <div>
              <label>สถานะ</label>
              <select data-copy-field="active">
                <option value="true" ${row.active !== false ? 'selected' : ''}>ใช้งาน</option>
                <option value="false" ${row.active === false ? 'selected' : ''}>พักใช้งาน</option>
              </select>
            </div>
            <div>
              <label>เป็นแบตเตอรี่</label>
              <select data-copy-field="is_battery">
                <option value="false" ${!row.is_battery ? 'selected' : ''}>ไม่ใช่</option>
                <option value="true" ${row.is_battery ? 'selected' : ''}>ใช่</option>
              </select>
            </div>
          </div>
        </div>`;
    }).join('');

    panel.classList.remove('hidden');
    if (fromEl) fromEl.textContent = `จากรถคัน ${this.copySourceLabel}`;
    if (countChip) countChip.textContent = `รอคัดลอก ${this.copyStaging.length} รายการ`;
    if (confirmBtn) confirmBtn.textContent = `ยืนยันคัดลอก ${this.copyStaging.length} รายการ`;
    this.renderCopyStockSummary();

    list.querySelectorAll('[data-copy-field]').forEach(input => {
      input.addEventListener('input', () => this.onCopyFieldChange(input));
    });
    list.querySelectorAll('[data-copy-action="remove"]').forEach(btn => {
      btn.addEventListener('click', () => {
        this.removeCopyRow(Number(btn.closest('.copy-row')?.dataset.idx));
      });
    });
  }

  renderCopyStockSummary() {
    const el = document.getElementById('copyPreviewStock');
    if (!el) return;
    const withStock = this.copyStaging.filter(row => String(row.current_qty ?? '').trim() !== '').length;
    const resting = this.copyStaging.filter(row => row.active === false).length;
    el.textContent = `มียอดคงเหลือบนรถติดมา ${withStock}/${this.copyStaging.length} รายการ`
      + (resting > 0 ? ` • พักใช้งาน ${resting} รายการ` : '');
    el.classList.toggle('hidden', this.copyStaging.length === 0);
  }

  onCopyFieldChange(input) {
    const rowEl = input.closest('.copy-row');
    const idx = Number(rowEl?.dataset.idx);
    const field = input.dataset.copyField;
    if (!rowEl || Number.isNaN(idx) || !this.copyStaging[idx]) return;

    if (field === 'is_battery' || field === 'active') {
      this.copyStaging[idx][field] = input.value === 'true';
    } else if (field === 'current_qty') {
      // พิมพ์ตัวเลขเอง = ถือเป็นยอดที่นับจริงบนคันนี้ แม้ต้นทางจะยังไม่เคยตรวจนับ
      this.copyStaging[idx].current_qty = input.value.trim();
      if (this.copyStaging[idx].current_qty !== '') this.copyStaging[idx].counted = true;
    } else {
      this.copyStaging[idx][field] = input.value;
    }

    if (field === 'current_qty') this.renderCopyStockSummary();

    if (field === 'name') {
      const dupEl = rowEl.querySelector('[data-copy-dup]');
      if (dupEl) {
        const existing = new Set(this.items.map(i => String(i.name || '').trim().toLowerCase()));
        dupEl.classList.toggle('hidden', !existing.has(String(input.value).trim().toLowerCase()));
      }
    }
  }

  removeCopyRow(idx) {
    if (Number.isNaN(idx) || !this.copyStaging[idx]) return;
    this.copyStaging.splice(idx, 1);
    this.renderCopyPreview();
    window.toast.info(`เหลือรายการรอคัดลอก ${this.copyStaging.length} รายการ`);
  }

  async handleCopyConfirm() {
    if (this.copyStaging.length === 0) return;

    for (let i = 0; i < this.copyStaging.length; i++) {
      const row = this.copyStaging[i];
      if (!String(row.name).trim() || !String(row.standard).trim()) {
        window.toast.error(`รายการที่ ${i + 1}: กรอกชื่ออุปกรณ์และจำนวนมาตรฐานให้ครบก่อนยืนยัน`);
        return;
      }
      const qtyText = String(row.current_qty ?? '').trim();
      if (qtyText !== '' && (!Number.isFinite(Number(qtyText)) || Number(qtyText) < 0)) {
        window.toast.error(`รายการที่ ${i + 1}: ยอดคงเหลือบนรถต้องเป็นตัวเลข 0 ขึ้นไป`);
        return;
      }
    }

    const btn = document.getElementById('btnCopyConfirm');
    UI.setLoading(btn, true);
    try {
      const res = await window.vehicleApi.copyItems(this.currentVehicleId, {
        items: this.copyStaging.map(row => ({
          name: String(row.name).trim(),
          standard: String(row.standard).trim(),
          expiry_text: String(row.expiry_text || '').trim(),
          is_battery: !!row.is_battery,
          item_type: row.item_type || '',
          has_pressure: !!row.has_pressure,
          active: row.active !== false,
          // null = ไม่มีข้อมูลตรวจนับ → ฝั่งเซิร์ฟเวอร์จะปล่อย "ยังไม่เคยตรวจนับ" ไว้
          current_qty: String(row.current_qty ?? '').trim() === '' ? null : String(row.current_qty).trim(),
        })),
      });
      const created = res.data?.created ?? this.copyStaging.length;
      const inactiveCopied = Number(res.data?.inactive_copied ?? 0);
      this.clearCopyStaging();
      window.toast.success(
        `คัดลอกรายชื่ออุปกรณ์แล้ว ${created} รายการ (ไม่รวมของบนรถ)`
        + (inactiveCopied > 0 ? ` • พักใช้งาน ${inactiveCopied} รายการ` : '')
      );
      await this.loadItems(this.currentVehicleId);
    } catch (error) {
      window.toast.error('คัดลอกไม่สำเร็จ: ' + (error.message || 'ไม่ทราบสาเหตุ'));
    } finally {
      UI.setLoading(btn, false);
    }
  }

  async handleCopyCancel() {
    if (this.copyStaging.length > 0) {
      const ok = await window.confirmDialog(
        'คุณต้องการยกเลิกรายการคัดลอกทั้งหมดหรือไม่? รายการที่ดึงมายังไม่ถูกบันทึก',
        { title: 'ยกเลิกรายการคัดลอก', confirmText: 'ยกเลิกรายการ' }
      );
      if (!ok) return;
    }
    this.clearCopyStaging();
  }

  clearCopyStaging() {
    this.copyStaging = [];
    this.copySourceLabel = '';
    this.renderCopyPreview();
  }

  // ==================== Vehicle CRUD (add / edit / delete) ====================

  // ฟอร์มเดียวใช้สองโหมด: 'create' กับ 'edit'
  // โหมด edit อ่านค่าเริ่มต้นจาก this.currentVehicle (รถที่เลือกอยู่)
  vehicleFormFields(mode) {
    const v = mode === 'edit' ? (this.currentVehicle || {}) : {};
    const isEdit = mode === 'edit';

    const statusOptions = Object.keys(VEHICLE_STATUS_LABEL).map(value =>
      `<option value="${value}" ${v.status === value ? 'selected' : ''}>${VEHICLE_STATUS_LABEL[value]}</option>`
    ).join('');

    const seedSelect = `
      <div class="md:col-span-2">
        <label for="vehItemSource">รายการอุปกรณ์เริ่มต้นของรถคันใหม่</label>
        <select id="vehItemSource">
          <option value="none" selected>รถว่าง — เพิ่มอุปกรณ์เองทีหลัง</option>
          <option value="default">ใช้รายชื่อชุดอุปกรณ์มาตรฐาน</option>
          <option value="copy">คัดลอกรายชื่อจากรถคันอื่น</option>
        </select>
        <div id="vehCopyWrap" class="hidden">
          <label for="vehCopySource" class="!mt-2">เลือกรถต้นทาง</label>
          <select id="vehCopySource">
            <option value="">— เลือก —</option>
            ${this.vehicles.map(item => `<option value="${UI.escape(item.id)}">${UI.escape(vehiclePlate(item))}</option>`).join('')}
          </select>
        </div>
        <p id="vehSeedNote" class="mt-1 text-xs leading-relaxed text-slate-500">กำลังเตรียมข้อมูล…</p>
      </div>
    `;

    return `
      <div class="grid gap-4 md:grid-cols-2">
        <div class="md:col-span-2">
          <label for="vehPlate" class="!mt-0">ทะเบียนรถ <span class="text-red-500" aria-hidden="true">*</span></label>
          <input id="vehPlate" type="text" value="${UI.escape(v.plate || '')}" placeholder="เช่น กข-1234 กรุงเทพฯ" autocomplete="off">
          <p class="mt-1 hidden text-xs font-semibold text-red-600" id="vehPlateError" role="alert"></p>
        </div>
        <div>
          <label for="vehBrand">ยี่ห้อ / รุ่น</label>
          <input id="vehBrand" type="text" value="${UI.escape(v.brand_model || '')}" placeholder="เช่น Toyota Commuter">
        </div>
        <div>
          <label for="vehType">ชื่อเรียกประจำรถ</label>
          <input id="vehType" type="text" value="${UI.escape(v.name || '')}" placeholder="เช่น รถ Refer คันที่ 4">
        </div>
        <div>
          <label for="vehStatus">สถานะรถ</label>
          <select id="vehStatus">${statusOptions}</select>
        </div>
        <div>
          <label for="vehOdometer">เลขไมล์เริ่มต้น (กม.)</label>
          <input id="vehOdometer" type="number" min="0" step="1" inputmode="numeric"
            value="${v.odometer_initial === null || v.odometer_initial === undefined ? '' : UI.escape(String(v.odometer_initial))}"
            placeholder="เช่น 45000">
          <p class="mt-1 hidden text-xs font-semibold text-red-600" id="vehOdometerError" role="alert"></p>
        </div>
        <div class="md:col-span-2">
          <label for="vehColor">สีประจำรถ</label>
          <div class="color-row">
            <input id="vehColor" type="color" value="${UI.escape(/^#[0-9A-Fa-f]{6}$/.test(v.vehicle_color || '') ? v.vehicle_color : window.VEHICLE_DEFAULT_COLOR)}" aria-describedby="vehColorPreview">
            <input id="vehColorHex" type="text" maxlength="7" value="${UI.escape(v.vehicle_color || '')}" placeholder="${window.VEHICLE_DEFAULT_COLOR} (เริ่มต้น)" aria-label="รหัสสี #RRGGBB" autocomplete="off" spellcheck="false">
            <button type="button" id="vehColorReset" class="small-btn gray">ใช้สีเริ่มต้น</button>
          </div>
          <p class="mt-1 text-xs text-slate-500">ตัวอย่าง: <span id="vehColorPreview"></span> — ใช้แสดงรถคันนี้ในหน้าแรก ตรวจเช็ค และเติมอุปกรณ์</p>
          <p class="mt-1 hidden text-xs font-semibold text-red-600" id="vehColorHexError" role="alert"></p>
        </div>
        <div class="md:col-span-2">
          <label for="vehNotes">หมายเหตุเพิ่มเติม</label>
          <textarea id="vehNotes" rows="2" placeholder="รายละเอียดเพิ่มเติม เช่น ประจำจุดใด ใช้งานช่วงไหน">${UI.escape(v.notes || '')}</textarea>
        </div>
        ${isEdit ? '' : seedSelect}
      </div>
    `;
  }

  openVehicleForm(mode) {
    if (mode === 'edit' && !this.currentVehicleId) {
      window.toast.warning('กรุณาเลือกรถก่อน');
      return;
    }

    if (this._vehicleModal) {
      try { this._vehicleModal.destroy(); } catch (e) { /* node ถูกลบไปแล้ว */ }
      this._vehicleModal = null;
    }

    const isEdit = mode === 'edit';
    const heading = isEdit
      ? `แก้ไขข้อมูลรถ — ${vehiclePlate(this.currentVehicle || {})}`
      : 'เพิ่มรถใหม่';

    const modal = new Modal({
      title: heading,
      size: 'lg',
      content: `
        <form id="vehicleForm" novalidate>
          ${this.vehicleFormFields(mode)}
        </form>
      `,
      onClose: () => { this._vehicleModal = null; },
    }).render();

    this._vehicleModal = modal;
    modal._vehicleMode = mode;

    const actions = modal.element.querySelector('.sheet-actions');
    actions.innerHTML = `
      <button type="button" class="small-btn gray flex-1" data-action="cancel">ยกเลิก / ล้างข้อมูล</button>
      <button type="button" class="flex-1" data-action="save">${isEdit ? 'บันทึกการแก้ไข' : 'บันทึกข้อมูลรถใหม่'}</button>
    `;
    actions.querySelector('[data-action="cancel"]').onclick = () => this.cancelVehicleForm();
    actions.querySelector('[data-action="save"]').onclick = () => this.handleSaveVehicle(mode);

    const form = modal.element.querySelector('#vehicleForm');
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.handleSaveVehicle(mode);
    });
    form.querySelector('#vehPlate')?.addEventListener('input', () => this.setVehicleFieldError('vehPlate', ''));
    form.querySelector('#vehOdometer')?.addEventListener('input', () => this.setVehicleFieldError('vehOdometer', ''));
    this.bindColorFields(form);

    if (!isEdit) {
      const source = form.querySelector('#vehItemSource');
      const wrap = form.querySelector('#vehCopyWrap');
      const copySel = form.querySelector('#vehCopySource');
      const note = form.querySelector('#vehSeedNote');

      const updateSeedNote = async () => {
        if (!note) return;
        const mode2 = source ? source.value : 'none';
        if (mode2 === 'none') {
          note.textContent = 'รถคันใหม่ยังไม่มีอุปกรณ์ — เพิ่มรายการที่หน้ารถ แล้วจัดของขึ้นรถที่ “เติมของ”';
          return;
        }
        if (mode2 !== 'copy') {
          note.textContent = 'คัดลอกเฉพาะรายชื่ออุปกรณ์จากชุดมาตรฐาน — ไม่มีของบนรถ ต้องจัดของขึ้นรถที่ “เติมของ”';
          return;
        }
        const id = copySel ? copySel.value : '';
        if (!id) {
          note.textContent = 'เลือกรถต้นทางเพื่อดูจำนวนรายการที่จะคัดลอก';
          return;
        }
        note.textContent = 'กำลังโหลดข้อมูลรถต้นทาง…';
        try {
          const isAdmin = window.auth && typeof window.auth.isAdmin === 'function' && window.auth.isAdmin();
          const res = isAdmin ? await window.adminApi.getVehicle(id) : await window.vehicleApi.get(id);
          const items = res.data?.items || [];
          const resting = items.filter(i => i.active === false || i.active === 0).length;
          note.textContent = `จะคัดลอก ${items.length} รายการ`
            + (resting > 0 ? ` • สถานะ พักใช้งาน ${resting} รายการ` : '')
            + ' — คัดลอกเฉพาะรายชื่อ ไม่คัดลอกของบนรถ';
        } catch (error) {
          note.textContent = 'โหลดข้อมูลรถต้นทางไม่สำเร็จ — ระบบจะคัดลอกตามข้อมูลจริงเมื่อเซิร์ฟเวอร์ทำรายการสำเร็จ';
        }
      };

      source?.addEventListener('change', () => {
        wrap?.classList.toggle('hidden', source.value !== 'copy');
        updateSeedNote();
      });
      copySel?.addEventListener('change', updateSeedNote);
      updateSeedNote();
    }

    modal.open();
    form.querySelector('#vehPlate')?.focus();
  }

  showAddVehicleForm() {
    this.openVehicleForm('create');
  }

  showEditVehicleForm() {
    this.openVehicleForm('edit');
  }

  getVehicleFormEl() {
    return this._vehicleModal?.element.querySelector('#vehicleForm') || null;
  }

  // ช่องเลือกสี (color picker) + ช่องรหัส #RRGGBB + ตัวอย่างป้ายทะเบียนก่อนบันทึก
  bindColorFields(form) {
    const picker = form.querySelector('#vehColor');
    const hex = form.querySelector('#vehColorHex');
    const preview = form.querySelector('#vehColorPreview');
    const plateInput = form.querySelector('#vehPlate');
    const paint = () => {
      const value = (hex.value || '').trim().toUpperCase();
      const color = /^#[0-9A-F]{6}$/.test(value) ? value : window.VEHICLE_DEFAULT_COLOR;
      const label = UI.escape(plateInput?.value?.trim() || 'ทะเบียนรถ');
      preview.innerHTML = `<span class="vehicle-chip" style="background:${color};color:${window.vehicleTextColor(color)}">🚑 ${label}</span>`;
    };
    picker?.addEventListener('input', () => {
      hex.value = picker.value.toUpperCase();
      this.setVehicleFieldError('vehColorHex', '');
      paint();
    });
    hex?.addEventListener('input', () => {
      const value = hex.value.trim();
      if (/^#[0-9A-Fa-f]{6}$/.test(value)) picker.value = value.toLowerCase();
      this.setVehicleFieldError('vehColorHex', '');
      paint();
    });
    form.querySelector('#vehColorReset')?.addEventListener('click', () => {
      hex.value = '';
      picker.value = window.VEHICLE_DEFAULT_COLOR.toLowerCase();
      paint();
    });
    plateInput?.addEventListener('input', paint);
    paint();
  }

  setVehicleFieldError(inputId, message) {
    const form = this.getVehicleFormEl();
    const input = form?.querySelector('#' + inputId);
    const errorEl = form?.querySelector('#' + inputId + 'Error');
    if (input) {
      if (message) input.setAttribute('aria-invalid', 'true');
      else input.removeAttribute('aria-invalid');
    }
    if (errorEl) {
      errorEl.textContent = message || '';
      errorEl.classList.toggle('hidden', !message);
    }
  }

  validateVehicleForm(mode) {
    const form = this.getVehicleFormEl();
    if (!form) return null;
    const value = (id) => (form.querySelector('#' + id)?.value || '').trim();

    const plate = value('vehPlate');
    const odometerRaw = value('vehOdometer');
    let firstInvalid = null;

    if (!plate) {
      this.setVehicleFieldError('vehPlate', 'กรุณากรอกทะเบียนรถ');
      firstInvalid = 'vehPlate';
    } else {
      this.setVehicleFieldError('vehPlate', '');
    }

    if (odometerRaw !== '') {
      const n = Number(odometerRaw);
      if (!Number.isInteger(n) || n < 0) {
        this.setVehicleFieldError('vehOdometer', 'เลขไมล์ต้องเป็นจำนวนเต็มไม่ติดลบ');
        if (!firstInvalid) firstInvalid = 'vehOdometer';
      } else {
        this.setVehicleFieldError('vehOdometer', '');
      }
    } else {
      this.setVehicleFieldError('vehOdometer', '');
    }

    const itemSource = mode === 'edit' ? '' : (form.querySelector('#vehItemSource')?.value || 'none');
    const copySource = mode === 'edit' ? '' : (form.querySelector('#vehCopySource')?.value || '');
    if (itemSource === 'copy' && copySource === '') {
      window.toast.error('กรุณาเลือกรถต้นทางที่จะคัดลอกรายการอุปกรณ์');
      form.querySelector('#vehCopySource')?.focus();
      return null;
    }

    if (firstInvalid) {
      form.querySelector('#' + firstInvalid)?.focus();
      window.toast.error('กรุณาตรวจสอบข้อมูลในฟอร์มอีกครั้ง');
      return null;
    }

    const payload = {
      plate,
      name: value('vehType'),
      brand_model: value('vehBrand'),
      status: form.querySelector('#vehStatus')?.value || 'available',
      notes: value('vehNotes'),
      vehicle_color: (value('vehColorHex') || '').toUpperCase(),
    };
    if (payload.vehicle_color && !/^#[0-9A-F]{6}$/.test(payload.vehicle_color)) {
      this.setVehicleFieldError('vehColorHex', 'สีต้องอยู่ในรูปแบบ #RRGGBB เช่น #2563EB');
      return null;
    }

    // โหมด edit: ส่งเฉพาะฟิลด์ที่เปลี่ยนจริง (เซิร์ฟเวอร์ตรวจว่า "ไม่มีข้อมูลที่ต้องแก้ไข")
    if (mode === 'edit') {
      const v = this.currentVehicle || {};
      const patch = {};
      if (plate !== (v.plate || '')) patch.plate = plate;
      if (payload.name !== (v.name || '')) patch.name = payload.name;
      if (payload.brand_model !== (v.brand_model || '')) patch.brand_model = payload.brand_model;
      if (payload.status !== (v.status || '')) patch.status = payload.status;
      if (payload.notes !== (v.notes || '')) patch.notes = payload.notes;
      if (payload.vehicle_color !== String(v.vehicle_color || '').toUpperCase()) patch.vehicle_color = payload.vehicle_color;
      if (odometerRaw !== '' && Number(odometerRaw) !== Number(v.odometer_initial ?? -1)) {
        patch.odometer_initial = Number(odometerRaw);
      }
      if (Object.keys(patch).length === 0) {
        window.toast.warning('ยังไม่มีข้อมูลที่ต้องแก้ไข');
        return null;
      }
      return patch;
    }

    payload.item_source = itemSource;
    if (itemSource === 'copy') payload.copy_source_vehicle_id = copySource;
    if (odometerRaw !== '') payload.odometer_initial = Number(odometerRaw);
    return payload;
  }

  async handleSaveVehicle(mode) {
    const payload = this.validateVehicleForm(mode);
    if (!payload) return;

    const saveBtn = this._vehicleModal?.element.querySelector('[data-action="save"]');
    if (saveBtn) UI.setLoading(saveBtn, true);

    try {
      if (mode === 'edit') {
        const id = this.currentVehicleId;
        await window.vehicleApi.update(id, payload);
        this._vehicleModal?.destroy();
        // renderVehicleSelect() เรียก onVehicleChange ให้เอง และรักษา คันเดิมที่เลือกไว้
        await this.loadVehicles();
        this.currentVehicleId = id;
        this.renderVehicleSelect();
        window.toast.success('บันทึกการแก้ไขรถแล้ว');
        return;
      }

      const res = await window.vehicleApi.create(payload);
      const created = res.data || {};
      const items = Number(created.items_created || 0);
      const inactive = Number(created.items_inactive || 0);
      const sourceLabel = created.item_source_label || '';
      window.toast.success(
        `เพิ่มรถใหม่แล้ว: ${created.name || created.plate || payload.plate}`
        + (items > 0
          ? ` — สร้างรายการอุปกรณ์ ${items} รายการ (${sourceLabel})`

            + (inactive > 0 ? ` • พักใช้งาน ${inactive}` : '')
          : ' — ยังไม่มีรายการอุปกรณ์เริ่มต้น')
      );
      this._vehicleModal?.destroy();
      await this.loadVehicles();
      this.renderVehicleSelect();
      if (created.id) {
        const select = document.getElementById('stockCar');
        if (select) {
          select.value = created.id;
          await this.onVehicleChange();
        }
      }
    } catch (error) {
      const errs = error.data?.errors || {};
      const first = (key) => Array.isArray(errs[key]) ? errs[key][0] : errs[key];
      if (errs.plate) this.setVehicleFieldError('vehPlate', first('plate'));
      if (errs.odometer_initial) this.setVehicleFieldError('vehOdometer', first('odometer_initial'));
      if (error.status === 409) this.setVehicleFieldError('vehPlate', error.message);
      window.toast.error((mode === 'edit' ? 'แก้ไขรถไม่สำเร็จ: ' : 'เพิ่มรถไม่สำเร็จ: ')
        + (error.message || 'ไม่ทราบสาเหตุ'));
    } finally {
      if (saveBtn) UI.setLoading(saveBtn, false);
    }
  }

  async cancelVehicleForm() {
    const form = this.getVehicleFormEl();
    const hasInput = ['vehPlate', 'vehBrand', 'vehType', 'vehOdometer', 'vehNotes']
      .some(id => (form?.querySelector('#' + id)?.value || '').trim() !== '');
    if (hasInput) {
      const ok = await window.confirmDialog('ต้องการยกเลิกและล้างข้อมูลที่กรอกไว้ทั้งหมดหรือไม่?', {
        title: 'ยกเลิก / ล้างข้อมูล',
        confirmText: 'ล้างข้อมูล',
      });
      if (!ok) return;
    }
    this._vehicleModal?.destroy();
  }

  // ---------- Delete vehicle (safety check: ประวัติตรวจ / งานเติมค้าง) ----------

  async handleDeleteVehicle() {
    const vehicle = this.currentVehicle || this.vehicles.find(v => v.id === this.currentVehicleId);
    if (!vehicle) {
      window.toast.warning('กรุณาเลือกรถก่อน');
      return;
    }

    const label = vehiclePlate(vehicle);
    const checks = Number(vehicle.check_count || 0);
    const drafts = Number(vehicle.draft_count || 0);
    const openRestock = Number(vehicle.open_restock_count || 0);
    const restockTotal = Number(vehicle.restock_count || 0);
    const items = Number(vehicle.active_item_count ?? vehicle.item_count ?? 0);
    const hasHistory = (checks + drafts + restockTotal) > 0 || openRestock > 0;

    // เซิร์ฟเวอร์เป็นผู้ตัดสินจริง (FK RESTRICT) — ด้านหน้าแค่เตือนไว้ก่อน
    if (hasHistory) {
      const details = [
        checks > 0 ? `ประวัติการตรวจ ${checks} ครั้ง` : '',
        drafts > 0 ? `ร่างงานตรวจ ${drafts} รายการ` : '',
        openRestock > 0 ? `งานเติมที่ค้างอยู่ ${openRestock} งาน` : '',
        restockTotal > openRestock ? `งานเติมทั้งหมด ${restockTotal} งาน` : '',
        items > 0 ? `อุปกรณ์ประจำรถ ${items} รายการ` : '',
      ].filter(Boolean).join(' • ');

      const disableFirst = await window.confirmDialog(
        `รถ “${label}” มีข้อมูลผูกอยู่ (${details})\n\n`
        + 'การลบถาวรจะกระทบประวัติย้อนหลังของระบบ ระบบจึงแนะนำให้ "งดใช้งาน" แทน '
        + '(รถจะหายจากเมนูเลือกสำหรับหน้าตรวจ แต่ข้อมูลเดิมยังอยู่ครบ)\n\n'
        + 'กด "งดใช้งานแทน" เพื่อตั้งสถานะเป็น งดใช้งาน',
        { title: 'รถมีประวัติผูกอยู่', confirmText: 'งดใช้งานแทน' }
      );
      if (disableFirst) {
        await this.removeVehicle(label, 'disable');
        return;
      }
      const force = await window.confirmDialog(
        `ยังยืนยันจะลบ “${label}” ถาวรหรือไม่? ระบบจะปฏิเสธถ้ามีประวัติผูกอยู่จริง (409)`,
        { title: 'ลบรถถาวร', confirmText: 'ลองลบถาวร' }
      );
      if (!force) return;
      await this.removeVehicle(label, 'auto');
      return;
    }

    const ok = await window.confirmDialog(
      `ต้องการลบรถ “${label}” ถาวรหรือไม่? อุปกรณ์ประจำรถ ${items} รายการจะถูกลบไปด้วย และย้อนกลับไม่ได้`,
      { title: 'ลบรถ', confirmText: 'ลบถาวร' }
    );
    if (!ok) return;
    await this.removeVehicle(label, 'auto');
  }

  async removeVehicle(label, mode) {
    const id = this.currentVehicleId;
    if (!id) {
      window.toast.warning('กรุณาเลือกรถก่อน');
      return;
    }

    try {
      const res = await window.vehicleApi.remove(id, { mode });
      const result = res.data || {};
      if (result.mode === 'deleted') {
        window.toast.success(`ลบรถ “${result.name || label}” แล้ว`);
      } else {
        window.toast.success(`ตั้งสถานะ “${result.name || label}” เป็น งดใช้งานแล้ว (ประวัติเดิมยังอยู่ครบ)`);
      }
      // ล้างการเลือกคันที่ถูกลบออกก่อน แล้วให้ renderVehicleSelect() โหลดข้อมูลใหม่
      this.currentVehicle = null;
      this.currentVehicleId = null;
      await this.loadVehicles();
      this.renderVehicleSelect();
      if (!this.vehicles.length) {
        this.items = [];
        this.renderItems();
        this.renderVehicleMeta();
      }
    } catch (error) {
      // 409 = เซิร์ฟเวอร์ตรวจพบข้อมูลผูก → เสนอ "งดใช้งาน" เป็นทางเลือกปลอดภัย
      if (error.status === 409 && mode !== 'disable') {
        const b = error.data?.blockers || {};
        const summary = [
          b.checks > 0 ? `ประวัติตรวจ ${b.checks}` : '',
          b.drafts > 0 ? `ร่างงาน ${b.drafts}` : '',
          b.restock_open > 0 ? `งานเติมค้าง ${b.restock_open}` : '',
          b.restock_total > 0 ? `งานเติมทั้งหมด ${b.restock_total}` : '',
        ].filter(Boolean).join(' • ');
        const fallback = await window.confirmDialog(
          `${error.message}\n\n(${summary || 'มีข้อมูลผูกอยู่'})\n\nเปลี่ยนเป็น "งดใช้งาน" แทนหรือไม่?`,
          { title: 'ลบไม่ได้เพราะมีประวัติ', confirmText: 'งดใช้งานแทน' }
        );
        if (fallback) await this.removeVehicle(label, 'disable');
        return;
      }
      window.toast.error('ลบรถไม่สำเร็จ: ' + (error.message || 'ไม่ทราบสาเหตุ'));
    }
  }
}

// Initialize
let vehiclesPage;
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('tabContentStock') && !document.getElementById('tabContentStock').classList.contains('hidden')) {
    vehiclesPage = new VehiclesPage();
    window.vehiclesPage = vehiclesPage;
  }
});

// Export for tab switching
window.initVehicles = () => {
  if (!vehiclesPage) {
    vehiclesPage = new VehiclesPage();
    window.vehiclesPage = vehiclesPage;
  } else {
    vehiclesPage.loadVehicles().then(() => vehiclesPage.renderVehicleSelect());
  }
};

// Global functions
window.showAddItemForm = () => vehiclesPage?.showAddItemForm();
window.hideAddItemForm = () => vehiclesPage?.hideAddItemForm();
window.showAddVehicleForm = () => vehiclesPage?.showAddVehicleForm();