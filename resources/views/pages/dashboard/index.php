<!-- Dashboard Tab -->
<div id="tabContentDashboard">
  <div class="card mascot-hero">
    <img class="mascot-hero-img" src="<?= asset('images/mascot/cat-thumbsup.svg') ?>" alt="" width="150" height="154">
    <div class="mascot-hero-text">
      <p class="mascot-hero-hi" id="dashGreeting">สวัสดีครับ 👋</p>
      <h2>ยินดีต้อนรับสู่ระบบเติมสต๊อกรถ Refer</h2>
      <p>ตรวจเช็กและเติมเวชภัณฑ์ให้รถพร้อมออกปฏิบัติงานทุกเวร</p>
    </div>
  </div>
  <div class="card">
    <h2>ภาพรวมระบบ</h2>
    <div class="dash-stats" id="dashStats"></div>
    <div id="dashContent"></div>
  </div>
</div>

<!-- Inspection/Check Tab -->
<div id="tabContentCheck" class="hidden">
  <!-- ตรวจเช็กแบบทีละขั้น: 1 เลือกรถ → 2 ผู้ตรวจ/เวร → 3 ตรวจทีละหมวด → 4 สรุป → เสร็จ -->
  <div class="card wiz" id="inspectionWizard" data-help-tel="<?= escape(env('HELP_PHONE', '')) ?>">
    <div class="wiz-top">
      <button type="button" class="gray wiz-back" id="inspectionBackBtn" data-call="inspectionBack">← ย้อนกลับ</button>
      <button type="button" class="wiz-help" data-call="openInspectionHelp">📞 ขอความช่วยเหลือ</button>
    </div>
    <div class="wiz-progress">
      <p class="wiz-step-text" id="inspectionStepText" aria-live="polite"></p>
      <ol class="wiz-dots" id="inspectionStepDots" aria-hidden="true"><li></li><li></li><li></li><li></li></ol>
    </div>

    <!-- ขั้นที่ 1: เลือกรถ -->
    <section class="wiz-step" id="inspectionStepCar">
      <h2>🚑 วันนี้ตรวจรถคันไหน?</h2>
      <p class="wiz-sub">แตะที่รถที่ต้องการตรวจ</p>
      <div class="car-grid" aria-label="เลือกรถ" id="inspectionCarGrid"></div>
    </section>

    <!-- ขั้นที่ 2: ผู้ตรวจ วันที่ และเวร -->
    <section class="wiz-step hidden" id="inspectionStepWho">
      <form id="inspectionStartForm">
        <h2>👤 ใครเป็นผู้ตรวจ?</h2>
        <div class="wiz-picked" id="inspectionPickedCar"></div>

        <input type="hidden" id="inspectionNurse">
        <p id="inspectionNurseLock" class="wiz-lock hidden" role="status"></p>
        <div id="inspectionNursePicker">
          <label for="inspectionNurseSearch">🔍 พิมพ์ชื่อเพื่อค้นหา</label>
          <input type="search" id="inspectionNurseSearch" autocomplete="off" placeholder="เช่น สมศรี">
          <div class="pick-list" id="inspectionNurseList" role="group" aria-label="รายชื่อผู้ตรวจ"></div>
        </div>

        <p class="step-label">⏰ เวรที่ตรวจ</p>
        <input type="hidden" id="inspectionShift">
        <div class="seg wiz-shift" role="group" aria-label="เลือกเวร">
          <button type="button" class="seg-btn" data-shift="morning" aria-pressed="false">เช้า<small>08–16 น.</small></button>
          <button type="button" class="seg-btn" data-shift="afternoon" aria-pressed="false">บ่าย<small>16–24 น.</small></button>
          <button type="button" class="seg-btn" data-shift="night" aria-pressed="false">ดึก<small>00–08 น.</small></button>
        </div>
        <details class="wiz-date">
          <summary>📅 วันที่ <b id="inspectionDateText"></b> (แตะเพื่อเปลี่ยน)</summary>
          <label for="inspectionDate">วันที่ตรวจ</label>
          <input type="date" id="inspectionDate" required>
        </details>
        <p id="inspectionShiftHint" class="shift-hint" role="status" aria-live="polite"></p>

        <div class="save-bar wiz-bar">
          <button type="submit" id="inspectionStartBtn">เริ่มตรวจ →</button>
        </div>
      </form>
    </section>

    <!-- ขั้นที่ 3: ตรวจทีละหมวด -->
    <section class="wiz-step hidden" id="inspectionStepCheck">
      <div class="top-info" id="inspectionLoginInfo"></div>
      <p id="inspectionModeNote" class="mode-note hidden" role="status"></p>
      <h2 id="inspectionGroupTitle"></h2>
      <p class="wiz-sub" id="inspectionGroupSub" role="status" aria-live="polite"></p>
      <button type="button" class="wiz-all-ok hidden" id="inspectionPageNoneBtn" data-call="checkAllInspection">✅ หน้านี้ไม่ได้ใช้ ไม่มีหาย ทั้งหมด</button>

      <div id="inspectionItemTable" class="list"></div>

      <p class="autosave-note" id="inspectionAutosaveNote" role="status" aria-live="polite"></p>
      <div class="save-bar wiz-bar">
        <p id="inspectionSaveHint" class="save-hint"></p>
        <button type="button" id="inspectionNextBtn" data-call="inspectionNext">ถัดไป →</button>
      </div>
    </section>

    <!-- ขั้นที่ 4: สรุปก่อนส่ง -->
    <section class="wiz-step hidden" id="inspectionStepSummary">
      <h2>📋 ตรวจสอบอีกครั้งก่อนส่ง</h2>
      <div class="top-info" id="inspectionSummaryInfo"></div>
      <div id="inspectionSummary"></div>
      <div class="save-bar wiz-bar">
        <button id="inspectionSubmitBtn" type="button" class="secondary" data-call="submitInspection">✅ ยืนยันส่งผลการตรวจ</button>
      </div>
    </section>

    <!-- เสร็จแล้ว -->
    <section class="wiz-step hidden" id="inspectionStepDone">
      <div class="wiz-done">
        <div class="wiz-done-icon" aria-hidden="true">✅</div>
        <h2>บันทึกผลการตรวจเรียบร้อยแล้ว</h2>
        <p id="inspectionDoneText"></p>
      </div>
      <div class="wiz-done-actions">
        <button type="button" data-call="switchTab" data-arg="dashboard">🏠 กลับหน้าแรก</button>
        <button type="button" class="gray" data-call="inspectionRestart">🚑 ตรวจรถคันอื่น</button>
      </div>
    </section>
  </div>

  <!-- หน้าต่างช่วยเหลือ -->
  <div id="inspectionHelp" class="hidden sheet-overlay" role="dialog" aria-modal="true" aria-labelledby="inspectionHelpTitle" data-call="closeInspectionHelp" data-call-self>
    <div class="sheet">
      <h3 id="inspectionHelpTitle">📞 ขอความช่วยเหลือ</h3>
      <div id="inspectionHelpBody"></div>
      <a class="wiz-call hidden" id="inspectionHelpCall" href="#">📞 โทรหาเจ้าหน้าที่</a>
      <button type="button" class="gray" data-call="closeInspectionHelp">ปิดหน้าต่างนี้</button>
    </div>
  </div>
</div>

<!-- Restock Tab -->
<div id="tabContentRestock" class="hidden">
  <div class="card">
    <h2>เติมของ</h2>
    <div class="mascot-tip">
      <img src="<?= asset('images/mascot/cat-cart.svg') ?>" alt="" width="120" height="82">
      <p><b>เลือกรถที่จะเติมของ</b><br>แตะการ์ดรถด้านล่าง ระบบจะแสดงรายการที่ต่ำกว่าเกณฑ์ให้ก่อน</p>
    </div>
    <p class="step-label">เลือกรถ</p>
    <div class="car-grid" aria-label="เลือกรถที่จะเติมของ" id="refillCarGrid"></div>
    <div id="refillSheet" class="hidden">
      <div class="refill-head" id="refillHead"></div>
      <div class="mascot-tip">
        <img src="<?= asset('images/mascot/cat-clipboard.svg') ?>" alt="" width="70" height="72">
        <p><b>ก่อนกดบันทึก</b><br>ใส่จำนวนที่เติมจริง และตรวจวันหมดอายุของแต่ละล็อตให้ถูกต้อง</p>
      </div>
      <div class="filter-chips" role="group" aria-label="กรองรายการ">
        <button type="button" class="chip-btn on" id="refillFilterNeed" data-call="setRefillFilter" data-arg="need">ต้องเติม <b id="refillCntNeed"></b></button>
        <button type="button" class="chip-btn" id="refillFilterAll" data-call="setRefillFilter" data-arg="all">ทั้งหมด <b id="refillCntAll"></b></button>
        <button type="button" class="chip-btn" id="refillFilterExp" data-call="setRefillFilter" data-arg="expiry">หมดอายุ <b id="refillCntExp"></b></button>
      </div>
      <div id="refillItems" class="list"></div>
      <label for="refillNote">หมายเหตุ (ถ้ามี)</label>
      <input type="text" id="refillNote" maxlength="200">
      <div class="save-bar">
        <p id="refillSummary" class="save-hint" role="status" aria-live="polite"></p>
        <button id="refillSubmitBtn" type="button" class="secondary" data-call="submitRefill">💾 บันทึก</button>
      </div>
    </div>
  </div>

  <div class="card">
    <h3>รายการรอเติม</h3>
    <div class="filter-chips" role="group" aria-label="กรองสถานะ" style="margin-bottom: 12px;">
      <button type="button" class="chip-btn on" id="restockFilterOpen" data-call="setRestockFilter" data-arg="open">ยังไม่ครบ</button>
      <button type="button" class="chip-btn" id="restockFilterCompleted" data-call="setRestockFilter" data-arg="COMPLETED">เติมครบ</button>
      <button type="button" class="chip-btn" id="restockFilterCancelled" data-call="setRestockFilter" data-arg="CANCELLED">ยกเลิก</button>
      <button type="button" class="chip-btn" id="restockFilterAll" data-call="setRestockFilter" data-arg="all">ทั้งหมด</button>
    </div>
    <div id="restockList"></div>
  </div>
</div>

<!-- Reports Tab -->
<div id="tabContentReports" class="hidden">
  <div class="card">
    <h2>ประวัติการตรวจและการเติมอุปกรณ์</h2>
    <div class="seg" role="group" aria-label="เลือกประเภทประวัติ">
      <button type="button" class="seg-btn on" id="historySegInspections" data-call="setHistoryView" data-arg="inspections">การตรวจเช็ค</button>
      <button type="button" class="seg-btn" id="historySegRestock" data-call="setHistoryView" data-arg="restock">การเติมอุปกรณ์</button>
    </div>
    
    <div class="report-toolbar">
      <input type="search" id="historySearch" placeholder="🔍 ค้นหา วันที่ ทะเบียน ผู้ตรวจ ผู้ดำเนินการ เลขที่คำขอ หรือชื่ออุปกรณ์">
      <div class="filter-chips" id="historyCarChips" role="group" aria-label="กรองตามรถ"></div>
      <div class="filter-chips" id="historyStatusChips" role="group" aria-label="กรองสถานะ"></div>
      <div class="date-row">
        <div><label for="historyFrom">ตั้งแต่วันที่</label><input type="date" id="historyFrom"></div>
        <div><label for="historyTo">ถึงวันที่</label><input type="date" id="historyTo"></div>
      </div>
    </div>
    <p class="report-count" id="historyCount"></p>
    <div id="historyInspectionsTable" class="list"></div>
    <div id="historyRestockTable" class="list hidden"></div>
  </div>
</div>

<!-- Vehicles/Stock Tab -->
<div id="tabContentStock" class="hidden">
  <div class="card">
    <h2>ทะเบียนรถและอุปกรณ์ประจำรถ</h2>

    <div class="vehicle-pick">
      <div class="vehicle-pick-main">
        <label for="stockCar">เลือกรถ</label>
        <select id="stockCar"></select>
      </div>
      <div class="vehicle-pick-actions">
        <button type="button" id="btnEditVehicle" class="small-btn secondary">✏️ แก้ไขข้อมูลรถ</button>
        <button type="button" id="btnToggleVehicleStatus" class="small-btn">🚫 ปิดใช้งานรถ</button>
        <button type="button" id="btnDeleteVehicle" class="small-btn danger">🗑 ลบรถ</button>
      </div>
    </div>
    <p id="stockCarMeta" class="vehicle-meta">จำนวนและวันหมดอายุแยกตามรถแต่ละคัน</p>

    <div class="stock-toolbar">
      <button type="button" class="small-btn secondary" data-call="showAddVehicleForm">🚚 เพิ่มรถใหม่</button>
      <button type="button" class="small-btn" data-call="showAddItemForm">+ เพิ่มอุปกรณ์</button>

      <div id="copyBox" class="copy-box">
        <label for="copySourceCar">คัดลอกรายการจากรถคันอื่น</label>
        <select id="copySourceCar" aria-label="เลือกรถต้นทางสำหรับคัดลอกอุปกรณ์"></select>
        <button type="button" id="btnCopyStart" class="copy-start-btn" disabled>📋 คัดลอกรายการ</button>
      </div>

      <div id="itemImportBox" class="copy-box">
        <label for="itemImportFile">นำเข้ารายการจากไฟล์ (.csv / .xlsx)</label>
        <input type="file" id="itemImportFile" accept=".csv,.xlsx,.xls,text/csv" hidden>
        <button type="button" id="btnItemImport" class="copy-start-btn">📥 นำเข้าจากไฟล์</button>
        <button type="button" id="btnItemTemplate" class="copy-start-btn alt">📄 ดาวน์โหลดไฟล์ต้นแบบ</button>
      </div>
    </div>

    <div id="copyPreview" class="hidden" role="region" aria-label="รายการอุปกรณ์รอเพิ่มลงรถ">
      <div class="copy-preview-head">
        <div class="copy-preview-title">
          <strong>📋 รายการรอเพิ่มลงรถ</strong>
          <span id="copyPreviewFrom" class="copy-preview-from"></span>
        </div>
        <span id="copyPreviewCount" class="chip chip-warn"></span>
      </div>
      <p class="copy-preview-note">ยังไม่ถูกบันทึก — แก้ไขรายการ ใส่จำนวนและเลือกวันหมดอายุได้เลย แล้วกด “ยืนยันเพิ่มลงรถ”</p>
      <p id="copyPreviewStock" class="copy-preview-stock hidden"></p>
      <div id="copyPreviewList"></div>
      <div class="copy-preview-actions">
        <button type="button" id="btnCopyCancel" class="small-btn gray">ยกเลิกรายการทั้งหมด</button>
        <button type="button" id="btnCopyConfirm">ยืนยันเพิ่มลงรถ</button>
      </div>
    </div>

    <div id="stockTable" class="list"></div>
  </div>
</div>

<!-- Nurses Tab -->
<div id="tabContentNurses" class="hidden">
  <div class="card">
    <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2>จัดการผู้ใช้</h2>
        <p>พยาบาลเวร Refer และบัญชีเข้าใช้งานระบบทั้งหมดในหน้าเดียว — เพิ่ม/แก้ไขพยาบาล บัญชี บทบาท และสถานะ</p>
      </div>
      <button type="button" id="wardManagerBtn" class="m-0 inline-flex w-auto min-h-0 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition-colors duration-200 hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-slate-200">
        🏥 จัดการวอร์ด <span id="wardManagerCount" class="rounded-full bg-slate-100 px-1.5 text-[11px] font-bold text-slate-600"></span>
      </button>
    </div>

    <form id="nurseForm" novalidate>
      <h3 id="nurseFormTitle">เพิ่มพยาบาลใหม่</h3>

      <div id="nurseEditor" class="grid gap-5">
        <fieldset class="grid gap-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:grid-cols-2">
          <legend class="px-2 text-sm font-bold text-slate-700">ข้อมูลส่วนตัว</legend>

          <div>
            <label class="!mt-0" for="nName">ชื่อ–นามสกุล <span class="text-red-500" aria-hidden="true">*</span></label>
            <input id="nName" type="text" placeholder="เช่น สมศรี ใจดี" autocomplete="name" required>
            <p class="mt-1 text-xs leading-relaxed text-slate-500">ชื่อ-สกุลจริง ใช้แสดงในประวัติการตรวจ</p>
            <p class="mt-1 hidden text-xs font-semibold text-red-600" id="nNameError" role="alert"></p>
          </div>

          <div>
            <label class="!mt-0" for="nPhone">โทรศัพท์</label>
            <input id="nPhone" type="tel" inputmode="tel" placeholder="081-234-5678" autocomplete="tel">
            <p class="mt-1 text-xs leading-relaxed text-slate-500">พิมพ์เฉพาะตัวเลข ระบบจัดรูปแบบเบอร์ให้อัตโนมัติ</p>
            <p class="mt-1 hidden text-xs font-semibold text-red-600" id="nPhoneError" role="alert"></p>
          </div>
        </fieldset>

        <fieldset class="grid gap-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:grid-cols-2">
          <legend class="px-2 text-sm font-bold text-slate-700">ข้อมูลการทำงาน</legend>

          <div>
            <label class="!mt-0" for="nId">รหัสพยาบาล <span class="text-red-500" aria-hidden="true">*</span></label>
            <div class="flex gap-2">
              <input id="nId" type="text" placeholder="เช่น 1004" class="flex-1 min-w-0" required>
              <button type="button" id="nurseCodeGenBtn" title="สร้างรหัสลำดับถัดไปให้อัตโนมัติ" class="m-0 w-auto min-h-0 shrink-0 whitespace-nowrap rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition-colors duration-200 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-teal-200">⟳ สร้างรหัส</button>
            </div>
            <p class="mt-1 text-xs leading-relaxed text-slate-500">ระบบแนะนำรหัสถัดไปให้ — รหัสต้องไม่ซ้ำกับรายชื่อที่มีอยู่</p>
            <p class="mt-1 hidden text-xs font-semibold text-red-600" id="nIdError" role="alert"></p>
          </div>

          <div>
            <label class="!mt-0" for="nDept">หน่วยงาน / วอร์ด <span class="text-red-500" aria-hidden="true">*</span></label>
            <div class="flex gap-2">
              <select id="nDept" class="min-w-0 flex-1" required></select>
              <button type="button" id="deptQuickAddBtn" title="เพิ่มวอร์ดใหม่เข้าไปในทะเบียนทันที" class="m-0 w-auto min-h-0 shrink-0 whitespace-nowrap rounded-lg border border-teal-300 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 transition-colors duration-200 hover:bg-teal-100 focus:outline-none focus:ring-2 focus:ring-teal-200">+ วอร์ด</button>
            </div>
            <p class="mt-1 text-xs leading-relaxed text-slate-500">เลือกจากทะเบียนวอร์ด — กด "+ วอร์ด" เพื่อเพิ่มหน่วยงานใหม่ หรือ "จัดการวอร์ด" เพื่อแก้ชื่อ/ปิดใช้งาน</p>
            <p class="mt-1 hidden text-xs font-semibold text-red-600" id="nDeptError" role="alert"></p>
          </div>

          <div>
            <label class="!mt-0" for="nStart">วันเริ่มปฏิบัติงาน</label>
            <input id="nStart" type="date">
            <p class="mt-1 text-xs leading-relaxed text-slate-500">กดช่องเพื่อเลือกจากปฏิทิน — ระบบคำนวณวันครบ 6 เดือนให้อัตโนมัติ</p>
            <p class="mt-1 hidden text-xs leading-relaxed text-amber-600" id="nStartLegacy"></p>
          </div>

          <div>
            <label class="!mt-0" for="nActive">สถานะ</label>
            <select id="nActive"><option value="true">ปฏิบัติงาน</option><option value="false">พักใช้งาน</option></select>
            <p class="mt-1 text-xs leading-relaxed text-slate-500">พักใช้งาน = ไม่แสดงในตัวเลือกตอนตรวจเช็ค แต่ประวัติเดิมยังอยู่</p>
          </div>

          <div class="md:col-span-2">
            <label class="!mt-0" for="nNote">หมายเหตุ</label>
            <input id="nNote" type="text" placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)">
          </div>
        </fieldset>

        <fieldset class="grid gap-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:grid-cols-2">
          <legend class="px-2 text-sm font-bold text-slate-700">บัญชีเข้าใช้งานระบบ (ถ้ามี)</legend>

          <div>
            <label class="!mt-0" for="nUsername">ชื่อผู้ใช้สำหรับเข้าสู่ระบบ (เบอร์โทรศัพท์)</label>
            <input id="nUsername" type="text" inputmode="tel" placeholder="เช่น 0812345678" autocomplete="off" autocapitalize="none" spellcheck="false">
            <p class="mt-1 text-xs leading-relaxed text-slate-500">ใช้เบอร์โทรศัพท์ของพยาบาล (กรอกเบอร์โทรด้านบนแล้วระบบใส่ให้เอง) — เว้นว่างได้ถ้ายังไม่เปิดบัญชีให้พยาบาลรายนี้</p>
            <p class="mt-1 hidden text-xs font-semibold text-red-600" id="nUsernameError" role="alert"></p>
          </div>

          <div>
            <label class="!mt-0" for="nPassword">รหัสผ่าน</label>
            <input id="nPassword" type="password" placeholder="เว้นว่าง = ใช้เบอร์โทรเป็นรหัสเริ่มต้น" autocomplete="new-password">
            <p class="mt-1 text-xs leading-relaxed text-slate-500">บัญชีใหม่: เว้นว่าง = รหัสผ่านเริ่มต้นคือเบอร์โทรศัพท์ • ตอนแก้ไข: เว้นว่าง = ใช้รหัสเดิม, กรอก = รีเซ็ตรหัส — ผู้ใช้จะถูกบังคับตั้งรหัสใหม่ตอนเข้าสู่ระบบครั้งแรก</p>
            <p class="mt-1 hidden text-xs font-semibold text-red-600" id="nPasswordError" role="alert"></p>
          </div>

          <p id="nLoginStatus" class="md:col-span-2 hidden rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs leading-relaxed text-teal-700"></p>
        </fieldset>

        <input type="hidden" id="nEnd">

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" id="resetNurseBtn" class="m-0 w-full min-h-0 rounded-xl border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition-all duration-200 hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-slate-200 active:scale-[0.98] sm:w-auto">ล้างช่องกรอก</button>
          <button type="submit" id="nurseSubmitBtn" class="m-0 w-full min-h-0 rounded-xl bg-teal-600 px-6 py-3 text-sm font-bold text-white shadow-md transition-all duration-200 hover:bg-teal-700 hover:shadow-lg focus:outline-none focus:ring-4 focus:ring-teal-200 active:scale-[0.98] sm:w-auto">เพิ่มพยาบาล</button>
        </div>
      </div>
    </form>

    <div class="mt-6 border-t border-slate-200 pt-5">
      <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm font-bold text-slate-700">นำเข้ารายชื่อจากไฟล์ Excel / CSV</p>
        <button type="button" id="nurseTemplateBtn" class="m-0 inline-flex w-auto min-h-0 items-center gap-1.5 rounded-lg border border-teal-300 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 transition-colors duration-200 hover:bg-teal-100 focus:outline-none focus:ring-4 focus:ring-teal-100">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="7 10 12 15 17 10"></polyline>
            <line x1="12" y1="15" x2="12" y2="3"></line>
          </svg>
          ดาวน์โหลดไฟล์ตัวอย่าง (.xlsx)
        </button>
      </div>
      <div id="nurseDropzone" role="button" tabindex="0" aria-label="อัปโหลดไฟล์รายชื่อ Excel หรือ CSV" class="cursor-pointer rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center transition-colors duration-200 hover:border-teal-400 hover:bg-teal-50">
        <svg class="mx-auto mb-2 h-8 w-8 text-teal-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
          <polyline points="17 8 12 3 7 8"></polyline>
          <line x1="12" y1="3" x2="12" y2="15"></line>
        </svg>
        <p class="text-sm font-semibold text-slate-700">ลากไฟล์ .xlsx .xls .csv มาวางที่นี่</p>
        <p class="mt-1 text-xs text-slate-500">หรือ <span class="font-semibold text-teal-600 underline">คลิกเพื่อเลือกไฟล์</span> — ระบบจะแสดงตารางพรีวิวให้ตรวจสอบก่อนบันทึกจริง</p>
        <input id="nurseImport" type="file" accept=".xlsx,.xls,.csv" class="sr-only">
      </div>
      <div id="nurseFileStatus" class="mt-2 hidden flex items-center gap-3 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2">
        <span class="text-lg" aria-hidden="true">📄</span>
        <div class="min-w-0 flex-1">
          <p id="nurseFileName" class="truncate text-xs font-semibold text-slate-700"></p>
          <p id="nurseFileMeta" class="text-xs text-teal-700"></p>
        </div>
        <button type="button" id="nurseFileClear" class="m-0 w-auto min-h-0 shrink-0 rounded-md px-2 py-1 text-xs font-semibold text-teal-700 transition-colors duration-200 hover:bg-teal-100" aria-label="ลบไฟล์ที่เลือก">✕</button>
      </div>
    </div>
    
    <div class="search-box flex flex-wrap items-center gap-2">
      <input type="text" id="nurseSearch" placeholder="🔍 ค้นหาด้วย ชื่อ, สกุล, ชื่อบัญชี หรือหน่วยงาน..." class="min-w-0 flex-1">
      <select id="nurseDeptFilter" aria-label="กรองรายชื่อตามวอร์ด" class="w-auto min-w-0 shrink-0 whitespace-nowrap"></select>
      <select id="userRoleFilter" aria-label="กรองตามประเภท/บทบาท" class="w-auto min-w-0 shrink-0 whitespace-nowrap">
        <option value="">ทุกประเภท</option>
        <option value="nurse">พยาบาลทั้งหมด</option>
        <option value="nurse-noaccount">พยาบาลที่ยังไม่มีบัญชี</option>
        <option value="admin">ผู้ดูแลระบบ (Admin)</option>
        <option value="staff">เจ้าหน้าที่ (Staff)</option>
        <option value="other">บัญชีที่ไม่ใช่พยาบาล</option>
      </select>
      <select id="userStatusFilter" aria-label="กรองตามสถานะ" class="w-auto min-w-0 shrink-0 whitespace-nowrap">
        <option value="">ทุกสถานะ</option>
        <option value="active">ใช้งาน / ปฏิบัติงาน</option>
        <option value="inactive">ปิดใช้งาน / พักใช้งาน</option>
      </select>
    </div>
    <p id="nurseFilterNote" class="hidden mb-2 text-xs font-semibold text-teal-700"></p>

    <h3 class="user-section-title">👩‍⚕️ พยาบาลและบัญชีเข้าใช้งาน</h3>
    <div id="nurseDirectoryTable" class="list"></div>

    <h3 class="user-section-title">👥 บัญชีผู้ใช้อื่น (ไม่ได้ผูกกับรายชื่อพยาบาล)</h3>
    <p class="text-xs text-slate-500">เช่น ผู้ดูแลระบบ หรือเจ้าหน้าที่ที่ไม่อยู่ในทะเบียนพยาบาล — บัญชีของพยาบาลแสดงในการ์ดของพยาบาลด้านบน</p>
    <div id="userDirectoryTable" class="list"></div>

    <details id="userFormWrap" class="user-form-wrap">
      <summary>+ เพิ่ม / แก้ไขบัญชีผู้ใช้ที่ไม่ใช่พยาบาล</summary>
      <form id="userForm">
        <h3 id="userFormTitle">เพิ่มผู้ใช้ใหม่</h3>
        <div class="editor" id="userEditor">
          <div><label for="uUsername">ชื่อผู้ใช้ (Username)</label><input id="uUsername" placeholder="เช่น staff01" autocomplete="off" autocapitalize="none" spellcheck="false"></div>
          <div><label for="uPassword">รหัสผ่าน</label><input id="uPassword" type="password" placeholder="รหัสผ่าน (อย่างน้อย 6 ตัวอักษร)" autocomplete="new-password"></div>
          <div><label for="uFullName">ชื่อ–นามสกุล</label><input id="uFullName" placeholder="ชื่อ–นามสกุล"></div>
          <div><label for="uRole">บทบาท</label><select id="uRole"><option value="staff">Staff (เจ้าหน้าที่ปฏิบัติงาน)</option><option value="admin">Admin (ผู้ดูแลระบบ)</option></select></div>
          <div><label for="uNurse">ผูกกับรายชื่อพยาบาล (ถ้ามี)</label><select id="uNurse"><option value="">-- ไม่ผูก --</option></select></div>
          <button type="submit">เพิ่มผู้ใช้</button>
          <button type="button" class="gray">ล้างช่องกรอก</button>
        </div>
        <p class="text-xs text-slate-500">บัญชีของพยาบาลสร้าง/แก้ได้ในฟอร์มพยาบาลด้านบน (ส่วน "บัญชีเข้าใช้งานระบบ") — พยาบาล 1 คนมีได้ 1 บัญชี</p>
      </form>
    </details>
  </div>
</div>
