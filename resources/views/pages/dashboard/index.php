<!-- Dashboard Tab -->
<div id="tabContentDashboard">
  <div class="card">
    <h2>ภาพรวมระบบ</h2>
    <div class="dash-stats" id="dashStats"></div>
    <div id="dashContent"></div>
  </div>
</div>

<!-- Inspection/Check Tab -->
<div id="tabContentCheck" class="hidden">
  <!-- Step 1: Car & Nurse Selection View -->
  <div class="card" id="inspectionLoginPage">
    <h2>เริ่มตรวจเช็ครถ</h2>
    <form id="inspectionStartForm">
      <div class="car-grid" aria-label="เลือกรถรีเฟอร์" id="inspectionCarGrid"></div>

      <label for="inspectionNurse">ชื่อผู้ตรวจ (พยาบาล)</label>
      <select id="inspectionNurse">
        <option value="">-- เลือกรายชื่อพยาบาล --</option>
      </select>
      <p id="inspectionNurseLock" class="mt-1 hidden text-xs font-semibold" role="status"></p>

      <div class="shift-row">
        <div>
          <label for="inspectionDate">วันที่ตรวจ</label>
          <input type="date" id="inspectionDate" required>
        </div>
        <div>
          <label for="inspectionShift">เวร</label>
          <select id="inspectionShift" required>
            <option value="">-- เลือกเวร --</option>
            <option value="morning">เวรเช้า (08:00 - 16:00)</option>
            <option value="afternoon">เวรบ่าย (16:00 - 24:00)</option>
            <option value="night">เวรดึก (00:00 - 08:00)</option>
          </select>
        </div>
      </div>
      <p id="inspectionShiftHint" class="shift-hint" role="status" aria-live="polite"></p>

      <button type="submit" id="inspectionStartBtn">เริ่มตรวจเช็ค</button>
    </form>
  </div>

  <!-- Step 2: The Active Inspection Form -->
  <div class="card hidden" id="inspectionCheckPage">
    <h2>แบบฟอร์มตรวจสอบอุปกรณ์</h2>
    <div class="top-info" id="inspectionLoginInfo"></div>
    <p id="inspectionModeNote" class="mode-note hidden" role="status"></p>
    <details class="check-help">
      <summary>วิธีตรวจ</summary>
      <ol>
        <li>กรอก <b>ใช้ไป</b> ในเวรนี้ (ไม่ได้ใช้ = 0) ระบบคำนวณ <b>คงเหลือที่ควรมี</b> ให้</li>
        <li>นับของจริงแล้วกด <b>✓ ครบถ้วน</b> ถ้าตรง — ถ้าไม่ตรงให้กรอก <b>นับได้จริง</b> พร้อมหมายเหตุ (= พบความผิดปกติ)</li>
        <li>เครื่องใช้ไฟฟ้า: เลือก พร้อม/ไม่พร้อมใช้งาน และแบต 1-4 ขีด ทีละเครื่อง (ไม่พร้อมใช้ต้องระบุสาเหตุ)</li>
      </ol>
    </details>

    <div class="check-sticky">
      <div class="progress-wrap">
        <div class="progress-track"><div class="progress-fill" id="inspectionProgressFill"></div></div>
        <div class="progress-text" id="inspectionProgressText"></div>
        <p class="autosave-note" id="inspectionAutosaveNote" role="status" aria-live="polite"></p>
      </div>
      <div class="filter-chips" role="group" aria-label="กรองรายการตรวจ">
        <button type="button" class="chip-btn on" id="inspectionChipAll" onclick="setInspectionFilter('all')">ทั้งหมด <b id="inspectionCntAll"></b></button>
        <button type="button" class="chip-btn" id="inspectionChipPending" onclick="setInspectionFilter('pending')">ยังไม่ตรวจ <b id="inspectionCntPending"></b></button>
        <button type="button" class="chip-btn" id="inspectionChipAttn" onclick="setInspectionFilter('attn')">ต้องเติม/ผิดปกติ <b id="inspectionCntAttn"></b></button>
      </div>
    </div>

    <div class="btn-row">
      <button type="button" onclick="checkAllInspection()" title="ยืนยันเฉพาะรายการที่ยังไม่ตรวจ ว่าใช้ไป 0 และนับได้ครบตามยอด">✅ ยืนยันครบถ้วนรายการที่เหลือ (ใช้ไป 0)</button>
      <button type="button" class="gray" onclick="cancelInspection()">ยกเลิก</button>
    </div>

    <div id="inspectionItemTable" class="list"></div>

    <div class="save-bar">
      <p id="inspectionSaveHint" class="save-hint"></p>
      <button id="inspectionSubmitBtn" type="button" class="secondary" onclick="submitInspection()">💾 บันทึกผลตรวจ</button>
    </div>
  </div>
</div>

<!-- Restock Tab -->
<div id="tabContentRestock" class="hidden">
  <div class="card">
    <h2>เติมอุปกรณ์เข้ารถ</h2>
    <p class="text-sm text-slate-500">เติมเข้ารถที่เลือกโดยตรง (ไม่มีคลังกลาง) — ของสิ้นเปลืองต้องระบุวันหมดอายุทุก Lot, เติมหลายวันหมดอายุได้ในครั้งเดียว</p>
    <div class="car-grid" aria-label="เลือกรถที่จะเติมของ" id="refillCarGrid"></div>
    <div id="refillSheet" class="hidden">
      <div class="refill-head" id="refillHead"></div>
      <div class="filter-chips" role="group" aria-label="กรองรายการ">
        <button type="button" class="chip-btn on" id="refillFilterNeed" onclick="setRefillFilter('need')">ต้องเติม <b id="refillCntNeed"></b></button>
        <button type="button" class="chip-btn" id="refillFilterAll" onclick="setRefillFilter('all')">ทั้งหมด <b id="refillCntAll"></b></button>
        <button type="button" class="chip-btn" id="refillFilterExp" onclick="setRefillFilter('expiry')">หมดอายุ/ใกล้หมด <b id="refillCntExp"></b></button>
      </div>
      <div id="refillItems" class="list"></div>
      <div class="save-bar">
        <input type="text" id="refillNote" maxlength="200" placeholder="หมายเหตุการเติม (ถ้ามี)" aria-label="หมายเหตุการเติม">
        <p id="refillSummary" class="save-hint" role="status" aria-live="polite"></p>
        <button id="refillSubmitBtn" type="button" class="secondary" onclick="submitRefill()">💾 บันทึกการเติม</button>
      </div>
    </div>
  </div>

  <div class="card">
    <h3>รายการรอเติม (สร้างจากผลตรวจเช็ค)</h3>
    <div class="filter-chips" role="group" aria-label="กรองสถานะ" style="margin-bottom: 12px;">
      <button type="button" class="chip-btn on" id="restockFilterOpen" onclick="setRestockFilter('open')">ยังไม่ครบ</button>
      <button type="button" class="chip-btn" id="restockFilterCompleted" onclick="setRestockFilter('COMPLETED')">เติมครบ</button>
      <button type="button" class="chip-btn" id="restockFilterCancelled" onclick="setRestockFilter('CANCELLED')">ยกเลิก</button>
      <button type="button" class="chip-btn" id="restockFilterAll" onclick="setRestockFilter('all')">ทั้งหมด</button>
    </div>
    <div id="restockList"></div>
  </div>
</div>

<!-- Reports Tab -->
<div id="tabContentReports" class="hidden">
  <div class="card">
    <h2>ประวัติการตรวจและการเติมอุปกรณ์</h2>
    <div class="seg" role="group" aria-label="เลือกประเภทประวัติ">
      <button type="button" class="seg-btn on" id="historySegInspections" onclick="setHistoryView('inspections')">การตรวจเช็ค</button>
      <button type="button" class="seg-btn" id="historySegRestock" onclick="setHistoryView('restock')">การเติมอุปกรณ์</button>
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

    <div id="addItemForm" class="hidden">
      <h3>เพิ่มอุปกรณ์ใหม่</h3>
      <form id="stockAddForm">
        <div class="editor">
          <div><label for="stockName">ชื่ออุปกรณ์</label><input id="stockName" placeholder="ชื่ออุปกรณ์" required></div>
          <div><label for="stockStandard">จำนวนมาตรฐาน</label><input id="stockStandard" placeholder="จำนวนมาตรฐาน เช่น 10 หรือ 1 ชุด" required></div>
          <div>
            <label for="stockItemType">ประเภทอุปกรณ์</label>
            <select id="stockItemType">
              <option value="piece">แบบชิ้น — แต่ละชิ้นวันหมดอายุอาจต่างกัน</option>
              <option value="box">แบบกล่อง — ทั้งกล่อง/Lot วันหมดอายุเดียวกัน</option>
              <option value="electric">เครื่องใช้ไฟฟ้า — สถานะ/แบตรายเครื่อง</option>
            </select>
          </div>
          <button type="submit">เพิ่มอุปกรณ์</button>
          <button type="button" class="gray" onclick="hideAddItemForm()">ยกเลิก</button>
        </div>
        <p class="text-xs text-slate-500">รายการใหม่ยังไม่มียอดบนรถ — เพิ่มของพร้อมวันหมดอายุ/ลงทะเบียนเครื่องที่เมนู “เติมอุปกรณ์” หรือตรวจนับในเวรถัดไป</p>
      </form>
    </div>
    
    <div class="stock-toolbar">
      <button type="button" class="small-btn secondary" onclick="showAddVehicleForm()">🚚 เพิ่มรถใหม่</button>
      <button type="button" class="small-btn" onclick="showAddItemForm()">+ เพิ่มอุปกรณ์</button>

      <div id="copyBox" class="copy-box">
        <label for="copySourceCar">คัดลอกรายการจากรถคันอื่น</label>
        <select id="copySourceCar" aria-label="เลือกรถต้นทางสำหรับคัดลอกอุปกรณ์"></select>
        <button type="button" id="btnCopyStart" class="copy-start-btn" disabled>📋 คัดลอกรายการ</button>
      </div>
    </div>

    <div id="copyPreview" class="hidden" role="region" aria-label="รายการอุปกรณ์รอคัดลอก">
      <div class="copy-preview-head">
        <div class="copy-preview-title">
          <strong>📋 รายการรอคัดลอก</strong>
          <span id="copyPreviewFrom" class="copy-preview-from"></span>
        </div>
        <span id="copyPreviewCount" class="chip chip-warn"></span>
      </div>
      <p class="copy-preview-note">ยังไม่ถูกบันทึก — ตรวจสอบ/แก้ไขรายการ แล้วกด “ยืนยันคัดลอก”</p>
      <p id="copyPreviewStock" class="copy-preview-stock hidden"></p>
      <div id="copyPreviewList"></div>
      <div class="copy-preview-actions">
        <button type="button" id="btnCopyCancel" class="small-btn gray">ยกเลิกรายการคัดลอก</button>
        <button type="button" id="btnCopyConfirm">ยืนยันคัดลอก</button>
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
        <h2>จัดการพยาบาลเวร Refer</h2>
        <p>ทะเบียนนี้จัดเก็บในฐานข้อมูลของระบบ เพิ่มหรือแก้ไขได้โดยไม่ต้องแก้โค้ด</p>
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
            <label class="!mt-0" for="nUsername">ชื่อผู้ใช้สำหรับเข้าสู่ระบบ</label>
            <input id="nUsername" type="text" placeholder="เช่น somsee.j" autocomplete="off" autocapitalize="none" spellcheck="false">
            <p class="mt-1 text-xs leading-relaxed text-slate-500">ใช้ a-z A-Z 0-9 . _ - เท่านั้น — เว้นว่างได้ถ้ายังไม่เปิดบัญชีให้พยาบาลรายนี้</p>
            <p class="mt-1 hidden text-xs font-semibold text-red-600" id="nUsernameError" role="alert"></p>
          </div>

          <div>
            <label class="!mt-0" for="nPassword">รหัสผ่าน</label>
            <input id="nPassword" type="password" placeholder="อย่างน้อย 6 ตัวอักษร" autocomplete="new-password">
            <p class="mt-1 text-xs leading-relaxed text-slate-500">กรอกเมื่อสร้างบัญชีใหม่หรือต้องการเปลี่ยนรหัส — ตอนแก้ไข: เว้นว่าง = ใช้รหัสผ่านเดิม</p>
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
      <input type="text" id="nurseSearch" placeholder="🔍 ค้นหาด้วย ชื่อ, สกุล หรือหน่วยงาน..." class="min-w-0 flex-1">
      <select id="nurseDeptFilter" aria-label="กรองรายชื่อตามวอร์ด" class="w-auto min-w-0 shrink-0 whitespace-nowrap"></select>
    </div>
    <p id="nurseFilterNote" class="hidden mb-2 text-xs font-semibold text-teal-700"></p>
    
    <div id="nurseDirectoryTable" class="list"></div>
  </div>
</div>

<!-- Users Tab (Admin only) -->
<div id="tabContentUsers" class="hidden">
  <div class="card">
    <h2>จัดการผู้ใช้ระบบ</h2>
    <p>สร้างบัญชีผู้ใช้สำหรับเข้าสู่ระบบ กำหนดบทบาท Admin หรือ Staff</p>
    
    <form id="userForm">
      <h3 id="userFormTitle">เพิ่มผู้ใช้ใหม่</h3>
      <div class="editor" id="userEditor">
        <div><label for="uUsername">ชื่อผู้ใช้ (Username)</label><input id="uUsername" placeholder="เช่น staff01"></div>
        <div><label for="uPassword">รหัสผ่าน</label><input id="uPassword" type="password" placeholder="รหัสผ่าน (อย่างน้อย 6 ตัวอักษร)"></div>
        <div><label for="uFullName">ชื่อ–นามสกุล</label><input id="uFullName" placeholder="ชื่อ–นามสกุล"></div>
        <div><label for="uRole">บทบาท</label><select id="uRole"><option value="staff">Staff (เจ้าหน้าที่ปฏิบัติงาน)</option><option value="admin">Admin (ผู้ดูแลระบบ)</option></select></div>
        <div><label for="uNurse">พยาบาลที่เชื่อมโยง (ถ้ามี)</label><select id="uNurse"><option value="">-- ไม่เชื่อมโยง --</option></select></div>
        <button type="submit">เพิ่มผู้ใช้</button>
        <button type="button" class="gray">ล้างช่องกรอก</button>
      </div>
    </form>
    
    <div class="search-box">
      <input type="text" id="userSearch" placeholder="🔍 ค้นหาผู้ใช้...">
    </div>
    
    <div id="userDirectoryTable" class="list"></div>
  </div>
</div>