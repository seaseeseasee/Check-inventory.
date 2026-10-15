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
      total_vehicles: (d?.vehicles || []).length,
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
    
    let stats = [];
    
    if (this.userRole === 'admin') {
      stats = [
        { key: 'total_vehicles', label: 'รถทั้งหมด', class: '' },
        { key: 'checked_today', label: 'ตรวจแล้ววันนี้', class: '' },
        { key: 'pending_drafts', label: 'ร่างตรวจค้าง', class: this.data.pending_drafts > 0 ? 'warn' : '' },
        { key: 'attention_items', label: 'ต้องติดตาม', class: this.data.attention_items > 0 ? 'warn' : '' },
        { key: 'restock.pending', label: 'รอเติม', class: this.data.restock?.pending > 0 ? 'warn' : '' },
        { key: 'restock.in_progress', label: 'กำลังเติม', class: this.data.restock?.in_progress > 0 ? 'warn' : '' },
        { key: 'restock.partial', label: 'เติมไม่ครบ', class: this.data.restock?.partial > 0 ? 'warn' : '' },
        { key: 'restock.completed', label: 'เติมครบ', class: '' },
      ];
    } else {
      stats = [
        { key: 'total_vehicles', label: 'รถทั้งหมด', class: '' },
        { key: 'checked_today', label: 'ตรวจแล้ววันนี้', class: '' },
        { key: 'restock.my_requests', label: 'งานของฉัน', class: this.data.restock?.my_requests > 0 ? 'warn' : '' },
        { key: 'restock.pending', label: 'รอเติม', class: this.data.restock?.pending > 0 ? 'warn' : '' },
        { key: 'restock.in_progress', label: 'กำลังเติม', class: this.data.restock?.in_progress > 0 ? 'warn' : '' },
        { key: 'restock.partial', label: 'เติมไม่ครบ', class: this.data.restock?.partial > 0 ? 'warn' : '' },
      ];
    }
    
    container.innerHTML = stats.map(stat => {
      let value = this.data;
      for (const key of stat.key.split('.')) {
        value = value?.[key];
      }
      return `
        <div class="stat ${stat.class}">
          <b>${value ?? 0}</b>
          <span>${stat.label}</span>
        </div>
      `;
    }).join('');
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
            <span class="plate-chip">${UI.escape(vehiclePlate(vehicle))}</span>
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
                `${UI.escape(item.name)} ${item.days_left < 0 ? 'หมดอายุแล้ว' : 'ใกล้หมดอายุ'} (${UI.escape(item.expiry_text)})`
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
    const vehicles = this.data.vehicles || [];
    const lastInspections = this.data.last_inspections || {};
    const pendingRequests = this.data.pending_requests || [];
    
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
              <span class="plate-chip">${UI.escape(vehiclePlate({ id: req.car_id, name: req.car_name }))}</span>
              <span>รถ: ${UI.escape(req.car_name)}</span>
              <span>${UI.formatDateTimeThai(req.created_at)}</span>
            </div>
            <div class="rs-actions">
              ${req.status === 'PENDING' ? `
                <button type="button" class="act-btn ok" onclick="dashboardPage.startRestock('${req.id}')">เริ่มเติม</button>
              ` : req.status === 'IN_PROGRESS' || req.status === 'PARTIAL' ? `
                <button type="button" class="act-btn secondary" onclick="dashboardPage.viewRestockDetail('${req.id}')">ดำเนินการต่อ</button>
              ` : `
                <button type="button" class="act-btn gray" onclick="dashboardPage.viewRestockDetail('${req.id}')">ดูรายละเอียด</button>
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
          const disabled = vehicle.status === 'disabled';
          
          return `
            <article class="dash-car${isToday ? '' : ''}">
              <div class="dash-car-head">
                <span class="plate-chip">${UI.escape(vehiclePlate(vehicle))}</span>
                <span class="chip ${isToday ? 'chip-ok' : 'chip-off'}">
                  ${isToday ? 'ตรวจแล้ววันนี้' : 'ยังไม่ตรวจวันนี้'}
                </span>
                ${disabled ? '<span class="chip chip-off">งดใช้งาน</span>' : ''}
              </div>
              <p class="dash-last">
                ${last 
                  ? `ตรวจล่าสุด ${UI.formatDateTimeThai(last.completed_at)} • ${UI.escape(last.nurse_name)} • เวร ${UI.escape(last.shift_name)}`
                  : 'ยังไม่มีประวัติการตรวจที่บันทึก'
                }
              </p>
              <div class="rs-actions">
                ${disabled
                  ? '<span class="dash-disabled-note">งดใช้งาน — เปิดกลับที่แท็บ "ทะเบียนรถ/อุปกรณ์" จึงจะตรวจได้</span>'
                  : `<button type="button" class="act-btn ok" onclick="dashboardPage.startInspection('${vehicle.id}')">ตรวจคันนี้</button>`}
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