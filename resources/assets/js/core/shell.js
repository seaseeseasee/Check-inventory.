// ========================================
// CORE: App shell — tab switching, sign out, click dispatcher
// ย้ายมาจาก <script> inline ใน layouts/app.php เพื่อให้ CSP ตัด 'unsafe-inline' ของ script-src ได้
// ========================================

// Tab switching
function switchTab(id) {
  // หน้าจัดการผู้ใช้เดิมถูกรวมเข้ากับหน้าพยาบาล — ลิงก์/โค้ดเก่าที่เรียก 'users' ไปหน้าเดียวกัน
  if (id === 'users') id = 'nurses';
  const tabs = ['Dashboard', 'Check', 'Restock', 'Reports', 'Stock', 'Nurses'];
  tabs.forEach(name => {
    const content = document.getElementById('tabContent' + name);
    const btn = document.getElementById('btnTab' + name);
    if (content) content.classList.toggle('hidden', name.toLowerCase() !== id);
    if (btn) btn.classList.toggle('active', name.toLowerCase() === id);
  });

  // Initialize page-specific logic
  switch (id) {
    case 'dashboard':
      if (typeof initDashboard === 'function') initDashboard();
      break;
    case 'check':
      if (typeof initInspection === 'function') initInspection();
      break;
    case 'restock':
      if (typeof initRestock === 'function') initRestock();
      break;
    case 'reports':
      if (typeof initHistory === 'function') initHistory();
      break;
    case 'stock':
      if (typeof initVehicles === 'function') initVehicles();
      break;
    case 'nurses':
      if (typeof initNurses === 'function') initNurses();
      if (typeof initUsers === 'function') initUsers();   // บัญชีที่ไม่ใช่พยาบาลอยู่หน้าเดียวกัน
      break;
  }
}

// Sign out
async function signOut() {
  if (window.auth?.isAuthenticated) {
    await window.auth.logout();
  } else {
    window.location.reload();
  }
}

// Global vehicle plate helper → ย้ายไป js/core/vehicle.js (window.vehiclePlate)
// เพื่อให้ทุกหน้าใช้กติกาเดียวกัน: plate จากฐานข้อมูลก่อน แล้วค่อย fallback

// ปุ่มที่เคยใช้ onclick="fn('x')" → data-call="fn" data-arg="x"
//   data-call : ชื่อฟังก์ชันบน window หรือ "object.method" (เช่น dashboardPage.startInspection)
//   data-arg  : อาร์กิวเมนต์ 1 ตัว (ข้อความ) — ไม่ต้องมีถ้าฟังก์ชันไม่รับค่า
//   data-call-self : เรียกเฉพาะเมื่อคลิกที่ตัว element เอง (เช่นคลิกพื้นหลัง overlay เพื่อปิด)
// ค่าใน attribute เป็นข้อมูลล้วน ไม่ถูกตีความเป็นโค้ด — ต่างจาก onclick ที่ HTML-escape แล้วยังฉีด JS ได้
const CALL_PATTERN = /^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)?$/;

document.addEventListener('click', (event) => {
  const el = event.target.closest('[data-call]');
  if (!el) return;
  if (el.hasAttribute('data-call-self') && event.target !== el) return;
  if (el.disabled) return;

  const path = el.dataset.call || '';
  if (!CALL_PATTERN.test(path)) return;
  const [head, method] = path.split('.');
  const owner = method ? window[head] : window;
  const fn = owner && owner[method || head];
  if (typeof fn !== 'function') {
    console.warn('data-call: ไม่พบฟังก์ชัน', path);
    return;
  }
  const args = el.hasAttribute('data-arg') ? [el.dataset.arg] : [];
  fn.apply(owner, args);
});
