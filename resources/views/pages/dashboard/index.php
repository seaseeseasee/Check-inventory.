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
        <!-- ฟอร์มสั้น: แถบไม่ลอย (is-static) เพื่อไม่บังช่องเลือกวันที่/ข้อความเมื่อจอเตี้ย -->
        <div class="save-bar wiz-bar is-static">
          <p id="inspectionShiftHint" class="shift-hint" role="status" aria-live="polite"></p>
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
      <!-- หน้าสรุปสั้น และต้องอ่านให้จบก่อนส่ง: ปุ่มอยู่ท้ายรายการ (ไม่ลอย) จึงไม่บังกล่องสรุปสุดท้าย -->
      <div class="save-bar wiz-bar is-static wiz-submit-bar">
        <p class="save-hint">อ่านสรุปด้านบนครบแล้ว กดส่งได้เลย</p>
        <button id="inspectionSubmitBtn" type="button" class="wiz-submit" data-call="submitInspection">✅ ยืนยันส่งผลการตรวจ</button>
      </div>
    </section>

    <!-- เสร็จแล้ว -->
    <section class="wiz-step hidden" id="inspectionStepDone">
      <div class="wiz-done">
        <!-- มาสคอตแมวชูสองมือดีใจ + ป้ายติ๊กถูก แทนไอคอน ✅ อย่างเดียว -->
        <div class="wiz-done-mascot" aria-hidden="true">
          <img src="<?= asset('images/mascot/cat-cheer.svg') ?>" alt="" width="150" height="154">
          <span class="wiz-done-badge">✅</span>
        </div>
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
      <!-- เลือกเติมเฉพาะบางกลุ่มก่อน: ค้นหาชื่อ + ประเภท (ใช้ร่วมกับปุ่มด้านบน) -->
      <div class="refill-narrow">
        <input type="search" id="refillSearch" placeholder="🔍 ค้นหาชื่ออุปกรณ์" aria-label="ค้นหาชื่ออุปกรณ์ที่จะเติม" autocomplete="off">
        <select id="refillType" aria-label="กรองตามประเภทอุปกรณ์">
          <option value="">ทุกประเภท</option>
          <option value="individual">🧩 รายชิ้น</option>
          <option value="box">📦 กล่อง / ล็อต</option>
          <option value="non_expiring">🩺 ไม่มีวันหมดอายุ</option>
        </select>
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
    <!-- งานของ "รถทุกคัน" (เพิ่มรถ) อยู่ข้างหัวข้อ แยกจากงานของ "รถคันที่เลือก" ด้านล่าง -->
    <div class="stock-head">
      <h2>ทะเบียนรถและอุปกรณ์</h2>
      <button type="button" class="small-btn stock-add-car" data-call="showAddVehicleForm">+ เพิ่มรถ</button>
    </div>

    <label for="stockCar">เลือกรถ</label>
    <select id="stockCar"></select>

    <div class="vehicle-card">
      <div id="stockCarMeta" class="vehicle-meta">จำนวนและวันหมดอายุแยกตามรถแต่ละคัน</div>
      <div class="vehicle-actions" id="vehicleActions">
        <button type="button" id="btnEditVehicle" class="small-btn vehicle-edit">✏️ แก้ไขข้อมูลรถ</button>
        <!-- ปิดใช้งาน / ลบ นาน ๆ ใช้ที: เก็บในเมนู ⋯ ลดปุ่มแดงบนจอและกันกดพลาด -->
        <details class="vehicle-more" id="vehicleMore">
          <summary class="small-btn" aria-label="ตัวเลือกเพิ่มเติมของรถคันนี้" title="ตัวเลือกเพิ่มเติม">⋯</summary>
          <div class="vehicle-more-menu">
            <button type="button" id="btnToggleVehicleStatus" class="vehicle-more-item">🚫 ปิดใช้งานรถ</button>
            <button type="button" id="btnDeleteVehicle" class="vehicle-more-item is-danger">🗑 ลบรถ</button>
          </div>
        </details>
      </div>
    </div>

    <div class="stock-section-head">
      <h3>อุปกรณ์ในรถคันนี้</h3>
      <span id="stockItemCount" class="stock-item-count"></span>
    </div>
    <button type="button" class="stock-add-item" data-call="showAddItemForm">+ เพิ่มอุปกรณ์</button>

    <!-- คัดลอก / นำเข้า ใช้ตอนตั้งค่ารถคันใหม่เป็นหลัก จึงพับเก็บไว้ -->
    <details class="stock-more-ways" id="stockMoreWays">
      <summary>วิธีเพิ่มอุปกรณ์แบบอื่น <small>(คัดลอกจากรถคันอื่น / นำเข้าจากไฟล์)</small></summary>
      <div class="stock-more-body">
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
    </details>

    <div id="copyPreview" class="hidden" role="region" aria-label="รายการอุปกรณ์รอเพิ่มลงรถ">
      <!-- หัวสรุปติดด้านบนระหว่างเลื่อนดูรายการ: จำนวน / ที่มา / ความคืบหน้า / ตัวกรอง -->
      <div class="copy-sum">
        <div class="copy-sum-title">
          <strong id="copyPreviewCount">รอเพิ่มลงรถ</strong>
          <span id="copyPreviewFrom" class="copy-preview-from"></span>
        </div>
        <div class="copy-progress" aria-hidden="true"><i id="copyProgressBar"></i></div>
        <p id="copyPreviewStock" class="copy-sum-line"></p>
        <div class="copy-filters" role="group" aria-label="กรองรายการรอเพิ่มลงรถ">
          <button type="button" data-copy-filter="all" aria-pressed="true">ทั้งหมด</button>
          <button type="button" data-copy-filter="dup" class="is-warn" aria-pressed="false">ซ้ำกับในรถ</button>
          <button type="button" data-copy-filter="empty" aria-pressed="false">ยังไม่ใส่จำนวน</button>
        </div>
      </div>
      <div id="copyPreviewList"></div>
      <p id="copyFilterEmpty" class="copy-filter-empty hidden">ไม่มีรายการในกลุ่มนี้แล้ว</p>
      <div class="save-bar copy-preview-actions">
        <button type="button" id="btnCopyCancel" class="small-btn gray">ยกเลิกรายการทั้งหมด</button>
        <button type="button" id="btnCopyConfirm">ยืนยันเพิ่มลงรถ</button>
      </div>
    </div>

    <div id="stockTable" class="list"></div>
  </div>
</div>

<!-- Nurses Tab (จัดการผู้ใช้: รายชื่อก่อน — เพิ่ม/แก้ไข/นำเข้า อยู่ในหน้าต่างที่เลื่อนขึ้นจากด้านล่าง) -->
<div id="tabContentNurses" class="hidden">
  <div class="card users-page">
    <!-- แถบบนติดตอนเลื่อน: หัวข้อ + ปุ่ม / ตัวเลขสรุป / ค้นหา + วอร์ด / แท็บกรอง -->
    <div class="users-top">
      <div class="users-title-row">
        <h2>จัดการผู้ใช้</h2>
        <div class="users-head-actions">
          <button type="button" id="userAddBtn" class="users-add-btn">+ เพิ่มผู้ใช้</button>
          <details class="users-more" id="usersMore">
            <summary class="small-btn" aria-label="ตัวเลือกเพิ่มเติม" title="ตัวเลือกเพิ่มเติม">⋯</summary>
            <div class="users-more-menu">
              <button type="button" id="userImportOpenBtn" class="users-more-item">📥 นำเข้าจาก Excel / CSV</button>
              <button type="button" id="nurseTemplateBtn" class="users-more-item">📄 ดาวน์โหลดไฟล์ตัวอย่าง (.xlsx)</button>
              <button type="button" id="wardManagerBtn" class="users-more-item">🏥 จัดการวอร์ด <span id="wardManagerCount" class="users-more-count"></span></button>
            </div>
          </details>
        </div>
      </div>
      <div class="users-stats" id="userStats" aria-live="polite"></div>
      <div class="users-search">
        <input type="search" id="nurseSearch" placeholder="🔍 ชื่อ, รหัส, เบอร์, ชื่อผู้ใช้" aria-label="ค้นหาผู้ใช้">
        <select id="nurseDeptFilter" aria-label="กรองรายชื่อตามวอร์ด"></select>
      </div>
      <div class="users-chips" id="userChips" role="group" aria-label="กรองตามประเภทและสถานะ" data-role="" data-status="">
        <!-- 3 หมวด — กดหมวดที่เลือกอยู่ซ้ำ = กลับไปแสดงทั้งหมด -->
        <button type="button" data-role="nurse" data-status="" aria-pressed="false">👩‍⚕️ พยาบาล</button>
        <button type="button" data-role="admin" data-status="" aria-pressed="false">👑 แอดมิน</button>
        <button type="button" data-role="other" data-status="" aria-pressed="false">👤 ผู้ใช้</button>
      </div>
      <p id="nurseFilterNote" class="hidden users-filter-note"></p>
    </div>

    <p class="users-group" id="nurseGroupTitle">พยาบาล</p>
    <div id="nurseDirectoryTable" class="users-list"></div>

    <p class="users-group" id="otherGroupTitle">บัญชีอื่น (ไม่ผูกกับรายชื่อพยาบาล)</p>
    <div id="userDirectoryTable" class="users-list"></div>
    <p id="usersEmpty" class="empty hidden">ไม่พบผู้ใช้ที่ตรงกับตัวกรอง</p>
  </div>

  <!-- หน้าต่างเพิ่ม / แก้ไขผู้ใช้ (พยาบาล หรือ บัญชีอื่น) -->
  <div id="userSheet" class="hidden sheet-overlay" role="dialog" aria-modal="true" aria-labelledby="userSheetTitle" data-call="closeUserSheet" data-call-self>
    <div class="sheet user-sheet">
      <div class="user-sheet-head">
        <div class="user-sheet-title">
          <h3 id="userSheetTitle">เพิ่มผู้ใช้</h3>
          <div class="user-sheet-tools">
            <!-- แก้ไขพยาบาล: คำสั่งที่ใช้นาน ๆ ครั้ง (ลบ) เก็บไว้ในเมนู ไม่วางใกล้ปุ่มบันทึก -->
            <button type="button" class="user-sheet-x hidden" id="nurseMenuBtn" aria-label="คำสั่งเพิ่มเติม" aria-haspopup="menu" aria-expanded="false" aria-controls="nurseMenu">⋮</button>
            <div id="nurseMenu" class="user-menu hidden" role="menu" aria-label="คำสั่งเพิ่มเติม">
              <button type="button" id="nurseDeleteBtn" class="user-menu-item is-danger" role="menuitem">🗑 ลบพยาบาลคนนี้</button>
            </div>
            <button type="button" class="user-sheet-x" data-call="closeUserSheet" aria-label="ปิดหน้าต่าง">✕</button>
          </div>
        </div>
        <div class="user-kind" id="userKind" role="group" aria-label="ประเภทผู้ใช้">
          <button type="button" data-kind="nurse" aria-pressed="true">👩‍⚕️ พยาบาล</button>
          <button type="button" data-kind="other" aria-pressed="false">👤 บัญชีอื่น</button>
        </div>
      </div>

      <form id="nurseForm" class="user-form" novalidate>
        <h3 id="nurseFormTitle" class="sr-only">เพิ่มพยาบาลใหม่</h3>
        <div id="nurseEditor" class="user-form-body">
          <section class="user-sec">
            <h4>ข้อมูลพื้นฐาน</h4>
            <div class="user-grid">
              <div class="user-wide">
                <label for="nName">ชื่อ–นามสกุล <span class="user-req" aria-hidden="true">*</span></label>
                <input id="nName" type="text" placeholder="เช่น สมศรี ใจดี" autocomplete="name" required>
                <p class="user-err hidden" id="nNameError" role="alert"></p>
              </div>
              <div>
                <label for="nId">รหัสพยาบาล <span class="user-req" aria-hidden="true">*</span></label>
                <div class="user-with-btn">
                  <input id="nId" type="text" placeholder="เช่น 1004" required>
                  <button type="button" id="nurseCodeGenBtn" class="user-mini-btn" title="สร้างรหัสลำดับถัดไปให้อัตโนมัติ" aria-label="สร้างรหัสถัดไป">⟳</button>
                </div>
                <p class="user-err hidden" id="nIdError" role="alert"></p>
              </div>
              <div>
                <label for="nPhone">โทรศัพท์</label>
                <input id="nPhone" type="tel" inputmode="tel" placeholder="081-234-5678" autocomplete="tel">
                <p class="user-err hidden" id="nPhoneError" role="alert"></p>
              </div>
              <div class="user-wide">
                <label for="nDept">หน่วยงาน / วอร์ด <span class="user-req" aria-hidden="true">*</span></label>
                <div class="user-with-btn">
                  <select id="nDept" required></select>
                  <button type="button" id="deptQuickAddBtn" class="user-mini-btn" title="เพิ่มวอร์ดใหม่เข้าไปในทะเบียนทันที">+ วอร์ด</button>
                </div>
                <p class="user-err hidden" id="nDeptError" role="alert"></p>
              </div>
            </div>
          </section>

          <details class="user-sec user-extra" id="nurseExtra">
            <summary>ข้อมูลเพิ่มเติม (วันเริ่มงาน, สถานะ, หมายเหตุ)</summary>
            <div class="user-grid">
              <div>
                <label for="nStart">วันเริ่มปฏิบัติงาน</label>
                <input id="nStart" type="date">
                <p class="user-hint user-hint-warn hidden" id="nStartLegacy"></p>
              </div>
              <div>
                <label for="nActive">สถานะ</label>
                <select id="nActive"><option value="true">ปฏิบัติงาน</option><option value="false">พักใช้งาน</option></select>
              </div>
              <div class="user-wide">
                <label for="nNote">หมายเหตุ</label>
                <input id="nNote" type="text" placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)">
              </div>
            </div>
            <p class="user-hint">ครบ 6 เดือนคำนวณจากวันเริ่มให้อัตโนมัติ · พักใช้งาน = ไม่แสดงตอนเลือกผู้ตรวจ แต่ประวัติเดิมยังอยู่</p>
          </details>

          <section class="user-sec">
            <h4>บัญชีเข้าใช้งานระบบ</h4>
            <div class="user-switch" id="nAccountSwitchRow">
              <div>
                <b>เปิดบัญชีให้คนนี้</b>
                <small>เข้าสู่ระบบด้วยเบอร์โทร · รหัสเริ่มต้น = เบอร์โทร</small>
              </div>
              <button type="button" class="user-toggle" id="nAccountToggle" role="switch" aria-checked="true" aria-label="เปิดบัญชีเข้าใช้งาน"></button>
            </div>
            <div id="nAccountFields" class="user-grid">
              <div>
                <label for="nUsername">ชื่อผู้ใช้ (เบอร์โทร)</label>
                <input id="nUsername" type="text" inputmode="tel" placeholder="0812345678" autocomplete="off" autocapitalize="none" spellcheck="false">
                <p class="user-err hidden" id="nUsernameError" role="alert"></p>
              </div>
              <div>
                <label for="nPassword">รหัสผ่าน</label>
                <input id="nPassword" type="password" placeholder="ว่าง = ใช้เบอร์โทร" autocomplete="new-password">
                <p class="user-err hidden" id="nPasswordError" role="alert"></p>
              </div>
              <p class="user-hint user-wide" id="nAccountHint">ผู้ใช้จะถูกให้ตั้งรหัสใหม่ตอนเข้าสู่ระบบครั้งแรก</p>
              <p id="nLoginStatus" class="user-hint user-wide hidden"></p>
            </div>
            <!-- บัญชีที่มีอยู่แล้ว: สวิตช์เปิด-ปิดบัญชี / สิทธิ์ Admin — กดแล้วถามยืนยันและมีผลทันที -->
            <div id="nAccountActions" class="user-acc-actions hidden">
              <div class="user-switch">
                <b>อนุญาตให้เข้าสู่ระบบ</b>
                <span class="user-switch-end">
                  <small id="nAccountActiveState" aria-hidden="true"></small>
                  <button type="button" class="user-toggle" id="nAccountActiveBtn" role="switch" aria-checked="false" aria-label="อนุญาตให้เข้าสู่ระบบ"></button>
                </span>
              </div>
              <div class="user-switch">
                <b>สิทธิ์ผู้ดูแลระบบ (Admin)</b>
                <span class="user-switch-end">
                  <small id="nRoleState" aria-hidden="true"></small>
                  <button type="button" class="user-toggle" id="nRoleBtn" role="switch" aria-checked="false" aria-label="สิทธิ์ผู้ดูแลระบบ (Admin)"></button>
                </span>
              </div>
              <p id="nLastLogin" class="user-hint"></p>
            </div>
          </section>

          <input type="hidden" id="nEnd">
        </div>
        <div class="user-sheet-foot">
          <button type="button" class="small-btn gray" data-call="closeUserSheet">ยกเลิก</button>
          <button type="submit" id="nurseSubmitBtn">เพิ่มพยาบาล</button>
        </div>
      </form>

      <form id="userForm" class="user-form hidden" novalidate>
        <h3 id="userFormTitle" class="sr-only">เพิ่มบัญชีผู้ใช้ใหม่</h3>
        <div id="userEditor" class="user-form-body">
          <section class="user-sec">
            <h4>ข้อมูลบัญชี</h4>
            <div class="user-grid">
              <div class="user-wide"><label for="uFullName">ชื่อ–นามสกุล <span class="user-req" aria-hidden="true">*</span></label><input id="uFullName" placeholder="ชื่อ–นามสกุล"></div>
              <div><label for="uUsername">ชื่อผู้ใช้ <span class="user-req" aria-hidden="true">*</span></label><input id="uUsername" placeholder="เช่น staff01" autocomplete="off" autocapitalize="none" spellcheck="false"></div>
              <div><label for="uRole">บทบาท</label><select id="uRole"><option value="staff">Staff (เจ้าหน้าที่)</option><option value="admin">Admin (ผู้ดูแลระบบ)</option></select></div>
              <div class="user-wide"><label for="uPassword">รหัสผ่าน</label><input id="uPassword" type="password" placeholder="อย่างน้อย 6 ตัวอักษร" autocomplete="new-password"></div>
            </div>
          </section>
          <details class="user-sec user-extra">
            <summary>ผูกกับรายชื่อพยาบาล (ถ้ามี)</summary>
            <div class="user-grid">
              <div class="user-wide"><label for="uNurse">พยาบาล</label><select id="uNurse"><option value="">-- ไม่ผูก --</option></select></div>
            </div>
            <p class="user-hint">พยาบาล 1 คนมีได้ 1 บัญชี — ผูกแล้วบัญชีจะไปแสดงในแถวของพยาบาลคนนั้น</p>
          </details>
          <div id="uAccountActions" class="user-acc-actions hidden">
            <button type="button" id="uActiveBtn" class="user-acc-btn"></button>
            <p id="uLastLogin" class="user-hint"></p>
          </div>
        </div>
        <div class="user-sheet-foot">
          <button type="button" class="small-btn gray" data-call="closeUserSheet">ยกเลิก</button>
          <button type="submit" id="userSubmitBtn">เพิ่มบัญชี</button>
        </div>
      </form>
    </div>
  </div>

  <!-- หน้าต่างนำเข้ารายชื่อจากไฟล์ -->
  <div id="userImportSheet" class="hidden sheet-overlay" role="dialog" aria-modal="true" aria-labelledby="userImportTitle" data-call="closeUserImport" data-call-self>
    <div class="sheet user-sheet">
      <div class="user-sheet-head">
        <div class="user-sheet-title">
          <h3 id="userImportTitle">นำเข้ารายชื่อจาก Excel / CSV</h3>
          <button type="button" class="user-sheet-x" data-call="closeUserImport" aria-label="ปิดหน้าต่าง">✕</button>
        </div>
      </div>
      <div class="user-form-body">
        <div id="nurseDropzone" role="button" tabindex="0" aria-label="อัปโหลดไฟล์รายชื่อ Excel หรือ CSV" class="user-dropzone">
          <span class="user-dropzone-ico" aria-hidden="true">📥</span>
          <p class="user-dropzone-main">ลากไฟล์ .xlsx .xls .csv มาวาง หรือ <u>แตะเพื่อเลือกไฟล์</u></p>
          <p class="user-hint">ระบบจะแสดงตารางพรีวิวให้ตรวจก่อนบันทึกจริง</p>
          <input id="nurseImport" type="file" accept=".xlsx,.xls,.csv" class="sr-only">
        </div>
        <div id="nurseFileStatus" class="user-file-status hidden">
          <span aria-hidden="true">📄</span>
          <div class="user-file-text">
            <p id="nurseFileName"></p>
            <p id="nurseFileMeta"></p>
          </div>
          <button type="button" id="nurseFileClear" class="user-sheet-x" aria-label="ซ่อนสถานะไฟล์">✕</button>
        </div>
        <button type="button" data-call="downloadNurseTemplate" class="user-acc-btn">📄 ดาวน์โหลดไฟล์ตัวอย่าง (.xlsx)</button>
      </div>
    </div>
  </div>
</div>
