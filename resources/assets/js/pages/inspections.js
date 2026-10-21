// ========================================
// PAGE: INSPECTION (CHECKLIST)
// ========================================

class InspectionPage {
  constructor() {
    this.currentInspection = null;
    this.checkResults = [];
    this.vehicles = [];
    this.nurses = [];
    this.checkFilter = 'all';
    this.inspectorLocked = false;
    this.inspectorLockError = false;
    // ค่าที่พิมพ์ค้างในช่อง "คงเหลือจริง" ระหว่างที่หน้า list ถูก render ใหม่
    this.pendingInputs = new Map();
    this.focusRemainingIndex = null;
    this.submitting = false;
    this.init();
  }

  async init() {
    await this.loadReferenceData();
    this.bindEvents();
    this.checkUrlParams();
  }

  async loadReferenceData() {
    try {
      const [vehiclesRes, nursesRes] = await Promise.all([
        window.vehicleApi.getAll(),
        window.nurseApi.getAll(),
      ]);
      this.vehicles = vehiclesRes.data || [];
      this.nurses = nursesRes.data || [];
      window.vehicleRef.remember(this.vehicles);
      
      this.renderVehicleSelection();
      this.renderNurseSelect();
      this.renderShiftSelect();
    } catch (error) {
      console.error('Failed to load reference data:', error);
    }
  }

  bindEvents() {
    // Vehicle selection
    document.querySelectorAll('.car-symbol').forEach(btn => {
      btn.addEventListener('click', () => this.selectVehicle(btn.dataset.carId));
    });
    
    // Form submission
    const startForm = document.getElementById('inspectionStartForm');
    if (startForm) {
      startForm.addEventListener('submit', (e) => this.handleStartInspection(e));
    }
    
    // Filter chips
    document.getElementById('inspectionChipAll')?.addEventListener('click', () => this.setFilter('all'));
    document.getElementById('inspectionChipPending')?.addEventListener('click', () => this.setFilter('pending'));
    document.getElementById('inspectionChipAttn')?.addEventListener('click', () => this.setFilter('attn'));
  }

  checkUrlParams() {
    // Dashboard deep-links use "#check?car=..." — the query rides in the hash
    const hash = window.location.hash || '';
    const hashQuery = hash.includes('?') ? hash.slice(hash.indexOf('?') + 1) : '';
    const params = new URLSearchParams(hashQuery || window.location.search);
    const carId = params.get('car');
    if (carId) {
      this.selectVehicle(carId);
    }
  }

  renderVehicleSelection() {
    const container = document.getElementById('inspectionCarGrid');
    if (!container) return;

    // รถ "งดใช้งาน" ยังอยู่ในทะเบียน/ประวัติ แต่ไม่อยู่ในรอบตรวจ — เซิร์ฟเวอร์ยืนยันอีกชั้น
    // ที่ StaffController::startInspection (การซ่อนปุ่มอย่างเดียวเชื่อถือไม่ได้)
    const usable = this.vehicles.filter(v => v.status !== 'disabled');
    const hiddenCount = this.vehicles.length - usable.length;

    const cards = usable.map(vehicle => `
      <button type="button" 
        class="car-symbol" 
        data-car-id="${UI.escape(vehicle.id)}" 
        aria-pressed="false" 
        aria-label="เลือกรถ ${UI.escape(vehiclePlate(vehicle))}"
        onclick="inspectionPage.selectVehicle('${UI.escape(vehicle.id)}')">
        <img src="${this.getVehicleIcon(vehicle.id)}" alt="" width="64" height="64">
        <span>${UI.escape(vehiclePlate(vehicle))}</span>
        <span class="car-selected-mark">✓</span>
        <span class="car-stat">ยังไม่มีประวัติ</span>
      </button>
    `).join('');

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

    document.querySelectorAll('.car-symbol').forEach(btn => {
      btn.setAttribute('aria-pressed', btn.dataset.carId === carId);
    });
  }

  renderNurseSelect() {
    const select = document.getElementById('inspectionNurse');
    if (!select) return;
    
    select.innerHTML = '<option value="">-- เลือกรายชื่อพยาบาล --</option>' +
      this.nurses.filter(n => n.active).map(nurse => `
        <option value="${nurse.id}">${UI.escape(nurse.name)} (${UI.escape(nurse.department)})</option>
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

  // Staff are locked to the nurse linked to their account; admins pick freely.
  // The server re-checks this (StaffController::startInspection) — UI lock is
  // only there so the nurse never has to pick their own name.
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
    if (nurse) {
      select.value = nurse.id;
      select.disabled = true;
      this.inspectorLocked = true;
      if (note) {
        note.textContent = `🔒 ผู้ตรวจ: ${nurse.name} — ล็อกตามบัญชีที่เข้าสู่ระบบ`;
        note.classList.remove('hidden');
        note.classList.add('text-teal-700');
        note.classList.remove('text-red-600');
      }
    } else {
      select.disabled = true;
      this.inspectorLockError = true;
      if (note) {
        note.textContent = '⚠️ บัญชีนี้ยังไม่ได้เชื่อมโยงรายชื่อพยาบาลที่ใช้งานอยู่ กรุณาติดต่อผู้ดูแลระบบ';
        note.classList.remove('hidden');
        note.classList.add('text-red-600');
        note.classList.remove('text-teal-700');
      }
    }
  }

  renderShiftSelect() {
    const select = document.getElementById('inspectionShift');
    if (!select) return;
    
    select.innerHTML = `
      <option value="">-- เลือกเวร --</option>
      <option value="เช้า">เช้า (08:00 - 16:00)</option>
      <option value="บ่าย">บ่าย (16:00 - 24:00)</option>
      <option value="ดึก">ดึก (24:00 - 08:00)</option>
    `;
  }

  getVehicleIcon(carId) {
    // ต้อง URL-encode: double quote ใน data URI จะปิด attribute src ของ <img> ทำให้อิโมจิหลุดออกมาแสดงบนหน้าจอ
    const svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚑</text></svg>";
    return 'data:image/svg+xml,' + encodeURIComponent(svg);
  }

  async handleStartInspection(event) {
    event.preventDefault();

    if (this.startSubmitting) return;

    if (this.inspectorLockError) {
      window.toast.error('บัญชีนี้ยังไม่ได้เชื่อมโยงรายชื่อพยาบาลที่ใช้งานอยู่ กรุณาติดต่อผู้ดูแลระบบ');
      return;
    }

    const nurseId = document.getElementById('inspectionNurse')?.value;
    const shift = document.getElementById('inspectionShift')?.value;
    const carId = document.querySelector('.car-symbol[aria-pressed="true"]')?.dataset.carId;

    if (!nurseId || !shift || !carId) {
      window.toast.error('กรุณาเลือกพยาบาล เวร และรถให้ครบ');
      return;
    }

    const vehicle = this.vehicles.find(v => v.id === carId);
    if (!vehicle) {
      window.toast.error('รถไม่ถูกต้อง');
      return;
    }

    const nurse = this.nurses.find(n => n.id === nurseId);
    if (!nurse || !nurse.active) {
      window.toast.error('พยาบาลไม่ถูกต้อง');
      return;
    }

    this.startSubmitting = true;
    const startBtn = document.querySelector('#inspectionStartForm button[type="submit"]');
    const startBtnLabel = startBtn?.textContent;
    if (startBtn) { startBtn.disabled = true; startBtn.textContent = 'กำลังเริ่มตรวจ...'; }

    try {
      // Ask about another nurse's open draft before sending the start request,
      // so the normal flow never produces a failed request in the console
      let replaceDraft = false;
      try {
        const draftInfo = await window.api.get(`/staff/inspections/draft?car=${encodeURIComponent(carId)}`);
        const draft = draftInfo.data?.draft;
        const sameOwner = draft && draft.nurse_id === nurseId && draft.shift_name === shift;
        if (draft && !sameOwner) {
          replaceDraft = this.confirmReplaceDraft(draft);
          if (!replaceDraft) return;
        }
      } catch (preCheckError) {
        // Pre-check is best-effort - the start endpoint still guards the conflict
      }

      const payload = { car_id: carId, nurse_id: nurseId, shift: shift };
      if (replaceDraft) payload.replace = true;

      const result = await window.api.post('/staff/inspections/start', payload);

      this.applyInspectionStarted(result.data, vehicle, nurse, shift);
    } catch (error) {
      // Race fallback: the draft appeared or changed after the pre-check
      if (error.data?.code === 'DRAFT_EXISTS') {
        if (!this.confirmReplaceDraft(error.data.draft || {})) return;

        try {
          const result = await window.api.post('/staff/inspections/start', {
            car_id: carId,
            nurse_id: nurseId,
            shift: shift,
            replace: true,
          });
          this.applyInspectionStarted(result.data, vehicle, nurse, shift);
        } catch (retryError) {
          window.toast.error('เริ่มตรวจไม่สำเร็จ: ' + retryError.message);
        }
        return;
      }

      window.toast.error('เริ่มตรวจไม่สำเร็จ: ' + error.message);
    } finally {
      this.startSubmitting = false;
      if (startBtn) { startBtn.disabled = false; startBtn.textContent = startBtnLabel; }
    }
  }

  confirmReplaceDraft(draft) {
    return window.confirm(
      `รถคันนี้มีร่างการตรวจของ ${draft.nurse_name || 'พยาบาลท่านอื่น'} (เวร${draft.shift_name || '-'}) อยู่แล้ว\n` +
      'ต้องการลบร่างเดิมและเริ่มการตรวจใหม่หรือไม่?'
    );
  }

  applyInspectionStarted(data, vehicle, nurse, shift) {
    this.currentInspection = data;
    this.checkResults = data.results || [];
    this.pendingInputs.clear();
    this.focusRemainingIndex = null;

    if (this.checkResults.length === 0) {
      window.toast.warning('รถคันนี้ยังไม่มีรายการอุปกรณ์ให้ตรวจ กรุณาเพิ่มรายการอุปกรณ์ก่อน');
      return;
    }

    // Show check page
    this.showCheckPage(vehicle, nurse, shift);
    this.renderChecklistTable();
  }

  showCheckPage(vehicle, nurse, shift) {
    document.getElementById('inspectionLoginPage').classList.add('hidden');
    document.getElementById('inspectionCheckPage').classList.remove('hidden');
    document.getElementById('inspectionLoginInfo').textContent = `ผู้ตรวจ: ${nurse.name} | เวร: ${shift} | ${vehiclePlate(vehicle)}`;
  }

  renderChecklistTable() {
    const container = document.getElementById('inspectionItemTable');
    if (!container) return;
    
    const doneCount = this.checkResults.filter(i => i.status !== 'ยังไม่ตรวจ').length;
    const totalCount = this.checkResults.length;
    const progress = totalCount > 0 ? Math.round(doneCount / totalCount * 100) : 0;
    
    document.getElementById('inspectionProgressFill').style.width = progress + '%';
    document.getElementById('inspectionProgressText').textContent = `ตรวจแล้ว ${doneCount} / ${totalCount} รายการ`;
    
    // Update filter counts
    document.getElementById('inspectionCntAll').textContent = totalCount;
    document.getElementById('inspectionCntPending').textContent = totalCount - doneCount;
    document.getElementById('inspectionCntAttn').textContent = this.checkResults.filter(this.needsAttention).length;

    // แถวสรุปก่อนบันทึก: บอกให้รู้ว่ายังขาดอะไร (ข้อ 1.4 + กันกดบันทึกซ้ำ/ค้าง)
    const needsInput = this.checkResults.filter((item, index) => this.itemNeedsRemainingInput(item, index));
    const hint = document.getElementById('inspectionSaveHint');
    const submitBtn = document.getElementById('inspectionSubmitBtn');
    if (hint) {
      if (totalCount === 0) hint.textContent = 'รถคันนี้ยังไม่มีรายการอุปกรณ์';
      else if (doneCount < totalCount) hint.textContent = `เหลือ ${totalCount - doneCount} รายการที่ยังไม่ได้เลือกสถานะ`;
      else if (needsInput.length > 0) hint.textContent = `ต้องกรอก “จำนวนคงเหลือจริง” อีก ${needsInput.length} รายการจึงจะบันทึกได้`;
      else hint.textContent = 'ตรวจครบแล้ว — บันทึกเพื่อตั้งยอดคงเหลือบนรถและสร้างรายการรอเติมอัตโนมัติ';
    }
    if (submitBtn) submitBtn.disabled = totalCount === 0 || doneCount < totalCount || needsInput.length > 0;
    
    // Render items
    const visible = this.checkResults
      .map((item, index) => ({ item, index }))
      .filter(({ item }) => this.checkFilter === 'all' || 
        (this.checkFilter === 'pending' && item.status === 'ยังไม่ตรวจ') ||
        (this.checkFilter === 'attn' && this.needsAttention(item)));
    
    container.innerHTML = visible.map(({ item, index }) => this.renderItemCard(item, index)).join('');
    
    // Bind item events
    this.bindItemEvents();
  }

  renderItemCard(item, index) {
    const statusClass = this.getStatusClass(item);
    const chipClass = this.getChipClass(item.status);
    
    return `
      <article class="item-card ${statusClass}" data-index="${index}" data-item-id="${item.id}">
        <div class="item-head">
          <span class="item-no">${index + 1}</span>
          <div class="item-name">${UI.escape(item.name)}</div>
          <span class="chip ${chipClass}">${UI.escape(item.status)}</span>
        </div>
        ${this.renderStockRow(item)}
        <div class="item-meta">
          <span class="std-chip">มาตรฐาน ${UI.escape(item.standard)}</span>
          ${this.detailLabel(item) ? `<span class="detail-chip">${UI.escape(this.detailLabel(item))}</span>` : ''}
        </div>
        ${this.renderRemainingRow(item, index)}
        <div class="exp-row">
          <label for="exp-${index}">วันหมดอายุ</label>
          <input type="text" id="exp-${index}" value="${UI.escape(item.expire)}" placeholder="วัน/เดือน/ปี" data-index="${index}" data-field="expire">
        </div>
        <div class="item-actions">
          ${item.isBattery ? this.renderBatteryButtons(item, index) : this.renderActionButtons(item, index)}
        </div>
      </article>
    `;
  }

  // ==================== ยอดสต็อกบนหน้าจอ (ข้อ 1 ของสเปก) ====================

  /**
   * แถบแสดง "ยอดล่าสุดจากฐานข้อมูล" ของรายการนี้: บนรถ / เป้าหมาย / คลังกลาง
   * car_qty = null แปลว่า "ยังไม่เคยตรวจนับ" — ห้ามแสดงเป็น 0 (ข้อ 1.5)
   */
  renderStockRow(item) {
    const unit = item.unit ? ' ' + item.unit : '';
    const counted = item.car_qty !== null && item.car_qty !== undefined && item.car_qty !== '';

    const onCar = counted
      ? `<span class="stock-chip onhand">บนรถ ${this.fmtQty(item.car_qty)}${UI.escape(unit)}</span>`
      : '<span class="stock-chip unknown">บนรถ: ยังไม่เคยตรวจนับ</span>';
    const asof = counted && item.car_qty_at
      ? `<span class="stock-asof">ณ ${UI.escape(this.shortDateTime(item.car_qty_at))}</span>`
      : '';

    const hasTarget = item.target_qty !== null && item.target_qty !== undefined && item.target_qty !== '';
    const target = hasTarget
      ? `<span class="stock-chip target">เป้าหมาย ${this.fmtQty(item.target_qty)}${UI.escape(unit)}</span>`
      : '<span class="stock-chip target">เป้าหมายไม่ระบุ</span>';

    const hasWh = item.warehouse_qty !== null && item.warehouse_qty !== undefined && item.warehouse_qty !== '';
    // คลังที่ "ยังไม่ตั้งยอด" (ยอด 0 ไม่มีขั้นต่ำ) ต้องไม่ทำให้พยาบาลเข้าใจผิดว่าของหมดคลัง
    const whUntracked = hasWh && item.warehouse_status === 'unset';
    const whLow = hasWh && !whUntracked
      && parseFloat(item.warehouse_min) > 0 && parseFloat(item.warehouse_qty) <= parseFloat(item.warehouse_min);
    const wh = whUntracked
      ? '<span class="stock-chip wh unknown">คลังกลาง: ยังไม่ตั้งยอด</span>'
      : hasWh
        ? `<span class="stock-chip wh${whLow ? ' low' : ''}">คลังกลาง ${this.fmtQty(item.warehouse_qty)}${whLow ? ' (ใกล้หมด)' : ''}</span>`
        : '<span class="stock-chip wh">คลังกลาง: ยังไม่ได้ขึ้นทะเบียน</span>';

    return `<div class="stock-row">${onCar}${asof}${target}${wh}</div>`;
  }

  /**
   * ช่องกรอก "จำนวนคงเหลือจริง" เท่านั้น (ข้อ 1.4)
   * ระบบไม่รับช่อง "จำนวนที่ใช้ไป" เพื่อไม่ให้ผู้ใช้กรอกสองแบบปนกัน
   */
  renderRemainingRow(item, index) {
    if (item.isBattery) {
      return `<p class="remaining-hint">เลือกจำนวนขีดแบตเตอรี่ 0-4 (ต่ำกว่า 4 ขีดจะถูกรวมไว้ในรายการรอเติม)</p>`;
    }

    const unit = item.unit ? ' ' + item.unit : '';

    if (item.status === 'ยังไม่ตรวจ') {
      return '<p class="remaining-hint">เลือก ครบ / ใช้ไป / ขาด แล้วกรอก “จำนวนคงเหลือจริง” ที่นับได้จากบนรถ</p>';
    }

    if (item.status === 'Checked') {
      const hasTarget = item.target_qty !== null && item.target_qty !== undefined && item.target_qty !== '';
      const shown = hasTarget ? `${this.fmtQty(item.target_qty)}${UI.escape(unit)}` : 'ครบตามมาตรฐาน';
      return `<div class="remaining-row"><span class="remaining-static">คงเหลือจริง = ${shown} (บันทึกว่าครบ)</span></div>`;
    }

    const stored = (item.actual_remaining === null || item.actual_remaining === undefined) ? '' : String(item.actual_remaining);
    // ค่าที่ผู้ใช้พิมพ์ค้างไว้ก่อน render ใหม่ (ยังไม่ commit) ห้ามหายไป (ข้อ 1.5)
    const pending = this.pendingInputs instanceof Map ? this.pendingInputs.get(String(index)) : null;
    const raw = pending !== null && pending !== undefined ? pending : stored;

    return `
      <div class="remaining-row">
        <label class="remaining-label" for="rem-${index}">
          จำนวนคงเหลือจริงบนรถ <span class="muted">(ไม่ใช่จำนวนที่ใช้ไป${UI.escape(unit)})</span>
        </label>
        <input class="remaining-input" id="rem-${index}" type="number" min="0" step="0.5" inputmode="decimal"
          data-index="${index}" data-field="actual" value="${UI.escape(raw)}" placeholder="เช่น 7"
          aria-describedby="rem-calc-${index}">
        <p class="remaining-help">ระบบจะคำนวณ “ใช้ไป” เอง = ยอดบนรถเดิม − คงเหลือจริงที่กรอก</p>
        <p class="remaining-calc" id="rem-calc-${index}">${this.calcPreviewHtml(item, raw)}</p>
      </div>
    `;
  }

  /** ข้อความสรุปใต้ชื่ออุปกรณ์ (แบตใช้ขีด, อื่น ๆ ใช้ "คงเหลือ N หน่วย") */
  detailLabel(item) {
    if (item.isBattery && item.batteryLevel !== null && item.batteryLevel !== undefined) {
      return item.batteryLevel + '/4 ขีด';
    }
    const has = item.actual_remaining !== null && item.actual_remaining !== undefined && item.actual_remaining !== '';
    if (has && item.status !== 'Checked') {
      return 'คงเหลือ ' + this.fmtQty(item.actual_remaining) + (item.unit ? ' ' + item.unit : '');
    }
    return item.missing || '';
  }

  /** ตัวเลขคาดไว้ที่จะถูกบันทึก (ใช้กติกาเดียวกับฝั่งเซิร์ฟเวอร์) */
  calcPreviewHtml(item, rawValue) {
    const unit = item.unit ? ' ' + item.unit : '';
    const actual = this.toNum(rawValue);

    if (actual === null) {
      return '<span class="muted">ยังไม่ได้กรอก — ยังไม่บันทึกยอดของรายการนี้</span>';
    }

    const counted = item.car_qty !== null && item.car_qty !== undefined && item.car_qty !== '';
    const before = counted ? parseFloat(item.car_qty) : null;

    let usedText;
    if (before === null) {
      usedText = '<span class="muted">ใช้ไป: ยังคำนวณไม่ได้ (รถยังไม่เคยตรวจนับ) — รอบนี้จะตั้งยอดคงเหลือเป็นค่าใหม่</span>';
    } else if (actual > before) {
      usedText = `<span class="warn-text">นับได้มากกว่ายอดเดิม (${this.fmtQty(before)}${UI.escape(unit)}) → บันทึกเป็นการปรับยอด ไม่ใช่ “ใช้ไป” ติดลบ</span>`;
    } else {
      usedText = `ใช้ไป ${this.fmtQty(before - actual)}${UI.escape(unit)}`;
    }

    const hasTarget = item.target_qty !== null && item.target_qty !== undefined && item.target_qty !== '';
    const refillText = hasTarget
      ? `ต้องเติม ${this.fmtQty(Math.max(0, parseFloat(item.target_qty) - actual))}${UI.escape(unit)}`
      : 'ต้องเติม: ไม่ระบุเป้าหมาย';

    return `<span>${usedText}</span><span class="sep">•</span><span>${refillText}</span>`;
  }

  toNum(value) {
    const text = String(value ?? '').trim();
    if (text === '' || !/^\d+(?:[.]\d+)?$/.test(text)) return null;
    const n = parseFloat(text);
    return Number.isFinite(n) ? Math.round(n * 100) / 100 : null;
  }

  /** รายการที่ต้องมี "ตัวเลขคงเหลือจริง" ก่อนกดบันทึก */
  itemNeedsRemainingInput(item, index) {
    if (item.isBattery) return false;
    if (item.status === 'ยังไม่ตรวจ' || item.status === 'Checked') return false;
    const pending = this.pendingInputs.get(String(index));
    return this.toNum(pending === undefined || pending === null || pending === '' ? item.actual_remaining : pending) === null;
  }

  fmtQty(value) {
    const n = parseFloat(value);
    if (!Number.isFinite(n)) return String(value ?? '-');
    return String(Math.round(n * 100) / 100);
  }

  shortDateTime(iso) {
    try {
      const d = new Date(iso);
      if (Number.isNaN(d.getTime())) return String(iso).slice(0, 16);
      const p = new Intl.DateTimeFormat('th-TH', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(d);
      return p.replace(/\s+/g, ' ');
    } catch (e) {
      return String(iso).slice(0, 16);
    }
  }

  renderActionButtons(item, index) {
    return `
      <button type="button" class="act-btn ok" data-action="check" data-index="${index}">✓ ครบ</button>
      <button type="button" class="act-btn warn" data-action="used" data-index="${index}">ใช้ไป</button>
      <button type="button" class="act-btn danger" data-action="missing" data-index="${index}">ขาด</button>
    `;
  }

  renderBatteryButtons(item, index) {
    return `
      <div class="battery-container" role="group" aria-label="ระดับแบตเตอรี่ ${UI.escape(item.name)}">
        ${[0,1,2,3,4].map(v => `
          <button type="button" class="bat-btn ${item.batteryLevel === v ? 'on' : ''}" 
            aria-pressed="${item.batteryLevel === v}" 
            aria-label="แบตเตอรี่ ${v}/4" 
            data-action="battery" 
            data-index="${index}" 
            data-value="${v}">${v}/4</button>
        `).join('')}
      </div>
    `;
  }

  bindItemEvents() {
    // Status buttons
    document.querySelectorAll('#inspectionItemTable [data-action="check"]').forEach(btn => {
      btn.addEventListener('click', () => this.setItemStatus(btn.dataset.index, 'Checked'));
    });
    document.querySelectorAll('#inspectionItemTable [data-action="used"]').forEach(btn => {
      btn.addEventListener('click', () => this.beginManualEntry(btn.dataset.index, 'ใช้ไป'));
    });
    document.querySelectorAll('#inspectionItemTable [data-action="missing"]').forEach(btn => {
      btn.addEventListener('click', () => this.beginManualEntry(btn.dataset.index, 'ขาด'));
    });
    document.querySelectorAll('#inspectionItemTable [data-action="battery"]').forEach(btn => {
      btn.addEventListener('click', () => this.setBatteryLevel(btn.dataset.index, parseInt(btn.dataset.value)));
    });
    // ช่อง "จำนวนคงเหลือจริง": พิมพ์แล้วเห็นตัวเลขคำนวณทันที, ออกจากช่อง/กด Enter จึงบันทึกร่าง
    document.querySelectorAll('#inspectionItemTable [data-field="actual"]').forEach(input => {
      const calc = document.getElementById('rem-calc-' + input.dataset.index);
      input.addEventListener('input', () => {
        this.pendingInputs.set(String(input.dataset.index), input.value);
        const item = this.checkResults[parseInt(input.dataset.index)];
        if (item && calc) calc.innerHTML = this.calcPreviewHtml(item, input.value);
      });
      input.addEventListener('change', () => this.commitActualRemaining(input.dataset.index, input.value));
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); this.commitActualRemaining(input.dataset.index, input.value); }
      });
    });
    // Expiry inputs
    document.querySelectorAll('#inspectionItemTable [data-field="expire"]').forEach(input => {
      input.addEventListener('change', () => this.changeExpiration(input.dataset.index, input.value));
    });

    // หลัง render ใหม่ ให้ cursor กลับไปที่ช่องคงเหลือจริงของรายการที่ผู้ใช้เพิ่งเลือก
    if (this.focusRemainingIndex !== null && this.focusRemainingIndex !== undefined) {
      const el = document.getElementById('rem-' + this.focusRemainingIndex);
      if (el) el.focus();
      this.focusRemainingIndex = null;
    }
  }

  getStatusClass(item) {
    if (item.status === 'ขาด') return 'is-missing';
    if (item.status === 'ใช้ไป') return 'is-used';
    if (item.status === 'Checked') return 'is-checked';
    return '';
  }

  getChipClass(status) {
    const classes = {
      'Checked': 'chip-ok',
      'ขาด': 'chip-miss',
      'ใช้ไป': 'chip-used',
      'ยังไม่ตรวจ': 'chip-pending',
    };
    return classes[status] || 'chip-pending';
  }

  needsAttention(item) {
    return item.status === 'ขาด' || item.status === 'ใช้ไป' || (item.isBattery && (item.batteryLevel || 4) < 4);
  }

  setFilter(filter) {
    this.checkFilter = filter;
    // Update active chip
    document.querySelectorAll('#inspectionChipAll, #inspectionChipPending, #inspectionChipAttn').forEach(btn => {
      btn.classList.toggle('on', btn.id.includes(filter.charAt(0).toUpperCase() + filter.slice(1)) || 
        (filter === 'all' && btn.id === 'inspectionChipAll'));
    });
    this.renderChecklistTable();
  }

  async setItemStatus(index, status) {
    const item = this.checkResults[index];
    if (!item) return;

    item.status = status;
    item.missing = '';
    if (status === 'Checked') {
      // "ครบ" = คงเหลือจริงเท่าเป้าหมาย → ฝั่งเซิร์ฟเวอร์คำนวณใช้ไปต่อเอง
      item.actual_remaining = (item.target_qty === null || item.target_qty === undefined || item.target_qty === '')
        ? null : parseFloat(item.target_qty);
    }
    await this.saveDraft();
  }

  /**
   * เลือก "ใช้ไป"/"ขาด" → เปิดช่องกรอก "จำนวนคงเหลือจริง" (ไม่ใช้ prompt free-text อีกแล้ว)
   */
  async beginManualEntry(index, status) {
    const item = this.checkResults[index];
    if (!item) return;

    item.status = status;
    item.missing = '';
    // คงค่าเดิมไว้ถ้าเคยกรอกแล้ว (เช่น สลับสถานะกลับไปกลับมา)
    if (item.actual_remaining === undefined) item.actual_remaining = null;

    this.focusRemainingIndex = index;
    await this.saveDraft();
  }

  /**
   * รับตัวเลขคงเหลือจริงจากช่องกรอก (ข้อ 1.4 + ข้อ 2.4)
   * - ต้องเป็นตัวเลขไม่ติดลบเท่านั้น (0 ได้ = หมดรถ)
   * - ห้ามกรอก "จำนวนที่ใช้ไป" ปนมา — ถ้ามากกว่ายอดบนรถจะเตือนว่าถือเป็นการปรับยอด
   */
  async commitActualRemaining(index, rawValue) {
    const i = parseInt(index);
    const item = this.checkResults[i];
    if (!item) return;

    const text = String(rawValue ?? '').trim();
    if (text === '' || !/^\d+(?:[.]\d+)?$/.test(text) || parseFloat(text) < 0) {
      window.toast.error('จำนวนคงเหลือจริงต้องเป็นตัวเลขไม่ติดลบ เช่น 7 หรือ 7.5 (ถ้าหมดรถกรอก 0)');
      this.pendingInputs.delete(String(i));
      const el = document.getElementById('rem-' + i);
      if (el) { el.value = (item.actual_remaining === null || item.actual_remaining === undefined) ? '' : String(item.actual_remaining); el.focus(); }
      return;
    }

    const value = Math.round(parseFloat(text) * 100) / 100;
    this.pendingInputs.delete(String(i));
    item.actual_remaining = value;
    item.missing = 'คงเหลือ ' + this.fmtQty(value) + (item.unit ? ' ' + item.unit : '');

    const counted = item.car_qty !== null && item.car_qty !== undefined && item.car_qty !== '';
    if (counted && value > parseFloat(item.car_qty)) {
      window.toast.warning(
        `"${item.name}": นับได้ ${this.fmtQty(value)} มากกว่ายอดเดิม ${this.fmtQty(item.car_qty)} `
        + '→ ระบบจะบันทึกเป็นยอดคงเหลือใหม่ (ไม่สร้าง “ใช้ไป” ติดลบ) กรุณาตรวจว่ามีการเติมที่ยังไม่ได้บันทึกหรือไม่'
      );
    }

    await this.saveDraft();
  }

  /** ทางเลือกเดิมสำหรับ inline handler เก่า — ตอนนี้เปิดช่องกรอกตัวเลขแทน prompt */
  async promptManualValue(index, status) {
    return this.beginManualEntry(index, status);
  }

  async setBatteryLevel(index, level) {
    const item = this.checkResults[index];
    if (!item) return;

    item.batteryLevel = level;
    item.status = 'Checked';
    item.missing = level + '/4 ขีด';
    item.actual_remaining = (item.target_qty === null || item.target_qty === undefined || item.target_qty === '')
      ? null : parseFloat(item.target_qty);
    await this.saveDraft();
  }

  async changeExpiration(index, value) {
    this.checkResults[index].expire = value;
    await this.saveDraft();
  }

  async checkAll() {
    if (!confirm('ยืนยันเฉพาะรายการที่ยังไม่ตรวจว่าได้ตรวจครบแล้ว? รายการขาด/ใช้ไปและแบตเตอรี่จะคงเดิม')) return;
    
    this.checkResults = this.checkResults.map(item => 
      item.status === 'ยังไม่ตรวจ' && !item.isBattery ? { ...item, status: 'Checked' } : item
    );
    await this.saveDraft();
  }

  /**
   * บันทึกร่างทุกครั้งที่แก้ไขรายการ — ไม่ขึ้น toast ทุกครั้ง (ลดการรบกวนเวลาตรวจรายการยาว ๆ)
   * ใช้ข้อความเบา ๆ ข้างแถบ progress แทน และขึ้น toast เฉพาะเมื่อบันทึกไม่สำเร็จ
   */
  async saveDraft() {
    if (!this.currentInspection) return;

    const note = document.getElementById('inspectionAutosaveNote');
    try {
      await window.api.put('/staff/inspections/draft', {
        car_id: this.currentInspection.car_id,
        results: this.checkResults,
      });
      this.renderChecklistTable();
      if (note) {
        note.removeAttribute('data-state');
        const time = new Intl.DateTimeFormat('th-TH', { hour: '2-digit', minute: '2-digit' }).format(new Date());
        note.textContent = `บันทึกร่างล่าสุด ${time} น.`;
      }
    } catch (error) {
      if (note) {
        note.dataset.state = 'error';
        note.textContent = 'บันทึกร่างไม่สำเร็จ — การเปลี่ยนแปลงล่าสุดอาจยังไม่ถูกเก็บ';
      }
      window.toast.error('บันทึกร่างไม่สำเร็จ: ' + error.message);
    }
  }

  async submitInspection() {
    if (this.submitting) {
      window.toast.warning('กำลังบันทึกอยู่กรุณารอเล็กน้อย');
      return;
    }

    // Validate all checked
    const unchecked = this.checkResults.filter(i => i.status === 'ยังไม่ตรวจ');
    if (unchecked.length > 0) {
      window.toast.error('กรุณาตรวจทุกรายการ รวมถึงระดับแบตเตอรี่ก่อนบันทึก');
      return;
    }

    // ข้อ 1.4 / 2.4: รายการที่ "ใช้ไป" หรือ "ขาด" ต้องมีตัวเลขคงเหลือจริงครบก่อนกดบันทึก
    const missingQty = this.checkResults.filter((item, index) => this.itemNeedsRemainingInput(item, index));
    if (missingQty.length > 0) {
      window.toast.error('กรุณากรอก "จำนวนคงเหลือจริง" ให้ครบ: ' + missingQty.slice(0, 3).map(i => i.name).join(', ') + (missingQty.length > 3 ? ' ฯลฯ' : ''));
      return;
    }

    // ส่งค่าคงเหลือจริงที่พิมพ์ค้างไว้ก่อนหน้า submit ด้วย
    const payload = this.checkResults.map((item, index) => {
      const pending = this.toNum(this.pendingInputs.get(String(index)));
      return pending === null ? item : { ...item, actual_remaining: pending };
    });

    this.submitting = true;
    const button = document.getElementById('inspectionSubmitBtn');
    if (button) { button.disabled = true; button.dataset.busy = '1'; }

    try {
      const result = await window.api.post('/staff/inspections/submit', {
        car_id: this.currentInspection.car_id,
        results: payload,
      });

      const data = result?.data || {};
      const refill = data.refill || {};
      const adjustments = data.adjustments || [];

      if (refill.created) {
        window.toast.success(
          `บันทึกผลตรวจแล้ว • สร้างรายการรอเติม ${refill.request_no || ''} (${refill.line_count || 0} รายการ)`
        );
      } else {
        window.toast.success('บันทึกผลตรวจแล้ว • อุปกรณ์ครบตามมาตรฐาน จึงไม่มีรายการรอเติม');
      }

      if (refill.skipped && refill.skipped.length > 0) {
        window.toast.warning(
          `${refill.skipped.length} รายการมีคำขอเดิมค้างอยู่ จึงไม่สร้างซ้ำ — ดูที่เมนู “รอเติมอุปกรณ์”`
        );
      }

      // การนับมากกว่ายอดเดิม = ปรับยอด/นับคลาดเคลื่อน ต้องให้ผู้ตรวจเห็นชัด (ข้อ 2.5)
      if (adjustments.length > 0) {
        const names = adjustments.slice(0, 3).map(a => a.name).join(', ');
        window.toast.warning(
          `${adjustments.length} รายการนับได้มากกว่ายอดเดิม จึงบันทึกเป็นการปรับยอด (ไม่ใช่ใช้ไปติดลบ): ${names}${adjustments.length > 3 ? ' ฯลฯ' : ''}`
        );
      }

      this.pendingInputs.clear();
      if (typeof refreshWarehouseBadge === 'function') refreshWarehouseBadge();
      
      // Reset and go to restock tab
      this.currentInspection = null;
      this.checkResults = [];
      document.getElementById('inspectionCheckPage').classList.add('hidden');
      document.getElementById('inspectionLoginPage').classList.remove('hidden');
      
      switchTab('restock');
    } catch (error) {
      window.toast.error('บันทึกไม่สำเร็จ: ' + (error?.message || 'กรุณาลองใหม่'));
    } finally {
      this.submitting = false;
      if (button) { button.disabled = false; delete button.dataset.busy; }
    }
  }

  cancelInspection() {
    if (!confirm('ยกเลิกการตรวจครั้งนี้? ข้อมูลร่างจะถูกเก็บไว้เพื่อทำต่อได้ภายหลัง')) return;
    
    this.currentInspection = null;
    this.checkResults = [];
    document.getElementById('inspectionCheckPage').classList.add('hidden');
    document.getElementById('inspectionLoginPage').classList.remove('hidden');
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
    inspectionPage.checkUrlParams();
  }
};

// Global functions for inline handlers
window.setInspectionFilter = (filter) => inspectionPage?.setFilter(filter);
window.setItemStatus = (index, status) => inspectionPage?.setItemStatus(index, status);
window.triggerManualValue = (index, status) => inspectionPage?.promptManualValue(index, status);
window.clickBatteryUI = (index, value) => inspectionPage?.setBatteryLevel(index, value);
window.changeExpirationRow = (index, value) => inspectionPage?.changeExpiration(index, value);
window.checkAllInspection = () => inspectionPage?.checkAll();
window.submitInspection = () => inspectionPage?.submitInspection();
window.cancelInspection = () => inspectionPage?.cancelInspection();