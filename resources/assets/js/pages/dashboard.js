// ========================================
// PAGE: DASHBOARD
// ========================================

class DashboardPage {
  constructor() {
    this.data = null;
    this.userRole = null;
    this.init();
  }

  async init() {
    // Get user role from auth
    const user = window.auth?.getUser();
    this.userRole = user?.role || 'staff';
    
    await this.loadData();
    this.render();
    this.startAutoRefresh();
  }

  async loadData() {
    try {
      let result;
      if (this.userRole === 'admin') {
        result = await window.api.get('/admin/dashboard');
        this.data = this.normalizeAdminData(result.data);
      } else {
        result = await window.api.get('/staff/dashboard');
        this.data = this.normalizeStaffData(result.data);
      }
      window.vehicleRef.remember(this.data?.vehicles || []);
    } catch (error) {
      console.error('Failed to load dashboard data:', error);
      window.toast.error('โหลดข้อมูลแดชบอร์ดไม่สำเร็จ');
    }
  }

  // Admin API nests inspection stats under "inspections"; flatten for rendering
  normalizeAdminData(d) {
    const insp = d?.inspections || {};
    return {
      ...d,
      total_vehicles: insp.total_vehicles ?? (d?.vehicles || []).length,
      checked_today: insp.checked_today ?? 0,
      pending_drafts: insp.pending_drafts ?? 0,
      attention_items: insp.attention_items ?? 0,
      last_inspections: insp.last_inspections || {},
      drafts: insp.drafts || {},
      shifts_today: insp.shifts_today || {},
    };
  }

  // Staff API omits total_vehicles / checked_today; derive them locally
  normalizeStaffData(d) {
    const lastInspections = d?.last_inspections || {};
    const today = new Date().toDateString();
    const checkedToday = Object.values(lastInspections).filter(last =>
      last && last.completed_at && new Date(last.completed_at).toDateString() === today
    ).length;
    return {
      ...d,
      // นับเฉพาะรถที่พนักงานเห็นจริง (งดใช้งานถูกซ่อนจากหน้าแรกของผู้ใช้งานทั่วไป)
      total_vehicles: (d?.vehicles || []).filter(v => v.status !== 'disabled').length,
      checked_today: checkedToday,
    };
  }

  render() {
    if (!this.data) return;
    
    this.renderStats();
    this.renderContent();
  }

  renderStats() {
    const container = document.getElementById('dashStats');
    if (!container || !this.data) return;

    // แยกกลุ่มตามความสำคัญ/บริบท: "สถานะการตรวจเช็ก" กับ "สถานะการเติมอุปกรณ์"
    // แต่ละตัวคลิกได้ → พาไปหน้า/ตัวกรองที่เกี่ยวข้องโดยตรง (ไม่ต้องไปหาเอง)
    let groups = [];

    if (this.userRole === 'admin') {
      groups = [
        {
          title: 'สถานะการตรวจเช็ก',
          stats: [
            { key: 'total_vehicles', label: 'รถทั้งหมด', class: '', goto: () => switchTab('stock') },
            { key: 'checked_today', label: 'ตรวจแล้ววันนี้', class: '', goto: () => switchTab('reports') },
            { key: 'pending_drafts', label: 'ร่างตรวจค้าง', class: this.data.pending_drafts > 0 ? 'warn' : '', goto: () => switchTab('check') },
            { key: 'attention_items', label: 'ต้องติดตาม', class: this.data.attention_items > 0 ? 'warn' : '', goto: () => switchTab('restock') },
          ],
        },
        {
          title: 'สถานะการเติมอุปกรณ์',
          stats: [
            { key: 'restock.pending', label: 'รอเติม', class: this.data.restock?.pending > 0 ? 'warn' : '', goto: () => this.gotoRestock('PENDING') },
            { key: 'restock.in_progress', label: 'กำลังเติม', class: this.data.restock?.in_progress > 0 ? 'warn' : '', goto: () => this.gotoRestock('IN_PROGRESS') },
            { key: 'restock.partial', label: 'เติมไม่ครบ', class: this.data.restock?.partial > 0 ? 'warn' : '', goto: () => this.gotoRestock('PARTIAL') },
            { key: 'restock.completed', label: 'เติมครบ', class: '', goto: () => this.gotoRestock('COMPLETED') },
          ],
        },
      ];
    } else {
      groups = [
        {
          title: 'สถานะการตรวจเช็ก',
          stats: [
            { key: 'total_vehicles', label: 'รถทั้งหมด', class: '', goto: () => switchTab('stock') },
            { key: 'checked_today', label: 'ตรวจแล้ววันนี้', class: '', goto: () => switchTab('reports') },
          ],
        },
        {
          title: 'สถานะการเติมอุปกรณ์',
          stats: [
            { key: 'restock.my_requests', label: 'งานของฉัน', class: this.data.restock?.my_requests > 0 ? 'warn' : '', goto: () => switchTab('restock') },
            { key: 'restock.pending', label: 'รอเติม', class: this.data.restock?.pending > 0 ? 'warn' : '', goto: () => this.gotoRestock('PENDING') },
            { key: 'restock.in_progress', label: 'กำลังเติม', class: this.data.restock?.in_progress > 0 ? 'warn' : '', goto: () => this.gotoRestock('IN_PROGRESS') },
            { key: 'restock.partial', label: 'เติมไม่ครบ', class: this.data.restock?.partial > 0 ? 'warn' : '', goto: () => this.gotoRestock('PARTIAL') },
          ],
        },
      ];
    }

    this.statActions = [];
    container.innerHTML = groups.map(group => `
      <div class="dash-stat-group">
        <h3 class="dash-stat-group-title">${group.title}</h3>
        <div class="dash-stats" data-count="${group.stats.length}">
          ${group.stats.map(stat => {
            let value = this.data;
            for (const key of stat.key.split('.')) {
              value = value?.[key];
            }
            const actionIndex = this.statActions.length;
            this.statActions.push(stat.goto);
            return `
              <button type="button" class="stat ${stat.class}" data-stat-action="${actionIndex}">
                <b>${value ?? 0}</b>
                <span>${stat.label}</span>
              </button>
            `;
          }).join('')}
        </div>
      </div>
    `).join('');

    container.querySelectorAll('[data-stat-action]').forEach(btn => {
      btn.addEventListener('click', () => this.statActions[parseInt(btn.dataset.statAction, 10)]?.());
    });
  }

  gotoRestock(filter) {
    // ไม่มีขั้น "กำลังเติม" แยกแล้ว: รอเติม/เติมบางส่วน (และสถานะเดิม IN_PROGRESS) = รายการที่ยังไม่ครบ
    if (['PENDING', 'IN_PROGRESS', 'PARTIAL'].includes(filter)) filter = 'open';
    switchTab('restock');
    if (typeof window.setRestockFilter === 'function') window.setRestockFilter(filter);
  }

  // สถานะ 3 เวรของวันนี้ (เช้า/บ่าย/ดึก) จาก shifts_today ของ API
  shiftDots(carId) {
    const done = (this.data.shifts_today || {})[carId] || {};
    const names = { morning: 'เช้า', afternoon: 'บ่าย', night: 'ดึก' };
    return '<span class="car-shifts">' + Object.keys(names).map(code =>
      `<span class="shift-dot ${done[code] ? 'done' : ''}" title="เวร${names[code]}${done[code] ? ' ตรวจแล้ว' : ' ยังไม่ตรวจ'}">${names[code]}${done[code] ? ' ✓' : ''}</span>`
    ).join('') + '</span>';
  }

  renderContent() {
    const container = document.getElementById('dashContent');
    if (!container || !this.data) return;
    
    if (this.userRole === 'admin') {
      this.renderAdminContent(container);
    } else {
      this.renderStaffContent(container);
    }
  }

  renderAdminContent(container) {
    const vehicles = this.data.vehicles || [];
    const lastInspections = this.data.last_inspections || {};
    const drafts = this.data.drafts || {};
    const restockStats = this.data.restock || {};
    const expiringItems = this.data.expiring_items || [];
    
    // Group expiring by vehicle
    const expiringByVehicle = {};
    expiringItems.forEach(item => {
      if (!expiringByVehicle[item.car_id]) expiringByVehicle[item.car_id] = [];
      expiringByVehicle[item.car_id].push(item);
    });

    container.innerHTML = vehicles.map(vehicle => {
      const last = lastInspections[vehicle.id];
      const draft = drafts[vehicle.id];
      const expiring = expiringByVehicle[vehicle.id] || [];
      const isToday = last && new Date(last.completed_at).toDateString() === new Date().toDateString();
      // รถ "งดใช้งาน" ยังแสดงในภาพรวมพร้อมประวัติ แต่เริ่มรอบตรวจใหม่ไม่ได้ (เซิร์ฟเวอร์ยืนยันที่ startInspection)
      const disabled = vehicle.status === 'disabled';
      
      let alertClass = '';
      if (draft || restockStats.my_requests > 0 || expiring.length > 0) {
        alertClass = ' is-alert';
      }
      
      return `
        <article class="dash-car${alertClass}">
          <div class="dash-car-head">
            ${window.vehicleChip(vehicle)} ${this.shiftDots(vehicle.id)}
            <span class="chip ${draft ? 'chip-warn' : isToday ? 'chip-ok' : 'chip-off'}">
              ${draft ? 'มีร่างค้าง' : isToday ? 'ตรวจแล้ววันนี้' : 'ยังไม่ตรวจวันนี้'}
            </span>
            ${disabled ? '<span class="chip chip-off">งดใช้งาน</span>' : ''}
          </div>
          <p class="dash-last">
            ${last 
              ? `ตรวจล่าสุด ${UI.formatDateTimeThai(last.completed_at)} • ${UI.escape(last.nurse_name)} • เวร ${UI.escape(last.shift_name)}`
              : 'ยังไม่มีประวัติการตรวจที่บันทึก'
            }
          </p>
          ${expiring.length > 0 ? `
            <p class="dash-attn">
              วันหมดอายุ: ${expiring.slice(0, 3).map(item => 
                `${UI.escape(item.name)} ${item.days_left < 0 ? 'หมดอายุแล้ว' : 'ใกล้หมดอายุ'} (${UI.escape(UI.formatDateThai(item.expiry_date))})`
              ).join(' • ')}${expiring.length > 3 ? ` และอีก ${expiring.length - 3} รายการ` : ''}
            </p>
          ` : ''}
          ${draft ? `<p class="dash-last">ร่างนี้ทำต่อได้เฉพาะผู้ตรวจและเวรเดิม</p>` : ''}
          <div class="rs-actions">
            ${disabled
              ? '<span class="dash-disabled-note">งดใช้งาน — เปิดกลับที่แท็บ "ทะเบียนรถ/อุปกรณ์" จึงจะตรวจได้</span>'
              : `<button type="button" class="act-btn ok" onclick="dashboardPage.startInspection('${vehicle.id}')">ตรวจคันนี้</button>`}
            <button type="button" class="act-btn gray" onclick="dashboardPage.viewRestock('${vehicle.id}')">ดูการเติม</button>
          </div>
        </article>
      `;
    }).join('');
  }

  renderStaffContent(container) {
    // รถที่ "งดใช้งาน" ให้เห็นเฉพาะหน้าแอดมิน — ผู้ใช้งานทั่วไปไม่ต้องเห็น (ตรวจไม่ได้อยู่แล้ว)
    const vehicles = (this.data.vehicles || []).filter(v => v.status !== 'disabled');
    const lastInspections = this.data.last_inspections || {};
    // "ต้องดำเนินการ" ต้องไม่รวมงานที่เติมครบ/ยกเลิกแล้ว — งานเหล่านั้นดูได้ที่แท็บ "เติมอุปกรณ์"/"ประวัติ" แทน
    const pendingRequests = (this.data.pending_requests || [])
      .filter(req => req.status !== 'COMPLETED' && req.status !== 'CANCELLED');

    container.innerHTML = `
      <div style="margin-bottom: 16px;">
        <h3 style="margin: 0 0 8px; color: var(--teal-700);">รายการเติมที่ต้องดำเนินการ (${pendingRequests.length})</h3>
        ${pendingRequests.slice(0, 5).map(req => `
          <article class="rs-card" style="margin-bottom: 8px;">
            <div class="rs-head">
              <span class="rs-no">${UI.escape(req.no)}</span>
              <span class="chip chip-${req.status}">${this.getStatusLabel(req.status)}</span>
            </div>
            <div class="rs-meta">
              ${window.vehicleChip({ id: req.car_id, name: req.car_name })}
              <span>รถ: ${UI.escape(req.car_name)}</span>
              <span>${UI.formatDateTimeThai(req.created_at)}</span>
            </div>
            <div class="rs-actions">
              ${['PENDING', 'IN_PROGRESS', 'PARTIAL'].includes(req.status) ? `
                <button type="button" class="act-btn ok" onclick="dashboardPage.viewRestock('${UI.escape(req.car_id)}')">เติมรถคันนี้</button>
              ` : `
                <button type="button" class="act-btn gray" onclick="dashboardPage.viewRestockDetail('${UI.escape(req.id)}')">ดูรายละเอียด</button>
              `}
            </div>
          </article>
        `).join('') || '<p class="empty">ไม่มีรายการเติมที่ต้องดำเนินการ</p>'}
      </div>
      <div style="border-top: 1px solid var(--line); padding-top: 16px;">
        <h3 style="margin: 0 0 8px; color: var(--teal-700);">สถานะรถทั้งหมด</h3>
        ${vehicles.map(vehicle => {
          const last = lastInspections[vehicle.id];
          const isToday = last && new Date(last.completed_at).toDateString() === new Date().toDateString();

          return `
            <article class="dash-car">
              <div class="dash-car-head">
                ${window.vehicleChip(vehicle)} ${this.shiftDots(vehicle.id)}
                <span class="chip ${isToday ? 'chip-ok' : 'chip-off'}">
                  ${isToday ? 'ตรวจแล้ววันนี้' : 'ยังไม่ตรวจวันนี้'}
                </span>
              </div>
              <p class="dash-last">
                ${last
                  ? `ตรวจล่าสุด ${UI.formatDateTimeThai(last.completed_at)} • ${UI.escape(last.nurse_name)} • เวร ${UI.escape(last.shift_name)}`
                  : 'ยังไม่มีประวัติการตรวจที่บันทึก'
                }
              </p>
              <div class="rs-actions">
                <button type="button" class="act-btn ok" onclick="dashboardPage.startInspection('${vehicle.id}')">ตรวจคันนี้</button>
                <button type="button" class="act-btn gray" onclick="dashboardPage.viewRestock('${vehicle.id}')">ดูการเติม</button>
              </div>
            </article>
          `;
        }).join('')}
      </div>
    `;
  }

  getStatusLabel(status) {
    const labels = {
      'PENDING': 'รอเติม',
      'IN_PROGRESS': 'กำลังเติม',
      'PARTIAL': 'เติมไม่ครบ',
      'COMPLETED': 'เติมครบ',
      'CANCELLED': 'ยกเลิก',
    };
    return labels[status] || status;
  }

  startInspection(carId) {
    window.location.href = `#check?car=${carId}`;
    switchTab('check');
  }

  viewRestock(carId) {
    window.location.href = `#restock?car=${carId}`;
    switchTab('restock');
  }

  startRestock(requestId) {
    window.location.href = `#restock?request=${requestId}`;
    switchTab('restock');
  }

  viewRestockDetail(requestId) {
    window.location.href = `#restock?request=${requestId}`;
    switchTab('restock');
  }

  startAutoRefresh() {
    setInterval(async () => {
      if (document.hidden) return;
      try {
        await this.loadData();
        this.render();
      } catch (error) {
        console.warn('Auto refresh failed:', error);
      }
    }, 30000);
  }
}

// Initialize
let dashboardPage;
document.addEventListener('DOMContentLoaded', async () => {
  const box = document.getElementById('tabContentDashboard');
  if (!box || box.classList.contains('hidden')) return;
  // Wait for the shared auth check so the login gate fires no API calls
  if (!(await window.auth.init())) return;
  dashboardPage = new DashboardPage();
  window.dashboardPage = dashboardPage;
});

window.initDashboard = () => {
  if (!dashboardPage) {
    dashboardPage = new DashboardPage();
    window.dashboardPage = dashboardPage;
  } else {
    dashboardPage.loadData().then(() => dashboardPage.render());
  }
};