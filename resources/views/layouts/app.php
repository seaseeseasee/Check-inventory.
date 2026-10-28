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
  <link rel="stylesheet" href="<?= asset('css/mascot.css') ?>">
  
  <!-- Page-specific styles (will be injected by page controller) -->
  <?php if (isset($pageStyles)): ?>
    <?php foreach ($pageStyles as $style): ?>
      <link rel="stylesheet" href="<?= asset('css/pages/' . $style . '.css') ?>">
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Vendored Tailwind utilities (utilities-only build) — must load last so utility classes win -->
  <link rel="stylesheet" href="<?= asset('css/tailwind.css') ?>">
  <!-- มือถือ/ผู้สูงอายุ: โหลดท้ายสุดเพื่อยกค่าพื้นฐาน (ตัวอักษร/พื้นที่กด/เมนูล่าง) -->
  <link rel="stylesheet" href="<?= asset('css/a11y.css') ?>">
  <!-- สีสถานะ/การแจ้งเตือน (แดง/น้ำเงินทึบ = ตัวอักษรขาว) ต้องอยู่หลังสุดเพื่อทับสไตล์รายหน้า -->
  <link rel="stylesheet" href="<?= asset('css/status.css') ?>">
</head>
<body>
  <!-- Auth Gate -->
  <div id="authGate">
    <form id="authForm" class="auth-card auth-card-mascot" method="post">
      <img class="auth-mascot" src="<?= asset('images/mascot/cat-wave.svg') ?>" width="140" height="144" alt="">
      <h2>ระบบตรวจเช็ครถ Refer / Ambulance</h2>
      <p class="auth-sub">ชื่อผู้ใช้คือเบอร์โทรศัพท์ของคุณ — เข้าครั้งแรกใช้เบอร์โทรศัพท์เป็นรหัสผ่าน</p>
      <label for="systemUsername">ชื่อผู้ใช้ (เบอร์โทรศัพท์)</label>
      <input type="text" id="systemUsername" name="username" autocomplete="username" placeholder="เช่น 0812345678" autofocus required>
      <label for="systemPassword">รหัสผ่าน</label>
      <input type="password" id="systemPassword" name="password" autocomplete="current-password" required>
      <div class="auth-error" id="authError" role="alert" aria-live="polite"></div>
      <button type="submit" class="auth-submit">เข้าสู่ระบบ</button>
    </form>
  </div>

  <!-- บังคับเปลี่ยนรหัสผ่าน: แสดงแทนทั้งแอปจนกว่าจะเปลี่ยนสำเร็จ (server ก็ปฏิเสธ API อื่นด้วย PASSWORD_CHANGE_REQUIRED) -->
  <div id="pwGate" class="auth-gate-pw" hidden>
    <form id="pwForm" class="auth-card" method="post" novalidate>
      <img class="auth-logo" src="<?= asset('images/logo.png') ?>" width="88" height="88" alt="โลโก้ระบบ" loading="lazy">
      <h2>ตั้งรหัสผ่านใหม่</h2>
      <p class="auth-sub" id="pwIntro">คุณเข้าสู่ระบบด้วยรหัสผ่านเริ่มต้น — เพื่อความปลอดภัย กรุณาตั้งรหัสผ่านใหม่ก่อนเริ่มใช้งาน</p>
      <input type="text" id="pwUsername" name="username" autocomplete="username" hidden readonly>
      <label for="pwCurrent">รหัสผ่านปัจจุบัน (รหัสเริ่มต้น = เบอร์โทรศัพท์)</label>
      <input type="password" id="pwCurrent" name="current_password" autocomplete="current-password" required>
      <label for="pwNew">รหัสผ่านใหม่</label>
      <input type="password" id="pwNew" name="new_password" autocomplete="new-password" aria-describedby="pwRules" required>
      <ul class="pw-rules" id="pwRules" aria-live="polite">
        <li data-rule="len">อย่างน้อย 8 ตัว (ตัวเลขล้วน หรือผสมตัวอักษรก็ได้)</li>
        <li data-rule="phone">ไม่ใช่เบอร์โทรศัพท์ / ชื่อผู้ใช้ และไม่มีช่องว่าง</li>
      </ul>
      <label for="pwConfirm">ยืนยันรหัสผ่านใหม่</label>
      <input type="password" id="pwConfirm" name="confirm_password" autocomplete="new-password" required>
      <label class="pw-show"><input type="checkbox" id="pwShow"> แสดงรหัสผ่าน</label>
      <div class="auth-error" id="pwError" role="alert" aria-live="polite"></div>
      <button type="submit" class="auth-submit">บันทึกรหัสผ่านใหม่</button>
      <button type="button" class="small-btn gray pw-logout" data-call="signOut">ออกจากระบบ</button>
    </form>
  </div>

  <!-- App Top Bar -->
  <div class="app-top">
    <header id="mainHeader">
      <img class="brand-logo" src="<?= asset('images/logo.png') ?>" alt="โลโก้ระบบ" width="48" height="48" loading="lazy">
      <span id="headerTitle"><span class="header-name">ตรวจเช็กรถ Refer</span></span>
      <button type="button" class="signout" data-call="signOut">ออกจากระบบ</button>
    </header>

    <nav class="nav-tabs" aria-label="เมนูหลัก" id="mainNavTabs">
      <button type="button" class="tab-btn active" id="btnTabDashboard" data-call="switchTab" data-arg="dashboard"><span class="tab-ico" aria-hidden="true">🏠</span><span class="tab-txt">หน้าแรก</span></button>
      <button type="button" class="tab-btn" id="btnTabCheck" data-call="switchTab" data-arg="check"><span class="tab-ico" aria-hidden="true">🚑</span><span class="tab-txt">ตรวจเช็ก</span></button>
      <button type="button" class="tab-btn" id="btnTabRestock" data-call="switchTab" data-arg="restock"><span class="tab-ico" aria-hidden="true">🧰</span><span class="tab-txt">เติมของ</span></button>
      <button type="button" class="tab-btn" id="btnTabReports" data-call="switchTab" data-arg="reports"><span class="tab-ico" aria-hidden="true">📋</span><span class="tab-txt">ประวัติ</span></button>
      <button type="button" class="tab-btn" id="btnTabStock" data-call="switchTab" data-arg="stock"><span class="tab-ico" aria-hidden="true">📦</span><span class="tab-txt">รถ</span></button>
      <button type="button" class="tab-btn" id="btnTabNurses" data-call="switchTab" data-arg="nurses"><span class="tab-ico" aria-hidden="true">👥</span><span class="tab-txt">ผู้ใช้</span></button>
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

  <!-- ปุ่มแมวลอยมุมขวาล่าง = แจ้งเตือน + ช่วยเหลือ: ตัวเลขแดงคือรายการรอเติม, ชี้เมาส์หรือแตะเพื่อเปิดกล่อง -->
  <?php $helpTel = trim((string) env('HELP_PHONE', '')); ?>
  <div class="help-cat" id="helpCat">
    <div class="help-cat-panel" id="helpCatPanel" role="dialog" aria-labelledby="helpCatTitle" hidden>
      <button type="button" class="help-cat-notify" id="notifyGo">
        <span class="help-cat-notify-ico" aria-hidden="true">🔔</span>
        <span id="notifyText">ไม่มีรายการรอเติม</span>
        <span aria-hidden="true">→</span>
      </button>
      <h3 id="helpCatTitle">มีอะไรให้ช่วยไหม?</h3>
      <ul class="help-cat-list">
        <li><b>🚑 ตรวจเช็ก</b> เลือกรถ → ผู้ตรวจ/เวร → ตรวจทีละหมวด → ยืนยันส่ง</li>
        <li><b>🧰 เติมของ</b> เลือกรถ → ใส่จำนวนที่เติมและวันหมดอายุ → กดบันทึก</li>
      </ul>
      <?php if ($helpTel !== ''): ?>
        <a class="help-cat-call" href="tel:<?= escape(preg_replace('/[^\d+]/', '', $helpTel)) ?>">📞 โทรหาเจ้าหน้าที่ <?= escape($helpTel) ?></a>
      <?php else: ?>
        <p class="help-cat-note">ถ้ายังไม่แน่ใจ สอบถามหัวหน้าเวรได้เลย</p>
      <?php endif; ?>
      <button type="button" class="small-btn gray help-cat-close" id="helpCatClose">ปิด</button>
    </div>
    <button type="button" class="help-cat-btn" id="helpCatBtn" aria-expanded="false" aria-controls="helpCatPanel" aria-label="ขอความช่วยเหลือ" title="ขอความช่วยเหลือ">
      <img src="<?= asset('images/mascot/cat-wave.svg') ?>" alt="" width="60" height="62">
      <span id="notifyBadge" class="help-cat-badge hidden" aria-hidden="true">0</span>
    </button>
  </div>

  <!-- แจ้งผลบันทึกสำเร็จพร้อมน้องแมว (เปิดด้วย window.mascotSuccess) -->
  <div id="mascotSuccess" class="hidden sheet-overlay mascot-success" role="alertdialog" aria-modal="true" aria-labelledby="mascotSuccessTitle" aria-describedby="mascotSuccessText">
    <div class="sheet">
      <img src="<?= asset('images/mascot/cat-thumbsup.svg') ?>" alt="" width="160" height="164">
      <h3 id="mascotSuccessTitle"></h3>
      <p id="mascotSuccessText"></p>
      <button type="button" id="mascotSuccessOk">ตกลง</button>
    </div>
  </div>

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
  <script src="<?= asset('js/components/mascot.js') ?>"></script>
  
  <!-- Page-specific JS (will be injected by page controller) -->
  <?php if (isset($pageScripts)): ?>
    <?php foreach ($pageScripts as $script): ?>
      <script src="<?= asset('js/pages/' . $script . '.js') ?>"></script>
    <?php endforeach; ?>
  <?php endif; ?>
  
  <!-- Main App JS (ไฟล์แยก — CSP ไม่อนุญาต inline script) -->
  <script src="<?= asset('js/core/shell.js') ?>"></script>
</body>
</html>
