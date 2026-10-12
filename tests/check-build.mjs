import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const html = readFileSync(join(root, 'public', 'index.html'), 'utf8');

let fail = 0;
const bad = (msg) => { fail++; console.log('FAIL ' + msg); };

// 1) No placeholders left
if (html.includes('__IMG_B64__')) bad('placeholder __IMG_B64__ remains');
const imgs = html.match(/src="data:image\/png;base64,/g) || [];
console.log('embedded data URL images: ' + imgs.length);
if (imgs.length !== 5) bad('expected 5 embedded images, got ' + imgs.length);

// 2) Extract inline <script> (the app script)
const m = html.match(/<script>([\s\S]*)<\/script>/);
if (!m) { bad('no inline <script> found'); process.exit(1); }
const script = m[1];

// 3) JS syntax check
try {
  new Function(script);
  console.log('JS syntax: OK');
} catch (e) {
  bad('JS syntax error: ' + e.message);
}

// 4) IDs
const ids = ['authGate','authForm','systemPassword','authError','mainHeader','headerTitle','btnTabDashboard','btnTabCheck','btnTabRestock','btnTabNurses','btnTabReports','btnTabStock','tabContentDashboard','tabContentCheck','tabContentRestock','tabContentNurses','tabContentReports','tabContentStock','loginPage','checkPage','userName','shift','car','loginInfo','itemTable','progressFill','progressText','chipAll','chipPending','chipAttn','cntAll','cntPending','cntAttn','dashStats','dashCars','reportSearch','reportCarChips','restockStatusChips','historyFrom','historyTo','reportCount','nurseEditor','nId','nName','nDept','nPhone','nStart','nEnd','nNote','nActive','nurseSearch','nurseDirectoryTable','reportTable','restockHistoryTable','historyFooter','clearHistoryBtn','stockCar','stockName','stockStandard','stockTable','storageState','sheetOverlay','sheetInput','sheetTitle','sheetOk','sheetCancel','rsCar','rsSourceInfo','rsBy','rsReason','rsNote','rsItems','restockList','restockSheet','rsSheetTitle','rsSheetBody','rsSheetOk','rsSheetCancel','segChecks','segRestock'];
const missIds = ids.filter((id) => !html.includes('id="' + id + '"'));
if (missIds.length) bad('missing IDs: ' + missIds.join(', '));
else console.log('all ' + ids.length + ' IDs present');

// 5) Functions
const fns = ['statusText','api','mergeChanges','persist','mutate','boot','switchTab','renderDashboard','startCheckFromDash','startRestockFromDash','vehiclePlate','selectVehicle','syncVehicleSelection','setupDropdowns','setupRestockDropdowns','login','renderChecklistTable','setCheckFilter','saveDraft','askDetail','changeExpirationRow','setItemStatus','triggerManualValue','clickBatteryUI','checkAll','saveCheck','logout','resetNurseEditor','editNurse','saveNurse','renderNurseDirectory','filterNurseTable','importNurses','renderStock','updateStock','addStock','needsAttention','loadReport','renderRestockHistory','setReportCar','setHistoryView','setRestockStatus','filterHistory','clearData','signOut','onRestockCarChange','loadRestockEditor','renderRestockEditor','toggleRestockItem','setRestockQty','collectRestockItems','mergeRestockItems','saveRestockDraft','submitRestock','resetRestockEditor','editRestockDraft','restockCardHTML','renderRestock','restockAction','closeRestockSheet','confirmRestockAction','addQty','parseExpireDate','expireState','suggestQty','nextRestockNo','restockCheckConflict','activeCheckRef','numeric'];
const missFns = fns.filter((f) => !script.includes('function ' + f + '('));
if (missFns.length) bad('missing functions: ' + missFns.join(', '));
else console.log('all ' + fns.length + ' functions present');

// 6) Original static select options (boot seeding dependency)
const opts = ['รถ Refer (3ชฏ3250)','รถ Refer (ฆง8434)','รถ Refer (5กล1034)'];
const missOpts = opts.filter((o) => !html.includes('value="' + o + '"'));
if (missOpts.length) bad('missing car options: ' + missOpts.join(', '));
else console.log('all 3 car seed options present');

// 7) Checklist config count
const cfg = script.match(/const ambulanceChecklistConfig=\[([\s\S]*?)\];/);
if (!cfg) bad('ambulanceChecklistConfig not found');
else {
  const n = (cfg[1].match(/name:/g) || []).length;
  console.log('checklist config items: ' + n);
  if (n !== 63) bad('expected 63 checklist items, got ' + n);
}

// 8) API actions intact
for (const a of ['/api/login','/api/logout','/api/state']) {
  if (!script.includes(a)) bad('missing API call ' + a);
}
console.log(fail === 0 ? 'BUILD OK' : 'BUILD FAILED (' + fail + ')');
process.exit(fail === 0 ? 0 : 1);
