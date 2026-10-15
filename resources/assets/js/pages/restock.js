// ========================================
// PAGE: RESTOCK
// ========================================

// สถานะที่แก้ไขจำนวนเติมได้ (PENDING = กดบันทึกแล้วระบบจะเริ่มดำเนินการให้อัตโนมัติ)
const RS_EDITABLE_STATUSES = ['PENDING', 'IN_PROGRESS', 'PARTIAL'];

class RestockPage {
  constructor() {
    this.requests = [];
    this.vehicles = [];
    this.nurses = [];
    this.currentFilter = 'all';
    this.requestIdFromUrl = null;
    // เก็บค่าที่ผู้ใช้พิมพ์ในช่อง "เติมแล้ว" แต่ยังไม่กดบันทึก — key = `${requestId}:${itemId}`
    // เพื่อไม่ให้ค่าที่พิมพ์หายเมื่อตาราง re-render (เปลี่ยนฟิลเตอร์ / บันทึกแถวอื่น)
    this.fillDrafts = new Map();
    this.init();
  }

  async init() {
    await this.loadReferenceData();
    this.bindEvents();
    this.checkUrlParams();
    this.render();
  }

  isAdmin() {
    return window.auth?.getUser()?.role === 'admin';
  }

  fetchRequests() {
    // Admin เห็นทุกรายการ (รวมยกเลิก) — Staff เห็นเฉพาะที่ได้รับมอบหมาย/ยังไม่มอบหมาย
    return this.isAdmin()
      ? window.restockApi.getAll()
      : window.staffApi.getRestockRequests();
  }

  async loadReferenceData() {
    try {
      const [vehiclesRes, nursesRes, restockRes] = await Promise.all([
        window.vehicleApi.getAll(),
        window.nurseApi.getAll(),
        this.fetchRequests(),
      ]);
      this.vehicles = vehiclesRes.data || [];
      this.nurses = nursesRes.data || [];
      this.requests = restockRes.data || [];
      window.vehicleRef.remember(this.vehicles);
    } catch (error) {
      console.error('Failed to load restock data:', error);
      window.toast.error('โหลดข้อมูลไม่สำเร็จ');
    }
  }

  bindEvents() {
    // Filter chips
    document.getElementById('restockFilterAll')?.addEventListener('click', () => this.setFilter('all'));
    document.getElementById('restockFilterPending')?.addEventListener('click', () => this.setFilter('PENDING'));
    document.getElementById('restockFilterProgress')?.addEventListener('click', () => this.setFilter('IN_PROGRESS'));
    document.getElementById('restockFilterPartial')?.addEventListener('click', () => this.setFilter('PARTIAL'));
    document.getElementById('restockFilterCompleted')?.addEventListener('click', () => this.setFilter('COMPLETED'));

    const list = document.getElementById('restockList');
    if (list) {
      // Delegated listeners: ทำงานกับการ์ดที่ render ใหม่ทุกครั้งโดยไม่ต้อง bind ซ้ำ
      list.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-act]');
        if (btn) this.handleAction(btn.dataset.act, btn);
      });
      list.addEventListener('input', (e) => {
        const input = e.target.closest('.rs-fill-input');
        if (input) this.onFillInput(input);
      });
      list.addEventListener('change', (e) => {
        const input = e.target.closest('.rs-fill-input');
        if (input) this.onFillInput(input);
      });
    }
  }

  checkUrlParams() {
    // Dashboard deep-links use "#restock?..." — the query rides in the hash
    const hash = window.location.hash || '';
    const hashQuery = hash.includes('?') ? hash.slice(hash.indexOf('?') + 1) : '';
    const params = new URLSearchParams(hashQuery || window.location.search);
    this.requestIdFromUrl = params.get('request');
    const carId = params.get('car');

    if (carId) {
      // Filter by vehicle
    }
  }

  setFilter(filter) {
    this.currentFilter = filter;
    // Update active chip
    const chips = {
      'all': 'restockFilterAll',
      'PENDING': 'restockFilterPending',
      'IN_PROGRESS': 'restockFilterProgress',
      'PARTIAL': 'restockFilterPartial',
      'COMPLETED': 'restockFilterCompleted',
    };

    Object.entries(chips).forEach(([value, id]) => {
      const el = document.getElementById(id);
      if (el) el.classList.toggle('on', value === filter);
    });

    this.render();
  }

  render() {
    this.renderRestockList();
  }

  renderRestockList() {
    const container = document.getElementById('restockList');
    if (!container) return;

    let filtered = this.requests;
    if (this.currentFilter !== 'all') {
      filtered = this.requests.filter(r => r.status === this.currentFilter);
    }

    // Sort by priority: PENDING first, then IN_PROGRESS, PARTIAL, COMPLETED
    const statusOrder = { 'PENDING': 1, 'IN_PROGRESS': 2, 'PARTIAL': 3, 'COMPLETED': 4, 'CANCELLED': 5 };
    filtered.sort((a, b) => (statusOrder[a.status] || 99) - (statusOrder[b.status] || 99));

    if (this.requestIdFromUrl) {
      // Highlight specific request
      const targetIndex = filtered.findIndex(r => r.id === this.requestIdFromUrl);
      if (targetIndex !== -1) {
        const target = filtered.splice(targetIndex, 1)[0];
        filtered.unshift(target);
      }
    }

    container.innerHTML = filtered.map(r => this.renderRestockCard(r)).join('') ||
      '<p class="empty">ไม่มีรายการเติมอุปกรณ์</p>';
  }

  /**
   * แถวที่ต้องแสดง = เฉพาะอุปกรณ์ที่ "ต้องเติม > 0" และ "เหลือ > 0" (ยังขาดอยู่)
   * อุปกรณ์ที่ครบหรือไม่ต้องเติมจะถูกซ่อน และถ้าไม่เหลือแถวใดเลยจะแสดงข้อความแทนตาราง
   */
  getVisibleItems(request) {
    return (request.items || []).filter(item => {
      const requested = parseFloat(item.qty_requested || 0);
      const filled = parseFloat(item.qty_filled || 0);
      return requested > 0 && (requested - filled) > 0;
    });
  }

  renderRestockCard(request) {
    const statusLabels = {
      'PENDING': 'รอเติม',
      'IN_PROGRESS': 'กำลังเติม',
      'PARTIAL': 'เติมไม่ครบ',
      'COMPLETED': 'เติมครบ',
      'CANCELLED': 'ยกเลิก',
    };

    const actionLabels = {
      'create': 'สร้างรายการ',
      'start': 'เริ่มเติม',
      'restock': 'บันทึกการเติม',
      'complete': 'ยืนยันครบ',
      'cancel': 'ยกเลิก',
    };

    const canEdit = RS_EDITABLE_STATUSES.includes(request.status);
    const allItems = request.items || [];
    const visibleItems = this.getVisibleItems(request);
    const hiddenCount = allItems.length - visibleItems.length;

    let itemsHtml;
    if (visibleItems.length > 0) {
      itemsHtml = `
        <div class="rs-table-wrap">
          <table class="rs-items">
            <thead>
              <tr>
                <th>อุปกรณ์</th>
                <th>มาตรฐาน</th>
                <th>มีจริง</th>
                <th>ต้องเติม</th>
                <th>บนรถ (ก่อน→หลัง)</th>
                <th>เติมแล้ว</th>
                <th>เหลือ</th>
                <th>ความคืบหน้า</th>
                <th>บันทึก</th>
              </tr>
            </thead>
            <tbody>${visibleItems.map(item => this.renderItemRow(request, item, canEdit)).join('')}</tbody>
          </table>
        </div>
      `;
    } else {
      itemsHtml = `
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-semibold text-teal-800" role="status">
          <span aria-hidden="true">✅</span>
          <span>อุปกรณ์ครบตามมาตรฐานแล้ว</span>
          ${hiddenCount > 0 ? `<span class="text-xs font-normal text-teal-600">(แสดงเฉพาะรายการที่ยังขาด — ซ่อน ${hiddenCount} รายการที่ครบ/ไม่ต้องเติม)</span>` : ''}
        </div>
      `;
    }

    const timelineHtml = (request.actions || []).map(action => `
      <li>
        <b>${actionLabels[action.type] || action.type}</b> — ${UI.formatDateTimeThai(action.at)} • ${UI.escape(action.by_name)}
        ${action.note ? ` • ${UI.escape(action.note)}` : ''}
        ${(action.action_lines || []).length ? ' • ' + action.action_lines.map(l => {
          const it = request.items.find(i => i.item_id === l.itemId);
          return `${UI.escape(it?.name || l.itemId)} ${UI.escape(l.qty)}`;
        }).join(', ') : ''}
      </li>
    `).join('');

    // Determine available actions based on status and user role
    const isAdmin = this.isAdmin();
    let buttonsHtml = '';

    if (request.status === 'PENDING') {
      buttonsHtml = `
        <div class="rs-actions">
          <button type="button" class="act-btn ok" data-act="start" data-id="${request.id}">เริ่มเติม</button>
          ${isAdmin ? `<button type="button" class="act-btn gray" data-act="assign" data-id="${request.id}">มอบหมาย</button>` : ''}
        </div>
      `;
    } else if (request.status === 'IN_PROGRESS' || request.status === 'PARTIAL') {
      buttonsHtml = `
        <div class="rs-actions">
          <button type="button" class="act-btn secondary" data-act="restock" data-id="${request.id}">บันทึกการเติม</button>
          <button type="button" class="act-btn warning" data-act="complete" data-id="${request.id}">ยืนยันครบ</button>
          <button type="button" class="act-btn danger" data-act="cancel" data-id="${request.id}">ยกเลิก</button>
        </div>
      `;
    } else if (request.status === 'COMPLETED') {
      buttonsHtml = `
        <div class="rs-actions">
          <button type="button" class="act-btn gray" data-act="view" data-id="${request.id}">ดูรายละเอียด</button>
        </div>
      `;
    } else if (request.status === 'CANCELLED') {
      buttonsHtml = `
        <div class="rs-actions">
          <button type="button" class="act-btn gray" data-act="view" data-id="${request.id}">ดูรายละเอียด</button>
        </div>
      `;
    }

    const highlightClass = this.requestIdFromUrl === request.id ? ' style="border: 2px solid var(--teal-600);"' : '';

    return `
      <article class="rs-card" data-id="${request.id}" data-car-id="${request.car_id}" data-status="${request.status}" data-at="${request.created_at.slice(0,10)}"${highlightClass}>
        <div class="rs-head">
          <span class="rs-no">${UI.escape(request.no)}</span>
          <span class="chip chip-${request.status}">${statusLabels[request.status] || request.status}</span>
        </div>
        <div class="rs-meta">
          <span class="plate-chip">${UI.escape(vehiclePlate({ id: request.car_id, name: request.car_name }))}</span>
          <span>รถ: ${UI.escape(request.car_name)}</span>
          <span>${UI.formatDateTimeThai(request.created_at)}</span>
          ${request.assigned_to_user_id ? `<span>ผู้ดูแล: ${UI.escape(request.assigned_name || 'กำลังมอบหมาย')}</span>` : ''}
          ${request.reason ? `<span>เหตุผล: ${UI.escape(request.reason)}</span>` : ''}
        </div>
        ${itemsHtml}
        <details class="report-details">
          <summary>ประวัติการดำเนินการ (${request.actions?.length || 0})</summary>
          <ul class="timeline">${timelineHtml}</ul>
        </details>
        ${buttonsHtml}
      </article>
    `;
  }

  /**
   * แถวอุปกรณ์: ช่อง "เติมแล้ว" เป็น <input type="number"> (min=0, max=จำนวนที่ต้องเติม)
   * พร้อมปุ่มบันทึกต่อแถว — การคำนวณ "เหลือ" และ % ความคืบหน้าอัปเดตแบบ real-time ที่ onFillInput()
   */
  renderItemRow(request, item, canEdit) {
    const requested = parseFloat(item.qty_requested || 0);
    const filledSaved = parseFloat(item.qty_filled || 0);
    const key = `${request.id}:${item.item_id}`;

    const draft = this.fillDrafts.get(key);
    const shownValue = draft !== undefined ? draft : String(filledSaved);
    let shownNum = parseFloat(shownValue);
    if (isNaN(shownNum)) shownNum = 0;
    shownNum = Math.min(Math.max(shownNum, 0), requested);

    const remaining = Math.max(0, requested - shownNum);
    const progress = requested > 0 ? Math.min(100, Math.round((shownNum / requested) * 100)) : 100;
    const delta = shownNum - filledSaved;
    const saveDisabled = !canEdit || delta < 1;
    // ช่องนี้คือ "ยอดรวมที่เติมแล้วทั้งหมด" (ไม่ใช่ยอดที่เติมครั้งนี้) — ค่าที่ต่ำกว่ายอดที่บันทึกไว้จริง
    // บันทึกไม่ได้ จึงต้องบอกผู้ใช้ตรง ๆ ว่าทำไมปุ่มถึงมืด (ข้อ 4.2 ห้ามตีความตัวเลขสองแบบปนกัน)
    const belowSaved = delta < 0;
    const saveTitle = canEdit
      ? (belowSaved ? `กรอกได้เฉพาะยอดรวมที่มากกว่าที่บันทึกแล้ว (${this.fmtNum(filledSaved)})`
                   : (delta < 1 ? 'ระบุจำนวนที่เติมเพิ่มก่อนบันทึก' : ''))
      : '';

    // ข้อ 4.3: ยอดบนรถ "ก่อนเติม → หลังเติม" อ่านจากแถวที่บันทึกไว้จริง ไม่เดายอดถ้ายังไม่เคยตรวจนับ
    const stockBefore = this.numOrNull(item.vehicle_stock_before);
    const stockAfter = this.numOrNull(item.vehicle_stock_after);
    const stockTitle = stockBefore === null
      ? 'รถคันนี้ยังไม่เคยถูกตรวจนับยอดคงเหลือ จึงยังไม่แสดงตัวเลข (ระบบจะตั้งยอดใหม่ตอนตรวจรถครั้งถัดไป)'
      : 'ยอดบนรถหลังเติม = ยอดก่อนเติม + จำนวนที่เติมจริง'
        + (delta > 0 ? ' (ตัวจางคือค่าที่จะเกิดหลังบันทึกครั้งนี้)' : '');
    const stockHtml = stockBefore === null
      ? '<span class="rs-stock unknown">ยังไม่เคยตรวจนับ</span>'
      : `<span class="rs-stock">${this.fmtNum(stockBefore)} → <b>${this.fmtNum(stockAfter ?? stockBefore)}</b></span>`;

    const inputHtml = `
      <input type="number" min="0" max="${requested}" step="1"
        class="rs-fill-input m-0 w-24 min-h-0 rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-right text-sm font-semibold tabular-nums text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-200 disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-500"
        value="${UI.escape(shownValue)}"
        data-item-id="${UI.escape(item.item_id)}"
        aria-label="จำนวนที่เติมแล้วของ ${UI.escape(item.name)}"
        ${canEdit ? '' : 'disabled'}
      >
    `;

    const saveBtnHtml = canEdit ? `
      <button type="button"
        class="rs-row-save m-0 w-auto min-h-0 shrink-0 whitespace-nowrap rounded-lg border-0 bg-teal-600 px-3 py-2 text-xs font-bold text-white transition-colors duration-200 hover:bg-teal-700 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-400"
        data-act="row-save" data-id="${request.id}" data-item-id="${UI.escape(item.item_id)}"
        ${saveDisabled ? 'disabled' : ''}
        title="${UI.escape(saveTitle)}"
      >${request.status === 'PENDING' ? 'เริ่มเติม' : '＋ บันทึก'}</button>
    ` : '<span class="text-xs text-slate-400">—</span>';

    return `
      <tr data-key="${UI.escape(key)}" data-requested="${requested}" data-filled="${filledSaved}">
        <td>${UI.escape(item.name)}</td>
        <td class="num">${UI.escape(item.standard)}</td>
        <td class="num">${UI.escape(item.actual_qty || '-')}</td>
        <td class="num">${UI.escape(item.qty_requested)}</td>
        <td class="num rs-stock-cell" data-before="${stockBefore === null ? '' : stockBefore}" data-after="${stockAfter === null ? '' : stockAfter}" title="${UI.escape(stockTitle)}">${stockHtml}</td>
        <td class="num">${inputHtml}</td>
        <td class="num rs-left">${belowSaved
          ? `<span class="chip chip-miss">ต่ำกว่าที่บันทึกแล้ว ${this.fmtNum(filledSaved)}</span>`
          : (remaining > 0 ? this.fmtNum(remaining) : '<span class="chip chip-ok">ครบ</span>')}</td>
        <td class="num">
          <div style="min-width: 96px;">
            <div class="progress-track" style="height: 6px;">
              <div class="progress-fill" style="width: ${progress}%"></div>
            </div>
            <small class="rs-pct">${progress}%</small>
          </div>
        </td>
        <td class="num">${saveBtnHtml}</td>
      </tr>
    `;
  }

  /**
   * Real-time: clamp ค่าให้อยู่ใน [0, ต้องเติม] แล้วอัปเดตช่อง "เหลือ" + progress bar + สถานะปุ่มบันทึก
   */
  onFillInput(input) {
    const tr = input.closest('tr[data-key]');
    if (!tr) return;

    const requested = parseFloat(input.max) || 0;
    let value = parseFloat(input.value);
    if (!isNaN(value) && value > requested) {
      value = requested;
      input.value = String(requested);
    } else if (!isNaN(value) && value < 0) {
      value = 0;
      input.value = '0';
    }
    const effective = isNaN(value) ? 0 : value;
    const filledSaved = parseFloat(tr.dataset.filled || '0');
    const belowSaved = effective < filledSaved;

    const leftCell = tr.querySelector('.rs-left');
    if (leftCell) {
      const remaining = Math.max(0, requested - effective);
      leftCell.innerHTML = belowSaved
        ? `<span class="chip chip-miss">ต่ำกว่าที่บันทึกแล้ว ${this.fmtNum(filledSaved)}</span>`
        : (remaining > 0 ? String(remaining) : '<span class="chip chip-ok">ครบ</span>');
    }

    const progress = requested > 0 ? Math.min(100, Math.round((effective / requested) * 100)) : 100;
    const fill = tr.querySelector('.progress-fill');
    if (fill) fill.style.width = progress + '%';
    const pctLabel = tr.querySelector('.rs-pct');
    if (pctLabel) pctLabel.textContent = progress + '%';

    const saveBtn = tr.querySelector('.rs-row-save');
    if (saveBtn && saveBtn.dataset.busy !== '1') {
      const delta = effective - filledSaved;
      saveBtn.disabled = delta < 1;
      saveBtn.title = belowSaved
        ? `กรอกได้เฉพาะยอดรวมที่มากกว่าที่บันทึกแล้ว (${this.fmtNum(filledSaved)})`
        : (delta < 1 ? 'ระบุจำนวนที่เติมเพิ่มก่อนบันทึก' : '');
    }

    // พรีวิว "ยอดบนรถหลังบันทึก" จากค่าที่บันทึกจริงใน DB + ส่วนต่างที่ยังไม่บันทึก
    const stockCell = tr.querySelector('.rs-stock-cell');
    if (stockCell) {
      const before = this.numOrNull(stockCell.dataset.before);
      const after = this.numOrNull(stockCell.dataset.after);
      if (before === null) {
        stockCell.innerHTML = '<span class="rs-stock unknown">ยังไม่เคยตรวจนับ</span>';
      } else {
        const saved = after === null ? before : after;
        const add = Math.max(0, effective - filledSaved);
        const projected = Math.round((saved + add) * 100) / 100;
        stockCell.innerHTML = `<span class="rs-stock">${this.fmtNum(before)} → <b>${this.fmtNum(saved)}</b>`
          + (add > 0 ? ` <span class="rs-stock-projected">+ ${this.fmtNum(add)} = ${this.fmtNum(projected)}</span>` : '')
          + '</span>';
      }
    }

    this.fillDrafts.set(tr.dataset.key, input.value);
  }

  numOrNull(value) {
    if (value === null || value === undefined || value === '') return null;
    const n = parseFloat(value);
    return Number.isFinite(n) ? n : null;
  }

  fmtNum(value) {
    const n = parseFloat(value);
    return Number.isFinite(n) ? String(Math.round(n * 100) / 100) : '-';
  }

  replaceRequest(updated) {
    if (!updated) return;
    const idx = this.requests.findIndex(r => r.id === updated.id);
    if (idx !== -1) {
      this.requests[idx] = updated;
    } else {
      this.requests.push(updated);
    }
  }

  async handleAction(action, button) {
    const requestId = button.dataset.id;
    switch (action) {
      case 'start':
        await this.startRestock(requestId);
        break;
      case 'restock':
        await this.openRestockSheet(requestId);
        break;
      case 'complete':
        await this.completeRestock(requestId);
        break;
      case 'cancel':
        await this.cancelRestock(requestId);
        break;
      case 'assign':
        await this.assignRestock(requestId);
        break;
      case 'view':
        await this.viewDetail(requestId);
        break;
      case 'row-save':
        await this.saveRow(button, requestId, button.dataset.itemId);
        break;
    }
  }

  /**
   * บันทึกจำนวนเติมของแถวเดียว (delta = ค่าที่กรอก − ที่บันทึกไว้เดิม)
   * ถ้ารายการยัง PENDING จะเริ่มดำเนินการ (start) ให้อัตโนมัติก่อน แล้วจึงบันทึกจำนวน
   */
  async saveRow(button, requestId, itemId) {
    if (button.disabled || button.dataset.busy === '1') return;

    const request = this.requests.find(r => r.id === requestId);
    const item = (request?.items || []).find(i => i.item_id === itemId);
    if (!request || !item) return;

    const requested = parseFloat(item.qty_requested || 0);
    const filledSaved = parseFloat(item.qty_filled || 0);
    const input = button.closest('tr')?.querySelector('.rs-fill-input');
    if (!input) return;

    let value = parseFloat(input.value);
    if (isNaN(value)) value = 0;
    value = Math.min(Math.max(value, 0), requested);
    const delta = value - filledSaved;

    if (delta < 1) {
      window.toast.warning('กรุณาระบุจำนวนที่เติมเพิ่มก่อนบันทึก');
      return;
    }

    button.dataset.busy = '1';
    button.disabled = true;
    button.textContent = 'กำลังบันทึก...';

    try {
      if (request.status === 'PENDING') {
        const started = await window.staffApi.startRestock(requestId, {});
        this.replaceRequest(started.data);
      }

      const result = await window.staffApi.recordRestock(requestId, {
        items: [{ item_id: itemId, qty: delta }],
        note: '',
      });
      this.replaceRequest(result.data);
      this.fillDrafts.delete(`${requestId}:${itemId}`);

      const updatedItem = (result.data?.items || []).find(i => i.item_id === itemId);
      const newFilled = parseFloat(updatedItem?.qty_filled || 0);
      this.notifyRestockResult(result.data?.restock_result, item.name, newFilled >= requested);
    } catch (error) {
      window.toast.error('บันทึกไม่สำเร็จ: ' + (error?.message || 'กรุณาลองใหม่'));
    } finally {
      this.render();
    }
  }

  /**
   * แสดงผลจากการบันทึกการเติม 1 ครั้ง (ข้อ 4): เติมจริงเท่าไหร่ / ข้ามอะไร / คลังใกล้หมดหรือของไม่พอ
   */
  notifyRestockResult(res, nameHint, completed = false) {
    if (!res) {
      window.toast.success('บันทึกการเติมแล้ว');
      return;
    }

    const applied = res.applied || [];
    const untracked = res.untracked || [];
    if (applied.length > 0) {
      const detail = applied.map(a => `${a.name} +${a.qty}`).join(', ');
      const whNote = untracked.length > 0
        ? ' • ยอดบนรถอัปเดตแล้ว (คลังกลางยังไม่ถูกตัด เพราะยังไม่ได้ตรวจนับคลัง)'
        : ' • ยอดบนรถกับคลังกลางอัปเดตแล้ว';
      window.toast.success(`เติมขึ้นรถแล้ว: ${detail}${whNote}`);
    }

    (res.skipped || []).forEach(s => {
      window.toast.warning(`ข้าม “${s.name || s.item_id || '-'}”: ${s.reason}`);
    });
    untracked.forEach(u => {
      window.toast.warning(`คลังกลาง “${u.name}” ยังไม่เคยรับเข้า/ปรับยอด — เติมขึ้นรถ ${u.qty} แล้วแต่ไม่ได้ตัดคลัง กรุณาตรวจนับคลังกลางในเมนูคลังสต็อก`);
    });
    (res.warnings || []).forEach(w => window.toast.warning(w));
    (res.alerts || []).forEach(a => {
      window.toast.warning(`คลังกลาง “${a.name}” เหลือ ${a.balance} (ขั้นต่ำ ${a.min}) — ควรเบิกเข้าคลัง`);
    });

    if (res.status === 'COMPLETED' || completed) {
      window.toast.success('รายการนี้เติมครบตามมาตรฐานแล้ว (ระบบปิดงานให้อัตโนมัติ)');
    }
  }

  async startRestock(requestId) {
    try {
      const result = await window.staffApi.startRestock(requestId, {});
      this.replaceRequest(result.data);
      window.toast.success('เริ่มดำเนินการเติมอุปกรณ์');
      this.render();
    } catch (error) {
      window.toast.error('ไม่สามารถเริ่มได้: ' + error.message);
    }
  }

  async openRestockSheet(requestId) {
    const request = this.requests.find(r => r.id === requestId);
    if (!request) return;

    // Build sheet content for recording restock quantities
    let body = `
      <label for="rsSheetNote" style="margin-top:10px;display:block">หมายเหตุ</label>
      <input id="rsSheetNote" maxlength="500" type="text" placeholder="หมายเหตุ (ถ้ามี)">
      <div style="margin-top: 12px; max-height: 40vh; overflow-y: auto;">
    `;

    (request.items || []).forEach(item => {
      const requested = parseFloat(item.qty_requested || 0);
      const filled = parseFloat(item.qty_filled || 0);
      const remaining = Math.max(0, requested - filled);

      if (remaining > 0) {
        body += `
          <div style="margin-bottom: 12px;">
            <label style="display:block;margin-bottom:4px;font-weight:600">${UI.escape(item.name)} <span class="muted">(ต้องเติม ${requested} | เติมแล้ว ${filled} | เหลือ ${remaining})</span></label>
            <input type="number" min="1" max="${remaining}" step="1" class="restock-qty-input" data-item-id="${item.item_id}" placeholder="จำนวนที่เติม (เหลือ ${remaining})" style="width:100%;padding:12px;border:1.5px solid #d1d5db;border-radius:10px;">
          </div>
        `;
      } else {
        body += `
          <div style="margin-bottom: 12px; padding: 8px; background: var(--ok-soft); border-radius: 8px;">
            <strong>${UI.escape(item.name)}</strong> <span class="chip chip-ok">เติมครบแล้ว</span>
          </div>
        `;
      }
    });

    body += '</div>';

    document.getElementById('rsSheetTitle').textContent = 'บันทึกการเติมอุปกรณ์ — ' + request.no;
    document.getElementById('rsSheetBody').innerHTML = body;
    document.getElementById('restockSheet').classList.remove('hidden');

    // Store context for confirmation
    this.sheetContext = { requestId, action: 'restock' };
  }

  async confirmRestockAction() {
    if (!this.sheetContext) return;
    const { requestId, action } = this.sheetContext;

    if (action === 'restock') {
      const items = [];
      document.querySelectorAll('.restock-qty-input').forEach(input => {
        const qty = parseInt(input.value) || 0;
        if (qty > 0) {
          items.push({
            item_id: input.dataset.itemId,
            qty: qty,
          });
        }
      });

      if (items.length === 0) {
        window.toast.error('กรุณาระบุจำนวนที่เติมอย่างน้อย 1 รายการ');
        return;
      }

      const note = document.getElementById('rsSheetNote')?.value || '';

      try {
        const result = await window.staffApi.recordRestock(requestId, {
          items,
          note,
        });

        this.replaceRequest(result.data);
        items.forEach(it => this.fillDrafts.delete(`${requestId}:${it.item_id}`));
        this.notifyRestockResult(result.data?.restock_result);
        this.closeRestockSheet();
        this.render();
      } catch (error) {
        window.toast.error('บันทึกไม่สำเร็จ: ' + (error?.message || 'กรุณาลองใหม่'));
      }
    }
  }

  async completeRestock(requestId) {
    if (!confirm('ยืนยันว่าเติมครบทุกรายการแล้ว?')) return;

    try {
      const result = await window.staffApi.completeRestock(requestId, {
        note: 'เติมครบตามมาตรฐาน',
      });

      this.replaceRequest(result.data);
      window.toast.success('ยืนยันการเติมครบแล้ว');
      this.render();
    } catch (error) {
      window.toast.error('ไม่สามารถยืนยันได้: ' + error.message);
    }
  }

  async cancelRestock(requestId) {
    const reason = prompt('กรุณาระบุเหตุผลการยกเลิก:');
    if (!reason?.trim()) {
      window.toast.error('ต้องระบุเหตุผลการยกเลิก');
      return;
    }

    if (!confirm('ยืนยันยกเลิกรายการนี้?')) return;

    try {
      const result = await window.staffApi.cancelRestock(requestId, {
        reason,
      });

      this.replaceRequest(result.data);
      window.toast.success('ยกเลิกสำเร็จ');
      this.render();
    } catch (error) {
      window.toast.error('ยกเลิกไม่สำเร็จ: ' + error.message);
    }
  }

  async assignRestock(requestId) {
    const userId = prompt('กรอกรหัสผู้ใช้ที่จะมอบหมาย (User ID):');
    if (!userId?.trim()) return;

    try {
      const result = await window.adminApi.assignRestock(requestId, { assigned_user_id: userId });
      this.replaceRequest(result.data);
      window.toast.success('มอบหมายสำเร็จ');
      this.render();
    } catch (error) {
      window.toast.error('มอบหมายไม่สำเร็จ: ' + error.message);
    }
  }

  async viewDetail(requestId) {
    // Could open a modal with full details
    window.toast.info('ดูรายละเอียด - เปิดในรูปแบบเต็ม');
  }

  closeRestockSheet() {
    document.getElementById('restockSheet').classList.add('hidden');
    this.sheetContext = null;
  }

  async reload() {
    try {
      const res = await this.fetchRequests();
      this.requests = res.data || [];
      this.checkUrlParams();
      this.render();
    } catch (error) {
      console.error('Failed to reload:', error);
    }
  }
}

// Initialize
let restockPage;
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('tabContentRestock') && !document.getElementById('tabContentRestock').classList.contains('hidden')) {
    restockPage = new RestockPage();
    window.restockPage = restockPage;
  }
});

// Global functions
window.setRestockFilter = (filter) => restockPage?.setFilter(filter);
window.initRestock = () => {
  if (!restockPage) {
    restockPage = new RestockPage();
    window.restockPage = restockPage;
  } else {
    restockPage.reload();
  }
};

// Sheet handlers
document.getElementById('rsSheetOk')?.addEventListener('click', () => restockPage?.confirmRestockAction());
document.getElementById('rsSheetCancel')?.addEventListener('click', () => restockPage?.closeRestockSheet());
