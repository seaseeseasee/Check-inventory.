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
    
    container.innerHTML = this.vehicles.map(vehicle => `
      <button type="button" 
        class="car-symbol" 
        data-car-id="${vehicle.id}" 
        aria-pressed="false" 
        aria-label="เลือกรถ ${this.getPlate(vehicle.id)}"
        onclick="inspectionPage.selectVehicle('${vehicle.id}')">
        <img src="${this.getVehicleIcon(vehicle.id)}" alt="" width="64" height="64">
        <span>${this.getPlate(vehicle.id)}</span>
        <span class="car-selected-mark">✓</span>
        <span class="car-stat">ยังไม่มีประวัติ</span>
      </button>
    `).join('');
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

  getPlate(carId) {
    const plates = {
      'car-1': '3ชฏ3250',
      'car-2': 'ฆง8434',
      'car-3': '5กล1034',
    };
    return plates[carId] || carId;
  }

  getVehicleIcon(carId) {
    return 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">🚑</text></svg>';
  }

  selectVehicle(carId) {
    document.querySelectorAll('.car-symbol').forEach(btn => {
      btn.setAttribute('aria-pressed', btn.dataset.carId === carId);
    });
  }

  async handleStartInspection(event) {
    event.preventDefault();

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
    document.getElementById('inspectionLoginInfo').textContent = `ผู้ตรวจ: ${nurse.name} | เวร: ${shift} | ${this.getPlate(vehicle.id)}`;
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
        <div class="item-meta">
          <span class="std-chip">มาตรฐาน ${UI.escape(item.standard)}</span>
          ${item.missing ? `<span class="detail-chip">${UI.escape(item.missing)}</span>` : ''}
        </div>
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
      btn.addEventListener('click', () => this.promptManualValue(btn.dataset.index, 'ใช้ไป'));
    });
    document.querySelectorAll('#inspectionItemTable [data-action="missing"]').forEach(btn => {
      btn.addEventListener('click', () => this.promptManualValue(btn.dataset.index, 'ขาด'));
    });
    document.querySelectorAll('#inspectionItemTable [data-action="battery"]').forEach(btn => {
      btn.addEventListener('click', () => this.setBatteryLevel(btn.dataset.index, parseInt(btn.dataset.value)));
    });
    // Expiry inputs
    document.querySelectorAll('#inspectionItemTable [data-field="expire"]').forEach(input => {
      input.addEventListener('change', () => this.changeExpiration(input.dataset.index, input.value));
    });
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
    this.checkResults[index].status = status;
    this.checkResults[index].missing = '';
    await this.saveDraft();
  }

  async promptManualValue(index, status) {
    const value = prompt('ระบุจำนวน / รายละเอียด');
    if (!value?.trim()) return;
    
    this.checkResults[index].status = status;
    this.checkResults[index].missing = value.trim();
    await this.saveDraft();
  }

  async setBatteryLevel(index, level) {
    this.checkResults[index].batteryLevel = level;
    this.checkResults[index].status = 'Checked';
    this.checkResults[index].missing = level + '/4 ขีด';
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

  async saveDraft() {
    if (!this.currentInspection) return;
    
    try {
      await window.api.put('/staff/inspections/draft', {
        car_id: this.currentInspection.car_id,
        results: this.checkResults,
      });
      this.renderChecklistTable();
      window.toast.success('บันทึกร่างแล้ว');
    } catch (error) {
      window.toast.error('บันทึกไม่สำเร็จ: ' + error.message);
    }
  }

  async submitInspection() {
    // Validate all checked
    const unchecked = this.checkResults.filter(i => i.status === 'ยังไม่ตรวจ');
    if (unchecked.length > 0) {
      window.toast.error('กรุณาตรวจทุกรายการ รวมถึงระดับแบตเตอรี่ก่อนบันทึก');
      return;
    }
    
    try {
      const result = await window.api.post('/staff/inspections/submit', {
        car_id: this.currentInspection.car_id,
        results: this.checkResults,
      });
      
      window.toast.success('บันทึกผลตรวจสำเร็จ - ระบบจะสร้างรายการเติมอุปกรณ์โดยอัตโนมัติหากพบของขาด');

      // แจ้งเตือนสต็อกกลางต่ำหลังตัดอัตโนมัติ (รายการ "ใช้ไป")
      const stockAlerts = result?.data?.stock_alerts || [];
      if (stockAlerts.length > 0) {
        const names = stockAlerts.map(a => a.name).slice(0, 3).join(', ');
        window.toast.warning(`สต็อกกลางต่ำกว่าขั้นต่ำ ${stockAlerts.length} รายการ: ${names}${stockAlerts.length > 3 ? ' และอื่น ๆ' : ''} — ดูที่เมนู “คลังสต็อกกลาง”`);
      }
      if (typeof refreshWarehouseBadge === 'function') refreshWarehouseBadge();
      
      // Reset and go to restock tab
      this.currentInspection = null;
      this.checkResults = [];
      document.getElementById('inspectionCheckPage').classList.add('hidden');
      document.getElementById('inspectionLoginPage').classList.remove('hidden');
      
      switchTab('restock');
    } catch (error) {
      window.toast.error('บันทึกไม่สำเร็จ: ' + error.message);
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