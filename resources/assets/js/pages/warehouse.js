// ========================================
// PAGE: WAREHOUSE / CENTRAL STOCK (คลังสต็อกกลาง)
// ========================================

const whState = {
  items: [],
  summary: null,
  filter: 'all',
  search: '',
  txLimit: 100,
  bound: false,
};

const WH_STATUS_LABEL = {
  ok: 'ปกติ',
  low: 'ใกล้หมด',
  out: 'หมดสต็อก',
  unset: 'ยังไม่กำหนด',
};

const WH_TX_META = {
  INIT: { label: '🏁 ตั้งต้น', cls: 'is-init' },
  RECEIVE: { label: '📥 รับเข้า', cls: 'is-receive' },
  ISSUE: { label: '🔻 ตัดจ่าย (ใช้ไป)', cls: 'is-issue' },
  ADJUST: { label: '⚙️ ปรับปรุง', cls: 'is-adjust' },
};

function whFmtQty(value) {
  const n = Number(value) || 0;
  return Number.isInteger(n) ? String(n) : String(Math.round(n * 100) / 100);
}

function whEscape(value) {
  return UI.escape(String(value ?? ''));
}

async function initWarehouse() {
  if (!whState.bound) {
    whState.bound = true;
    bindWarehouseEvents();
  }
  await refreshWarehouseAll();
}

function bindWarehouseEvents() {
  document.getElementById('whRefreshBtn')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    UI.setLoading(btn, true);
    try {
      await refreshWarehouseAll();
      window.toast.success('รีเฟรชข้อมูลคลังแล้ว');
    } finally {
      UI.setLoading(btn, false);
    }
  });

  document.getElementById('whSearch')?.addEventListener('input', (e) => {
    whState.search = e.target.value.trim().toLowerCase();
    renderWarehouseTable();
  });

  document.querySelectorAll('.wh-filter-btn').forEach((btn) => {
    btn.addEventListener('click', () => applyWarehouseFilter(btn.dataset.whFilter || 'all'));
  });

  document.getElementById('whAlertFilterBtn')?.addEventListener('click', () => {
    applyWarehouseFilter('alert');
    document.getElementById('whTable')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  document.getElementById('whTxLimit')?.addEventListener('change', (e) => {
    whState.txLimit = Number(e.target.value) || 100;
    loadWarehouseTransactions();
  });

  const table = document.getElementById('whTable');
  table?.addEventListener('click', (e) => {
    const receiveBtn = e.target.closest('[data-wh-action="receive"]');
    if (receiveBtn) {
      handleWarehouseReceive(receiveBtn);
      return;
    }
    const adjustBtn = e.target.closest('[data-wh-action="adjust"]');
    if (adjustBtn) {
      openAdjustModal(adjustBtn.dataset.id);
    }
  });
  table?.addEventListener('keydown', (e) => {
    if (e.target.matches('[data-wh-qty]') && e.key === 'Enter') {
      const receiveBtn = e.target.closest('.wh-row')?.querySelector('[data-wh-action="receive"]');
      if (receiveBtn) handleWarehouseReceive(receiveBtn);
    }
  });
}

function applyWarehouseFilter(filter) {
  whState.filter = filter;
  document.querySelectorAll('.wh-filter-btn').forEach((b) => {
    b.classList.toggle('active', b.dataset.whFilter === filter);
  });
  renderWarehouseTable();
}

async function refreshWarehouseAll() {
  // ทะเบียนรถกลางต้องใช้ใน "รถ/คัน" ของประวัติการเคลื่อนไหว (ledger เก็บแค่ car_id)
  await Promise.all([window.vehicleRef.ensure(), loadWarehouseOverview(), loadWarehouseTransactions()]);
}

async function loadWarehouseOverview() {
  const table = document.getElementById('whTable');
  try {
    const res = await window.stockApi.getOverview();
    whState.items = res.data?.items || [];
    whState.summary = res.data?.summary || null;
    renderWarehouseStats();
    renderWarehouseTable();
    setWarehouseBadge(Number(whState.summary?.alerts) || 0);
  } catch (error) {
    if (table) table.innerHTML = '<p class="empty">โหลดข้อมูลคลังไม่สำเร็จ</p>';
    window.toast.error('โหลดคลังสต็อกไม่สำเร็จ: ' + (error.message || ''));
  }
}

function renderWarehouseStats() {
  const s = whState.summary || {};
  const set = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.textContent = String(value ?? '–');
  };

  const total = Number(s.total) || 0;
  const low = Number(s.low) || 0;
  const out = Number(s.out) || 0;
  const unset = Number(s.unset) || 0;
  set('whStatTotal', total);
  set('whStatOk', Math.max(0, total - low - out - unset));
  set('whStatLow', low);
  set('whStatOut', out);

  const banner = document.getElementById('whAlertBanner');
  const text = document.getElementById('whAlertText');
  const alerts = Number(s.alerts) || 0;
  if (banner && text) {
    banner.classList.toggle('hidden', alerts === 0);
    if (alerts > 0) {
      const parts = [];
      if (out > 0) parts.push(`หมดสต็อก ${out} รายการ`);
      if (low > 0) parts.push(`ต่ำกว่าขั้นต่ำ ${low} รายการ`);
      text.textContent = `อุปกรณ์ ${alerts} รายการต้องเติมเข้าคลัง (${parts.join(' • ')}) — กรุณาตรวจสอบและสั่งเติม`;
    }
  }
}

function renderWarehouseTable() {
  const table = document.getElementById('whTable');
  if (!table) return;

  const isAdmin = !!window.auth?.isAdmin?.();
  let items = whState.items;

  if (whState.filter === 'alert') {
    items = items.filter((i) => i.status === 'low' || i.status === 'out');
  } else if (whState.filter === 'ok') {
    items = items.filter((i) => i.status === 'ok');
  } else if (whState.filter === 'unset') {
    items = items.filter((i) => i.status === 'unset');
  }
  if (whState.search) {
    items = items.filter((i) => String(i.name || '').toLowerCase().includes(whState.search));
  }

  if (items.length === 0) {
    table.innerHTML = whState.items.length === 0
      ? '<p class="empty">ยังไม่มีรายการในคลัง — เพิ่มอุปกรณ์ในเมนู “ทะเบียนรถ/อุปกรณ์” แล้วกำหนดสต็อกปัจจุบัน/ขั้นต่ำ</p>'
      : '<p class="empty">ไม่พบรายการตามเงื่อนไขที่กรอง</p>';
    return;
  }

  table.innerHTML = items.map((item) => {
    const status = item.status || 'unset';
    const current = Number(item.current_qty) || 0;
    const min = Number(item.min_qty) || 0;
    const name = whEscape(item.name);
    return `
      <div class="wh-row is-${status}" data-id="${whEscape(item.id)}">
        <div class="wh-row-top">
          <span class="wh-row-name">${name}</span>
          <span class="wh-badge is-${status}">${WH_STATUS_LABEL[status] || whEscape(status)}</span>
        </div>
        <div class="wh-row-nums">
          <span>คงเหลือ <strong>${whFmtQty(current)}</strong></span>
          <span>ขั้นต่ำ <strong>${min > 0 ? whFmtQty(min) : '—'}</strong></span>
        </div>
        <div class="wh-row-actions">
          <input type="number" inputmode="decimal" min="0" step="any" placeholder="จำนวนรับเข้า" data-wh-qty aria-label="จำนวนรับเข้าของ ${name}">
          <button type="button" class="small-btn" data-wh-action="receive" data-id="${whEscape(item.id)}">📥 เติมเข้าคลัง</button>
          ${isAdmin ? `<button type="button" class="small-btn gray" data-wh-action="adjust" data-id="${whEscape(item.id)}">⚙️ ปรับ</button>` : ''}
        </div>
      </div>`;
  }).join('');
}

async function handleWarehouseReceive(btn) {
  const id = btn.dataset.id;
  const row = btn.closest('.wh-row');
  const input = row?.querySelector('[data-wh-qty]');
  const qty = Number(input?.value);

  if (!input || input.value.trim() === '' || !Number.isFinite(qty) || qty <= 0) {
    window.toast.error('กรอกจำนวนที่จะรับเข้าให้ถูกต้อง (มากกว่า 0)');
    input?.focus();
    return;
  }

  UI.setLoading(btn, true);
  try {
    const res = await window.stockApi.receive(id, { qty });
    const item = res.data?.item;
    window.toast.success(`รับเข้าแล้ว: ${item?.name ?? ''} → คงเหลือ ${whFmtQty(item?.current_qty ?? 0)}`);
    input.value = '';
    await refreshWarehouseAll();
  } catch (error) {
    window.toast.error('รับเข้าไม่สำเร็จ: ' + (error.message || 'ไม่ทราบสาเหตุ'));
  } finally {
    UI.setLoading(btn, false);
  }
}

function openAdjustModal(id) {
  const item = whState.items.find((i) => String(i.id) === String(id));
  if (!item) return;

  const modal = new Modal({
    title: `⚙️ ปรับสต็อก: ${item.name}`,
    size: 'sm',
    content: `
      <div class="wh-adjust-form">
        <div>
          <label for="whAdjCurrent">สต็อกปัจจุบันในคลัง</label>
          <input id="whAdjCurrent" type="number" min="0" step="any" inputmode="decimal" value="${whFmtQty(item.current_qty)}">
        </div>
        <div>
          <label for="whAdjMin">จุดเตือนขั้นต่ำ (Minimum)</label>
          <input id="whAdjMin" type="number" min="0" step="any" inputmode="decimal" value="${whFmtQty(item.min_qty)}">
        </div>
        <div>
          <label for="whAdjNote">หมายเหตุ (ถ้ามี)</label>
          <input id="whAdjNote" type="text" maxlength="200" placeholder="เช่น ตรวจนับสต็อกประจำเดือน">
        </div>
      </div>
    `,
  }).render();

  const actions = modal.element.querySelector('.sheet-actions');
  actions.innerHTML = `
    <button type="button" class="small-btn gray flex-1" data-action="cancel">ยกเลิก</button>
    <button type="button" class="flex-1" data-action="save">บันทึกการปรับ</button>
  `;

  actions.querySelector('[data-action="cancel"]').onclick = () => modal.close();

  const saveBtn = actions.querySelector('[data-action="save"]');
  saveBtn.onclick = async () => {
    const curRaw = modal.element.querySelector('#whAdjCurrent').value.trim();
    const minRaw = modal.element.querySelector('#whAdjMin').value.trim();
    const note = modal.element.querySelector('#whAdjNote').value.trim();

    const curNum = curRaw === '' ? null : Number(curRaw);
    const minNum = minRaw === '' ? null : Number(minRaw);

    if (curNum !== null && (!Number.isFinite(curNum) || curNum < 0)) {
      window.toast.error('สต็อกปัจจุบันต้องเป็นตัวเลขไม่ติดลบ');
      return;
    }
    if (minNum !== null && (!Number.isFinite(minNum) || minNum < 0)) {
      window.toast.error('จุดเตือนขั้นต่ำต้องเป็นตัวเลขไม่ติดลบ');
      return;
    }
    if (curNum === null && minNum === null) {
      window.toast.error('กรอกอย่างน้อยหนึ่งช่อง: สต็อกปัจจุบัน หรือ ขั้นต่ำ');
      return;
    }

    const payload = {};
    if (curNum !== null) payload.current_qty = curNum;
    if (minNum !== null) payload.min_qty = minNum;
    if (note !== '') payload.note = note;

    UI.setLoading(saveBtn, true);
    try {
      await window.stockApi.adjust(id, payload);
      window.toast.success('ปรับสต็อกเรียบร้อย');
      modal.close();
      await refreshWarehouseAll();
    } catch (error) {
      window.toast.error('ปรับไม่สำเร็จ: ' + (error.message || 'ไม่ทราบสาเหตุ'));
      UI.setLoading(saveBtn, false);
    }
  };

  modal.open();
}

async function loadWarehouseTransactions() {
  const listEl = document.getElementById('whTxList');
  if (!listEl) return;

  try {
    const res = await window.stockApi.getTransactions({ limit: whState.txLimit });
    const rows = res.data || [];

    if (rows.length === 0) {
      listEl.innerHTML = '<p class="empty">ยังไม่มีประวัติการเคลื่อนไหวสต็อก — เมื่อมีการตัดจ่าย/รับเข้า รายการจะแสดงที่นี่</p>';
      return;
    }

    listEl.innerHTML = rows.map((tx) => {
      const meta = WH_TX_META[tx.type] || { label: whEscape(tx.type), cls: '' };
      const qty = Number(tx.qty) || 0;
      const qtyCls = qty > 0 ? 'is-plus' : (qty < 0 ? 'is-minus' : 'is-zero');
      const qtyText = qty > 0 ? `+${whFmtQty(qty)}` : whFmtQty(qty);

      const metaParts = [`คงเหลือ ${whFmtQty(tx.balance_after)}`];
      if (tx.car_id || tx.car_name) metaParts.push(`🚗 ${vehiclePlate({ id: tx.car_id, name: tx.car_name })}`);
      if (tx.by_name) metaParts.push(`👤 ${tx.by_name}`);
      metaParts.push(UI.formatDateTimeThai(tx.at));

      return `
        <div class="wh-tx ${meta.cls}">
          <div class="wh-tx-head">
            <span class="wh-tx-type">${meta.label}</span>
            <span class="wh-tx-qty ${qtyCls}">${qtyText}</span>
          </div>
          <div class="wh-tx-name">${whEscape(tx.name)}</div>
          <div class="wh-tx-meta">${whEscape(metaParts.join(' • '))}</div>
          ${tx.note ? `<div class="wh-tx-note">${whEscape(tx.note)}</div>` : ''}
        </div>`;
    }).join('');
  } catch (error) {
    listEl.innerHTML = '<p class="empty">โหลดประวัติไม่สำเร็จ</p>';
  }
}

// ---------- Nav badge ----------

function setWarehouseBadge(count) {
  const badge = document.getElementById('whNavBadge');
  if (!badge) return;
  badge.textContent = count > 99 ? '99+' : String(count);
  badge.classList.toggle('hidden', count === 0);
  badge.setAttribute('aria-label', count > 0 ? `สต็อกต้องเติม ${count} รายการ` : 'สต็อกปกติ');
}

async function refreshWarehouseBadge() {
  if (!window.auth?.isAuthenticated) return;
  try {
    const res = await window.stockApi.getOverview();
    setWarehouseBadge(Number(res.data?.summary?.alerts) || 0);
  } catch (error) {
    console.warn('Warehouse badge refresh failed:', error);
  }
}

// Start once the auth session resolves (same pattern as the notification center)
document.addEventListener('DOMContentLoaded', async () => {
  await window.auth.init();
  if (window.auth.isAuthenticated) {
    refreshWarehouseBadge();
  }
});
