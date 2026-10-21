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
        : '<span class="stock-stamp">ยังไม่เคยตรวจนับ</span>';

      // ยอดคงเหลือ + วันหมดอายุมาจาก Lot / รายเครื่อง (แก้ผ่านการเติมของ/การตรวจเช็คเท่านั้น)
      const type = item.item_type || (item.is_battery ? 'electric' : 'piece');
      const expiry = String(item.expiry_text ?? '');
      const qtyText = counted
        ? `${UI.escape(String(Math.round(Number(item.current_qty || 0) * 100) / 100))}${type === 'electric' ? ' เครื่อง' : ''}`
        : 'ยังไม่เคยตรวจนับ';

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
            <label>จำนวนมาตรฐาน</label>
            <input type="text" value="${UI.escape(item.standard)}" data-item-id="${item.id}" data-field="standard" placeholder="จำนวนมาตรฐาน">
          </div>
          <div>
            <label>ประเภท</label>
            <select data-item-id="${item.id}" data-field="item_type" style="min-height: 44px;">
              <option value="piece" ${type === 'piece' ? 'selected' : ''}>แบบชิ้น</option>
              <option value="box" ${type === 'box' ? 'selected' : ''}>แบบกล่อง</option>
              <option value="electric" ${type === 'electric' ? 'selected' : ''}>เครื่องใช้ไฟฟ้า</option>
            </select>
          </div>
          <div>
            <label>คงเหลือบนรถ</label>
            <div class="readonly-field">${qtyText}</div>
          </div>
          <div>
            <label>หมดอายุเร็วสุด</label>
            <div class="readonly-field">${type === 'electric' ? '—' : (expiry ? UI.escape(UI.formatDateThai(expiry)) : 'ไม่มีข้อมูล')}</div>
          </div>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; margin-top: 6px;">${stamp}</div>
        <div style="display: flex; gap: 8px; margin-top: 8px;">
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

    container.querySelectorAll('[data-action="delete"]').forEach(btn => {
      btn.addEventListener('click', () => this.deleteItem(btn.dataset.itemId));
    });
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
    if (typeEl) typeEl.value = 'piece';
    const minEl = null;
    if (minEl) minEl.value = '';
  }

  async handleAddItem(event) {
    event.preventDefault();

    const name = document.getElementById('stockName')?.value?.trim();
    const standard = document.getElementById('stockStandard')?.value?.trim();
    const itemType = document.getElementById('stockItemType')?.value || 'piece';
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
    const currentType = item.item_type || (item.is_battery ? 'electric' : 'piece');
    if (typeEl && typeEl.value !== currentType) data.item_type = typeEl.value;

    return data;
  }

  async saveItem(itemId) {
    const data = this.collectItemPatch(itemId);
    if (data === null) return;
    if (Object.keys(data).length === 0) {
      window.toast.info('ไม่มีรายการแก้ไข');
      return;
    }

    const btn = document.querySelector(`[data-action="save"][data-item-id="${itemId}"]`);
    if (btn) UI.setLoading(btn, true);
    try {
      await window.vehicleApi.updateItem(itemId, data);
      let message = 'อัปเดตเรียบร้อย';
      if (data.standard) message += ' • คำนวณจำนวนเป้าหมายใหม่จากมาตรฐานแล้ว';
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
          active: row.active !== false,
          // null = ไม่มีข้อมูลตรวจนับ → ฝั่งเซิร์ฟเวอร์จะปล่อย "ยังไม่เคยตรวจนับ" ไว้
          current_qty: String(row.current_qty ?? '').trim() === '' ? null : String(row.current_qty).trim(),
        })),
      });
      const created = res.data?.created ?? this.copyStaging.length;
      const stockCopied = Number(res.data?.stock_copied ?? 0);
      const inactiveCopied = Number(res.data?.inactive_copied ?? 0);
      this.clearCopyStaging();
      window.toast.success(
        `คัดลอกอุปกรณ์แล้ว ${created} รายการ • พร้อมยอดคงเหลือบนรถ ${stockCopied} รายการ`
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
          <option value="default" selected>ชุดอุปกรณ์มาตรฐาน (แนะนำ)</option>
          <option value="copy">คัดลอกจาก รถคันอื่นที่มีอยู่แล้ว</option>
          <option value="none">ไม่สร้างรายการเริ่มต้น</option>
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
        const mode2 = source ? source.value : 'default';
        if (mode2 === 'none') {
          note.textContent = 'สร้างรถโดยไม่มีรายการอุปกรณ์ — ต้องเพิ่มเองทีหลัง';
          return;
        }
        if (mode2 !== 'copy') {
          note.textContent = 'ใช้ชุดอุปกรณ์มาตรฐานกลาง จึงยังไม่มี "ยอดคงเหลือบนรถ" ของคันใด — ต้องตรวจนับจริงบนคันใหม่'
            + ' (เลือก "คัดลอกจาก รถคันอื่นที่มีอยู่แล้ว" ถ้าต้องการยกยอดมาด้วย)';
          return;
        }
        const id = copySel ? copySel.value : '';
        if (!id) {
          note.textContent = 'เลือกรถต้นทางเพื่อพรีวิวจำนวนรายการและยอดคงเหลือที่จะคัดลอก';
          return;
        }
        note.textContent = 'กำลังโหลดข้อมูลรถต้นทาง…';
        try {
          const isAdmin = window.auth && typeof window.auth.isAdmin === 'function' && window.auth.isAdmin();
          const res = isAdmin ? await window.adminApi.getVehicle(id) : await window.vehicleApi.get(id);
          const items = res.data?.items || [];
          const withStock = items.filter(i => String(i.stock_updated_at || '').trim() !== '').length;
          const resting = items.filter(i => i.active === false || i.active === 0).length;
          note.textContent = `จะคัดลอก ${items.length} รายการ`
            + ` • มียอดคงเหลือบนรถติดมา ${withStock} รายการ`
            + (resting > 0 ? ` • สถานะ พักใช้งาน ${resting} รายการ` : '')
            + ' — ตัวเลขที่ยกมาเป็นการคัดลอก ไม่ใช่การนับของจริงบนคันใหม่ ควรตรวจนับอีกครั้ง';
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

    const itemSource = mode === 'edit' ? '' : (form.querySelector('#vehItemSource')?.value || 'default');
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
      const stockCopied = Number(created.stock_copied || 0);
      const inactive = Number(created.items_inactive || 0);
      const sourceLabel = created.item_source_label || '';
      window.toast.success(
        `เพิ่มรถใหม่แล้ว: ${created.name || created.plate || payload.plate}`
        + (items > 0
          ? ` — สร้างรายการอุปกรณ์ ${items} รายการ (${sourceLabel})`
            + ` • คัดลอกยอดคงเหลือบนรถ ${stockCopied} รายการ`
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