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

// [ค่าที่ส่ง server, ป้ายในหน้าจอ, คำที่รับได้ในไฟล์นำเข้า]
const VEHICLE_ITEM_TYPES = [
  ['individual', '🧩 รายชิ้น', ['รายชิ้น', 'ชิ้น', 'individual']],
  ['box', '📦 กล่อง / ล็อต', ['กล่อง', 'ล็อต', 'กล่อง/ล็อต', 'box']],
  ['non_expiring', '🩺 ไม่มีวันหมดอายุ', ['ไม่มีวันหมดอายุ', 'non_expiring']],
];

// คอลัมน์ไฟล์นำเข้าอุปกรณ์ (ไฟล์ต้นแบบใช้หัวคอลัมน์ตามลำดับนี้ — ตอนอ่านจับคู่จากชื่อหัว ไม่สนลำดับ)
const ITEM_IMPORT_COLUMNS = [
  { key: 'name', header: 'ชื่ออุปกรณ์', required: true },
  { key: 'standard', header: 'จำนวนมาตรฐาน', required: true },
  { key: 'item_type', header: 'ประเภท (รายชิ้น/กล่อง/ไม่มีวันหมดอายุ)' },
  { key: 'is_battery', header: 'มีแบตเตอรี่ (ใช่/ไม่ใช่)' },
  { key: 'has_pressure', header: 'วัดแรงดัน (ใช่/ไม่ใช่)' },
  { key: 'active', header: 'สถานะ (ใช้งาน/พักใช้งาน)' },
  { key: 'stock_qty', header: 'จำนวนที่จะจัดขึ้นรถ' },
  { key: 'stock_expiry', header: 'วันหมดอายุ (วว/ดด/ปปปป)' },
];

const STAGING_KEY = 'emer.vehicleItemStaging';

/**
 * วันที่จากไฟล์ → YYYY-MM-DD (คืน '' ถ้าว่าง, null ถ้าอ่านไม่ได้)
 * รับ: Date (เซลล์วันที่ใน Excel), YYYY-MM-DD, วว/ดด/ปปปป หรือ วว-ดด-ปปปป ทั้ง พ.ศ. (>2400) และ ค.ศ.
 */
function parseImportDate(value) {
  const pad = (n) => String(n).padStart(2, '0');
  if (value instanceof Date) {
    return isNaN(value) ? null : `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
  }
  const text = String(value ?? '').trim();
  if (text === '') return '';
  let y, m, d;
  let match = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(text);
  if (match) [, y, m, d] = match.map(Number);
  else if ((match = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/.exec(text))) [, d, m, y] = match.map(Number);
  else return null;
  if (y > 2400) y -= 543;   // พ.ศ. → ค.ศ.
  const date = new Date(y, m - 1, d);
  return date.getFullYear() === y && date.getMonth() === m - 1 && date.getDate() === d ? `${y}-${pad(m)}-${pad(d)}` : null;
}

/**
 * แปลงตาราง (แถวแรก = หัวคอลัมน์) เป็นรายการอุปกรณ์
 * ข้ามแถวว่างและแถวที่ชื่อขึ้นต้นด้วย "ตัวอย่าง" (แถวตัวอย่างในไฟล์ต้นแบบ)
 */
function parseItemImportTable(table) {
  const norm = (v) => String(v ?? '').replace(/\s+/g, '').toLowerCase();
  const header = (table[0] || []).map(h => norm(h).replace(/\(.*\)/, '').replace(/\*/g, ''));
  const colOf = {};
  ITEM_IMPORT_COLUMNS.forEach(col => {
    const want = norm(col.header).replace(/\(.*\)/, '');
    const i = header.findIndex(h => h === want);
    if (i >= 0) colOf[col.key] = i;
  });
  const missing = ITEM_IMPORT_COLUMNS.filter(c => c.required && colOf[c.key] === undefined);
  if (missing.length) {
    return { rows: [], skipped: 0, errors: [`ไม่พบคอลัมน์: ${missing.map(c => c.header).join(', ')} — แถวแรกต้องเป็นหัวคอลัมน์ตามไฟล์ต้นแบบ`] };
  }

  const yes = ['ใช่', 'มี', 'yes', 'y', 'true', '1', '✓'];
  const no = ['ไม่ใช่', 'ไม่', 'ไม่มี', 'no', 'n', 'false', '0', ''];
  const rows = [];
  const errors = [];
  let skipped = 0;
  table.slice(1).forEach((cells, i) => {
    const line = i + 2;   // เลขแถวตามที่เห็นใน Excel
    const raw = (key) => (colOf[key] === undefined ? '' : cells[colOf[key]]);
    const get = (key) => {
      const v = raw(key);
      return v instanceof Date ? parseImportDate(v) : String(v ?? '').trim();
    };
    if (cells.every(c => String(c ?? '').trim() === '') || /^ตัวอย่าง/.test(get('name'))) {
      skipped++;
      return;
    }
    const bool = (key, label) => {
      const v = norm(get(key));
      if (yes.includes(v)) return true;
      if (no.includes(v)) return false;
      errors.push(`แถว ${line}: ${label} ต้องเป็น "ใช่" หรือ "ไม่ใช่" (ได้ "${get(key)}")`);
      return false;
    };

    const name = get('name');
    const standard = get('standard');
    if (!name) errors.push(`แถว ${line}: ไม่มีชื่ออุปกรณ์`);
    if (!standard) errors.push(`แถว ${line}: ไม่มีจำนวนมาตรฐาน${name ? ` (${name})` : ''}`);

    const typeText = norm(get('item_type'));
    const type = typeText === '' ? 'individual'
      : (VEHICLE_ITEM_TYPES.find(([, , words]) => words.some(w => norm(w) === typeText)) || [])[0];
    if (!type) errors.push(`แถว ${line}: ประเภท "${get('item_type')}" ไม่รู้จัก — ใช้ รายชิ้น / กล่อง / ไม่มีวันหมดอายุ`);

    const status = norm(get('active'));
    if (!['', 'ใช้งาน', 'พักใช้งาน'].includes(status)) {
      errors.push(`แถว ${line}: สถานะต้องเป็น "ใช้งาน" หรือ "พักใช้งาน" (ได้ "${get('active')}")`);
    }

    const stockQty = get('stock_qty');
    const stockExpiry = parseImportDate(raw('stock_expiry'));
    if (stockQty !== '' && !(Number(stockQty) > 0)) {
      errors.push(`แถว ${line}: จำนวนที่จะจัดขึ้นรถต้องเป็นตัวเลขมากกว่า 0 (ได้ "${stockQty}")`);
    }
    if (stockExpiry === null) {
      errors.push(`แถว ${line}: วันหมดอายุ "${get('stock_expiry')}" อ่านไม่ได้ — ใช้ วว/ดด/ปปปป เช่น 31/12/2569`);
    }
    if (stockQty !== '' && type === 'non_expiring') {
      errors.push(`แถว ${line}: ประเภทไม่มีวันหมดอายุ จัดเครื่องขึ้นรถที่แท็บ "เติมของ" (เว้นช่องจำนวนว่างไว้)`);
    }

    rows.push({
      name,
      standard,
      item_type: type || 'individual',
      is_battery: bool('is_battery', 'มีแบตเตอรี่'),
      has_pressure: bool('has_pressure', 'วัดแรงดัน'),
      active: status !== 'พักใช้งาน',
      stock_qty: stockQty,
      stock_expiry: stockExpiry || '',   // ขาดได้ — กรอก/เลือกในรายการก่อนบันทึก
    });
  });
  return { rows, errors, skipped };
}

class VehiclesPage {
  constructor() {
    this.vehicles = [];
    this.currentVehicleId = null;
    this.currentVehicle = null;
    this.items = [];
    this.openItems = new Set();   // การ์ดอุปกรณ์ที่ขยายอยู่ (ค่าเริ่มต้น = ย่อทุกใบ)
    this.editingItemId = null;
    this.copyStaging = [];
    this.copySourceLabel = '';
    this.copyFilter = 'all';   // ตัวกรองรายการรอเพิ่มลงรถ: all | dup | empty
    this._vehicleModal = null;
    this._itemModal = null;
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

    // เมนู ⋯ (ปิดใช้งาน / ลบรถ): ปิดเองเมื่อเลือกคำสั่งหรือแตะนอกเมนู
    const more = document.getElementById('vehicleMore');
    more?.querySelector('.vehicle-more-menu')?.addEventListener('click', () => { more.open = false; });
    document.addEventListener('click', (e) => {
      if (more?.open && !more.contains(e.target)) more.open = false;
    });

    // Copy items from another vehicle
    document.getElementById('btnCopyStart')?.addEventListener('click', () => this.handleCopyStart());
    document.getElementById('btnCopyConfirm')?.addEventListener('click', () => this.handleCopyConfirm());
    document.getElementById('btnCopyCancel')?.addEventListener('click', () => this.handleCopyCancel());
    document.querySelectorAll('#copyPreview [data-copy-filter]').forEach(btn => {
      btn.addEventListener('click', () => this.setCopyFilter(btn.dataset.copyFilter));
    });

    // Import items from a CSV / Excel file (เข้าคิวรายการเดียวกับการคัดลอก แล้วยืนยันด้วยปุ่มเดิม)
    const importInput = document.getElementById('itemImportFile');
    document.getElementById('btnItemImport')?.addEventListener('click', () => importInput?.click());
    importInput?.addEventListener('change', () => {
      const file = importInput.files?.[0];
      importInput.value = '';   // เลือกไฟล์เดิมซ้ำได้หลังแก้ไฟล์
      if (file) this.handleItemImport(file);
    });
    document.getElementById('btnItemTemplate')?.addEventListener('click', () => this.downloadItemTemplate());
  }

  renderVehicleSelect() {
    const select = document.getElementById('stockCar');
    if (!select) return;

    // จำคันที่เลือกไว้ก่อน rebuild — ไม่อย่างนั้นหลังแก้/เพิ่มรถ หน้าจะเด้งกลับไปคันแรก
    const previous = this.currentVehicleId || select.value || '';

    select.innerHTML = this.vehicles.map(v => {
      const status = VEHICLE_STATUS_LABEL[v.status] ? ` • ${VEHICLE_STATUS_LABEL[v.status]}` : '';
      const kind = v.vehicle_type === 'emr' ? ' • EMR' : ' • Refer';
      return `<option value="${UI.escape(v.id)}">${UI.escape(vehiclePlate(v))}${UI.escape(kind + status)}</option>`;
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
    if (hadStaging) {
      this.clearCopyStaging();   // ผู้ใช้สลับรถเอง → ทิ้งรายการที่ค้าง (รวมที่เก็บในเบราว์เซอร์)
      window.toast.info('สลับรถแล้ว — ล้างรายการรอเพิ่มลงรถที่ค้างอยู่');
    }
    // ไม่มีรายการค้างในหน้า: อย่าแตะที่เก็บในเบราว์เซอร์ — loadItems() จะกู้คืนให้ถ้าเป็นรถคันเดิม (เช่น หลังเข้าสู่ระบบใหม่)
    this.renderCopySourceSelect();
    this.renderVehicleMeta();
    await this.loadItems(vehicleId);
  }

  /** แถบสรุปข้อมูลรถที่เลือก — บอกสถานะ + สิ่งที่ผูกอยู่ (ใช้ตอนจะลบรถ) */
  renderVehicleMeta() {
    const el = document.getElementById('stockCarMeta');
    const actions = document.getElementById('vehicleActions');
    const count = document.getElementById('stockItemCount');
    this.updateToggleStatusButton();
    if (!el) return;
    const v = this.currentVehicle;
    if (actions) actions.hidden = !v;
    if (count) count.textContent = v?.active_item_count !== undefined ? `${v.active_item_count} รายการ` : '';
    if (!v) {
      el.innerHTML = 'ยังไม่มีรถในระบบ — กด “+ เพิ่มรถ” เพื่อเริ่ม';
      return;
    }

    const status = VEHICLE_STATUS_LABEL[v.status] || v.status || '-';
    const chip = VEHICLE_STATUS_CHIP[v.status] || 'chip-off';
    window.vehicleRef.remember([v]);

    // ตัวเลขสรุปเป็นช่องเรียงกัน สีกลาง ๆ — "รอเติม" เป็นสีส้มเฉพาะตอนมีงานค้าง
    const stat = (value, label, attn = false) =>
      `<div class="vcard-stat${attn ? ' is-attn' : ''}"><b>${UI.escape(String(value))}</b><span>${label}</span></div>`;
    const stats = [];
    if (v.active_item_count !== undefined) stats.push(stat(v.active_item_count, 'อุปกรณ์'));
    if (v.check_count !== undefined) stats.push(stat(v.check_count, 'ครั้งที่ตรวจ'));
    if (v.open_restock_count !== undefined) {
      stats.push(stat(v.open_restock_count, 'รอเติม', Number(v.open_restock_count) > 0));
    }

    // ประเภทรถ: EMR บอกวอร์ดประจำด้วย (ชื่อวอร์ดโหลดครั้งแรกแล้ววาดใหม่)
    if (v.vehicle_type === 'emr' && v.home_department_id && !this.departments) {
      this.loadDepartments().then(() => this.renderVehicleMeta());
    }
    const ward = v.vehicle_type === 'emr' ? this.departmentName(v.home_department_id) : '';
    const kindChip = v.vehicle_type === 'emr'
      ? `<span class="chip veh-kind-chip is-emr">🏥 EMR${ward ? ` · ${UI.escape(ward)}` : ''}</span>`
      : '<span class="chip veh-kind-chip">🚑 Refer</span>';

    el.innerHTML = `
      <div class="vcard-top">${window.vehicleChip(v, 'vcard-plate')}<span class="chip ${chip}">${UI.escape(status)}</span>${kindChip}</div>
      ${stats.length ? `<div class="vcard-stats">${stats.join('')}</div>` : ''}`;
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
    // อยู่ในเมนู ⋯ แล้ว จึงไม่ต้องทาสีแดง (สีแดงเหลือไว้ให้ "ลบรถ" อย่างเดียว)
    btn.textContent = v.status === 'disabled' ? '✅ เปิดใช้งานรถ' : '🚫 ปิดใช้งานรถ';
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
      this.restoreStagingDraft(vehicleId);
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

      // ยอดคงเหลือ + วันหมดอายุมาจาก Lot / รายเครื่อง (แก้ผ่านการเติมของ/การตรวจเช็คเท่านั้น)
      const type = item.item_type || (item.is_battery ? 'non_expiring' : 'individual');
      const unitWord = item.has_pressure ? ' ถัง' : ' เครื่อง';
      const viewLabel = type === 'non_expiring' ? (item.has_pressure ? '🔧 จัดการถัง' : '🔧 จัดการเครื่อง') : (type === 'individual' ? '📦 ดูรายชิ้น' : '📦 ดูล็อต');
      const expiry = String(item.expiry_text ?? '');
      const qtyNum = String(Math.round(Number(item.current_qty || 0) * 100) / 100);
      const qtyText = counted ? `${UI.escape(qtyNum)}${type === 'non_expiring' ? unitWord : ''}` : 'ยังไม่ได้จัดขึ้นรถ';
      const target = item.target_qty === null || item.target_qty === undefined || item.target_qty === '' ? null : item.target_qty;
      const minText = target !== null
        ? `ขั้นต่ำ ${UI.escape(String(Math.round(Number(target) * 100) / 100))}${type === 'non_expiring' ? unitWord : (item.unit ? ' ' + UI.escape(item.unit) : '')}`
        : (String(item.standard || '').trim() ? `ขั้นต่ำ ${UI.escape(item.standard)}` : '');

      // โครงการ์ดตอนขยาย: 1) ของบนรถตอนนี้ (ใช้บ่อยสุด อยู่บน) 2) ตั้งค่าอุปกรณ์ 3) บันทึก + เมนู ⋯ (พัก/ลบ)
      return `
      <article class="stock-card${item.active ? '' : ' is-off'}" data-item-id="${item.id}">
        <details class="stock-collapse" data-item-id="${item.id}" ${this.openItems.has(item.id) ? 'open' : ''}>
        ${this.renderItemSummary(item, type, counted, expiry, unitWord)}
        <!-- ประเภทอยู่บนสุด: กำหนดว่ากล่องของบนรถด้านล่างนับแบบไหน (รายชิ้น / ล็อต / รายเครื่อง) -->
        <div class="stock-type">
          <label for="typ-${item.id}">ประเภท</label>
          <select id="typ-${item.id}" data-item-id="${item.id}" data-field="item_type">
            <option value="individual" ${type === 'individual' ? 'selected' : ''}>🧩 รายชิ้น (วันหมดอายุรายชิ้น)</option>
            <option value="box" ${type === 'box' ? 'selected' : ''}>📦 กล่อง/ล็อต (วันหมดอายุทั้งกล่อง)</option>
            <option value="non_expiring" ${type === 'non_expiring' ? 'selected' : ''}>🩺 ไม่มีวันหมดอายุ (รายเครื่อง)</option>
          </select>
        </div>
        <section class="stock-onboard" aria-label="ของบนรถตอนนี้">
          <div class="stock-onboard-head">
            <label ${type === 'non_expiring' ? '' : `for="onb-${item.id}"`}>ของบนรถตอนนี้</label>
            ${minText ? `<small>${minText}</small>` : ''}
          </div>
          ${type === 'non_expiring'
            ? `<div class="stock-onboard-qty">${qtyText}</div>`
            : `${UI.stepper(`id="onb-${item.id}" min="0" data-item-id="${item.id}" data-field="onboard_qty" value="${counted ? UI.escape(qtyNum) : ''}" placeholder="${counted ? '' : 'ยังไม่ได้จัด'}"`, 'ยอดบนรถ')}
          <div class="add-dates" data-role="add-dates" data-item-id="${item.id}">${this.renderAddDates(item, 0)}</div>`}
          <div class="stock-facts">
            <div><span>หมดอายุเร็วสุด</span><b>${type === 'non_expiring' ? 'ไม่มีวันหมดอายุ' : (expiry ? UI.escape(UI.formatDateThai(expiry)) : '—')}</b></div>
            <div><span>นับล่าสุด</span><b>${counted ? UI.escape(this.fmtStamp(counted)) : 'ยังไม่เคย'}</b></div>
          </div>
          <button type="button" class="stock-link-btn" data-action="lots" data-item-id="${item.id}" aria-expanded="false" data-label="${viewLabel} →">${viewLabel} →</button>
          <div class="item-lots hidden" data-role="lots" aria-live="polite"></div>
        </section>

        <p class="stock-set-title">ตั้งค่าอุปกรณ์</p>
        <div class="stock-fields">
          <div class="stock-wide">
            <label for="nm-${item.id}">ชื่ออุปกรณ์</label>
            <input id="nm-${item.id}" type="text" value="${UI.escape(item.name)}" data-item-id="${item.id}" data-field="name">
          </div>
          <div>
            <label for="std-${item.id}">ขั้นต่ำที่ต้องมี</label>
            <input id="std-${item.id}" type="text" value="${UI.escape(item.standard)}" data-item-id="${item.id}" data-field="standard" placeholder="เช่น 5 หรือ 1 ชุด">
          </div>
          ${this.renderThresholdFields(item, type)}
          ${type === 'non_expiring' ? `
          <div class="flag-row">
            <label><input type="checkbox" data-item-id="${item.id}" data-field="is_battery" ${item.is_battery ? 'checked' : ''}> 🔋 มีแบตเตอรี่</label>
            <label><input type="checkbox" data-item-id="${item.id}" data-field="has_pressure" ${item.has_pressure ? 'checked' : ''}> 🫧 วัดแรงดัน (PSI)</label>
          </div>` : ''}
        </div>

        <div class="stock-actions">
          <p class="stock-dirty hidden" data-role="dirty">● มีการแก้ไขที่ยังไม่บันทึก</p>
          <button type="button" class="stock-save" data-action="save" data-item-id="${item.id}" disabled>บันทึก</button>
          <details class="stock-more">
            <summary class="small-btn" aria-label="ตัวเลือกเพิ่มเติมของอุปกรณ์นี้" title="ตัวเลือกเพิ่มเติม">⋯</summary>
            <div class="stock-more-menu">
              <button type="button" class="stock-more-item" data-action="toggle" data-item-id="${item.id}">${item.active ? '⏸ พักใช้งาน' : '▶️ เปิดใช้งาน'}</button>
              <button type="button" class="stock-more-item is-danger" data-action="delete" data-item-id="${item.id}">🗑 ลบอุปกรณ์</button>
            </div>
          </details>
        </div>
        </details>
      </article>
    `;
    }).join('') || '<p class="empty">ยังไม่มีอุปกรณ์ในรถคันนี้</p>';

    // จำว่าเปิดการ์ดไหนไว้ — render ใหม่ (เช่น แก้ล็อต/พักใช้งาน) แล้วยังเปิดอยู่ (ปิดเองเมื่อบันทึกสำเร็จ)
    container.querySelectorAll('details.stock-collapse').forEach(el => {
      el.addEventListener('toggle', () => {
        if (el.open) this.openItems.add(el.dataset.itemId); else this.openItems.delete(el.dataset.itemId);
      });
    });

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

      // ปุ่มบันทึกกดได้เมื่อมีการแก้ไขในการ์ด (ไม่นับช่องในรายการล็อต/รายชิ้น ซึ่งบันทึกแยกเอง)
      const markDirty = (e) => {
        const field = e.target.closest('[data-field]');
        if (!field || field.closest('[data-role="lots"]')) return;
        const card = field.closest('.stock-card');
        const save = card?.querySelector('[data-action="save"]');
        if (save) save.disabled = false;
        card?.querySelector('[data-role="dirty"]')?.classList.remove('hidden');
      };
      container.addEventListener('input', markDirty);
      container.addEventListener('change', markDirty);

      // เมนู ⋯ (พักใช้งาน / ลบ): ปิดเมื่อเลือกคำสั่ง หรือแตะนอกเมนู
      document.addEventListener('click', (e) => {
        container.querySelectorAll('details.stock-more[open]').forEach(menu => {
          if (!menu.contains(e.target) || e.target.closest('.stock-more-item')) menu.open = false;
        });
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

  /** แถวย่อของการ์ดอุปกรณ์ (กดเพื่อขยาย): ชื่อ • ประเภท • ยอดบนรถ/ขั้นต่ำ • หมดอายุเร็วสุด */
  renderItemSummary(item, type, counted, expiry, unitWord) {
    const TYPE = { individual: '🧩 รายชิ้น', box: '📦 กล่อง', non_expiring: '🩺 ไม่มีวันหมดอายุ' };
    const fmt = (n) => String(Math.round(Number(n || 0) * 100) / 100);
    const unit = type === 'non_expiring' ? unitWord : (item.unit ? ' ' + item.unit : '');
    const target = item.target_qty === null || item.target_qty === undefined || item.target_qty === '' ? null : Number(item.target_qty);
    const short = counted && target !== null && Number(item.current_qty || 0) < target;
    const qty = counted
      ? `<span class="${short ? 'txt-bad' : ''}">บนรถ <b>${UI.escape(fmt(item.current_qty))}</b>${target !== null ? ' / ' + UI.escape(fmt(target)) : ''}${UI.escape(unit)}${short ? ' (ขาด)' : ''}</span>`
      : '<span class="txt-bad">ยังไม่ได้จัดขึ้นรถ</span>';
    let exp = '';
    if (type !== 'non_expiring' && expiry) {
      const today = this.todayIso();
      const warnDays = item.expiry_warn_days === null || item.expiry_warn_days === undefined || item.expiry_warn_days === '' ? 30 : Number(item.expiry_warn_days);
      const soon = new Date(today + 'T00:00:00');
      soon.setDate(soon.getDate() + warnDays);
      const soonIso = `${soon.getFullYear()}-${String(soon.getMonth() + 1).padStart(2, '0')}-${String(soon.getDate()).padStart(2, '0')}`;
      const state = expiry < today ? ['txt-bad', '⛔ หมดอายุ '] : (expiry <= soonIso ? ['txt-warn', '⚠️ ใกล้หมดอายุ '] : ['', 'หมดอายุ ']);
      exp = `<span class="${state[0]}">${state[1]}${UI.escape(UI.formatDateThai(expiry))}</span>`;
    }
    return `
        <summary class="stock-summary">
          <span class="stock-sum-name">${UI.escape(item.name)}</span>
          ${item.active ? '' : '<span class="chip chip-off">พัก</span>'}
          <span class="stock-sum-meta">
            <span class="type-badge type-${UI.escape(type)}">${TYPE[type] || ''}</span>
            ${qty}${exp}
          </span>
        </summary>`;
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

  // ---------- เพิ่มอุปกรณ์: pop up แบบเดียวกับ "เพิ่มรถใหม่" ----------
  itemFormFields() {
    const types = [
      ['individual', '🧩 รายชิ้น', 'แต่ละชิ้นมีวันหมดอายุของตัวเอง'],
      ['box', '📦 กล่อง / ล็อต', 'ทั้งกล่องวันหมดอายุเดียวกัน'],
      ['non_expiring', '🩺 ไม่มีวันหมดอายุ', 'ตรวจรายเครื่อง: สถานะ / แบต / แรงดัน'],
    ];
    return `
      <p class="item-form-car">🚑 เพิ่มในรถ: ${window.vehicleChip(this.currentVehicle || { id: this.currentVehicleId })}</p>
      <div class="grid gap-4">
        <div>
          <label for="itemName" class="!mt-0">ชื่ออุปกรณ์ <span class="text-red-500" aria-hidden="true">*</span></label>
          <input id="itemName" type="text" maxlength="255" placeholder="เช่น Adrenaline 1 mg" autocomplete="off">
          <p class="mt-1 hidden text-xs font-semibold text-red-600" id="itemNameError" role="alert"></p>
        </div>
        <div>
          <label for="itemStandard">จำนวนมาตรฐาน (ขั้นต่ำที่ต้องมี) <span class="text-red-500" aria-hidden="true">*</span></label>
          <input id="itemStandard" type="text" maxlength="100" placeholder="เช่น 10 หลอด หรือ 1 ชุด" autocomplete="off">
          <p class="mt-1 hidden text-xs font-semibold text-red-600" id="itemStandardError" role="alert"></p>
        </div>
        <fieldset class="item-type-pick">
          <legend>ประเภทอุปกรณ์</legend>
          ${types.map(([value, label, hint], i) => `
          <label class="item-type-opt">
            <input type="radio" name="itemType" value="${value}" ${i === 0 ? 'checked' : ''}>
            <span><b>${label}</b><small>${hint}</small></span>
          </label>`).join('')}
        </fieldset>
        <div class="flag-row hidden" id="itemFlags">
          <label><input type="checkbox" id="itemIsBattery"> 🔋 มีแบตเตอรี่</label>
          <label><input type="checkbox" id="itemHasPressure"> 🫧 วัดแรงดัน (PSI)</label>
        </div>
        <fieldset class="item-details" id="itemDetails"></fieldset>
      </div>
    `;
  }

  /**
   * รายละเอียดตามประเภท (กรอกต่อได้เลยในหน้าต่างเดียวกัน — ไม่กรอก = เพิ่มแค่รายการ ยังไม่มีของบนรถ)
   *  กล่อง: จำนวน + วันหมดอายุ 1 วัน | รายชิ้น: จำนวน + วันหมดอายุทีละชิ้น | ไม่มีวันหมดอายุ: เกณฑ์แบต/แรงดัน + เครื่อง/ถังทีละตัว
   * render ใหม่เมื่อเปลี่ยนประเภท/ธง/จำนวน โดยเก็บค่าที่กรอกไว้แล้ว (ตาม id ของช่อง)
   */
  renderItemDetails() {
    const box = this._itemModal?.element.querySelector('#itemDetails');
    if (!box) return;
    const keep = {};
    box.querySelectorAll('input, select').forEach(el => { if (el.id) keep[el.id] = el.value; });
    const type = this.itemFormType();
    const v = (id, dflt = '') => UI.escape(keep[id] ?? dflt);
    const num = (id, label, attrs, placeholder = '') => `
      <div><label for="${id}">${label}</label>
        <input id="${id}" type="number" inputmode="numeric" step="1" ${attrs} value="${v(id)}" placeholder="${placeholder}"></div>`;

    if (type !== 'non_expiring') {
      const qty = Number(keep.itemOnboard || 0);
      box.innerHTML = `<legend>รายละเอียด${type === 'individual' ? 'รายชิ้น' : 'กล่อง / ล็อต'} <small>(ไม่บังคับ — กรอกทีหลังได้)</small></legend>
        <div><label for="itemOnboard">จำนวนที่มีบนรถตอนนี้</label>
          ${UI.stepper(`id="itemOnboard" min="0" value="${v('itemOnboard')}" placeholder="0"`, 'จำนวนบนรถ')}</div>
        <div class="add-dates" data-role="add-dates">${this.renderAddDates({ id: 'new', item_type: type }, qty)}</div>
        ${num('itemWarnDays', 'เตือนใกล้หมดอายุ (วัน)', 'min="0" max="3650"', 'ว่าง = 30 วัน')}`;
      box.querySelectorAll('input[type="date"]').forEach(i => { if (keep[i.id]) i.value = keep[i.id]; });
      return;
    }

    const form = this._itemModal.element;
    const battery = form.querySelector('#itemIsBattery')?.checked;
    const pressure = form.querySelector('#itemHasPressure')?.checked;
    const maxBars = Number(keep.itemBatMax) >= 1 && Number(keep.itemBatMax) <= 10 ? Number(keep.itemBatMax) : 4;
    const count = Math.min(50, Math.max(0, Math.floor(Number(keep.itemUnitCount || 0))));
    const units = Array.from({ length: count }, (_, k) => {
      const id = (f) => `itemUnit-${k}-${f}`;
      const status = keep[id('status')] || 'ready';
      return `
        <div class="lot-input-card">
          <p class="lot-input-title">🩺 เครื่อง/ถังที่ ${k + 1}</p>
          <div class="lot-input-grid">
            <div><label for="${id('label')}">ชื่อ / เลขเครื่อง</label>
              <input id="${id('label')}" type="text" maxlength="100" value="${v(id('label'))}" placeholder="เช่น ถัง O2 #1"></div>
            <div><label for="${id('status')}">สถานะ</label>
              <select id="${id('status')}" data-rerender>
                ${[['ready', '✅ พร้อมใช้'], ['not_ready', '⛔ ไม่พร้อมใช้']].concat(battery ? [['no_power', '🔋 เปิดไม่ติด']] : [])
                  .map(([val, label]) => `<option value="${val}" ${status === val ? 'selected' : ''}>${label}</option>`).join('')}
              </select></div>
            ${battery && status !== 'no_power' ? num(id('bat'), `แบตเตอรี่ (0-${maxBars} ขีด) *`, `min="0" max="${maxBars}"`) : ''}
            ${pressure ? num(id('psi'), 'แรงดัน (PSI) *', 'min="0" max="10000"') : ''}
            <div><label for="${id('note')}">หมายเหตุ${status !== 'ready' ? ' (สาเหตุ) *' : ''}</label>
              <input id="${id('note')}" type="text" maxlength="200" value="${v(id('note'))}"></div>
          </div>
        </div>`;
    }).join('');
    box.innerHTML = `<legend>รายละเอียดเครื่อง / ถัง <small>(ไม่บังคับ — กรอกทีหลังได้)</small></legend>
      ${battery ? num('itemBatMax', 'แบตเต็มกี่ขีด', 'min="1" max="10" data-rerender', '4') + num('itemBatLow', 'เตือนเมื่อแบต ≤ (ขีด)', `min="0" max="${maxBars}"`, 'ว่าง = ไม่เตือน') : ''}
      ${pressure ? num('itemO2Low', 'เตือนเมื่อแรงดัน < (PSI)', 'min="0" max="10000"', 'ว่าง = ยังไม่กำหนดเกณฑ์') : ''}
      <div><label for="itemUnitCount">จำนวนเครื่อง/ถังที่มีบนรถตอนนี้</label>
        ${UI.stepper(`id="itemUnitCount" min="0" max="50" value="${v('itemUnitCount')}" placeholder="0"`, 'จำนวนเครื่อง')}</div>
      ${units}`;
  }

  /** ค่ารายละเอียดจากหน้าต่างเพิ่มอุปกรณ์ → ฟิลด์ใน payload (ตรวจซ้ำที่ server) */
  itemDetailsPayload(form, itemType) {
    const val = (id) => (form.querySelector('#' + id)?.value || '').trim();
    const out = {};
    const errors = [];
    const today = this.todayIso();
    if (itemType !== 'non_expiring') {
      if (val('itemWarnDays') !== '') out.expiry_warn_days = val('itemWarnDays');
      const qty = Number(val('itemOnboard') || 0);
      if (qty > 0) {
        out.onboard_qty = String(qty);
        if (itemType === 'individual') {
          if (!Number.isInteger(qty) || qty > 50) errors.push('ของแบบชิ้นต้องเป็นจำนวนเต็ม ไม่เกิน 50 ชิ้น');
          const dates = [...form.querySelectorAll('[data-field="piece_expiry"]')].map(i => i.value);
          const missing = dates.map((d, k) => (d ? 0 : k + 1)).filter(Boolean);
          if (missing.length) errors.push(`กรอกวันหมดอายุชิ้นที่ ${missing.join(', ')}`);
          out.onboard_expiries = dates;
        } else {
          out.onboard_expiry = form.querySelector('[data-field="onboard_expiry"]')?.value || '';
          if (!out.onboard_expiry) errors.push('กรอกวันหมดอายุของที่มีบนรถ');
        }
        if ([].concat(out.onboard_expiries || out.onboard_expiry).some(d => d && d < today)) errors.push('วันหมดอายุผ่านไปแล้ว — เพิ่มของหมดอายุไม่ได้');
      }
      return { out, errors };
    }
    const battery = form.querySelector('#itemIsBattery').checked;
    const pressure = form.querySelector('#itemHasPressure').checked;
    if (battery && val('itemBatMax') !== '') out.battery_max_bars = val('itemBatMax');
    if (battery && val('itemBatLow') !== '') out.battery_low_bars = val('itemBatLow');
    if (pressure && val('itemO2Low') !== '') out.o2_low_psi = val('itemO2Low');
    const count = Math.min(50, Math.max(0, Math.floor(Number(val('itemUnitCount') || 0))));
    out.units = Array.from({ length: count }, (_, k) => {
      const f = (name) => val(`itemUnit-${k}-${name}`);
      const status = f('status') || 'ready';
      const label = `เครื่อง/ถังที่ ${k + 1}`;
      if (battery && status !== 'no_power' && f('bat') === '') errors.push(`${label}: กรอกระดับแบตเตอรี่`);
      if (pressure && f('psi') === '') errors.push(`${label}: กรอกแรงดัน (PSI)`);
      if (status !== 'ready' && !f('note')) errors.push(`${label}: ไม่พร้อมใช้/เปิดไม่ติด ต้องระบุสาเหตุ`);
      return {
        label: f('label'), status, note: f('note'),
        battery_level: battery && status !== 'no_power' ? Number(f('bat')) : null,
        pressure_psi: pressure ? Number(f('psi')) : null,
      };
    });
    if (!out.units.length) delete out.units;
    return { out, errors };
  }

  showAddItemForm() {
    if (!this.currentVehicleId) {
      window.toast.warning('กรุณาเลือกรถก่อน');
      return;
    }
    this.hideAddItemForm();

    const modal = new Modal({
      title: 'เพิ่มอุปกรณ์ใหม่',
      size: 'md',
      content: `<form id="itemForm" novalidate>${this.itemFormFields()}</form>`,
      onClose: () => { this._itemModal = null; },
    }).render();
    this._itemModal = modal;

    const actions = modal.element.querySelector('.sheet-actions');
    actions.innerHTML = `
      <button type="button" class="small-btn gray flex-1" data-action="cancel">ยกเลิก</button>
      <button type="button" class="flex-1" data-action="save">บันทึกอุปกรณ์ใหม่</button>
    `;
    actions.querySelector('[data-action="cancel"]').onclick = () => this.cancelItemForm();
    actions.querySelector('[data-action="save"]').onclick = () => this.handleAddItem();

    const form = modal.element.querySelector('#itemForm');
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.handleAddItem();
    });
    form.querySelector('#itemName')?.addEventListener('input', () => this.setItemFieldError('itemName', ''));
    form.querySelector('#itemStandard')?.addEventListener('input', () => this.setItemFieldError('itemStandard', ''));
    // แบต/แรงดัน มีเฉพาะประเภท "ไม่มีวันหมดอายุ" — เปลี่ยนประเภท/ธง = เปลี่ยนช่องรายละเอียด
    form.querySelectorAll('input[name="itemType"], #itemIsBattery, #itemHasPressure').forEach(el => {
      el.addEventListener('change', () => {
        form.querySelector('#itemFlags')?.classList.toggle('hidden', this.itemFormType() !== 'non_expiring');
        this.renderItemDetails();
      });
    });
    const details = form.querySelector('#itemDetails');
    // จำนวนเปลี่ยน → ช่องวันหมดอายุ/เครื่องเปลี่ยนตาม (render เฉพาะส่วนล่าง เคอร์เซอร์ยังอยู่ช่องจำนวน)
    details.addEventListener('input', (e) => {
      if (e.target.id === 'itemOnboard') {
        const dates = details.querySelector('[data-role="add-dates"]');
        const keep = [...dates.querySelectorAll('input[type="date"]')].map(i => i.value);
        dates.innerHTML = this.renderAddDates({ id: 'new', item_type: this.itemFormType() }, Number(e.target.value || 0));
        dates.querySelectorAll('input[type="date"]').forEach((i, k) => { if (keep[k]) i.value = keep[k]; });
      }
    });
    details.addEventListener('change', (e) => {
      if (e.target.id === 'itemUnitCount' || e.target.hasAttribute('data-rerender')) {
        const focusId = e.target.id;
        this.renderItemDetails();
        form.querySelector('#' + CSS.escape(focusId))?.focus();
      }
    });
    details.addEventListener('click', (e) => {
      if (!e.target.closest('[data-action="fill-dates"]')) return;
      const inputs = [...details.querySelectorAll('[data-role="add-dates"] input[type="date"]')];
      const first = inputs.find(i => i.value);
      if (first) inputs.forEach(i => { if (!i.value) i.value = first.value; });
      else window.toast.warning('ใส่วันหมดอายุอย่างน้อย 1 ช่องก่อน');
    });
    this.renderItemDetails();

    modal.open();
    form.querySelector('#itemName')?.focus();
  }

  hideAddItemForm() {
    if (this._itemModal) {
      try { this._itemModal.destroy(); } catch (e) { /* node ถูกลบไปแล้ว */ }
      this._itemModal = null;
    }
  }

  itemFormType() {
    return this._itemModal?.element.querySelector('input[name="itemType"]:checked')?.value || 'individual';
  }

  setItemFieldError(inputId, message) {
    const form = this._itemModal?.element.querySelector('#itemForm');
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

  async cancelItemForm() {
    const form = this._itemModal?.element.querySelector('#itemForm');
    const hasInput = ['itemName', 'itemStandard'].some(id => (form?.querySelector('#' + id)?.value || '').trim() !== '');
    if (hasInput) {
      const ok = await window.confirmDialog('ต้องการยกเลิกและล้างข้อมูลที่กรอกไว้หรือไม่?', {
        title: 'ยกเลิกการเพิ่มอุปกรณ์',
        confirmText: 'ล้างข้อมูล',
        cancelText: 'กรอกต่อ',
      });
      if (!ok) return;
    }
    this.hideAddItemForm();
  }

  async handleAddItem() {
    const form = this._itemModal?.element.querySelector('#itemForm');
    if (!form) return;
    const vehicleId = this.currentVehicleId;
    const name = form.querySelector('#itemName').value.trim();
    const standard = form.querySelector('#itemStandard').value.trim();
    const itemType = this.itemFormType();

    this.setItemFieldError('itemName', name ? '' : 'กรุณากรอกชื่ออุปกรณ์');
    this.setItemFieldError('itemStandard', standard ? '' : 'กรุณากรอกจำนวนมาตรฐาน เช่น 10 หรือ 1 ชุด');
    if (!name || !standard) {
      form.querySelector(name ? '#itemStandard' : '#itemName')?.focus();
      return;
    }
    if (!vehicleId) {
      window.toast.error('กรุณาเลือกรถก่อน');
      return;
    }

    const details = this.itemDetailsPayload(form, itemType);
    if (details.errors.length) {
      window.toast.warning(details.errors.join(' • '));
      return;
    }
    const payload = { name, standard, item_type: itemType, ...details.out };
    if (itemType === 'non_expiring') {
      payload.is_battery = form.querySelector('#itemIsBattery').checked;
      payload.has_pressure = form.querySelector('#itemHasPressure').checked;
    }

    const saveBtn = this._itemModal.element.querySelector('[data-action="save"]');
    if (saveBtn) UI.setLoading(saveBtn, true);
    try {
      const res = await window.vehicleApi.addItem(vehicleId, payload);
      this.hideAddItemForm();
      const stocked = payload.onboard_qty || payload.units;
      window.toast.success(stocked ? `เพิ่ม “${name}” พร้อมของบนรถแล้ว` : `เพิ่ม “${name}” แล้ว — ใส่จำนวนบนรถและวันหมดอายุได้ที่การ์ดด้านล่าง`);
      await this.loadItems(vehicleId);
      this.focusItemCard(res?.data?.id);
    } catch (error) {
      const errs = error.data?.errors || {};
      const first = (key) => (Array.isArray(errs[key]) ? errs[key][0] : errs[key]);
      if (errs.name) this.setItemFieldError('itemName', first('name'));
      if (errs.standard) this.setItemFieldError('itemStandard', first('standard'));
      if (error.status === 409) this.setItemFieldError('itemName', error.message);   // ชื่อซ้ำในรถคันนี้
      window.toast.error('เพิ่มอุปกรณ์ไม่สำเร็จ: ' + (error.message || 'ไม่ทราบสาเหตุ'));
    } finally {
      if (saveBtn) UI.setLoading(saveBtn, false);
    }
  }

  // พาไปที่การ์ดอุปกรณ์ที่เพิ่งเพิ่ม — ผู้ใช้เห็นทันทีว่าเพิ่มแล้ว และกรอกยอดบนรถต่อได้เลย
  focusItemCard(itemId) {
    if (!itemId) return;
    const card = document.querySelector(`#stockTable .stock-card[data-item-id="${CSS.escape(String(itemId))}"]`);
    if (!card) return;
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    card.classList.add('is-focus');
    setTimeout(() => card.classList.remove('is-focus'), 3000);
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
        { confirmText: 'บันทึกยอด', tone: 'ok' }
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
      const closed = res?.data?.onboard?.requests || [];
      if (closed.length) message += ` • ครบมาตรฐานแล้ว ปิดรายการรอเติม ${closed.map(r => r.no).join(', ')}`;
      window.toast.success(message);
      this.openItems.delete(itemId);   // บันทึกแล้ว → ย่อกลับเป็นรายการ
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

    // ไม่คัดลอกยอดบนรถข้ามคัน (ของจริงไม่ได้ย้ายมา) — กรอก "จำนวนที่จะจัดขึ้นรถ + วันหมดอายุ" เองในรายการได้
    this.copyStaging = sourceItems.map(item => ({
      name: item.name || '',
      standard: item.standard || '',
      is_battery: !!item.is_battery,
      item_type: item.item_type || (item.is_battery ? 'non_expiring' : 'individual'),
      has_pressure: !!item.has_pressure,
      active: item.active !== false && item.active !== 0,
      stock_qty: '',
      stock_expiry: '',
    }));
    this.copySourceLabel = `จากรถคัน ${sourceLabel}`;
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
          <p class="mb-2">ระบบจะนำอุปกรณ์ทั้งหมด <strong>${count} รายการ</strong> มาแสดงในรถคัน <strong>${UI.escape(targetLabel)}</strong> เพื่อให้ตรวจสอบ/แก้ไขก่อนบันทึก — ยังไม่มีการบันทึกจนกว่าจะกด “ยืนยันเพิ่ม…ลงรถ”</p>
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
      this.copyFilter = 'all';
      this.saveStagingDraft();
      return;
    }

    // หัวสรุปติดใต้หัวเว็บ — ความสูงหัวเว็บต่างกันตามจอ จึงวัดจริง
    const appTop = document.querySelector('.app-top');
    if (appTop) document.documentElement.style.setProperty('--app-top-h', appTop.offsetHeight + 'px');

    const today = this.todayIso();
    const yesNo = (field, on) => `<select data-copy-field="${field}">
                <option value="false" ${!on ? 'selected' : ''}>ไม่ใช่</option>
                <option value="true" ${on ? 'selected' : ''}>ใช่</option>
              </select>`;
    // แต่ละรายการโชว์แค่ช่องที่ต้องกรอก (จำนวน + วันหมดอายุ) — ชื่อ/มาตรฐาน/ประเภท/สถานะ มาจากต้นทางแล้ว จึงพับไว้ใน "แก้ไขรายละเอียด"
    list.innerHTML = this.copyStaging.map((row, idx) => {
      const type = row.item_type || 'individual';
      const nonExpiring = type === 'non_expiring';
      const fill = nonExpiring
        ? '<p class="copy-row-hint">🩺 ไม่มีวันหมดอายุ — เพิ่มเครื่อง/ถังพร้อมค่าแบต/แรงดันที่แท็บ “เติมของ” หลังบันทึก</p>'
        : `<div class="copy-row-fill">
              <label for="cpq-${idx}">จำนวนที่จัดขึ้นรถ
                <input id="cpq-${idx}" type="number" min="0" step="${type === 'individual' ? 1 : 'any'}" inputmode="decimal"
                  data-copy-field="stock_qty" value="${UI.escape(String(row.stock_qty ?? ''))}" placeholder="ว่าง = ยังไม่จัด">
              </label>
              <label for="cpe-${idx}">วันหมดอายุ
                <input id="cpe-${idx}" type="date" min="${today}" data-copy-field="stock_expiry" value="${UI.escape(row.stock_expiry || '')}">
              </label>
            </div>`;
      const typeFields = nonExpiring
        ? `<div><label>มีแบตเตอรี่</label>${yesNo('is_battery', row.is_battery)}</div>
            <div><label>วัดแรงดัน (PSI)</label>${yesNo('has_pressure', row.has_pressure)}</div>`
        : '';
      return `
        <div class="copy-row" data-idx="${idx}">
          <div class="copy-row-head">
            <span class="copy-row-no">${idx + 1}</span>
            <strong class="copy-row-name" data-copy-name></strong>
            <button type="button" class="copy-row-remove" data-copy-action="remove"
              aria-label="เอารายการที่ ${idx + 1} ออก" title="เอาออกจากรายการรอบันทึก">🗑</button>
            <div class="copy-row-tags" data-copy-tags></div>
            <p class="copy-row-dup hidden" data-copy-dup>⚠️ มีชื่อนี้อยู่ในรถแล้ว — ถ้าไม่ต้องการให้กด 🗑</p>
          </div>
          ${fill}
          <details class="copy-row-edit">
            <summary>แก้ไขรายละเอียด</summary>
            <div class="copy-row-fields">
              <div class="copy-row-wide">
                <label for="cpn-${idx}">ชื่ออุปกรณ์</label>
                <input id="cpn-${idx}" type="text" data-copy-field="name" value="${UI.escape(row.name)}" placeholder="ชื่ออุปกรณ์">
              </div>
              <div>
                <label for="cps-${idx}">จำนวนมาตรฐาน</label>
                <input id="cps-${idx}" type="text" data-copy-field="standard" value="${UI.escape(row.standard)}">
              </div>
              <div>
                <label for="cpt-${idx}">ประเภท</label>
                <select id="cpt-${idx}" data-copy-field="item_type">
                  ${VEHICLE_ITEM_TYPES.map(([value, label]) =>
                    `<option value="${value}" ${type === value ? 'selected' : ''}>${label}</option>`).join('')}
                </select>
              </div>
              <div class="copy-row-wide">
                <label for="cpa-${idx}">สถานะ</label>
                <select id="cpa-${idx}" data-copy-field="active">
                  <option value="true" ${row.active !== false ? 'selected' : ''}>ใช้งาน</option>
                  <option value="false" ${row.active === false ? 'selected' : ''}>พักใช้งาน</option>
                </select>
              </div>
              ${typeFields}
            </div>
          </details>
        </div>`;
    }).join('');

    list.querySelectorAll('.copy-row').forEach(rowEl => this.renderCopyRowHead(rowEl));

    panel.classList.remove('hidden');
    if (fromEl) fromEl.textContent = this.copySourceLabel;
    if (countChip) countChip.textContent = `📥 รอเพิ่มลงรถ ${this.copyStaging.length} รายการ`;
    if (confirmBtn) confirmBtn.textContent = `ยืนยันเพิ่ม ${this.copyStaging.length} รายการ`;
    this.renderCopyStockSummary();
    this.applyCopyFilter();
    this.saveStagingDraft();

    list.querySelectorAll('[data-copy-field]').forEach(input => {
      input.addEventListener('input', () => this.onCopyFieldChange(input));
    });
    list.querySelectorAll('[data-copy-action="remove"]').forEach(btn => {
      btn.addEventListener('click', () => {
        this.removeCopyRow(Number(btn.closest('.copy-row')?.dataset.idx));
      });
    });
  }

  /** ส่วนหัวของรายการ: ชื่อ + ป้ายอ่านอย่างเดียว (ประเภท/มาตรฐาน/สถานะ) + เตือนชื่อซ้ำกับของในรถ */
  renderCopyRowHead(rowEl) {
    const row = this.copyStaging[Number(rowEl.dataset.idx)];
    if (!row) return;
    const name = String(row.name || '').trim();
    const nameEl = rowEl.querySelector('[data-copy-name]');
    if (nameEl) {
      nameEl.textContent = name || 'ยังไม่มีชื่อ — กด “แก้ไขรายละเอียด”';
      nameEl.classList.toggle('is-empty', !name);
    }

    const type = row.item_type || 'individual';
    const typeLabel = (VEHICLE_ITEM_TYPES.find(([value]) => value === type) || [])[1] || type;
    const tags = [typeLabel];
    if (String(row.standard || '').trim()) tags.push(`มาตรฐาน ${String(row.standard).trim()}`);
    if (row.active === false) tags.push('พักใช้งาน');
    if (type === 'non_expiring' && row.is_battery) tags.push('🔋 มีแบต');
    if (type === 'non_expiring' && row.has_pressure) tags.push('วัดแรงดัน');
    const tagsEl = rowEl.querySelector('[data-copy-tags]');
    if (tagsEl) tagsEl.innerHTML = tags.map(t => `<span class="copy-tag">${UI.escape(t)}</span>`).join('');

    const dup = this.copyRowIsDup(row);
    rowEl.classList.toggle('is-dup', dup);
    rowEl.querySelector('[data-copy-dup]')?.classList.toggle('hidden', !dup);
  }

  copyRowIsDup(row) {
    const name = String(row.name || '').trim().toLowerCase();
    return name !== '' && this.items.some(i => String(i.name || '').trim().toLowerCase() === name);
  }

  /** แถวที่ยังต้องใส่จำนวน (มีวันหมดอายุ แต่ยังว่าง) */
  copyRowNeedsQty(row) {
    return (row.item_type || 'individual') !== 'non_expiring' && String(row.stock_qty ?? '').trim() === '';
  }

  /** หัวสรุป: ความคืบหน้า + จำนวนซ้ำ/พักใช้งาน + ตัวเลขบนปุ่มกรอง */
  renderCopyStockSummary() {
    const el = document.getElementById('copyPreviewStock');
    if (!el) return;
    const rows = this.copyStaging;
    const fillable = rows.filter(row => (row.item_type || 'individual') !== 'non_expiring');
    const empty = rows.filter(row => this.copyRowNeedsQty(row)).length;
    const done = fillable.length - empty;
    const dup = rows.filter(row => this.copyRowIsDup(row)).length;
    const resting = rows.filter(row => row.active === false).length;

    const bits = [`<span>ใส่จำนวนแล้ว ${done}/${fillable.length}</span>`];
    if (dup > 0) bits.push(`<span class="is-warn">⚠️ ซ้ำกับในรถ ${dup}</span>`);
    if (resting > 0) bits.push(`<span>พักใช้งาน ${resting}</span>`);
    bits.push('<span>ยังไม่บันทึก · ที่เว้นว่างไว้เติมภายหลังที่แท็บ “เติมของ”</span>');
    el.innerHTML = bits.join('');

    const bar = document.getElementById('copyProgressBar');
    if (bar) bar.style.width = fillable.length ? `${(done / fillable.length) * 100}%` : '100%';

    const counts = { all: rows.length, dup, empty };
    const labels = { all: 'ทั้งหมด', dup: 'ซ้ำกับในรถ', empty: 'ยังไม่ใส่จำนวน' };
    document.querySelectorAll('#copyPreview [data-copy-filter]').forEach(btn => {
      const key = btn.dataset.copyFilter;
      btn.textContent = `${labels[key]} ${counts[key]}`;
      btn.hidden = key !== 'all' && counts[key] === 0 && this.copyFilter !== key;
    });
  }

  /** ซ่อน/แสดงรายการตามปุ่มกรอง — กรองตอนกดปุ่มเท่านั้น (พิมพ์จำนวนแล้วแถวไม่หายไปใต้มือ) */
  applyCopyFilter() {
    const filter = this.copyFilter || 'all';
    let shown = 0;
    document.querySelectorAll('#copyPreviewList .copy-row').forEach(rowEl => {
      const row = this.copyStaging[Number(rowEl.dataset.idx)];
      const visible = !row || filter === 'all'
        || (filter === 'dup' && this.copyRowIsDup(row))
        || (filter === 'empty' && this.copyRowNeedsQty(row));
      rowEl.hidden = !visible;
      if (visible) shown++;
    });
    document.getElementById('copyFilterEmpty')?.classList.toggle('hidden', shown > 0);
    document.querySelectorAll('#copyPreview [data-copy-filter]').forEach(btn => {
      btn.setAttribute('aria-pressed', String(btn.dataset.copyFilter === filter));
    });
  }

  setCopyFilter(filter) {
    this.copyFilter = filter;
    this.renderCopyStockSummary();
    this.applyCopyFilter();
  }

  onCopyFieldChange(input) {
    const rowEl = input.closest('.copy-row');
    const idx = Number(rowEl?.dataset.idx);
    const field = input.dataset.copyField;
    if (!rowEl || Number.isNaN(idx) || !this.copyStaging[idx]) return;

    if (field === 'is_battery' || field === 'has_pressure' || field === 'active') {
      this.copyStaging[idx][field] = input.value === 'true';
    } else {
      this.copyStaging[idx][field] = input.value;
    }

    if (field === 'item_type') {
      // ช่องในแถวต่างกันตามประเภท (จำนวน+วันหมดอายุ ↔ แบต/แรงดัน) — วาดใหม่แล้วคืนโฟกัสที่ช่องประเภท
      this.renderCopyPreview();
      const rowAfter = document.querySelector(`#copyPreviewList .copy-row[data-idx="${idx}"]`);
      const edit = rowAfter?.querySelector('.copy-row-edit');
      if (edit) edit.open = true;   // วาดใหม่แล้วพับปิด — เปิดค้างไว้ให้แก้ต่อได้
      rowAfter?.querySelector('[data-copy-field="item_type"]')?.focus();
      return;
    }
    if (field !== 'stock_expiry') {
      this.renderCopyRowHead(rowEl);
      this.renderCopyStockSummary();
    }
    this.saveStagingDraft();
  }

  removeCopyRow(idx) {
    if (Number.isNaN(idx) || !this.copyStaging[idx]) return;
    this.copyStaging.splice(idx, 1);
    this.renderCopyPreview();
    window.toast.info(`เหลือรายการรอคัดลอก ${this.copyStaging.length} รายการ`);
  }

  /** แถวนี้จะจัดของขึ้นรถด้วยหรือไม่ (มีจำนวน และไม่ใช่ประเภทไม่มีวันหมดอายุ) */
  stagedStock(row) {
    const qty = String(row.stock_qty ?? '').trim();
    return (row.item_type || 'individual') !== 'non_expiring' && qty !== '' ? Number(qty) : null;
  }

  /** ตรวจทุกแถวก่อนบันทึก — ผิดแถวไหนเลื่อนไปและโฟกัสช่องนั้น */
  validateStaging() {
    const today = this.todayIso();
    const fail = (i, field, message) => {
      window.toast.error(`รายการที่ ${i + 1}: ${message}`);
      const el = document.querySelector(`#copyPreviewList .copy-row[data-idx="${i}"] [data-copy-field="${field}"]`);
      // ช่องที่ผิดอาจถูกกรองซ่อน หรืออยู่ใน "แก้ไขรายละเอียด" ที่พับไว้
      if (el?.closest('.copy-row')?.hidden) this.setCopyFilter('all');
      const details = el?.closest('details');
      if (details) details.open = true;
      el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      el?.focus();
      return false;
    };
    for (let i = 0; i < this.copyStaging.length; i++) {
      const row = this.copyStaging[i];
      if (!String(row.name).trim()) return fail(i, 'name', 'กรอกชื่ออุปกรณ์');
      if (!String(row.standard).trim()) return fail(i, 'standard', 'กรอกจำนวนมาตรฐาน');
      if ((row.item_type || 'individual') === 'non_expiring') continue;
      const qty = this.stagedStock(row);
      const expiry = String(row.stock_expiry || '');
      if (qty === null) {
        if (expiry) return fail(i, 'stock_qty', 'ใส่วันหมดอายุแล้ว กรุณากรอกจำนวนที่จะจัดขึ้นรถด้วย (หรือลบวันที่ออก)');
        continue;
      }
      if (!Number.isFinite(qty) || qty <= 0) return fail(i, 'stock_qty', 'จำนวนที่จะจัดขึ้นรถต้องมากกว่า 0');
      if (row.item_type === 'individual' && !Number.isInteger(qty)) return fail(i, 'stock_qty', 'ของแบบรายชิ้นต้องเป็นจำนวนเต็ม');
      if (!/^\d{4}-\d{2}-\d{2}$/.test(expiry)) return fail(i, 'stock_expiry', 'เลือกวันหมดอายุของของที่จะจัดขึ้นรถ');
      if (expiry < today) return fail(i, 'stock_expiry', 'วันหมดอายุผ่านไปแล้ว — ไม่ควรจัดของหมดอายุขึ้นรถ');
    }
    return true;
  }

  async handleCopyConfirm() {
    if (this.copyStaging.length === 0 || !this.validateStaging()) return;

    const btn = document.getElementById('btnCopyConfirm');
    const carId = this.currentVehicleId;
    const rows = this.copyStaging.slice();
    UI.setLoading(btn, true);
    let res;
    try {
      res = await window.vehicleApi.copyItems(carId, {
        items: rows.map(row => ({
          name: String(row.name).trim(),
          standard: String(row.standard).trim(),
          is_battery: !!row.is_battery,
          item_type: row.item_type || '',
          has_pressure: !!row.has_pressure,
          active: row.active !== false,
        })),
      });
    } catch (error) {
      UI.setLoading(btn, false);
      // 401 = หมดเวลาเข้าสู่ระบบ: รายการยังเก็บไว้ในเบราว์เซอร์ เข้าสู่ระบบใหม่แล้วกลับมาที่รถคันนี้ได้เลย
      if (error.status !== 401) window.toast.error('บันทึกรายการไม่สำเร็จ: ' + (error.message || 'ไม่ทราบสาเหตุ'));
      return;
    }

    // จัดของขึ้นรถผ่านหน้าเติมของ (สร้าง Lot + วันหมดอายุ + ประวัติการเคลื่อนไหว) — ทางเดียวกับแท็บ "เติมของ"
    const ids = res.data?.item_ids || [];
    const lines = rows.map((row, i) => {
      const qty = this.stagedStock(row);
      return qty !== null && ids[i] ? { item_id: ids[i], lots: [{ qty, expiry_date: row.stock_expiry }] } : null;
    }).filter(Boolean);
    const created = res.data?.created ?? rows.length;
    const inactive = Number(res.data?.inactive_copied ?? 0);
    this.clearCopyStaging();

    let stocked = 0;
    if (lines.length) {
      try {
        const token = (window.crypto && crypto.randomUUID) ? crypto.randomUUID().replace(/-/g, '') : UI.uid() + UI.uid();
        await window.staffApi.refill(carId, { client_token: token, note: 'จัดของขึ้นรถพร้อมรายการที่เพิ่มใหม่', lines });
        stocked = lines.length;
      } catch (error) {
        window.toast.warning(`เพิ่มรายการแล้ว ${created} รายการ แต่จัดของขึ้นรถไม่สำเร็จ: ${error.message || ''} — เติมต่อได้ที่แท็บ “เติมของ”`, 12000);
      }
    }
    if (!lines.length || stocked) {
      window.toast.success(
        `เพิ่มรายการลงรถแล้ว ${created} รายการ`
        + (stocked ? ` • จัดของขึ้นรถพร้อมวันหมดอายุ ${stocked} รายการ` : '')
        + (inactive > 0 ? ` • พักใช้งาน ${inactive} รายการ` : '')
      );
    }
    UI.setLoading(btn, false);
    await this.loadItems(carId);
  }

  // รายการรอบันทึกเก็บในเบราว์เซอร์ (แท็บนี้) — ถ้าหมดเวลาเข้าสู่ระบบหรือรีเฟรชหน้า ข้อมูลที่แก้ไว้ไม่หาย
  saveStagingDraft() {
    try {
      if (this.copyStaging.length) {
        sessionStorage.setItem(STAGING_KEY, JSON.stringify({ carId: this.currentVehicleId, label: this.copySourceLabel, rows: this.copyStaging }));
      } else {
        sessionStorage.removeItem(STAGING_KEY);
      }
    } catch (error) { /* ไม่มี storage ก็ทำงานต่อได้ แค่ไม่กู้คืน */ }
  }

  restoreStagingDraft(vehicleId) {
    if (this.copyStaging.length) return;
    let saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(STAGING_KEY) || 'null'); } catch (error) { return; }
    if (!saved || String(saved.carId) !== String(vehicleId) || !Array.isArray(saved.rows) || !saved.rows.length) return;
    this.copyStaging = saved.rows;
    this.copySourceLabel = saved.label || '';
    this.renderCopyPreview();
    window.toast.info(`กู้คืนรายการที่ยังไม่ได้บันทึก ${saved.rows.length} รายการ — ตรวจสอบแล้วกดยืนยันได้เลย`);
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

  // ==================== Import items from file (.csv / .xlsx) ====================

  downloadItemTemplate() {
    const rows = [
      ITEM_IMPORT_COLUMNS.map(c => c.header),
      ['ตัวอย่าง Adrenaline 1 mg', '10 หลอด', 'รายชิ้น', 'ไม่ใช่', 'ไม่ใช่', 'ใช้งาน', '10', '31/12/2569'],
      ['ตัวอย่าง ชุดให้น้ำเกลือ', '2 ชุด', 'กล่อง', 'ไม่ใช่', 'ไม่ใช่', 'ใช้งาน', '2', '30/06/2570'],
      ['ตัวอย่าง ผ้าก๊อซ (ยังไม่จัดของ)', '5 ห่อ', 'กล่อง', 'ไม่ใช่', 'ไม่ใช่', 'ใช้งาน', '', ''],
      ['ตัวอย่าง ถังออกซิเจน', '2 ถัง', 'ไม่มีวันหมดอายุ', 'ไม่ใช่', 'ใช่', 'ใช้งาน', '', ''],
      ['ตัวอย่าง เครื่อง AED', '1 เครื่อง', 'ไม่มีวันหมดอายุ', 'ใช่', 'ไม่ใช่', 'ใช้งาน', '', ''],
    ];
    const csvCell = (v) => /[",\r\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v;
    const csv = rows.map(r => r.map(csvCell).join(',')).join('\r\n');
    // BOM ให้ Excel เปิดภาษาไทยถูก
    const blob = new Blob(['﻿' + csv + '\r\n'], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'แบบฟอร์มนำเข้าอุปกรณ์ในรถ.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    window.toast.success('ดาวน์โหลดไฟล์ต้นแบบแล้ว — แถวที่ขึ้นต้นด้วย "ตัวอย่าง" ระบบจะข้ามให้อัตโนมัติ');
  }

  async handleItemImport(file) {
    if (!this.currentVehicleId) {
      window.toast.warning('กรุณาเลือกรถที่จะเพิ่มอุปกรณ์ก่อน');
      return;
    }
    if (typeof XLSX === 'undefined') {
      window.toast.error('ตัวอ่านไฟล์ยังโหลดไม่สำเร็จ — กรุณารีเฟรชหน้าแล้วลองใหม่');
      return;
    }

    let table;
    try {
      const buffer = await file.arrayBuffer();
      const workbook = /\.csv$/i.test(file.name)
        ? XLSX.read(NursesPage.decodeCsvBytes(buffer), { type: 'string', raw: true })
        : XLSX.read(buffer, { type: 'array', cellDates: true });
      const sheet = workbook.Sheets[workbook.SheetNames[0]];
      // raw: เซลล์วันที่ใน Excel ได้เป็น Date (ไม่ถูกจัดรูปแบบเป็น ด/ว/ปป แบบอเมริกัน) ตัวเลขได้เป็น number
      table = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: true, defval: '' });
    } catch (error) {
      window.toast.error('อ่านไฟล์ไม่สำเร็จ — รองรับ .csv และ .xlsx (ใช้ไฟล์ต้นแบบได้)');
      return;
    }

    const { rows, errors, skipped } = parseItemImportTable(table);
    if (errors.length) {
      await window.alertDialog(
        'ยังนำเข้าไม่ได้ กรุณาแก้ไฟล์ตามนี้แล้วเลือกไฟล์ใหม่:\n\n' + errors.slice(0, 12).join('\n')
          + (errors.length > 12 ? `\n… และอีก ${errors.length - 12} จุด` : ''),
        { title: 'ไฟล์นำเข้ามีข้อผิดพลาด' }
      );
      return;
    }
    if (!rows.length) {
      await window.alertDialog('ไม่พบรายการอุปกรณ์ในไฟล์ (แถวตัวอย่างและแถวว่างจะถูกข้าม)', { title: 'ไม่มีรายการให้นำเข้า' });
      return;
    }
    if (rows.length > 300) {
      await window.alertDialog(`นำเข้าได้ครั้งละไม่เกิน 300 รายการ (ไฟล์นี้มี ${rows.length} รายการ) — กรุณาแบ่งไฟล์`, { title: 'รายการมากเกินไป' });
      return;
    }
    if (this.copyStaging.length > 0) {
      const ok = await window.confirmDialog(
        `มีรายการรอคัดลอก/นำเข้าค้างอยู่ ${this.copyStaging.length} รายการ — แทนที่ด้วยรายการจากไฟล์หรือไม่?`,
        { title: 'แทนที่รายการที่ค้างอยู่', confirmText: 'แทนที่' }
      );
      if (!ok) return;
    }

    this.copyStaging = rows;
    this.copySourceLabel = `จากไฟล์ ${file.name}`;
    this.renderCopyPreview();
    document.getElementById('copyPreview')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    window.toast.info(
      `อ่านไฟล์แล้ว ${rows.length} รายการ${skipped ? ` (ข้ามแถวตัวอย่าง/แถวว่าง ${skipped} แถว)` : ''} `
      + '— แก้ไข/เลือกวันหมดอายุได้เลย แล้วกด "ยืนยันเพิ่มลงรถ"'
    );
  }

  // ==================== Vehicle CRUD (add / edit / delete) ====================

  // ฟอร์มเดียวใช้สองโหมด: 'create' กับ 'edit'
  // โหมด edit อ่านค่าเริ่มต้นจาก this.currentVehicle (รถที่เลือกอยู่)
  vehicleFormFields(mode) {
    const v = mode === 'edit' ? (this.currentVehicle || {}) : {};
    const isEdit = mode === 'edit';
    const kind = v.vehicle_type === 'emr' ? 'emr' : 'refer';

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
        <fieldset class="veh-kind md:col-span-2">
          <legend>ประเภทรถ</legend>
          <div class="veh-kind-opts">
            <label class="veh-kind-opt">
              <input type="radio" name="vehKind" value="refer" ${kind === 'refer' ? 'checked' : ''}>
              <span><b>🚑 รถ Refer</b><small>รถส่งต่อผู้ป่วย</small></span>
            </label>
            <label class="veh-kind-opt">
              <input type="radio" name="vehKind" value="emr" ${kind === 'emr' ? 'checked' : ''}>
              <span><b>🏥 รถ EMR</b><small>อยู่ประจำวอร์ด</small></span>
            </label>
          </div>
        </fieldset>
        <div class="md:col-span-2 ${kind === 'emr' ? '' : 'hidden'}" id="vehWardWrap">
          <label for="vehWard">วอร์ดที่รถประจำอยู่</label>
          <select id="vehWard" data-value="${UI.escape(v.home_department_id || '')}"><option value="">กำลังโหลดรายชื่อวอร์ด…</option></select>
          <p class="mt-1 text-xs text-slate-500">ไม่บังคับ — เว้นว่างได้ถ้ายังไม่กำหนดวอร์ด</p>
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

    // ประเภทรถ: เลือก EMR แล้วค่อยโชว์ช่องวอร์ดประจำ
    form.querySelectorAll('input[name="vehKind"]').forEach(radio => radio.addEventListener('change', () => {
      form.querySelector('#vehWardWrap')?.classList.toggle('hidden', form.querySelector('input[name="vehKind"]:checked')?.value !== 'emr');
    }));
    this.fillWardSelect(form.querySelector('#vehWard'));

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

  /** ทะเบียนวอร์ด (โหลดครั้งเดียว ใช้ทั้งฟอร์มรถและป้ายวอร์ดบนการ์ดรถ) */
  async loadDepartments() {
    if (this.departments) return this.departments;
    try {
      const res = await window.departmentApi.getAll();
      this.departments = res.data || [];
    } catch (error) {
      console.error('โหลดทะเบียนวอร์ดไม่สำเร็จ:', error);
      return [];
    }
    return this.departments;
  }

  departmentName(id) {
    return (this.departments || []).find(d => String(d.id) === String(id))?.name || '';
  }

  async fillWardSelect(select) {
    if (!select) return;
    const current = select.dataset.value || '';
    const wards = await this.loadDepartments();
    const on = (d) => d.active === true || d.active === 1 || d.active === '1';
    // วอร์ดที่ปิดใช้งาน: แสดงเฉพาะวอร์ดที่รถคันนี้ประจำอยู่เดิม
    const list = wards.filter(d => on(d) || String(d.id) === current);
    select.innerHTML = '<option value="">— ยังไม่กำหนดวอร์ด —</option>'
      + list.map(d => `<option value="${UI.escape(d.id)}">${UI.escape(d.name)}${on(d) ? '' : ' (ปิดใช้งาน)'}</option>`).join('');
    select.value = current;
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
      vehicle_type: form.querySelector('input[name="vehKind"]:checked')?.value === 'emr' ? 'emr' : 'refer',
    };
    payload.home_department_id = payload.vehicle_type === 'emr' ? value('vehWard') : '';
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
      if (payload.vehicle_type !== (v.vehicle_type || 'refer') || payload.home_department_id !== (v.home_department_id || '')) {
        patch.vehicle_type = payload.vehicle_type;
        patch.home_department_id = payload.home_department_id;
      }
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