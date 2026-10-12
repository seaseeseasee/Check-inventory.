<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
function reply(int $code, array $body): never { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit; }
function textValue(mixed $value, int $max=10000): bool { return is_string($value) && strlen($value)<=$max; }
function need(bool $condition): void { if (!$condition) throw new InvalidArgumentException('Invalid data'); }
function readBody(): array {
    $raw=file_get_contents('php://input', false, null, 0, 8388609);
    if ($raw===false || strlen($raw)>8388608) reply(413,['error'=>'Request too large']);
    $body=json_decode($raw,true,64,JSON_THROW_ON_ERROR); need(is_array($body)); return $body;
}
const TABLES=['vehicles','items','nurses','checks','drafts','metadata'];
const RESTOCK_ACTIONS=['create','submit','issue','fill','fulfil','cancel'];
function validateState(array $s): void {
    foreach(TABLES as $t) { need(isset($s[$t]) && is_array($s[$t]) && array_is_list($s[$t]) && count($s[$t])<=100000); $ids=[];
        foreach($s[$t] as $r) { need(is_array($r) && textValue($r['id']??null,100) && $r['id']!==''); need(!isset($ids[$r['id']])); $ids[$r['id']]=true; }
    }
    $cars=array_column($s['vehicles'],null,'id'); $nurses=array_column($s['nurses'],null,'id');
    foreach($s['vehicles'] as $c) need(textValue($c['name']??null,765));
    foreach($s['nurses'] as $n){need(textValue($n['name']??null,765)&&textValue($n['dept']??null,765)&&is_bool($n['active']??null)); foreach(['phone','start','end'] as $k) need(textValue($n[$k]??'',300));need(textValue($n['note']??''));}
    $validItem=function(array $i) { need(textValue($i['name']??null,765)&& (is_string($i['standard']??null)||is_numeric($i['standard']??null))&&strlen((string)$i['standard'])<=300&&textValue($i['expire']??null,300)&&is_bool($i['isBattery']??null)); };
    foreach($s['items'] as $i){$validItem($i);need(isset($cars[$i['carId']??''])&&is_bool($i['active']??null));}
    $draftCars=[];
    foreach(['checks','drafts'] as $t) foreach($s[$t] as $c) {
        need(isset($cars[$c['carId']??''])&&isset($nurses[$c['nurseId']??''])&&textValue($c['car']??null,765)&&textValue($c['userName']??null,765)&&textValue($c['shift']??null,300));
        need(textValue($c['startedAt']??null,40)&&strtotime($c['startedAt'])!==false);
        if($t==='checks') need(textValue($c['completedAt']??null,40)&&strtotime($c['completedAt'])!==false);
        else {need(!isset($draftCars[$c['carId']]));$draftCars[$c['carId']]=true;}
        need(isset($c['results'])&&is_array($c['results'])&&array_is_list($c['results'])&&count($c['results'])>0);$ids=[];
        foreach($c['results'] as $i){need(is_array($i));$validItem($i);need(textValue($i['id']??null,100)&&!isset($ids[$i['id']]));$ids[$i['id']]=true;
            need(in_array($i['status']??null,['ยังไม่ตรวจ','Checked','ขาด','ใช้ไป'],true)&&textValue($i['missing']??null));
            if($t==='checks')need($i['status']!=='ยังไม่ตรวจ');
            if($i['isBattery']&&$i['status']==='Checked')need(is_int($i['batteryLevel']??null)&&$i['batteryLevel']>=0&&$i['batteryLevel']<=4);
            else need(($i['batteryLevel']??null)===null||(is_int($i['batteryLevel'])&&$i['batteryLevel']>=0&&$i['batteryLevel']<=4));
        }
    }
    validateRestocks($s);
}
// v5 restock: key "restocks" is optional so clients from before the upgrade keep working;
// when absent, existing restock rows are preserved untouched by writeState().
function validateRestocks(array $s): void {
    if(!array_key_exists('restocks',$s)) return;
    $rs=$s['restocks'];
    need(is_array($rs)&&array_is_list($rs)&&count($rs)<=100000);
    $cars=array_column($s['vehicles'],null,'id');$nurses=array_column($s['nurses'],null,'id');$ids=[];$activeChecks=[];
    foreach($rs as $r){
        need(is_array($r)&&textValue($r['id']??null,100)&&$r['id']!==''&&!isset($ids[$r['id']]));$ids[$r['id']]=true;
        need(textValue($r['no']??null,100)&&$r['no']!=='');
        need(isset($cars[$r['carId']??''])&&textValue($r['car']??null,765));
        need(($r['checkId']??null)===null||textValue($r['checkId'],100));
        need(isset($nurses[$r['createdBy']??''])&&textValue($r['createdByName']??null,765));
        need(textValue($r['createdAt']??null,40)&&strtotime($r['createdAt'])!==false);
        need(in_array($r['status']??null,['draft','submitted','partial','fulfilled','cancelled'],true));
        if(($r['checkId']??null)!==null&&$r['status']!=='cancelled'){need(!isset($activeChecks[$r['checkId']]));$activeChecks[$r['checkId']]=true;}
        foreach(['reason','note'] as $k)need(textValue($r[$k]??'',2000));
        need(isset($r['items'])&&is_array($r['items'])&&array_is_list($r['items'])&&count($r['items'])>0);
        $itemIds=[];
        foreach($r['items'] as $i){
            need(is_array($i)&&textValue($i['itemId']??null,100)&&$i['itemId']!==''&&!isset($itemIds[$i['itemId']]));$itemIds[$i['itemId']]=true;
            need(textValue($i['name']??null,765)&&(is_string($i['standard']??null)||is_numeric($i['standard']??null))&&strlen((string)$i['standard'])<=300);
            foreach(['actualQty','qtyRequested','qtyIssued','qtyFilled'] as $k)need(textValue($i[$k]??'',100));
        }
        need(isset($r['actions'])&&is_array($r['actions'])&&array_is_list($r['actions'])&&count($r['actions'])>0);
        $last=null;$first=true;
        foreach($r['actions'] as $a){
            need(is_array($a)&&in_array($a['type']??null,RESTOCK_ACTIONS,true));
            need(isset($nurses[$a['by']??''])&&textValue($a['byName']??null,765));
            need(textValue($a['at']??null,40)&&strtotime($a['at'])!==false&&textValue($a['note']??'',2000));
            foreach(($a['lines']??[]) as $l){need(is_array($l)&&textValue($l['itemId']??null,100)&&isset($itemIds[$l['itemId']])&&textValue($l['qty']??'',100));}
            if($first)need($a['type']==='create');$first=false;$last=$a['type'];
        }
        $st=$r['status'];
        if($st==='draft')need($last==='create');
        elseif($st==='submitted')need($last==='submit');
        elseif($st==='partial')need($last==='issue'||$last==='fill');
        elseif($st==='fulfilled')need($last==='fulfil');
        elseif($st==='cancelled'){need($last==='cancel');need(end($r['actions'])['note']!=='');}
    }
}
function restockTablesReady(PDO $db): bool {
    try{$q=$db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('refer_restock_requests','refer_restock_items','refer_restock_actions')");$q->execute();return (int)$q->fetchColumn()===3;}
    catch(Throwable){// MySQL-only query: fall back to sqlite_master for the SQLite test fixture (tests/test-restock.php)
        return (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('refer_restock_requests','refer_restock_items','refer_restock_actions')")->fetchColumn()===3;}
}
function loadState(PDO $db): array {
    $s=array_fill_keys(TABLES,[]);
    foreach($db->query('SELECT * FROM refer_vehicles ORDER BY id') as $r)$s['vehicles'][]=['id'=>$r['id'],'name'=>$r['name']];
    foreach($db->query('SELECT * FROM refer_nurses ORDER BY id') as $r)$s['nurses'][]=['id'=>$r['id'],'name'=>$r['name'],'dept'=>$r['department'],'phone'=>$r['phone'],'active'=>(bool)$r['active'],'start'=>$r['start_text'],'end'=>$r['end_text'],'note'=>$r['note']];
    foreach($db->query('SELECT * FROM refer_items ORDER BY car_id,id') as $r)$s['items'][]=['id'=>$r['id'],'carId'=>$r['car_id'],'name'=>$r['name'],'standard'=>$r['standard'],'expire'=>$r['expiry_text'],'isBattery'=>(bool)$r['is_battery'],'active'=>(bool)$r['active']];
    foreach(['checks','drafts'] as $t){$children=[];$fk=$t==='checks'?'check_id':'draft_id';$child=$t==='checks'?'refer_check_items':'refer_draft_items';
        foreach($db->query("SELECT * FROM $child ORDER BY $fk,sort_order") as $r)$children[$r[$fk]][]=['id'=>$r['item_id'],'name'=>$r['name'],'standard'=>$r['standard'],'expire'=>$r['expiry_text'],'isBattery'=>(bool)$r['is_battery'],'status'=>$r['status'],'missing'=>$r['detail'],'batteryLevel'=>$r['battery_level']===null?null:(int)$r['battery_level']];
        foreach($db->query("SELECT * FROM refer_$t ORDER BY started_at,id") as $r){$c=['id'=>$r['id'],'carId'=>$r['car_id'],'car'=>$r['car_name'],'nurseId'=>$r['nurse_id'],'userId'=>$r['nurse_id'],'userName'=>$r['nurse_name'],'shift'=>$r['shift_name'],'startedAt'=>$r['started_at'],'results'=>$children[$r['id']]??[]];if($t==='checks')$c['completedAt']=$r['completed_at'];$s[$t][]=$c;}
    }
    foreach($db->query('SELECT payload FROM refer_metadata ORDER BY id') as $r)$s['metadata'][]=json_decode($r['payload'],true,64,JSON_THROW_ON_ERROR);
    $s['restocks']=restockTablesReady($db)?loadRestocks($db):[];
    return $s;
}
function loadRestocks(PDO $db): array {
    $out=[];$items=[];$actions=[];
    foreach($db->query('SELECT * FROM refer_restock_items ORDER BY request_id,sort_order') as $r)$items[$r['request_id']][]=['itemId'=>$r['item_id'],'name'=>$r['name'],'standard'=>$r['standard'],'actualQty'=>$r['actual_qty'],'qtyRequested'=>$r['qty_requested'],'qtyIssued'=>$r['qty_issued'],'qtyFilled'=>$r['qty_filled']];
    foreach($db->query('SELECT * FROM refer_restock_actions ORDER BY request_id,seq') as $r)$actions[$r['request_id']][]=['type'=>$r['type'],'at'=>$r['at'],'by'=>$r['by_id'],'byName'=>$r['by_name'],'note'=>$r['note'],'lines'=>json_decode($r['lines'],true,64,JSON_THROW_ON_ERROR)];
    foreach($db->query('SELECT * FROM refer_restock_requests ORDER BY created_at,id') as $r)$out[]=['id'=>$r['id'],'no'=>$r['no'],'carId'=>$r['car_id'],'car'=>$r['car_name'],'checkId'=>$r['check_id'],'createdBy'=>$r['created_by'],'createdByName'=>$r['created_name'],'createdAt'=>$r['created_at'],'reason'=>$r['reason'],'note'=>$r['note'],'status'=>$r['status'],'items'=>$items[$r['id']]??[],'actions'=>$actions[$r['id']]??[]];
    return $out;
}
function writeState(PDO $db,array $s):void {
    // Only called inside a transaction after locking and checking refer_revision.
    foreach(['refer_draft_items','refer_check_items','refer_drafts','refer_checks','refer_items','refer_nurses','refer_vehicles','refer_metadata'] as $t)$db->exec("DELETE FROM $t");
    $q=$db->prepare('INSERT INTO refer_vehicles(id,name) VALUES(?,?)');foreach($s['vehicles'] as $r)$q->execute([$r['id'],$r['name']]);
    $q=$db->prepare('INSERT INTO refer_nurses(id,name,department,phone,active,start_text,end_text,note) VALUES(?,?,?,?,?,?,?,?)');foreach($s['nurses'] as $r)$q->execute([$r['id'],$r['name'],$r['dept'],$r['phone']??'',(int)$r['active'],$r['start']??'',$r['end']??'',$r['note']??'']);
    $q=$db->prepare('INSERT INTO refer_items(id,car_id,name,standard,expiry_text,is_battery,active) VALUES(?,?,?,?,?,?,?)');foreach($s['items'] as $r)$q->execute([$r['id'],$r['carId'],$r['name'],(string)$r['standard'],$r['expire'],(int)$r['isBattery'],(int)$r['active']]);
    foreach(['checks','drafts'] as $t){$complete=$t==='checks';$extra=$complete?',completed_at':'';$marks=$complete?',?':'';
        $q=$db->prepare("INSERT INTO refer_$t(id,car_id,nurse_id,car_name,nurse_name,shift_name,started_at$extra) VALUES(?,?,?,?,?,?,?$marks)");
        $child=$complete?'refer_check_items':'refer_draft_items';$fk=$complete?'check_id':'draft_id';$qi=$db->prepare("INSERT INTO $child($fk,item_id,sort_order,name,standard,expiry_text,is_battery,status,detail,battery_level) VALUES(?,?,?,?,?,?,?,?,?,?)");
        foreach($s[$t] as $r){$values=[$r['id'],$r['carId'],$r['nurseId'],$r['car'],$r['userName'],$r['shift'],$r['startedAt']];if($complete)$values[]=$r['completedAt'];$q->execute($values);
            foreach($r['results'] as $k=>$i)$qi->execute([$r['id'],$i['id'],$k,$i['name'],(string)$i['standard'],$i['expire'],(int)$i['isBattery'],$i['status'],$i['missing'],$i['batteryLevel']??null]);
        }
    }
    $q=$db->prepare('INSERT INTO refer_metadata(id,payload) VALUES(?,?)');foreach($s['metadata'] as $r)$q->execute([$r['id'],json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    if(array_key_exists('restocks',$s)){
        if(!restockTablesReady($db)){if($s['restocks'])throw new RuntimeException('Restock tables missing; run migrations/001-add-restock.sql first');return;}
        writeRestocks($db,$s['restocks']);
    }
}
function writeRestocks(PDO $db,array $rs): void {
    foreach(['refer_restock_actions','refer_restock_items','refer_restock_requests'] as $t)$db->exec("DELETE FROM $t");
    $q=$db->prepare('INSERT INTO refer_restock_requests(id,no,car_id,car_name,check_id,created_by,created_name,reason,note,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $qi=$db->prepare('INSERT INTO refer_restock_items(request_id,item_id,sort_order,name,standard,actual_qty,qty_requested,qty_issued,qty_filled) VALUES(?,?,?,?,?,?,?,?,?)');
    $qa=$db->prepare('INSERT INTO refer_restock_actions(request_id,seq,type,at,by_id,by_name,note,lines) VALUES(?,?,?,?,?,?,?,?)');
    foreach($rs as $r){
        $q->execute([$r['id'],$r['no'],$r['carId'],$r['car'],$r['checkId']??null,$r['createdBy'],$r['createdByName'],$r['reason']??'',$r['note']??'',$r['status'],$r['createdAt']]);
        foreach($r['items'] as $k=>$i)$qi->execute([$r['id'],$i['itemId'],$k,$i['name'],(string)$i['standard'],$i['actualQty']??'',$i['qtyRequested']??'',$i['qtyIssued']??'',$i['qtyFilled']??'']);
        foreach($r['actions'] as $k=>$a)$qa->execute([$r['id'],$k,$a['type'],$a['at'],$a['by'],$a['byName'],$a['note']??'',json_encode($a['lines']??[],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
}
// Validation can be tested locally without opening a connection or loading secrets.
if(defined('REFER_TEST_MODE')) return;
$db=null;
try {
    $configPath=getenv('REFER_CONFIG')?:dirname(__DIR__).'/config.php';
    if(!is_file($configPath))reply(503,['error'=>'Server configuration required']);
    $cfg=require $configPath;
    need(is_array($cfg)&&!empty($cfg['password_hash'])&&!empty($cfg['origin']));
    $origin=$cfg['origin'];$host=parse_url($origin,PHP_URL_HOST);$secure=parse_url($origin,PHP_URL_SCHEME)==='https';
    if(!$secure&&!in_array($host,['localhost','127.0.0.1','[::1]'],true))reply(503,['error'=>'HTTPS required']);
    $method=$_SERVER['REQUEST_METHOD'];$action=$_GET['action']??'';
    if($method!=='GET'&&($_SERVER['HTTP_ORIGIN']??'')!==$origin)reply(403,['error'=>'Origin rejected']);
    ini_set('session.use_strict_mode','1');session_name('refer_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);session_start();
    $db=new PDO('mysql:host='.$cfg['db_host'].';port='.(int)$cfg['db_port'].';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($action==='login'&&$method==='POST'){
        $b=readBody();$ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'');$now=time();
        $db->beginTransaction();$q=$db->prepare('INSERT IGNORE INTO refer_login_attempts VALUES(?,0,?)');$q->execute([$ip,$now]);$q=$db->prepare('SELECT * FROM refer_login_attempts WHERE ip_hash=? FOR UPDATE');$q->execute([$ip]);$a=$q->fetch();
        if((int)$a['window_start']<$now-60){$a['attempts']=0;$a['window_start']=$now;}
        if((int)$a['attempts']>=8){$db->commit();reply(429,['error'=>'Try again later']);}
        $q=$db->prepare('UPDATE refer_login_attempts SET attempts=?,window_start=? WHERE ip_hash=?');$q->execute([(int)$a['attempts']+1,$a['window_start'],$ip]);$db->commit();
        if(!textValue($b['password']??null,1024)||!password_verify($b['password'],$cfg['password_hash']))reply(401,['error'=>'Invalid credentials']);
        session_regenerate_id(true);$_SESSION['until']=time()+8*3600;reply(200,['ok'=>true]);
    }
    if(($_SESSION['until']??0)<time())reply(401,['error'=>'Login required']);
    if($action==='logout'&&$method==='POST'){$_SESSION=[];session_destroy();reply(200,['ok'=>true]);}
    session_write_close();
    if($action==='state'&&$method==='GET'){
        $db->beginTransaction();$rev=(int)$db->query('SELECT revision FROM refer_revision WHERE id=1 FOR UPDATE')->fetchColumn();$data=loadState($db);$db->commit();reply(200,['revision'=>$rev,'data'=>$data]);
    }
    if($action==='state'&&$method==='PUT'){
        $b=readBody();need(is_int($b['revision']??null)&&is_array($b['data']??null));validateState($b['data']);
        $db->beginTransaction();$rev=(int)$db->query('SELECT revision FROM refer_revision WHERE id=1 FOR UPDATE')->fetchColumn();
        if($b['revision']!==$rev){$db->rollBack();reply(409,['error'=>'Revision conflict']);}
        writeState($db,$b['data']);$db->exec('UPDATE refer_revision SET revision=revision+1 WHERE id=1');$db->commit();reply(200,['revision'=>$rev+1]);
    }
    reply(404,['error'=>'Not found']);
}catch(InvalidArgumentException|JsonException $e){if($db instanceof PDO&&$db->inTransaction())$db->rollBack();reply(400,['error'=>'Invalid data; nothing saved']);}
catch(Throwable $e){if($db instanceof PDO&&$db->inTransaction())$db->rollBack();reply(503,['error'=>'Server unavailable; nothing saved']);}
