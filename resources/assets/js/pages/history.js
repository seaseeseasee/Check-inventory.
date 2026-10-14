// ========================================
// PAGE: HISTORY / REPORTS
// ========================================

class HistoryPage {
  constructor() {
    this.historyView = 'inspections'; // 'inspections' or 'restock'
    this.filters = {
      car: 'all',
      status: 'all',
      from: '',
      to: '',
      search: '',
    };
    this.inspections = [];
    this.restockRequests = [];
    this.init();
  }

  async init() {
    await this.loadData();
    this.bindEvents();
    this.render();
  }

  async loadData() {
    try {
      const [inspectionsRes, restockRes] = await Promise.all([
        window.historyApi.getInspections(),
        window.historyApi.getRestock(),
      ]);
      this.inspections = inspectionsRes.data || [];
      this.restockRequests = restockRes.data || [];
    } catch (error) {
      console.error('Failed to load history data:', error);
      window.toast.error('โหลดประวัติไม่สำเร็จ');
    }
  }

  bindEvents() {
    // Segment buttons
    document.getElementById('historySegInspections')?.addEventListener('click', () => this.setView('inspections'));
    document.getElementById('historySegRestock')?.addEventListener('click', () => this.setView('restock'));
    
    // Search
    const search = document.getElementById('historySearch');
    if (search) {
      search.addEventListener('input', UI.debounce(() => this.applyFilters(), 300));
    }
    
    // Date filters
    document.getElementById('historyFrom')?.addEventListener('change', () => this.applyFilters());
    document.getElementById('historyTo')?.addEventListener('change', () => this.applyFilters());
  }

  render() {
    this.renderLists();
    this.renderFilterChips();
    this.applyFilters();
  }

  renderLists() {
    this.renderInspectionsList();
    this.renderRestockList();
  }

  renderInspectionsList() {
    const container = document.getElementById('historyInspectionsTable');
    if (!container) return;

    const list = [...this.inspections].sort((a, b) =>
      String(b.completed_at).localeCompare(String(a.completed_at))
    );

    container.innerHTML = list.map(check => {
      const hasCounts = check.item_count !== undefined && check.item_count !== null;
      const itemCount = hasCounts ? Number(check.item_count) : null;
      const attnCount = hasCounts ? Number(check.attention_count) : null;
      const chip = attnCount === null
        ? ''
        : attnCount > 0
          ? `<span class="chip chip-miss">ต้องติดตาม ${attnCount}</span>`
          : '<span class="chip chip-ok">ครบถ้วน</span>';

      return `
        <article class="report-card history-item" data-id="${UI.escape(check.id)}" data-car-id="${UI.escape(check.car_id)}" data-at="${UI.escape(String(check.completed_at || '').slice(0, 10))}">
          <div class="report-top">
            <div class="report-time">${UI.formatDateTimeThai(check.completed_at)}</div>
            ${chip}
          </div>
          <div class="report-meta">
            <span class="plate-chip">${UI.escape(this.getPlate(check.car_id))}</span>
            <span>${UI.escape(check.car_name || '')}</span>
            <span>ผู้ตรวจ: ${UI.escape(check.nurse_name || '-')}</span>
            <span>เวร ${UI.escape(check.shift_name || '-')}</span>
            ${itemCount !== null ? `<span>ตรวจ ${itemCount} รายการ</span>` : ''}
          </div>
          <details class="report-details">
            <summary>ดูทุกรายการ${itemCount !== null ? ` (${itemCount})` : ''}</summary>
            <div class="hist-detail"></div>
          </details>
        </article>
      `;
    }).join('') || '<p class="empty">ยังไม่มีรอบการตรวจที่บันทึก</p>';

    this.bindInspectionDetails(container);
  }

  bindInspectionDetails(container) {
    container.querySelectorAll('details.report-details').forEach(det => {
      det.addEventListener('toggle', () => {
        if (!det.open || det.dataset.loaded === '1') return;
        det.dataset.loaded = '1';
        this.loadInspectionItems(det);
      });
    });
  }

  async loadInspectionItems(det) {
    const box = det.querySelector('.hist-detail');
    const id = det.closest('.history-item')?.dataset.id;
    if (!box || !id) return;

    box.innerHTML = '<p class="muted">กำลังโหลด…</p>';
    try {
      const res = await window.historyApi.getInspection(id);
      const items = res.data?.results || [];
      box.innerHTML = items.map(item => {
        const level = item.battery_level === null || item.battery_level === undefined ? null : Number(item.battery_level);
        const attn = item.status === 'ขาด' || item.status === 'ใช้ไป' || (item.is_battery && level !== null && level < 4);
        return `<p class="${attn ? 'attn' : ''}">${UI.escape(item.name)}: ${UI.escape(item.status)}${item.detail ? ' ' + UI.escape(item.detail) : ''} | หมดอายุ ${UI.escape(item.expiry_text || '-')}</p>`;
      }).join('') || '<p class="muted">ไม่มีรายการในรอบนี้</p>';
    } catch (error) {
      det.dataset.loaded = '';
      box.innerHTML = '<p class="attn">โหลดรายการไม่สำเร็จ — ลองเปิดใหม่อีกครั้ง</p>';
      window.toast.error('โหลดรายละเอียดการตรวจไม่สำเร็จ');
    }
  }

  renderRestockList() {
    const container = document.getElementById('historyRestockTable');
    if (!container) return;

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

    const list = [...this.restockRequests].sort((a, b) =>
      String(b.created_at).localeCompare(String(a.created_at))
    );

    container.innerHTML = list.map(request => {
      const items = request.items || [];
      const actions = request.actions || [];

      const linesHtml = items.map(item => {
        const requested = parseFloat(item.qty_requested || 0);
        const filled = parseFloat(item.qty_filled || 0);
        const remaining = Math.max(0, requested - filled);
        const progress = requested > 0 ? Math.round((filled / requested) * 100) : 0;

        return `
          <tr>
            <td>${UI.escape(item.name)}</td>
            <td class="num">${UI.escape(item.standard)}</td>
            <td class="num">${UI.escape(item.qty_requested)}</td>
            <td class="num">${UI.escape(item.qty_filled || '0')}</td>
            <td class="num">${remaining > 0 ? remaining : '<span class="chip chip-ok">ครบ</span>'}</td>
            <td class="num">
              <div style="width: 100px;">
                <div class="progress-track" style="height: 6px;">
                  <div class="progress-fill" style="width: ${progress}%"></div>
                </div>
                <small>${progress}%</small>
              </div>
            </td>
          </tr>
        `;
      }).join('');

      const timelineHtml = actions.map(action => `
        <li>
          <b>${actionLabels[action.type] || action.type}</b> — ${UI.formatDateTimeThai(action.at)} • ${UI.escape(action.by_name)}
          ${action.note ? ` • ${UI.escape(action.note)}` : ''}
          ${(action.action_lines || []).length ? ' • ' + action.action_lines.map(l => {
            const it = items.find(i => i.item_id === l.itemId);
            return `${UI.escape(it?.name || l.itemId)} ${UI.escape(l.qty)}`;
          }).join(', ') : ''}
        </li>
      `).join('');

      return `
        <article class="rs-card history-item" data-id="${UI.escape(request.id)}" data-car-id="${UI.escape(request.car_id)}" data-status="${UI.escape(request.status)}" data-at="${UI.escape(String(request.created_at || '').slice(0, 10))}">
          <div class="rs-head">
            <span class="rs-no">${UI.escape(request.no)}</span>
            <span class="chip chip-${request.status}">${statusLabels[request.status] || request.status}</span>
          </div>
          <div class="rs-meta">
            <span class="plate-chip">${UI.escape(this.getPlate(request.car_id))}</span>
            <span>รถ: ${UI.escape(request.car_name)}</span>
            <span>${UI.formatDateTimeThai(request.created_at)}</span>
            ${request.assigned_to_user_id ? `<span>ผู้ดูแล: ${UI.escape(request.assigned_name || 'กำลังมอบหมาย')}</span>` : ''}
            ${request.reason ? `<span>เหตุผล: ${UI.escape(request.reason)}</span>` : ''}
          </div>
          <div class="rs-table-wrap">
            <table class="rs-items">
              <thead>
                <tr>
                  <th>อุปกรณ์</th>
                  <th>มาตรฐาน</th>
                  <th>ต้องเติม</th>
                  <th>เติมแล้ว</th>
                  <th>เหลือ</th>
                  <th>ความคืบหน้า</th>
                </tr>
              </thead>
              <tbody>${linesHtml}</tbody>
            </table>
          </div>
          <details class="report-details">
            <summary>ประวัติการดำเนินการ (${actions.length})</summary>
            <ul class="timeline">${timelineHtml}</ul>
          </details>
        </article>
      `;
    }).join('') || '<p class="empty">ยังไม่มีคำขอเบิก–เติม</p>';
  }

  setView(view) {
    this.historyView = view;
    
    document.getElementById('historySegInspections')?.classList.toggle('on', view === 'inspections');
    document.getElementById('historySegRestock')?.classList.toggle('on', view === 'restock');
    document.getElementById('historyInspectionsTable')?.classList.toggle('hidden', view !== 'inspections');
    document.getElementById('historyRestockTable')?.classList.toggle('hidden', view !== 'restock');
    document.getElementById('historyStatusChips')?.classList.toggle('hidden', view !== 'restock');
    
    this.applyFilters();
  }

  renderFilterChips() {
    // Car filter chips
    const carContainer = document.getElementById('historyCarChips');
    if (carContainer) {
      const carIds = [...new Set([
        ...this.inspections.map(i => i.car_id),
        ...this.restockRequests.map(r => r.car_id),
      ])];
      
      carContainer.innerHTML = `
        <button type="button" class="chip-btn${this.filters.car === 'all' ? ' on' : ''}" onclick="historyPage.setCarFilter('all')">ทุกคัน</button>
        ${carIds.map(id => `
          <button type="button" class="chip-btn${this.filters.car === id ? ' on' : ''}" onclick="historyPage.setCarFilter('${id}')">${this.getPlate(id)}</button>
        `).join('')}
      `;
    }
    
    // Restock status chips
    const statusContainer = document.getElementById('historyStatusChips');
    if (statusContainer) {
      const statuses = ['PENDING', 'IN_PROGRESS', 'PARTIAL', 'COMPLETED', 'CANCELLED'];
      const statusLabels = {
        'PENDING': 'รอเติม',
        'IN_PROGRESS': 'กำลังเติม',
        'PARTIAL': 'เติมไม่ครบ',
        'COMPLETED': 'เติมครบ',
        'CANCELLED': 'ยกเลิก',
      };
      
      const counts = {};
      this.restockRequests.forEach(r => {
        counts[r.status] = (counts[r.status] || 0) + 1;
      });
      
      statusContainer.innerHTML = `
        <button type="button" class="chip-btn${this.filters.status === 'all' ? ' on' : ''}" onclick="historyPage.setStatusFilter('all')">ทั้งหมด ${this.restockRequests.length}</button>
        ${statuses.map(s => `
          <button type="button" class="chip-btn${this.filters.status === s ? ' on' : ''}" onclick="historyPage.setStatusFilter('${s}')">${statusLabels[s]} ${counts[s] || 0}</button>
        `).join('')}
      `;
    }
  }

  setCarFilter(carId) {
    this.filters.car = carId;
    this.renderFilterChips();
    this.applyFilters();
  }

  setStatusFilter(status) {
    this.filters.status = status;
    this.renderFilterChips();
    this.applyFilters();
  }

  applyFilters() {
    const query = (document.getElementById('historySearch')?.value || '').toLowerCase().trim();
    const from = document.getElementById('historyFrom')?.value || '';
    const to = document.getElementById('historyTo')?.value || '';
    
    this.filters.search = query;
    this.filters.from = from;
    this.filters.to = to;
    
    const inRange = (dateStr) => {
      if (!dateStr) return false;
      const date = dateStr.slice(0, 10);
      return (!from || date >= from) && (!to || date <= to);
    };
    
    if (this.historyView === 'inspections') {
      const cards = document.querySelectorAll('#historyInspectionsTable .history-item');
      let shown = 0;
      
      cards.forEach(card => {
        const carMatch = this.filters.car === 'all' || this.filters.car === card.dataset.carId;
        const dateMatch = inRange(card.dataset.at);
        const searchMatch = !query || card.textContent.toLowerCase().includes(query);
        
        const match = carMatch && dateMatch && searchMatch;
        card.classList.toggle('hidden', !match);
        if (match) shown++;
      });
      
      const countEl = document.getElementById('historyCount');
      if (countEl) {
        countEl.textContent = this.inspections.length 
          ? `แสดง ${shown} จาก ${this.inspections.length} รอบการตรวจ`
          : '';
      }
    } else {
      const cards = document.querySelectorAll('#historyRestockTable .history-item');
      let shown = 0;
      
      cards.forEach(card => {
        const carMatch = this.filters.car === 'all' || this.filters.car === card.dataset.carId;
        const statusMatch = this.filters.status === 'all' || this.filters.status === card.dataset.status;
        const dateMatch = inRange(card.dataset.at);
        const searchMatch = !query || card.textContent.toLowerCase().includes(query);
        
        const match = carMatch && statusMatch && dateMatch && searchMatch;
        card.classList.toggle('hidden', !match);
        if (match) shown++;
      });
      
      const countEl = document.getElementById('historyCount');
      if (countEl) {
        countEl.textContent = this.restockRequests.length
          ? `แสดง ${shown} จาก ${this.restockRequests.length} รายการเติม`
          : '';
      }
    }
  }

  getPlate(carId) {
    const plates = {
      'car-1': '3ชฏ3250',
      'car-2': 'ฆง8434',
      'car-3': '5กล1034',
    };
    return plates[carId] || carId;
  }

  async refresh() {
    await this.loadData();
    this.render();
  }
}

// Initialize
let historyPage;
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('tabContentReports') && !document.getElementById('tabContentReports').classList.contains('hidden')) {
    historyPage = new HistoryPage();
    window.historyPage = historyPage;
  }
});

// Export for tab switching
window.initHistory = () => {
  if (!historyPage) {
    historyPage = new HistoryPage();
    window.historyPage = historyPage;
  } else {
    historyPage.refresh();
  }
};

// Global functions
window.setHistoryView = (view) => historyPage?.setView(view);
window.setCarFilter = (carId) => historyPage?.setCarFilter(carId);
window.setStatusFilter = (status) => historyPage?.setStatusFilter(status);