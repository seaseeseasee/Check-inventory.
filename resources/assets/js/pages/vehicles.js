// ========================================
// PAGE: VEHICLES / STOCK
// ========================================

class VehiclesPage {
  constructor() {
    this.vehicles = [];
    this.currentVehicleId = null;
    this.items = [];
    this.showAddForm = false;
    this.editingItemId = null;
    this.copyStaging = [];
    this.copySourceLabel = '';
    this.init();
  }

  async init() {
    await this.loadVehicles();
    this.bindEvents();
    this.renderVehicleSelect();
  }

  async loadVehicles() {
    try {
      const res = await window.vehicleApi.getAll();
      this.vehicles = res.data || [];
    } catch (error) {
      console.error('Failed to load vehicles:', error);
      window.toast.error('โหลดข้อมูลรถไม่สำเร็จ');
    }
  }

  bindEvents() {
    // Vehicle select
    document.getElementById('stockCar')?.addEventListener('change', () => this.onVehicleChange());
    
    // Add item form
    document.getElementById('stockAddForm')?.addEventListener('submit', (e) => this.handleAddItem(e));
    document.getElementById('stockBattery')?.addEventListener('change', (e) => {
      // Handle battery type change if needed
    });

    // Copy items from another vehicle
    document.getElementById('btnCopyStart')?.addEventListener('click', () => this.handleCopyStart());
    document.getElementById('btnCopyConfirm')?.addEventListener('click', () => this.handleCopyConfirm());
    document.getElementById('btnCopyCancel')?.addEventListener('click', () => this.handleCopyCancel());
  }

  renderVehicleSelect() {
    const select = document.getElementById('stockCar');
    if (!select) return;
    
    select.innerHTML = this.vehicles.map(v => 
      `<option value="${UI.escape(v.id)}">${UI.escape(v.name || this.getPlate(v.id))}</option>`
    ).join('');
    
    this.renderCopySourceSelect();
    this.onVehicleChange();
  }

  renderCopySourceSelect() {
    const select = document.getElementById('copySourceCar');
    const btn = document.getElementById('btnCopyStart');
    if (!select) return;

    const sources = this.vehicles.filter(v => String(v.id) !== String(this.currentVehicleId));
    select.innerHTML = sources.length
      ? sources.map(v => `<option value="${UI.escape(v.id)}">${UI.escape(v.name || this.getPlate(v.id))}</option>`).join('')
      : '<option value="">— ไม่มีรถคันอื่นให้คัดลอก —</option>';
    select.disabled = sources.length === 0;
    if (btn) btn.disabled = sources.length === 0;
  }

  getPlate(carId) {
    const plates = {
      'car-1': '3ชฏ3250',
      'car-2': 'ฆง8434',
      'car-3': '5กล1034',
    };
    return plates[carId] || carId;
  }

  async onVehicleChange() {
    const vehicleId = document.getElementById('stockCar')?.value;
    const hadStaging = this.copyStaging.length > 0;
    this.currentVehicleId = vehicleId;
    this.clearCopyStaging();
    if (hadStaging) {
      window.toast.info('สลับรถแล้ว — ล้างรายการรอคัดลอกที่ค้างอยู่');
    }
    this.renderCopySourceSelect();
    await this.loadItems(vehicleId);
  }

  async loadItems(vehicleId) {
    try {
      const res = await window.vehicleApi.get(vehicleId);
      this.items = res.data?.items || [];
      this.renderItems();
    } catch (error) {
      console.error('Failed to load items:', error);
      this.items = [];
      this.renderItems();
    }
  }

  renderItems() {
    const container = document.getElementById('stockTable');
    if (!container) return;
    
    container.innerHTML = this.items.map((item, index) => `
      <article class="stock-card${item.active ? '' : ' is-off'}" data-item-id="${item.id}">
        <div class="stock-top">
          <div class="stock-name">${UI.escape(item.name)}</div>
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
            <label>วันหมดอายุ</label>
            <input type="date" value="${UI.escape(item.expiry_text)}" data-item-id="${item.id}" data-field="expiry_text" placeholder="วันหมดอายุ">
          </div>
          <div>
            <label>เป็นแบตเตอรี่</label>
            <select data-item-id="${item.id}" data-field="is_battery" style="min-height: 44px;">
              <option value="false" ${!item.is_battery ? 'selected' : ''}>ไม่ใช่</option>
              <option value="true" ${item.is_battery ? 'selected' : ''}>ใช่</option>
            </select>
          </div>
        </div>
        <div style="display: flex; gap: 8px; margin-top: 8px;">
          <button type="button" class="small-btn" data-action="save" data-item-id="${item.id}">บันทึก</button>
          <button type="button" class="small-btn gray" data-action="toggle" data-item-id="${item.id}">
            ${item.active ? 'พักใช้งาน' : 'เปิดใช้งาน'}
          </button>
          <button type="button" class="small-btn danger" data-action="delete" data-item-id="${item.id}">ลบ</button>
        </div>
      </article>
    `).join('') || '<p class="empty">ยังไม่มีอุปกรณ์ในรถคันนี้</p>';
    
    // Bind events
    container.querySelectorAll('input[data-field], select[data-field]').forEach(input => {
      input.addEventListener('change', () => this.updateItem(input.dataset.itemId, input.dataset.field, input.value));
    });
    
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
    document.getElementById('stockExpiry').value = '';
    document.getElementById('stockBattery').value = 'false';
    const currentEl = document.getElementById('stockCurrent');
    if (currentEl) currentEl.value = '';
    const minEl = document.getElementById('stockMin');
    if (minEl) minEl.value = '';
  }

  async handleAddItem(event) {
    event.preventDefault();
    
    const name = document.getElementById('stockName')?.value?.trim();
    const standard = document.getElementById('stockStandard')?.value?.trim();
    const expiry = document.getElementById('stockExpiry')?.value || '';
    const isBattery = document.getElementById('stockBattery')?.value === 'true';
    const currentStock = document.getElementById('stockCurrent')?.value?.trim() ?? '';
    const minStock = document.getElementById('stockMin')?.value?.trim() ?? '';
    const vehicleId = this.currentVehicleId;
    
    if (!name || !standard) {
      window.toast.error('กรอกชื่อและจำนวน');
      return;
    }
    
    if (!vehicleId) {
      window.toast.error('เลือกรถก่อน');
      return;
    }

    const payload = { name, standard, is_battery: isBattery };
    if (currentStock !== '') payload.current_stock = Number(currentStock);
    if (minStock !== '') payload.min_stock = Number(minStock);
    
    try {
      const res = await window.vehicleApi.addItem(vehicleId, payload);
      this.hideAddItemForm();
      const stock = res?.data?.stock;
      if (stock && !stock.error) {
        window.toast.success(`เพิ่มอุปกรณ์เรียบร้อย • คลัง “${stock.name}” = ${stock.current_qty}` + (Number(stock.min_qty) > 0 ? ` (ขั้นต่ำ ${stock.min_qty})` : ''));
      } else if (stock && stock.error) {
        window.toast.warning(`เพิ่มอุปกรณ์แล้ว แต่ตั้งสต็อกไม่สำเร็จ: ${stock.error}`);
      } else {
        window.toast.success('เพิ่มอุปกรณ์เรียบร้อย');
      }
      await this.loadItems(vehicleId);
    } catch (error) {
      window.toast.error('เพิ่มไม่สำเร็จ: ' + error.message);
    }
  }

  async updateItem(itemId, field, value) {
    // For immediate UI feedback, we update locally
    // The actual save happens on "บันทึก" button click
  }

  async saveItem(itemId) {
    const item = this.items.find(i => i.id === itemId);
    if (!item) return;
    
    const standardEl = document.querySelector(`input[data-item-id="${itemId}"][data-field="standard"]`);
    const expiryEl = document.querySelector(`input[data-item-id="${itemId}"][data-field="expiry_text"]`);
    const batteryEl = document.querySelector(`select[data-item-id="${itemId}"][data-field="is_battery"]`);
    
    const data = {};
    if (standardEl) data.standard = standardEl.value;
    if (expiryEl) data.expiry_text = expiryEl.value;
    if (batteryEl) data.is_battery = batteryEl.value === 'true';
    
    if (Object.keys(data).length === 0) return;
    
    try {
      await window.vehicleApi.updateItem(itemId, data);
      window.toast.success('อัปเดตเรียบร้อย');
      await this.loadItems(this.currentVehicleId);
    } catch (error) {
      window.toast.error('อัปเดตไม่สำเร็จ: ' + error.message);
      await this.loadItems(this.currentVehicleId);
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
    if (!confirm('ยืนยันลบอุปกรณ์นี้? (จะเป็นการพักใช้งาน)')) return;
    
    try {
      await window.vehicleApi.updateItem(itemId, { active: false });
      window.toast.success('ลบเรียบร้อย');
      await this.loadItems(this.currentVehicleId);
    } catch (error) {
      window.toast.error('ลบไม่สำเร็จ: ' + error.message);
    }
  }

  // ---------- Copy items from another vehicle ----------

  vehicleLabel(vehicle) {
    return (vehicle && vehicle.name) || this.getPlate(vehicle?.id || '');
  }

  async handleCopyStart() {
    const sourceId = document.getElementById('copySourceCar')?.value || '';
    if (!sourceId) return;
    if (String(sourceId) === String(this.currentVehicleId)) {
      window.toast.error('รถต้นทางต้องไม่ใช่คันเดียวกับรถที่กำลังแก้ไข');
      return;
    }

    const source = this.vehicles.find(v => String(v.id) === String(sourceId));
    const target = this.vehicles.find(v => String(v.id) === String(this.currentVehicleId));
    const sourceLabel = this.vehicleLabel(source);
    const targetLabel = this.vehicleLabel(target);

    let sourceItems;
    try {
      const res = await window.vehicleApi.get(sourceId);
      sourceItems = res.data?.items || [];
    } catch (error) {
      window.toast.error('ดึงข้อมูลรถต้นทางไม่สำเร็จ: ' + error.message);
      return;
    }

    if (sourceItems.length === 0) {
      await window.alertDialog(`รถคัน ${sourceLabel} ยังไม่มีอุปกรณ์ในทะเบียนให้คัดลอก`);
      return;
    }

    const confirmed = await this.confirmCopyDialog(sourceLabel, targetLabel, sourceItems.length, this.copyStaging.length > 0);
    if (!confirmed) return;

    this.copyStaging = sourceItems.map(item => ({
      name: item.name || '',
      standard: item.standard || '',
      expiry_text: item.expiry_text || '',
      is_battery: !!item.is_battery,
    }));
    this.copySourceLabel = sourceLabel;
    this.renderCopyPreview();
    window.toast.info(`ดึงรายการจาก ${sourceLabel} จำนวน ${sourceItems.length} รายการ — ตรวจสอบก่อนกดยืนยัน`);
  }

  confirmCopyDialog(sourceLabel, targetLabel, count, replacing = false) {
    return new Promise((resolve) => {
      const modal = new Modal({
        title: 'ยืนยันการคัดลอกอุปกรณ์',
        size: 'sm',
        content: `
          <p class="mb-2">คุณต้องการคัดลอกรายการอุปกรณ์จากรถคัน <strong>${UI.escape(sourceLabel)}</strong> ใช่หรือไม่?</p>
          <p class="mb-2 text-sm">ระบบจะนำอุปกรณ์ทั้งหมด <strong>${count} รายการ</strong> มาแสดงในรถคัน <strong>${UI.escape(targetLabel)}</strong> เพื่อให้ตรวจสอบ/แก้ไขก่อนบันทึก — ยังไม่มีการบันทึกจนกว่าจะกด “ยืนยันคัดลอก”</p>
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
              <input type="date" data-copy-field="expiry_text" value="${UI.escape(row.expiry_text)}">
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

    list.querySelectorAll('[data-copy-field]').forEach(input => {
      input.addEventListener('input', () => this.onCopyFieldChange(input));
    });
    list.querySelectorAll('[data-copy-action="remove"]').forEach(btn => {
      btn.addEventListener('click', () => {
        this.removeCopyRow(Number(btn.closest('.copy-row')?.dataset.idx));
      });
    });
  }

  onCopyFieldChange(input) {
    const rowEl = input.closest('.copy-row');
    const idx = Number(rowEl?.dataset.idx);
    const field = input.dataset.copyField;
    if (!rowEl || Number.isNaN(idx) || !this.copyStaging[idx]) return;

    this.copyStaging[idx][field] = field === 'is_battery' ? input.value === 'true' : input.value;

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
        })),
      });
      const created = res.data?.created ?? this.copyStaging.length;
      this.clearCopyStaging();
      window.toast.success(`คัดลอกอุปกรณ์แล้ว ${created} รายการ`);
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

  // ---------- Add new vehicle ----------

  showAddVehicleForm() {
    if (this._vehicleModal) {
      try { this._vehicleModal.destroy(); } catch (e) { /* stale node */ }
      this._vehicleModal = null;
    }

    const sourceOptions = this.vehicles.map(v =>
      `<option value="${UI.escape(v.id)}">${UI.escape(this.vehicleLabel(v))}</option>`
    ).join('');

    const modal = new Modal({
      title: 'เพิ่มรถใหม่',
      size: 'lg',
      content: `
        <form id="vehicleForm" novalidate>
          <div class="grid gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
              <label for="vehPlate" class="!mt-0">ทะเบียนรถ <span class="text-red-500" aria-hidden="true">*</span></label>
              <input id="vehPlate" type="text" placeholder="เช่น กข-1234 กรุงเทพฯ" autocomplete="off">
              <p class="mt-1 hidden text-xs font-semibold text-red-600" id="vehPlateError" role="alert"></p>
            </div>
            <div>
              <label for="vehBrand">ยี่ห้อ / รุ่น</label>
              <input id="vehBrand" type="text" placeholder="เช่น Toyota Commuter">
            </div>
            <div>
              <label for="vehType">ประเภทรถ / ชื่อเรียกประจำรถ</label>
              <input id="vehType" type="text" placeholder="เช่น รถ Refer คันที่ 4">
            </div>
            <div>
              <label for="vehStatus">สถานะรถ</label>
              <select id="vehStatus">
                <option value="available" selected>พร้อมใช้งาน</option>
                <option value="maintenance">กำลังซ่อมบำรุง</option>
                <option value="disabled">งดใช้งาน</option>
              </select>
            </div>
            <div>
              <label for="vehOdometer">เลขไมล์เริ่มต้น (กม.)</label>
              <input id="vehOdometer" type="number" min="0" step="1" inputmode="numeric" placeholder="เช่น 45000">
              <p class="mt-1 hidden text-xs font-semibold text-red-600" id="vehOdometerError" role="alert"></p>
            </div>
            <div class="md:col-span-2">
              <label for="vehNotes">หมายเหตุเพิ่มเติม</label>
              <textarea id="vehNotes" rows="2" placeholder="รายละเอียดเพิ่มเติม เช่น ประจำจุดใด ใช้งานช่วงไหน"></textarea>
            </div>
            <div class="md:col-span-2">
              <label for="vehCopySource">คัดลอกรายการอุปกรณ์จากรถที่มีอยู่แล้ว</label>
              <select id="vehCopySource">
                <option value="">— ไม่คัดลอก —</option>
                ${sourceOptions}
              </select>
              <p class="mt-1 text-xs leading-relaxed text-slate-500">เลือกได้เพื่อให้รถคันใหม่มีรายการอุปกรณ์พร้อมใช้ทันทีหลังบันทึก (คัดลอกเฉพาะรายการที่ใช้งานอยู่)</p>
            </div>
          </div>
        </form>
      `,
      onClose: () => { this._vehicleModal = null; },
    }).render();

    this._vehicleModal = modal;

    const actions = modal.element.querySelector('.sheet-actions');
    actions.innerHTML = `
      <button type="button" class="small-btn gray flex-1" data-action="cancel">ยกเลิก / ล้างข้อมูล</button>
      <button type="button" class="flex-1" data-action="save">บันทึกข้อมูลรถใหม่</button>
    `;
    actions.querySelector('[data-action="cancel"]').onclick = () => this.cancelAddVehicle();
    actions.querySelector('[data-action="save"]').onclick = () => this.handleAddVehicle();

    const form = modal.element.querySelector('#vehicleForm');
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.handleAddVehicle();
    });
    modal.element.querySelector('#vehPlate')?.addEventListener('input', () => this.setVehicleFieldError('vehPlate', ''));
    modal.element.querySelector('#vehOdometer')?.addEventListener('input', () => this.setVehicleFieldError('vehOdometer', ''));

    modal.open();
  }

  getVehicleFormEl() {
    return this._vehicleModal?.element.querySelector('#vehicleForm') || null;
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

  validateVehicleForm() {
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

    if (firstInvalid) {
      form.querySelector('#' + firstInvalid)?.focus();
      window.toast.error('กรุณาตรวจสอบข้อมูลในฟอร์มอีกครั้ง');
      return null;
    }

    return {
      plate,
      name: value('vehType'),
      brand_model: value('vehBrand'),
      status: form.querySelector('#vehStatus')?.value || 'available',
      odometer_initial: odometerRaw === '' ? '' : Number(odometerRaw),
      notes: value('vehNotes'),
      copy_source_vehicle_id: form.querySelector('#vehCopySource')?.value || '',
    };
  }

  async handleAddVehicle() {
    const payload = this.validateVehicleForm();
    if (!payload) return;

    const saveBtn = this._vehicleModal?.element.querySelector('[data-action="save"]');
    if (saveBtn) UI.setLoading(saveBtn, true);
    try {
      const res = await window.adminApi.createVehicle(payload);
      const created = res.data || {};
      const copied = created.copied_items || 0;
      window.toast.success(
        `เพิ่มรถใหม่แล้ว: ${created.name || payload.plate}` + (copied > 0 ? ` — คัดลอกอุปกรณ์ ${copied} รายการ` : '')
      );
      // destroy (not close) so the form node leaves the DOM — a hidden leftover
      // would duplicate field IDs and shadow the next form's reads
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
      window.toast.error('เพิ่มรถไม่สำเร็จ: ' + (error.message || 'ไม่ทราบสาเหตุ'));
    } finally {
      if (saveBtn) UI.setLoading(saveBtn, false);
    }
  }

  async cancelAddVehicle() {
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