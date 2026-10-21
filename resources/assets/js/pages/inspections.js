// ========================================
// PAGE: INSPECTION (ตรวจเช็คอุปกรณ์ประจำรถตามเวร)
// ========================================
// กติกาเดียวกับ InspectionService::evaluateLine ฝั่ง server (server ตรวจซ้ำทุกครั้ง):
//  ของสิ้นเปลือง: ใช้ไป (0 ได้) → คงเหลือที่ควรมี = ก่อนตรวจ − ใช้ไป → ✓ ครบถ้วน หรือ กรอกนับได้จริง (+หมายเหตุ)
//  เครื่องไฟฟ้า: พร้อม/ไม่พร้อมใช้ + แบต 1-4 ขีด รายเครื่อง (ไม่ตัดสต๊อค)

const SHIFT_LABEL = { morning: 'เวรเช้า', afternoon: 'เวรบ่าย', night: 'เวรดึก' };
const ITEM_TYPE_LABEL = { box: '📦 กล่อง', piece: '🧩 ชิ้น', electric: '🔌 ไฟฟ้า' };
const RESULT_LABEL = {
  pending: 'ยังไม่ได้ตรวจสอบ',
  complete: 'ตรวจสอบแล้ว ครบถ้วน',
  needs_refill: 'ตรวจสอบแล้ว ต้องเติม',
  anomaly: 'ตรวจสอบแล้ว พบความผิดปกติ',
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
      if (btn) btn.textContent = 'เริ่มตรวจเช็ค';
      return;
    }
    const done = this.doneFor(this.selectedCarId)[shift];
    if (done) {
      hint.textContent = `ℹ️ ${SHIFT_LABEL[shift]}ของรถคันนี้ตรวจแล้วโดย ${done.nurse_name} — กดเพื่อเปิดรายการเดิมมาแก้ไข (ไม่สร้างรายการซ้ำ)`;
      hint.className = 'shift-hint is-done';
      if (btn) btn.textContent = '✏️ เปิดแก้ไขรายการเดิม';
    } else {
      hint.textContent = `${SHIFT_LABEL[shift]}ของรถคันนี้ยังไม่ได้ตรวจ`;
      hint.className = 'shift-hint';
      if (btn) btn.textContent = 'เริ่มตรวจเช็ค';
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
      window.toast.warning('กรุณาเลือกรถ ผู้ตรวจ วันที่ และเวรให้ครบ');
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
      used: item.used === null || item.used === undefined ? '' : String(item.used),
      actual: item.actual === null || item.actual === undefined ? '' : String(item.actual),
      confirmed: !!item.confirmed,
      note: item.note || '',
      units,
      // เครื่องที่ยังไม่ได้ตรวจในรอบนี้ = ยังไม่ตรวจ (ค่าที่โหลดมาเป็นสถานะล่าสุด ไม่ใช่ผลรอบนี้)
      unitTouched: Object.fromEntries(units.map(u => [u.id, !!u.checked])),
    };
  }

  // ---------------- คำนวณผลรายรายการ (ตรงกับ server) ----------------

  num(value) {
    if (value === '' || value === null || value === undefined) return null;
    const n = Number(value);
    return Number.isFinite(n) ? Math.round(n * 100) / 100 : NaN;
  }

  fmt(value) {
    if (value === null || value === undefined || value === '' || Number.isNaN(value)) return '-';
    return String(Math.round(Number(value) * 100) / 100);
  }

  evaluate(item) {
    const target = item.target_qty === null || item.target_qty === undefined ? null : Number(item.target_qty);

    if (item.item_type === 'electric') {
      if (!item.units.length) {
        return item.note.trim()
          ? { result: 'anomaly', text: 'ไม่มีเครื่องลงทะเบียนบนรถ' }
          : { result: 'pending', text: 'ยังไม่มีเครื่องลงทะเบียน — ระบุหมายเหตุ หรือเพิ่มเครื่องที่หน้าเติมอุปกรณ์' };
      }
      for (const u of item.units) {
        if (!item.unitTouched[u.id]) return { result: 'pending', text: `ยังไม่ได้ตรวจ ${u.label || 'เครื่อง'}` };
        if (!(u.battery_level >= 1 && u.battery_level <= 4)) return { result: 'pending', text: 'เลือกระดับแบต 1-4 ขีด' };
        if (u.status === 'not_ready' && !String(u.note || '').trim()) return { result: 'error', text: 'เครื่องไม่พร้อมใช้งาน ต้องระบุสาเหตุ' };
      }
      const notReady = item.units.filter(u => u.status === 'not_ready').length;
      const low = item.units.filter(u => u.battery_level <= 1).length;
      return notReady
        ? { result: 'anomaly', text: `ไม่พร้อมใช้งาน ${notReady} เครื่อง` }
        : { result: 'complete', text: low ? `พร้อมใช้งาน (แบตต่ำ ${low} เครื่อง)` : 'พร้อมใช้งานทุกเครื่อง' };
    }

    // ยังไม่เคยตรวจนับ → ตั้งยอดครั้งแรกด้วยจำนวนนับจริง
    if (item.before === null || item.before === undefined) {
      const actual = this.num(item.actual);
      if (actual === null) return { result: 'pending', text: 'ยังไม่เคยตรวจนับ — กรอกจำนวนที่นับได้จริง' };
      if (Number.isNaN(actual) || actual < 0) return { result: 'error', text: 'จำนวนต้องเป็นตัวเลขไม่ติดลบ' };
      return { result: target !== null && actual < target ? 'needs_refill' : 'complete', actual, text: 'ตั้งยอดครั้งแรก' };
    }

    const before = Number(item.before);
    const used = item.used === '' ? 0 : this.num(item.used);
    if (Number.isNaN(used) || used < 0) return { result: 'error', text: 'ใช้ไปต้องเป็นตัวเลขไม่ติดลบ' };
    if (used > before) return { result: 'error', text: `ใช้ไปมากกว่ายอดบนรถ (${this.fmt(before)}) — ถ้านับได้ไม่ตรงให้กรอก "นับได้จริง" แทน` };
    const expected = Math.round((before - used) * 100) / 100;

    let actual = null;
    if (item.confirmed) actual = expected;
    else if (item.actual !== '') actual = this.num(item.actual);
    if (actual === null) return { result: 'pending', expected, used, text: 'กด ✓ ครบถ้วน หรือกรอกจำนวนนับได้จริง' };
    if (Number.isNaN(actual) || actual < 0) return { result: 'error', expected, used, text: 'จำนวนนับได้จริงต้องเป็นตัวเลขไม่ติดลบ' };

    const diff = Math.round((actual - expected) * 100) / 100;
    if (diff !== 0) {
      if (!item.note.trim()) return { result: 'error', expected, used, actual, diff, text: `นับได้ ${this.fmt(actual)} ไม่ตรงกับที่คาด ${this.fmt(expected)} — ต้องระบุหมายเหตุ` };
      return { result: 'anomaly', expected, used, actual, diff, text: `คลาดเคลื่อน ${diff > 0 ? '+' : ''}${this.fmt(diff)}` };
    }
    if (target !== null && actual < target) {
      return { result: 'needs_refill', expected, used, actual, diff, text: `ต้องเติม ${this.fmt(target - actual)}` };
    }
    return { result: 'complete', expected, used, actual, diff, text: used > 0 ? `ใช้ไป ${this.fmt(used)} ยังครบตามเกณฑ์` : 'ครบตามเกณฑ์' };
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

  renderCard(item, index) {
    const ev = this.evaluate(item);
    const unit = item.unit ? ' ' + UI.escape(item.unit) : '';
    const head = `
      <div class="check-card-head">
        <div>
          <strong>${UI.escape(item.name)}</strong>
          <span class="type-badge type-${UI.escape(item.item_type)}">${ITEM_TYPE_LABEL[item.item_type] || ''}</span>
          <div class="check-sub">เป้าหมาย ${item.target_qty === null ? UI.escape(item.standard || '-') : this.fmt(item.target_qty) + unit}</div>
        </div>
        <span class="result-chip result-${ev.result}" data-role="chip">${RESULT_LABEL[ev.result] || '⚠️ กรอกไม่ถูกต้อง'}</span>
      </div>`;
    const body = item.item_type === 'electric' ? this.renderElectric(item, index) : this.renderConsumable(item, index, ev, unit);
    return head + body + `<p class="check-msg msg-${ev.result}" data-role="msg">${UI.escape(ev.text || '')}</p>`;
  }

  renderLots(item) {
    if (!item.lots || !item.lots.length) {
      return item.before ? '<div class="lot-list"><span class="lot lot-unknown">ไม่มีข้อมูล Lot</span></div>' : '';
    }
    return '<div class="lot-list" aria-label="วันหมดอายุแต่ละ Lot">' + item.lots.map(lot => `
      <span class="lot lot-${UI.escape(lot.state)}">
        ${lot.expiry_date ? 'หมดอายุ ' + UI.escape(UI.formatDateThai(lot.expiry_date)) : 'ไม่ทราบวันหมดอายุ'}
        · ${this.fmt(lot.qty)}${item.unit ? ' ' + UI.escape(item.unit) : ''}
        ${EXPIRY_LABEL[lot.state] && lot.expiry_date ? `<b>${EXPIRY_LABEL[lot.state]}</b>` : ''}
      </span>`).join('') + '</div>';
  }

  renderConsumable(item, index, ev, unit) {
    if (item.before === null || item.before === undefined) {
      return `
        ${this.renderLots(item)}
        <div class="check-grid">
          <label>นับได้จริง (ตั้งยอดครั้งแรก)
            <input type="number" min="0" step="any" inputmode="decimal" data-field="actual" value="${UI.escape(item.actual)}" placeholder="จำนวน">
          </label>
          <label>หมายเหตุ <input type="text" maxlength="200" data-field="note" value="${UI.escape(item.note)}"></label>
        </div>`;
    }
    const mismatchOpen = !item.confirmed && (item.actual !== '' || item.note !== '');
    return `
      <div class="qty-line">ก่อนตรวจ <b>${this.fmt(item.before)}</b>${unit}</div>
      ${this.renderLots(item)}
      <div class="check-grid">
        <label>ใช้ไปในเวรนี้
          <input type="number" min="0" step="any" inputmode="decimal" data-field="used" value="${UI.escape(item.used)}" placeholder="0">
        </label>
        <div class="expected-box">คงเหลือที่ควรมี <b data-role="expected">${this.fmt(ev.expected)}</b>${unit}</div>
      </div>
      <div class="confirm-row">
        <button type="button" class="confirm-btn ${item.confirmed ? 'on' : ''}" data-action="confirm" aria-pressed="${item.confirmed}">✓ ครบถ้วน (นับได้ตรง)</button>
        <button type="button" class="mismatch-btn ${mismatchOpen ? 'on' : ''}" data-action="mismatch">นับได้ไม่ตรง</button>
      </div>
      <div class="mismatch-box ${mismatchOpen ? '' : 'hidden'}" data-role="mismatch">
        <label>นับได้จริง <input type="number" min="0" step="any" inputmode="decimal" data-field="actual" value="${UI.escape(item.actual)}"></label>
        <label>หมายเหตุ (จำเป็นเมื่อไม่ตรง) <input type="text" maxlength="200" data-field="note" value="${UI.escape(item.note)}" placeholder="เช่น ชำรุดทิ้ง / หาไม่พบ"></label>
      </div>`;
  }

  renderElectric(item, index) {
    if (!item.units.length) {
      return `<p class="text-sm text-amber-700">ยังไม่มีเครื่องลงทะเบียนบนรถ — เพิ่มที่เมนู “เติมอุปกรณ์”</p>
        <label>หมายเหตุ <input type="text" maxlength="200" data-field="note" value="${UI.escape(item.note)}" placeholder="เช่น ส่งซ่อมทั้งหมด / ไม่มีเครื่อง"></label>`;
    }
    return '<div class="unit-list">' + item.units.map((u, ui) => {
      const touched = item.unitTouched[u.id];
      return `
      <div class="unit-row ${touched ? '' : 'untouched'}" data-unit="${ui}">
        <div class="unit-name">${UI.escape(u.label || `เครื่องที่ ${ui + 1}`)}</div>
        <div class="seg" role="group" aria-label="สถานะ">
          <button type="button" class="seg-btn ${touched && u.status === 'ready' ? 'on' : ''}" data-action="unit-status" data-value="ready">พร้อมใช้งาน</button>
          <button type="button" class="seg-btn danger ${touched && u.status === 'not_ready' ? 'on' : ''}" data-action="unit-status" data-value="not_ready">ไม่พร้อมใช้งาน</button>
        </div>
        <div class="battery-row" role="group" aria-label="ระดับแบตเตอรี่">
          ${[1, 2, 3, 4].map(level => `<button type="button" class="bat-btn ${touched && u.battery_level === level ? 'on' : ''}" data-action="unit-battery" data-value="${level}" aria-label="แบต ${level} ขีด">${'▮'.repeat(level)}${'▯'.repeat(4 - level)}</button>`).join('')}
        </div>
        <input type="text" maxlength="200" class="unit-note ${u.status === 'not_ready' ? '' : 'hidden'}" data-field="unit-note" value="${UI.escape(u.note || '')}" placeholder="สาเหตุ เช่น แบตเสื่อม / ชำรุด / ส่งซ่อม">
      </div>`;
    }).join('') + '</div>';
  }

  refreshCard(index) {
    const card = document.querySelector(`#inspectionItemTable .check-card[data-index="${index}"]`);
    if (!card) return;
    const item = this.items[index];
    const ev = this.evaluate(item);
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
    const expected = card.querySelector('[data-role="expected"]');
    if (expected) expected.textContent = this.fmt(ev.expected);
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
    if (field === 'unit-note') {
      const ui = Number(el.closest('.unit-row').dataset.unit);
      item.units[ui].note = el.value;
    } else {
      item[field] = el.value;
      // แก้ "ใช้ไป" หลังกดครบถ้วน → ต้องยืนยันใหม่กับยอดคาดการณ์ตัวใหม่ (ห้ามถือว่าครบโดยอัตโนมัติ)
      if (field === 'used' && item.confirmed) {
        item.confirmed = false;
        card.querySelector('[data-action="confirm"]')?.classList.remove('on');
      }
      if (field === 'actual' && el.value !== '') {
        item.confirmed = false;
        card.querySelector('[data-action="confirm"]')?.classList.remove('on');
      }
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

    if (action === 'confirm') {
      item.confirmed = !item.confirmed;
      if (item.confirmed) {
        item.actual = '';
        item.note = '';
      }
      card.innerHTML = this.renderCard(item, index);
    } else if (action === 'mismatch') {
      item.confirmed = false;
      card.innerHTML = this.renderCard(item, index);
      card.querySelector('.mismatch-box')?.classList.remove('hidden');
      card.querySelector('.mismatch-btn')?.classList.add('on');
      card.querySelector('[data-field="actual"]')?.focus();
    } else if (action === 'unit-status' || action === 'unit-battery') {
      const ui = Number(btn.closest('.unit-row').dataset.unit);
      const unit = item.units[ui];
      if (action === 'unit-status') unit.status = btn.dataset.value;
      else unit.battery_level = Number(btn.dataset.value);
      if (!item.unitTouched[unit.id]) {
        // แตะครั้งแรก: ค่าที่ยังไม่ได้เลือกในรอบนี้ต้องเลือกเองอีกค่า (ไม่ใช้ค่าค้างจากรอบก่อนโดยไม่รู้ตัว)
        item.unitTouched[unit.id] = true;
        if (action === 'unit-status') unit.battery_level = null;
      }
      card.innerHTML = this.renderCard(item, index);
      if (unit.status === 'not_ready') card.querySelector(`.unit-row[data-unit="${ui}"] .unit-note`)?.focus();
    }
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
    const pending = this.items.filter(item => item.item_type !== 'electric'
      && item.before !== null && item.before !== undefined
      && this.evaluate(item).result === 'pending');
    if (!pending.length) {
      window.toast.info('ไม่มีรายการของสิ้นเปลืองที่รอยืนยัน (เครื่องไฟฟ้าและรายการที่ยังไม่เคยนับต้องตรวจทีละรายการ)');
      return;
    }
    const ok = await window.confirmDialog(
      `ยืนยันว่าได้ตรวจนับแล้ว: ${pending.length} รายการที่เหลือ "ไม่ได้ใช้ในเวรนี้ และนับได้ครบตามยอด"?\n` +
      'รายการที่ใช้ไปหรือนับได้ไม่ตรง ให้กรอกทีละรายการก่อนกดปุ่มนี้',
      { confirmText: 'ยืนยันครบถ้วน' }
    );
    if (!ok) return;
    pending.forEach(item => {
      if (item.used === '') item.used = '0';
      item.confirmed = true;
      item.actual = '';
    });
    this.renderList();
    this.scheduleDraft();
  }

  // ---------------- ร่าง / บันทึก ----------------

  payloadLines() {
    return this.items.map(item => ({
      id: item.id,
      used: item.used === '' ? 0 : item.used,
      confirmed: item.confirmed,
      actual: item.confirmed ? null : (item.actual === '' ? null : item.actual),
      note: item.note,
      units: item.units
        .filter(u => item.unitTouched[u.id])
        .map(u => ({ id: u.id, status: u.status, battery_level: u.battery_level, note: u.note || '' })),
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

  async submitInspection() {
    if (!this.session || this.submitting) return;   // กันกดซ้ำระหว่างส่ง
    const evs = this.items.map(item => ({ item, ev: this.evaluate(item) }));
    const notReady = evs.filter(({ ev }) => ev.result === 'pending' || ev.result === 'error');
    if (notReady.length) {
      window.toast.warning(`ยังตรวจไม่ครบ/กรอกไม่ถูกต้อง ${notReady.length} รายการ เช่น ${notReady[0].item.name}: ${notReady[0].ev.text}`);
      this.setFilter('pending');
      return;
    }

    const attn = evs.filter(({ ev }) => ev.result === 'needs_refill' || ev.result === 'anomaly').length;
    const ok = await window.confirmDialog(
      (this.session.mode === 'edit' ? 'บันทึกการแก้ไขรายการตรวจเดิม?' : 'บันทึกผลตรวจเวรนี้?') +
      `\nครบถ้วน ${evs.length - attn} • ต้องเติม/ผิดปกติ ${attn} รายการ`,
      { confirmText: 'บันทึก' }
    );
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
      const data = res.data || {};
      const refill = data.refill || {};
      let msg = this.session.mode === 'edit' ? 'บันทึกการแก้ไขเรียบร้อย' : 'บันทึกผลตรวจเรียบร้อย';
      if (refill.created) msg += ` • สร้างรายการรอเติม ${refill.request_no || ''} (${refill.line_count} รายการ)`;
      if ((data.adjustments || []).length) msg += ` • พบความคลาดเคลื่อน ${data.adjustments.length} รายการ (บันทึกไว้แล้ว)`;
      window.toast.success(msg, 7000);
      this.closeSession();
      await this.refreshShiftStatus();
    } catch (error) {
      const list = error.data && Array.isArray(error.data.errors) ? '\n• ' + error.data.errors.slice(0, 5).join('\n• ') : '';
      window.toast.error(error.message + list, 9000);
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
      ? 'ปิดหน้าแก้ไขโดยไม่บันทึก? รายการเดิมจะไม่เปลี่ยนแปลง'
      : 'ออกจากหน้าตรวจ? ร่างจะถูกเก็บไว้ให้ทำต่อได้ภายหลัง';
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
