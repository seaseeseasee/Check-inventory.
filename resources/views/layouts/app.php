<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="light" />
  <meta name="theme-color" content="#0f766e" />
  <title>ระบบตรวจเช็กรถ Refer / Ambulance</title>
  
  <!-- CSRF Token -->
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <?php $apiBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/api'; ?>
  <meta name="api-base" content="<?= escape($apiBase) ?>">
  
  <!-- Stylesheets -->
  <link rel="stylesheet" href="<?= asset('css/base.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/layout.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/components.css') ?>">
  
  <!-- Page-specific styles (will be injected by page controller) -->
  <?php if (isset($pageStyles)): ?>
    <?php foreach ($pageStyles as $style): ?>
      <link rel="stylesheet" href="<?= asset('css/pages/' . $style . '.css') ?>">
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Vendored Tailwind utilities (utilities-only build) — must load last so utility classes win -->
  <link rel="stylesheet" href="<?= asset('css/tailwind.css') ?>">
</head>
<body>
  <!-- Auth Gate -->
  <div id="authGate">
    <form id="authForm" class="auth-card" method="post">
      <img class="auth-logo" src="<?= asset('images/logo.png') ?>" width="88" height="88" alt="โลโก้ระบบ" loading="lazy">
      <h2>ระบบตรวจเช็ครถ Refer / Ambulance</h2>
      <p class="auth-sub">กรอกชื่อผู้ใช้และรหัสผ่านเพื่อเข้าสู่ระบบ</p>
      <label for="systemUsername">ชื่อผู้ใช้</label>
      <input type="text" id="systemUsername" name="username" autocomplete="username" autofocus required>
      <label for="systemPassword">รหัสผ่าน</label>
      <input type="password" id="systemPassword" name="password" autocomplete="current-password" required>
      <div class="auth-error" id="authError" role="alert" aria-live="polite"></div>
      <button type="submit" class="auth-submit">เข้าสู่ระบบ</button>
    </form>
  </div>

  <!-- App Top Bar -->
  <div class="app-top">
    <header id="mainHeader">
      <img class="brand-logo" src="<?= asset('images/logo.png') ?>" alt="โลโก้ระบบ" width="48" height="48" loading="lazy">
      <span id="headerTitle">ระบบตรวจเช็กรถ Refer / Ambulance</span>
      <button type="button" class="signout" onclick="signOut()">ออกจากระบบ</button>
    </header>

    <nav class="nav-tabs" aria-label="เมนูหลัก" id="mainNavTabs">
      <button type="button" class="tab-btn active" id="btnTabDashboard" onclick="switchTab('dashboard')">🏠 หน้าแรก</button>
      <button type="button" class="tab-btn" id="btnTabCheck" onclick="switchTab('check')">🚗 ตรวจเช็ครถ</button>
      <button type="button" class="tab-btn" id="btnTabRestock" onclick="switchTab('restock')">🧰 เติมอุปกรณ์</button>
      <button type="button" class="tab-btn" id="btnTabReports" onclick="switchTab('reports')">📋 ประวัติ</button>
      <button type="button" class="tab-btn" id="btnTabStock" onclick="switchTab('stock')">📦 ทะเบียนรถ/อุปกรณ์</button>
      <button type="button" class="tab-btn" id="btnTabWarehouse" onclick="switchTab('warehouse')">🏬 คลังสต็อกกลาง<span id="whNavBadge" class="wh-nav-badge hidden" aria-hidden="true">0</span></button>
      <button type="button" class="tab-btn" id="btnTabNurses" onclick="switchTab('nurses')">👩‍⚕️ จัดการพยาบาล</button>
      <button type="button" class="tab-btn" id="btnTabUsers" onclick="switchTab('users')">👥 จัดการผู้ใช้</button>
    </nav>
    <div class="storage-note" id="storageState" role="status" aria-live="polite">กำลังเชื่อมต่อฐานข้อมูลของระบบ…</div>
  </div>

  <!-- Main Container -->
  <div class="container">
    <!-- Page content will be rendered here -->
    <?php if (isset($content)): ?>
      <?= $content ?>
    <?php endif; ?>
  </div>

  <!-- Bottom Sheet (Detail Input) -->
  <div id="sheetOverlay" class="hidden sheet-overlay" role="dialog" aria-modal="true" aria-labelledby="sheetTitle">
    <div class="sheet">
      <h3 id="sheetTitle"></h3>
      <input id="sheetInput" type="text" autocomplete="off" placeholder="ระบุจำนวน / รายละเอียด">
      <div class="sheet-actions">
        <button type="button" class="small-btn gray" id="sheetCancel">ยกเลิก</button>
        <button type="button" id="sheetOk">ตกลง</button>
      </div>
    </div>
  </div>

  <!-- Restock Sheet -->
  <div id="restockSheet" class="hidden sheet-overlay" role="dialog" aria-modal="true" aria-labelledby="rsSheetTitle">
    <div class="sheet" style="max-width: 90vw;">
      <h3 id="rsSheetTitle"></h3>
      <div class="sheet-body" id="rsSheetBody"></div>
      <div class="sheet-actions">
        <button type="button" class="small-btn gray" id="rsSheetCancel">ยกเลิก</button>
        <button type="button" id="rsSheetOk">ยืนยัน</button>
      </div>
    </div>
  </div>

  <!-- Notification Floating Button (m-0 bg-none override the global button rule in layout.css) -->
  <button id="notifyFab" type="button"
          class="m-0 bg-none fixed bottom-6 right-6 z-[300] flex items-center justify-center w-14 h-14 rounded-full bg-teal-600 text-white shadow-lg transition-all duration-200 hover:bg-teal-700 hover:shadow-xl hover:scale-105 active:scale-95 focus:outline-none focus:ring-4 focus:ring-teal-300"
          aria-label="การแจ้งเตือน" title="การแจ้งเตือน">
    <svg class="w-7 h-7 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
      <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
    </svg>
    <span id="notifyBadge" class="hidden absolute -top-1 -right-1 min-w-[20px] h-5 px-1 flex items-center justify-center rounded-full bg-red-500 text-white text-xs font-bold ring-2 ring-white" aria-hidden="true">0</span>
  </button>

  <!-- Alert Container -->
  <div id="alert-container" class="fixed top-4 right-4 z-[1200] flex flex-col gap-2 w-[calc(100vw-2rem)] max-w-sm pointer-events-none"></div>

  <!-- Vendor JS (vendored locally so import works without internet) -->
  <script src="<?= asset('js/vendor/xlsx.full.min.js') ?>"></script>
  
  <!-- Core JS -->
  <script src="<?= asset('js/core/api.js') ?>"></script>
  <script src="<?= asset('js/core/auth.js') ?>"></script>
  <script src="<?= asset('js/core/ui.js') ?>"></script>
  <script src="<?= asset('js/core/vehicle.js') ?>"></script>
  <script src="<?= asset('js/core/system-status.js') ?>"></script>
  
  <!-- Component JS -->
  <script src="<?= asset('js/components/modal.js') ?>"></script>
  <script src="<?= asset('js/components/alert.js') ?>"></script>
  <script src="<?= asset('js/components/table.js') ?>"></script>
  <script src="<?= asset('js/components/notifications.js') ?>"></script>
  
  <!-- Page-specific JS (will be injected by page controller) -->
  <?php if (isset($pageScripts)): ?>
    <?php foreach ($pageScripts as $script): ?>
      <script src="<?= asset('js/pages/' . $script . '.js') ?>"></script>
    <?php endforeach; ?>
  <?php endif; ?>
  
  <!-- Main App JS -->
  <script>
    // Tab switching
    function switchTab(id) {
      const tabs = ['Dashboard', 'Check', 'Restock', 'Reports', 'Stock', 'Warehouse', 'Nurses', 'Users'];
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
        case 'warehouse':
          if (typeof initWarehouse === 'function') initWarehouse();
          break;
        case 'nurses':
          if (typeof initNurses === 'function') initNurses();
          break;
        case 'users':
          if (typeof initUsers === 'function') initUsers();
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
  </script>
</body>
</html>
