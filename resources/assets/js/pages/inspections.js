// ========================================
// PAGE: INSPECTION (ตรวจเช็คอุปกรณ์ประจำรถตามเวร)
// ========================================
// กติกาเดียวกับ InspectionService::evaluateLine ฝั่ง server (server ตรวจซ้ำทุกครั้ง):
//  กล่อง/รายชิ้น: กรอกเฉพาะ "ใช้ไป/หายไป" (ไม่กรอกวันหมดอายุ) → ระบบตัดแบบ FEFO → ขาดจากมาตรฐาน = ส่งไปหน้าเติมของ
//  ไม่มีวันหมดอายุ: พร้อม/ไม่พร้อมใช้ รายเครื่อง + แบต (ถ้ามีแบต) / แรงดัน PSI (ถ้าวัดแรงดัน) — ไม่ตัดสต็อก

const SHIFT_LABEL = { morning: 'เวรเช้า', afternoon: 'เวรบ่าย', night: 'เวรดึก' };
const ITEM_TYPE_LABEL = { box: '📦 กล่อง', individual: '🧩 รายชิ้น', non_expiring: '🩺 ไม่มีวันหมดอายุ' };
const RESULT_LABEL = {
  pending: '⏳ ยังไม่ตรวจ',
  complete: '✅ ตรวจแล้ว',
  needs_refill: '⚠️ ของขาด',
  anomaly: '⛔ ผิดปกติ',
};
const EXPIRY_LABEL = { expired: 'หมดอายุแล้ว', soon: 'ใกล้หมดอายุ', ok: '', unknown: 'ไม่ทราบวันหมดอายุ' };

class InspectionPage {
  constructor() {
    this.session = null;          // {mode, id, car_id, check_date, shift_code, revision, results}
    this.items = [];
    this.vehicles = [];
    this.nurses = [];
    this.selectedCarId = '';
    this.shiftStatus = null;      // ผลจาก /staff/inspections/shifts
    this.checkFilter = 'all';
    this.inspectorLocked = false;
    this.inspectorLockError = false;
    this.submitting = false;
    this.draftTimer = null;
    this.init();
  }

  async init() {
    this.bindEvents();
    await this.loadReferenceData();
    this.checkUrlParams();
  }

  async loadReferenceData() {
    try {
      const [vehiclesRes, nursesRes, shiftRes] = await Promise.all([
        window.vehicleApi.getAll(),
        window.nurseApi.getAll(),
        window.staffApi.getShiftStatus(),
      ]);
      this.vehicles = vehiclesRes.data || [];
      this.nurses = nursesRes.data || [];
      this.shiftStatus = shiftRes.data || null;
      window.vehicleRef.remember(this.vehicles);

      const dateEl = document.getElementById('inspectionDate');
      const shiftEl = document.getElementById('inspectionShift');
      if (this.shiftStatus && dateEl && !dateEl.value) {
        dateEl.value = this.shiftStatus.current.check_date;
        dateEl.max = this.shiftStatus.current.check_date;
      }
      if (this.shiftStatus && shiftEl && !shiftEl.value) {
        shiftEl.value = this.shiftStatus.current.shift_code;
      }

      this.renderVehicleSelection();
      this.renderNurseSelect();
      this.renderShiftHint();
    } catch (error) {
      console.error('Failed to load reference data:', error);
      window.toast.error('โหลดข้อมูลรถ/พยาบาลไม่สำเร็จ: ' + error.message);
    }
  }

  bindEvents() {
    document.getElementById('inspectionStartForm')?.addEventListener('submit', (e) => this.handleStartInspection(e));
    document.getElementById('inspectionShift')?.addEventListener('change', () => this.renderShiftHint());
    document.getElementById('inspectionDate')?.addEventListener('change', () => this.refreshShiftStatus());

    const table = document.getElementById('inspectionItemTable');
    if (table) {
      table.addEventListener('input', (e) => this.onFieldInput(e));
      table.addEventListener('click', (e) => this.onCardClick(e));
    }
  }

  checkUrlParams() {
    // Dashboard deep-links use "#check?car=..." — the query rides in the hash
    const hash = window.location.hash || '';
    const hashQuery = hash.includes('?') ? hash.slice(hash.indexOf('?') + 1) : '';
    const params = new URLSearchParams(hashQuery || window.location.search);
    const carId = params.get('car');
    if (carId) this.selectVehicle(carId);
  }

  // ---------------- หน้าเริ่มตรวจ ----------------

  async refreshShiftStatus() {
    const date = document.getElementById('inspectionDate')?.value;
    try {
      const res = await window.staffApi.getShiftStatus(date);
      this.shiftStatus = res.data;
    } catch (error) {
      window.toast.error(error.message);
    }
    this.renderVehicleSelection();
    this.renderShiftHint();
  }

  doneFor(carId) {
    return (this.shiftStatus && this.shiftStatus.done && this.shiftStatus.done[carId]) || {};
  }

  renderVehicleSelection() {
    const container = document.getElementById('inspectionCarGrid');
    if (!container) return;

    // รถ "งดใช้งาน" ยังอยู่ในทะเบียน/ประวัติ แต่ไม่อยู่ในรอบตรวจ — server ตรวจซ้ำที่ startInspection
    const usable = this.vehicles.filter(v => v.status !== 'disabled');
    const hiddenCount = this.vehicles.length - usable.length;

    const cards = usable.map(vehicle => {
      const done = this.doneFor(vehicle.id);
      const shifts = ['morning', 'afternoon', 'night']
        .map(code => `<span class="shift-dot ${done[code] ? 'done' : ''}" title="${SHIFT_LABEL[code]}${done[code] ? ' ตรวจแล้ว' : ' ยังไม่ตรวจ'}">${SHIFT_LABEL[code].replace('เวร', '')}${done[code] ? ' ✓' : ''}</span>`)
        .join('');
      const color = window.vehicleColor(vehicle);
      return `
      <button type="button" class="car-symbol" data-car-id="${UI.escape(vehicle.id)}"
        aria-pressed="${String(vehicle.id) === this.selectedCarId}"
        aria-label="เลือกรถ ${UI.escape(vehiclePlate(vehicle))}"
        style="border-color:${color};box-shadow: inset 0 6px 0 ${color}"
        onclick="inspectionPage.selectVehicle('${UI.escape(vehicle.id)}')">
        <img src="${this.getVehicleIcon()}" alt="" width="64" height="64">
        ${window.vehicleChip(vehicle)}
        <span class="car-selected-mark">✓</span>
        <span class="car-shifts">${shifts}</span>
      </button>`;
    }).join('');

    container.innerHTML = (cards || '<p class="empty">ยังไม่มีรถที่พร้อมตรวจเช็ค — เพิ่มรถที่แท็บ "ทะเบียนรถ/อุปกรณ์"</p>')
      + (hiddenCount > 0
        ? `<p class="car-grid-note">มีรถที่งดใช้งาน ${hiddenCount} คันไม่แสดงในรายการนี้ (เปิดกลับได้ที่แท็บ "ทะเบียนรถ/อุปกรณ์")</p>`
        : '');
  }

  selectVehicle(carId) {
    const vehicle = this.vehicles.find(v => String(v.id) === String(carId));
    if (vehicle && vehicle.status === 'disabled') {
      window.toast.warning(`รถ ${vehiclePlate(vehicle)} งดใช้งานอยู่ — เปิดกลับที่แท็บ "ทะเบียนรถ/อุปกรณ์" ก่อนเริ่มตรวจ`);
      return;
    }
    this.selectedCarId = String(carId);
    document.querySelectorAll('#inspectionCarGrid .car-symbol').forEach(btn => {
      btn.setAttribute('aria-pressed', btn.dataset.carId === this.selectedCarId);
    });
    this.renderShiftHint();
  }

  renderShiftHint() {
    const hint = document.getElementById('inspectionShiftHint');
    const btn = document.getElementById('inspectionStartBtn');
    if (!hint) return;
    const shift = document.getElementById('inspectionShift')?.value;
    if (!this.selectedCarId || !shift) {
      hint.textContent = '';
      if (btn) btn.textContent = 'เริ่มตรวจ';
      return;
    }
    const done = this.doneFor(this.selectedCarId)[shift];
    if (done) {
      hint.textContent = `✅ ${SHIFT_LABEL[shift]}ตรวจแล้ว (${done.nurse_name}) — กดเพื่อแก้ไข`;
      hint.className = 'shift-hint is-done';
      if (btn) btn.textContent = '✏️ แก้ไขรายการเดิม';
    } else {
      hint.textContent = `${SHIFT_LABEL[shift]} ยังไม่ตรวจ`;
      hint.className = 'shift-hint';
      if (btn) btn.textContent = 'เริ่มตรวจ';
    }
  }

  renderNurseSelect() {
    const select = document.getElementById('inspectionNurse');
    if (!select) return;
    select.innerHTML = '<option value="">-- เลือกรายชื่อพยาบาล --</option>' +
      this.nurses.filter(n => n.active).map(nurse => `
        <option value="${UI.escape(nurse.id)}">${UI.escape(nurse.name)} (${UI.escape(nurse.department)})</option>
      `).join('');
    this.applyInspectorLock(select);
  }

  getCurrentUserSafe() {
    const authUser = window.auth?.getUser?.();
    if (authUser) return authUser;
    try {
      return JSON.parse(localStorage.getItem('currentUser') || 'null');
    } catch (error) {
      return null;
    }
  }

  // Staff ถูกล็อกกับพยาบาลที่ผูกกับบัญชี (server บังคับซ้ำที่ StaffController::startInspection)
  applyInspectorLock(select) {
    const note = document.getElementById('inspectionNurseLock');
    const user = this.getCurrentUserSafe();
    this.inspectorLocked = false;
    this.inspectorLockError = false;

    if (!user || user.role === 'admin') {
      if (note) note.classList.add('hidden');
      return;
    }

    const nurse = this.nurses.find(n => String(n.id) === String(user.nurse_id || '') && n.active);
    select.disabled = true;
    if (nurse) {
      select.value = nurse.id;
      this.inspectorLocked = true;
      if (note) {
        note.textContent = `🔒 ผู้ตรวจ: ${nurse.name} — ล็อกตามบัญชีที่เข้าสู่ระบบ`;
        note.className = 'mt-1 text-xs font-semibold text-teal-700';
      }
    } else {
      this.inspectorLockError = true;
      if (note) {
        note.textContent = '⚠️ บัญชีนี้ยังไม่ได้เชื่อมโยงรายชื่อพยาบาลที่ใช้งานอยู่ กรุณาติดต่อผู้ดูแลระบบ';
        note.className = 'mt-1 text-xs font-semibold text-red-600';
      }
    }
  }

  getVehicleIcon() {
    // ต้อง URL-encode: double quote ใน data URI จะปิด attribute src ของ <img>
    const svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚑</text></svg>";
    return 'data:image/svg+xml,' + encodeURIComponent(svg);
  }

  async handleStartInspection(event, replace = false) {
    if (event) event.preventDefault();
    if (this.inspectorLockError) {
      window.toast.error('บัญชีนี้ยังไม่ได้เชื่อมโยงรายชื่อพยาบาล กรุณาติดต่อผู้ดูแลระบบ');
      return;
    }
    const nurseId = document.getElementById('inspectionNurse')?.value;
    const shift = document.getElementById('inspectionShift')?.value;
    const date = document.getElementById('inspectionDate')?.value;
    if (!this.selectedCarId || !nurseId || !shift || !date) {
      window.toast.warning('เลือกรถ ผู้ตรวจ วันที่ และเวร');
      return;
    }

    const btn = document.getElementById('inspectionStartBtn');
    UI.setLoading(btn, true);
    try {
      const res = await window.staffApi.startInspection({
        car_id: this.selectedCarId, nurse_id: nurseId, shift_code: shift, check_date: date, replace,
      });
      this.openSession(res.data);
    } catch (error) {
      const code = error.data && error.data.code;
      if (error.status === 409 && code === 'DRAFT_EXISTS') {
        const d = error.data.draft || {};
        const ok = await window.confirmDialog(
          `รถคันนี้มีร่างการตรวจของ ${d.nurse_name || 'ผู้ตรวจท่านอื่น'} (${d.check_date || ''} เวร${d.shift_name || '-'}) ค้างอยู่\n` +
          'ต้องการเริ่มการตรวจใหม่แทนที่ร่างนั้นหรือไม่? (ร่างเดิมจะถูกลบ ไม่กระทบยอดคงเหลือ)',
          { confirmText: 'แทนที่ร่างเดิม', danger: true }
        );
        if (ok) await this.handleStartInspection(null, true);
      } else {
        window.toast.error(error.message);
      }
    } finally {
      UI.setLoading(btn, false);
    }
  }

  openSession(data) {
    this.session = data;
    this.items = (data.results || []).map(item => this.initItemState(item));
    this.checkFilter = 'all';

    const vehicle = this.vehicles.find(v => String(v.id) === String(data.car_id)) || { id: data.car_id, name: data.car_name };
    const info = document.getElementById('inspectionLoginInfo');
    if (info) {
      info.innerHTML = `${window.vehicleChip(vehicle)} <span>ผู้ตรวจ: <b>${UI.escape(data.nurse_name)}</b></span>
        <span>วันที่ ${UI.escape(UI.formatDateThai(data.check_date))}</span>
        <span class="shift-badge">${UI.escape(SHIFT_LABEL[data.shift_code] || data.shift_name)}</span>`;
    }
    const modeNote = document.getElementById('inspectionModeNote');
    if (modeNote) {
      const text = {
        edit: `✏️ แก้ไขรายการตรวจเดิม (ครั้งที่แก้ #${data.revision}) — เมื่อบันทึก ระบบจะคำนวณยอดใหม่จากส่วนต่าง ไม่หักซ้ำ และเก็บค่าเดิมไว้ในประวัติ`,
        draft: '↩️ ทำต่อจากร่างที่บันทึกไว้',
        new: '',
      }[data.mode] || '';
      modeNote.textContent = text;
      modeNote.classList.toggle('hidden', !text);
    }

    document.getElementById('inspectionLoginPage').classList.add('hidden');
    document.getElementById('inspectionCheckPage').classList.remove('hidden');
    this.renderList();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  initItemState(item) {
    const units = (item.units || []).map(u => ({ ...u }));
    return {
      ...item,
      // ใช้ไป/หายไป: '' = ยังไม่ได้ตรวจรายการนี้ (กด "ไม่ได้ใช้" = 0)
      used: item.used === null || item.used === undefined || (!item.confirmed && Number(item.used) === 0) ? '' : String(item.used),
      note: item.note || '',
      units,
      // เครื่องที่ยังไม่ได้ตรวจในรอบนี้ = ยังไม่ตรวจ (ค่าที่โหลดมาเป็นสถานะล่าสุด ไม่ใช่ผลรอบนี้)
      unitTouched: Object.fromEntries(units.map(u => [u.id, !!u.checked])),
    };
  }

  // ---------------- คำนวณผลรายรายการ (ตรงกับ InspectionService::evaluateLine) ----------------

  num(value) {
    if (value === '' || value === null || value === undefined) return null;
    const n = Number(value);
    return Number.isFinite(n) ? Math.round(n * 100) / 100 : NaN;
  }

  fmt(value) {
    if (value === null || value === undefined || value === '' || Number.isNaN(value)) return '-';
    return String(Math.round(Number(value) * 100) / 100);
  }

  isUnitType(item) {
    return item.item_type === 'non_expiring';
  }

  notStocked(item) {
    return !this.isUnitType(item) && (item.before === null || item.before === undefined);
  }

  unitWord(item) {
    return item.has_pressure ? 'ถัง' : 'เครื่อง';
  }

  evaluate(item) {
    const target = item.target_qty === null || item.target_qty === undefined ? null : Number(item.target_qty);
    const unit = item.unit ? ' ' + item.unit : '';

    if (this.isUnitType(item)) {
      const word = this.unitWord(item);
      const short = target !== null ? Math.max(0, target - item.units.length) : 0;
      if (!item.units.length) {
        if (short > 0) return { result: 'needs_refill', short, text: `ยังไม่มี${word}บนรถ — ต้องเติม ${this.fmt(short)} ${word}` };
        return item.note.trim()
          ? { result: 'complete', text: `ไม่มี${word}บนรถ` }
          : { result: 'pending', text: `ยังไม่มี${word}บนรถ — ระบุหมายเหตุ` };
      }
      const notReady = [];
      let low = 0;
      for (const u of item.units) {
        const name = u.label || word;
        if (!item.unitTouched[u.id] || !['ready', 'not_ready', 'no_power'].includes(u.status)) {
          return { result: 'pending', text: `ยังไม่ได้ตรวจ ${name}` };
        }
        if (item.has_battery && u.status !== 'no_power' && !(u.battery_level >= 0 && u.battery_level <= item.battery_max_bars)) {
          return { result: 'pending', text: `เลือกระดับแบตของ ${name} (0-${item.battery_max_bars} ขีด)` };
        }
        if (item.has_pressure && (u.pressure_psi === '' || u.pressure_psi === null || u.pressure_psi === undefined || !(Number(u.pressure_psi) >= 0))) {
          return { result: 'pending', text: `กรอกแรงดัน (PSI) ของ ${name}` };
        }
        if (u.status !== 'ready') {
          if (!String(u.note || '').trim()) return { result: 'error', text: `${name}: ไม่พร้อมใช้ ต้องระบุสาเหตุ` };
          notReady.push(name);
        } else if (item.has_battery && item.battery_low_bars !== null && u.battery_level <= item.battery_low_bars) {
          low++;
        } else if (item.has_pressure && item.o2_low_psi !== null && Number(u.pressure_psi) < item.o2_low_psi) {
          low++;
        }
      }
      if (notReady.length) return { result: 'anomaly', short, notReady, text: `ไม่พร้อมใช้ ${notReady.length} ${word} → ตรวจสอบ/เปลี่ยน` };
      if (short > 0) return { result: 'needs_refill', short, text: `${word}ไม่ครบ ขาด ${this.fmt(short)}` };
      if (low) return { result: 'needs_refill', low, text: item.has_pressure ? `แรงดันต่ำ ${low} ถัง` : `แบตเตอรี่ต่ำ ${low} เครื่อง` };
      return { result: 'complete', text: 'พร้อมใช้' };
    }

    // ยังไม่ได้จัดขึ้นรถ — ไม่มีอะไรให้ตัด ส่งไปเติมทั้งจำนวนมาตรฐาน
    if (this.notStocked(item)) {
      return target !== null && target > 0
        ? { result: 'needs_refill', short: target, text: `ยังไม่ได้จัดขึ้นรถ — ต้องเติม ${this.fmt(target)}${unit}` }
        : { result: 'complete', text: 'ยังไม่ได้จัดขึ้นรถ' };
    }

    const before = Number(item.before);
    if (item.used === '') return { result: 'pending', text: 'กรอกจำนวนที่ใช้ไป/หายไป หรือกด "ไม่ได้ใช้"' };
    const used = this.num(item.used);
    if (Number.isNaN(used) || used < 0) return { result: 'error', text: 'ต้องเป็นตัวเลขไม่ติดลบ' };
    if (item.item_type === 'individual' && !Number.isInteger(used)) return { result: 'error', text: 'ของแบบชิ้นต้องเป็นจำนวนเต็ม' };
    if (used > before) return { result: 'error', text: `ใช้ไป/หายไปมากกว่าที่มีบนรถ (${this.fmt(before)}${unit})` };
    const left = Math.round((before - used) * 100) / 100;
    const expiredLeft = Number((item.stock && item.stock.expired) || 0);
    const usable = Math.max(0, left - expiredLeft);
    if (target !== null && usable < target) {
      const short = Math.round((target - usable) * 100) / 100;
      return { result: 'needs_refill', used, left, short, text: `คงเหลือ ${this.fmt(left)} • ต้องเติม ${this.fmt(short)}${unit}${expiredLeft ? ` (หมดอายุ ${this.fmt(expiredLeft)})` : ''}` };
    }
    return { result: 'complete', used, left, text: `คงเหลือ ${this.fmt(left)}${unit}` };
  }

  // ---------------- แสดงผล ----------------

  renderList() {
    const container = document.getElementById('inspectionItemTable');
    if (!container) return;
    const rows = this.items
      .map((item, index) => ({ item, index, ev: this.evaluate(item) }))
      .filter(({ ev }) => this.checkFilter === 'all'
        || (this.checkFilter === 'pending' && (ev.result === 'pending' || ev.result === 'error'))
        || (this.checkFilter === 'attn' && (ev.result === 'needs_refill' || ev.result === 'anomaly')));
    container.innerHTML = rows.length
      ? rows.map(({ item, index }) => `<div class="check-card" data-index="${index}">${this.renderCard(item, index)}</div>`).join('')
      : '<p class="empty">ไม่มีรายการในตัวกรองนี้</p>';
    this.updateProgress();
  }

  typeBadge(item) {
    if (item.item_type === 'non_expiring') {
      return item.has_pressure ? '🫧 ไม่มีวันหมดอายุ · PSI' : (item.has_battery ? '🔋 ไม่มีวันหมดอายุ · แบต' : '🩺 ไม่มีวันหมดอายุ');
    }
    return ITEM_TYPE_LABEL[item.item_type] || '';
  }

  renderCard(item, index) {
    const ev = this.evaluate(item);
    const unit = item.unit ? ' ' + UI.escape(item.unit) : '';
    const std = item.target_qty === null ? UI.escape(item.standard || '-') : this.fmt(item.target_qty) + unit;
    const onHand = this.isUnitType(item)
      ? `${item.units.length} ${this.unitWord(item)}`
      : (this.notStocked(item) ? 'ยังไม่ได้จัดขึ้นรถ' : this.fmt(item.before) + unit);
    const head = `
      <div class="check-card-head">
        <div>
          <strong>${UI.escape(item.name)}</strong>
          <span class="type-badge type-${UI.escape(item.item_type)}">${this.typeBadge(item)}</span>
        </div>
        <span class="result-chip result-${ev.result}" data-role="chip">${RESULT_LABEL[ev.result] || '⚠️ แก้ไข'}</span>
      </div>
      <p class="qty-big">มาตรฐาน <b>${std}</b> · บนรถ <b>${onHand}</b></p>`;
    const body = this.isUnitType(item) ? this.renderUnits(item) : this.renderCounted(item, index);
    return head + body + `<p class="check-msg msg-${ev.result}" data-role="msg">${UI.escape(ev.text || '')}</p>`;
  }

  // คำเตือนเรื่องความปลอดภัย (หมดอายุ/ใกล้หมดอายุ) แสดงบนการ์ดเสมอ — ผู้ตรวจไม่ต้องกรอกวันหมดอายุ
  stockSummary(item) {
    const s = item.stock || {};
    const parts = [];
    if (Number(s.expired) > 0) parts.push(`<span class="txt-bad">⛔ หมดอายุแล้ว ${this.fmt(s.expired)}</span>`);
    const soon = (item.lots || []).filter(l => l.state === 'soon');
    if (soon.length) parts.push(`<span class="txt-warn">⚠️ ใกล้หมดอายุ ${this.fmt(soon.reduce((n, l) => n + Number(l.qty), 0))} (${UI.escape(UI.formatDateThai(soon[0].expiry_date))})</span>`);
    return parts.length ? `<div class="qty-line">${parts.join(' ')}</div>` : '';
  }

  renderCounted(item, index) {
    const id = (f) => `chk-${index}-${f}`;
    if (this.notStocked(item)) {
      return '<p class="text-sm text-amber-700">ยังไม่มีของนี้บนรถ — จัดขึ้นรถที่ “เติมของ” (ไม่ต้องกรอก)</p>';
    }
    return `
      ${this.stockSummary(item)}
      <div class="check-grid">
        <div>
          <label for="${id('used')}">ใช้ไป / หายไป</label>
          ${UI.stepper(`id="${id('used')}" min="0" max="${UI.escape(String(item.before))}" data-field="used" value="${UI.escape(item.used)}" placeholder="0"`, 'ใช้ไปหรือหายไป')}
        </div>
        <button type="button" class="confirm-btn ${item.used !== '' && Number(item.used) === 0 ? 'on' : ''}" data-action="none-used"
          aria-pressed="${item.used !== '' && Number(item.used) === 0}">✓ ไม่ได้ใช้</button>
      </div>
      <label for="${id('note')}">หมายเหตุ</label>
      <input id="${id('note')}" type="text" maxlength="200" data-field="note" value="${UI.escape(item.note.trim())}">`;
  }

  renderUnits(item) {
    const word = this.unitWord(item);
    if (!item.units.length) {
      return `<p class="text-sm text-amber-700">ยังไม่มี${word}บนรถ — เพิ่มที่ “เติมของ”</p>
        <label for="unote-${UI.escape(item.id)}">หมายเหตุ</label>
        <input id="unote-${UI.escape(item.id)}" type="text" maxlength="200" data-field="note" value="${UI.escape(item.note)}">`;
    }
    const statuses = item.has_battery
      ? [['ready', '✓ พร้อมใช้'], ['not_ready', '✗ ไม่พร้อม'], ['no_power', '⏻ เปิดไม่ติด']]
      : [['ready', '✓ พร้อมใช้'], ['not_ready', '✗ ไม่พร้อม']];
    return '<div class="unit-list">' + item.units.map((u, ui) => {
      const touched = item.unitTouched[u.id];
      const uid = `u-${UI.escape(u.id)}`;
      const last = u.updated_at ? `<small class="unit-last">ล่าสุด ${u.battery_level !== null && u.battery_level !== undefined ? `แบต ${u.battery_level}/${item.battery_max_bars} ` : ''}${u.pressure_psi !== null && u.pressure_psi !== undefined ? `${u.pressure_psi} PSI ` : ''}• ${UI.escape(UI.formatDateThai(String(u.updated_at).slice(0, 10)))}${u.updated_by ? ' ' + UI.escape(u.updated_by) : ''}</small>` : '';
      const psi = item.has_pressure
        ? `<label for="${uid}-psi">แรงดัน</label>
           <div class="psi-input"><input id="${uid}-psi" type="number" min="0" max="10000" step="1" inputmode="numeric" data-field="unit-psi" value="${touched && u.pressure_psi !== null && u.pressure_psi !== undefined && u.pressure_psi !== '' ? UI.escape(String(u.pressure_psi)) : ''}"><span class="psi-unit">PSI</span></div>
           ${item.o2_low_psi !== null ? `<small>ต่ำเมื่อ &lt; ${item.o2_low_psi} PSI</small>` : ''}`
        : '';
      const bat = item.has_battery && !(u.status === 'no_power' && touched)
        ? `<div class="unit-name">แบตเตอรี่ (ขีด)</div><div class="battery-row" role="group" aria-label="ระดับแบตเตอรี่">
            ${Array.from({ length: item.battery_max_bars + 1 }, (_, k) => k).map(level => `<button type="button" class="bat-btn ${touched && u.battery_level === level ? 'on' : ''} ${item.battery_low_bars !== null && level <= item.battery_low_bars ? 'low' : ''}" data-action="unit-battery" data-value="${level}" aria-pressed="${touched && u.battery_level === level}" aria-label="แบต ${level} จาก ${item.battery_max_bars} ขีด">${level}</button>`).join('')}
          </div>`
        : '';
      return `
      <div class="unit-row ${touched ? '' : 'untouched'}" data-unit="${ui}">
        <div class="unit-name">${UI.escape(u.label || `${word}ที่ ${ui + 1}`)} ${last}</div>
        <div class="seg" role="group" aria-label="สถานะ">
          ${statuses.map(([v, l]) => `<button type="button" class="seg-btn ${v !== 'ready' ? 'danger' : ''} ${touched && u.status === v ? 'on' : ''}" data-action="unit-status" data-value="${v}" aria-pressed="${touched && u.status === v}">${l}</button>`).join('')}
        </div>
        ${bat}${psi}
        <label for="${uid}-note" class="${touched && u.status !== 'ready' ? '' : 'hidden'}">สาเหตุ *</label>
        <input id="${uid}-note" type="text" maxlength="200" class="unit-note ${touched && u.status !== 'ready' ? '' : 'hidden'}" data-field="unit-note" value="${UI.escape(u.note || '')}">
      </div>`;
    }).join('') + '</div>';
  }

  refreshCard(index) {
    const card = document.querySelector(`#inspectionItemTable .check-card[data-index="${index}"]`);
    if (!card) return;
    const ev = this.evaluate(this.items[index]);
    const chip = card.querySelector('[data-role="chip"]');
    if (chip) {
      chip.className = `result-chip result-${ev.result}`;
      chip.textContent = RESULT_LABEL[ev.result] || '⚠️ กรอกไม่ถูกต้อง';
    }
    const msg = card.querySelector('[data-role="msg"]');
    if (msg) {
      msg.className = `check-msg msg-${ev.result}`;
      msg.textContent = ev.text || '';
    }
    const none = card.querySelector('[data-action="none-used"]');
    if (none) {
      const on = this.items[index].used !== '' && Number(this.items[index].used) === 0;
      none.classList.toggle('on', on);
      none.setAttribute('aria-pressed', String(on));
    }
    this.updateProgress();
  }

  updateProgress() {
    const evs = this.items.map(item => this.evaluate(item).result);
    const total = evs.length;
    const done = evs.filter(r => r === 'complete' || r === 'needs_refill' || r === 'anomaly').length;
    const attn = evs.filter(r => r === 'needs_refill' || r === 'anomaly').length;
    const pct = total ? Math.round(done * 100 / total) : 0;
    const fill = document.getElementById('inspectionProgressFill');
    if (fill) fill.style.width = pct + '%';
    const text = document.getElementById('inspectionProgressText');
    if (text) text.textContent = `ตรวจแล้ว ${done}/${total} รายการ`;
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('inspectionCntAll', total);
    set('inspectionCntPending', total - done);
    set('inspectionCntAttn', attn);
    const hint = document.getElementById('inspectionSaveHint');
    if (hint) hint.textContent = done < total ? `เหลืออีก ${total - done} รายการก่อนบันทึก` : 'ตรวจครบแล้ว พร้อมบันทึก';
  }

  // ---------------- การกรอก ----------------

  onFieldInput(event) {
    const el = event.target;
    const field = el.dataset.field;
    const card = el.closest('.check-card');
    if (!field || !card) return;
    const index = Number(card.dataset.index);
    const item = this.items[index];
    if (field === 'unit-note' || field === 'unit-psi') {
      const unit = item.units[Number(el.closest('.unit-row').dataset.unit)];
      if (field === 'unit-note') unit.note = el.value;
      else {
        unit.pressure_psi = el.value === '' ? '' : Number(el.value);
        if (!item.unitTouched[unit.id]) {
          item.unitTouched[unit.id] = true;
          if (!['ready', 'not_ready'].includes(unit.status)) unit.status = '';
        }
      }
    } else {
      item[field] = el.value;
    }
    this.refreshCard(index);
    this.scheduleDraft();
  }

  onCardClick(event) {
    const btn = event.target.closest('[data-action]');
    const card = event.target.closest('.check-card');
    if (!btn || !card) return;
    const index = Number(card.dataset.index);
    const item = this.items[index];
    const action = btn.dataset.action;

    if (action === 'none-used') {
      item.used = '0';
    } else if (action === 'unit-status' || action === 'unit-battery') {
      const unit = item.units[Number(btn.closest('.unit-row').dataset.unit)];
      if (!item.unitTouched[unit.id]) {
        // แตะครั้งแรก: ค่าที่ยังไม่ได้เลือกในรอบนี้ต้องเลือกเอง (ไม่ใช้ค่าค้างจากรอบก่อนโดยไม่รู้ตัว)
        item.unitTouched[unit.id] = true;
        if (action === 'unit-status') { unit.battery_level = null; unit.pressure_psi = ''; } else unit.status = '';
      }
      if (action === 'unit-status') unit.status = btn.dataset.value;
      else unit.battery_level = Number(btn.dataset.value);
    } else {
      return;
    }
    card.innerHTML = this.renderCard(item, index);
    this.updateProgress();
    this.scheduleDraft();
  }

  setFilter(filter) {
    this.checkFilter = filter;
    ['All', 'Pending', 'Attn'].forEach(name => {
      document.getElementById('inspectionChip' + name)?.classList.toggle('on', name.toLowerCase() === filter);
    });
    this.renderList();
  }

  async checkAll() {
    const pending = this.items.filter(item => !this.isUnitType(item) && !this.notStocked(item) && item.used === '');
    if (!pending.length) {
      window.toast.info('ไม่มีรายการที่ตั้งเป็น "ไม่ได้ใช้" ได้ (อุปกรณ์ไม่มีวันหมดอายุต้องตรวจทีละเครื่อง)');
      return;
    }
    const ok = await window.confirmDialog(
      `${pending.length} รายการที่ยังไม่กรอก: ไม่ได้ใช้ ไม่มีหาย?\nถ้ามีรายการที่ใช้ไปให้กรอกก่อน`,
      { confirmText: 'ไม่ได้ใช้ทั้งหมด' }
    );
    if (!ok) return;
    pending.forEach(item => { item.used = '0'; });
    this.renderList();
    this.scheduleDraft();
  }

  // ---------------- ร่าง / บันทึก ----------------

  payloadLines() {
    return this.items.map(item => ({
      id: item.id,
      used: item.used === '' ? 0 : item.used,
      confirmed: item.used !== '',   // ร่าง: แยก "ยังไม่ตรวจ" กับ "ไม่ได้ใช้ (0)"
      note: item.note.trim(),
      units: item.units
        .filter(u => item.unitTouched[u.id])
        .map(u => ({
          id: u.id, status: u.status, note: u.note || '',
          battery_level: u.battery_level, pressure_psi: u.pressure_psi === '' ? null : u.pressure_psi,
        })),
    }));
  }

  scheduleDraft() {
    if (!this.session || this.session.mode === 'edit') return;   // รายการที่บันทึกแล้วไม่มีร่าง
    clearTimeout(this.draftTimer);
    const note = document.getElementById('inspectionAutosaveNote');
    if (note) note.textContent = 'กำลังจะบันทึกร่าง…';
    this.draftTimer = setTimeout(() => this.saveDraft(), 1500);
  }

  async saveDraft() {
    if (!this.session || this.session.mode === 'edit') return;
    const note = document.getElementById('inspectionAutosaveNote');
    try {
      await window.staffApi.saveDraft({ car_id: this.session.car_id, results: this.payloadLines() });
      if (note) note.textContent = '💾 บันทึกร่างอัตโนมัติแล้ว ' + new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
    } catch (error) {
      if (note) note.textContent = '⚠️ บันทึกร่างไม่สำเร็จ: ' + error.message;
    }
  }

  // สรุปก่อนบันทึก: ใช้ไป / ต้องเติม / ไม่พร้อมใช้ / แบตหรือแรงดันต่ำ
  summaryText(evs) {
    const unitOf = (item) => (item.unit ? ' ' + item.unit : '');
    const used = evs.filter(({ ev }) => ev.used > 0).map(({ item, ev }) => `${item.name} ${this.fmt(ev.used)}${unitOf(item)}`);
    const refill = evs.filter(({ ev }) => ev.short > 0).map(({ item, ev }) => `${item.name} ${this.fmt(ev.short)}${unitOf(item)}`);
    const broken = evs.filter(({ ev }) => ev.notReady).map(({ item, ev }) => `${item.name} (${ev.notReady.join(', ')})`);
    const low = evs.filter(({ ev }) => ev.low).map(({ item }) => item.name);
    const block = (title, list) => (list.length ? `\n${title} ${list.length} รายการ\n• ${list.slice(0, 8).join('\n• ')}${list.length > 8 ? `\n• … อีก ${list.length - 8}` : ''}` : '');
    return `ตรวจครบ ${evs.length} รายการ`
      + block('📉 ใช้ไป/หายไป', used)
      + block('🧰 ต้องเติม', refill)
      + block('⛔ ไม่พร้อมใช้ (ตรวจสอบ/เปลี่ยน)', broken)
      + block('🔋 แบต/แรงดันต่ำ', low)
      + (used.length + refill.length + broken.length + low.length === 0 ? '\n✅ ไม่มีของใช้ไปหรือขาด' : '');
  }

  async submitInspection() {
    if (!this.session || this.submitting) return;   // กันกดซ้ำระหว่างส่ง
    const evs = this.items.map(item => ({ item, ev: this.evaluate(item) }));
    const notReady = evs.filter(({ ev }) => ev.result === 'pending' || ev.result === 'error');
    if (notReady.length) {
      window.toast.warning(`ยังไม่ครบ ${notReady.length} รายการ — ${notReady[0].item.name}: ${notReady[0].ev.text}`);
      this.setFilter('pending');
      return;
    }

    const ok = await window.confirmDialog(this.summaryText(evs) + '\n\nบันทึกผลตรวจนี้?', { confirmText: 'บันทึก' });
    if (!ok) return;

    clearTimeout(this.draftTimer);
    this.submitting = true;
    const btn = document.getElementById('inspectionSubmitBtn');
    UI.setLoading(btn, true);
    try {
      const res = await window.staffApi.submitInspection({
        car_id: this.session.car_id,
        check_id: this.session.id,
        mode: this.session.mode === 'edit' ? 'edit' : 'new',
        revision: this.session.revision,
        results: this.payloadLines(),
      });
      const refill = (res.data || {}).refill || {};
      window.toast.success('บันทึกสำเร็จ' + (refill.created ? ` • ส่งไปหน้าเติมของ ${refill.line_count} รายการ` : ''), 7000);
      this.closeSession();
      await this.refreshShiftStatus();
    } catch (error) {
      const list = error.data && Array.isArray(error.data.errors) ? '\n• ' + error.data.errors.slice(0, 5).join('\n• ') : '';
      window.toast.error('บันทึกไม่สำเร็จ — ' + error.message + list, 9000);
      if (error.data && error.data.code === 'STALE_REVISION') this.closeSession();
    } finally {
      this.submitting = false;
      UI.setLoading(btn, false);
    }
  }

  closeSession() {
    this.session = null;
    this.items = [];
    document.getElementById('inspectionCheckPage').classList.add('hidden');
    document.getElementById('inspectionLoginPage').classList.remove('hidden');
  }

  async cancelInspection() {
    const text = this.session && this.session.mode === 'edit'
      ? 'ออกโดยไม่บันทึก? (รายการเดิมไม่เปลี่ยน)'
      : 'ออกจากหน้าตรวจ? (บันทึกร่างไว้แล้ว)';
    if (!(await window.confirmDialog(text, { confirmText: 'ออก' }))) return;
    if (this.session && this.session.mode !== 'edit') await this.saveDraft();
    this.closeSession();
  }
}

// Initialize
let inspectionPage;
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('tabContentCheck') && !document.getElementById('tabContentCheck').classList.contains('hidden')) {
    inspectionPage = new InspectionPage();
    window.inspectionPage = inspectionPage;
  }
});

// Export for tab switching
window.initInspection = () => {
  if (!inspectionPage) {
    inspectionPage = new InspectionPage();
    window.inspectionPage = inspectionPage;
  } else {
    inspectionPage.refreshShiftStatus();
    inspectionPage.checkUrlParams();
  }
};

// Global functions for inline handlers
window.setInspectionFilter = (filter) => inspectionPage?.setFilter(filter);
window.checkAllInspection = () => inspectionPage?.checkAll();
window.submitInspection = () => inspectionPage?.submitInspection();
window.cancelInspection = () => inspectionPage?.cancelInspection();
