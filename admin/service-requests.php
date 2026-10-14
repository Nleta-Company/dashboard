<?php
/* =========================================================
   NLETA MAINTENANCE MANAGEMENT SYSTEM
   ADMIN — SERVICE REQUESTS

   UPDATED:
   - Admin Accept -> Owner email
   - Admin Reject -> Owner email
   - Owner email is read automatically from users.email
   - Accepted status remains "Accepted" for Phase 5
   - Admin login/review workflow preserved
   - Edit and permanent delete preserved
   - IST / Asia-Kolkata
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_role('admin');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

/* =========================================================
   MAILER
========================================================= */
$mailerFile = __DIR__ . '/../config/nleta-mailer.php';
if (file_exists($mailerFile)) {
    require_once $mailerFile;
}

/* =========================================================
   SAFE HELPERS
========================================================= */
function nleta_sr_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nleta_sr_ist($value): string {
    if (!$value) return '-';
    try {
        $dt = new DateTime((string)$value, new DateTimeZone('Asia/Kolkata'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format('d-m-Y h:i A');
    } catch (Throwable $e) {
        return (string)$value;
    }
}


/* =========================================================
   MULTI-SERVICE HELPERS
   ---------------------------------------------------------
   New Phase-4 workflow stores all selected services in
   service_request_services(request_id, service_id).
   Older requests still use service_requests.service_id.
========================================================= */
function sr_get_request_services(mysqli $conn, int $requestId, int $fallbackServiceId = 0): array {
    $services = [];

    /* New multi-service relation table */
    $tableExists = false;
    $chk = $conn->query("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'service_request_services'
        LIMIT 1
    ");
    if ($chk) {
        $tableExists = $chk->num_rows > 0;
        $chk->free();
    }

    if ($tableExists) {
        $st = $conn->prepare("
            SELECT sc.id, sc.service_code, sc.service_name, sc.description
            FROM service_request_services srs
            INNER JOIN service_catalog sc ON sc.id = srs.service_id
            WHERE srs.request_id = ?
            ORDER BY srs.id ASC, sc.id ASC
        ");
        if ($st) {
            $st->bind_param('i', $requestId);
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) {
                $services[] = $r;
            }
            $st->close();
        }
    }

    /* Backward compatibility for old single-service requests */
    if (!$services && $fallbackServiceId > 0) {
        $st = $conn->prepare("
            SELECT id, service_code, service_name, description
            FROM service_catalog
            WHERE id = ?
            LIMIT 1
        ");
        if ($st) {
            $st->bind_param('i', $fallbackServiceId);
            $st->execute();
            $r = $st->get_result()->fetch_assoc();
            $st->close();
            if ($r) $services[] = $r;
        }
    }

    return $services;
}

function sr_service_names(array $services): string {
    $names = [];
    foreach ($services as $service) {
        $name = trim((string)($service['service_name'] ?? ''));
        $code = trim((string)($service['service_code'] ?? ''));
        if ($name === '' && $code === '') continue;
        $names[] = $code !== '' ? ($code . ' - ' . $name) : $name;
    }
    return implode(', ', $names);
}

function sr_service_list_html(array $services): string {
    if (!$services) return '<span class="text-muted">No service selected</span>';

    $html = '<ol style="margin:0;padding-left:20px;">';
    foreach ($services as $service) {
        $code = nleta_sr_h($service['service_code'] ?? '');
        $name = nleta_sr_h($service['service_name'] ?? '');
        $html .= '<li style="margin-bottom:5px;"><strong>' . $code . '</strong> - ' . $name . '</li>';
    }
    $html .= '</ol>';
    return $html;
}

function sr_service_ids(array $services): array {
    $ids = [];
    foreach ($services as $service) {
        $id = (int)($service['id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

/* =========================================================
   OWNER DECISION EMAIL
   This function intentionally lives here as a safe fallback,
   so Accept/Reject works even if the mailer file only contains
   the Admin notification function.
========================================================= */
function nletaSendOwnerDecisionEmail(
    string $ownerEmail,
    string $ownerName,
    string $requestNo,
    string $serviceName,
    int $assetCount,
    string $decision,
    string $reason = ''
): bool {
    if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('NLETA: invalid owner email for request '.$requestNo);
        return false;
    }

    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        error_log('NLETA: PHPMailer class not available.');
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = defined('NLETA_SMTP_HOST') ? NLETA_SMTP_HOST : 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = defined('NLETA_SMTP_USERNAME') ? NLETA_SMTP_USERNAME : '';
        $mail->Password = defined('NLETA_SMTP_PASSWORD') ? NLETA_SMTP_PASSWORD : '';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = defined('NLETA_SMTP_PORT') ? NLETA_SMTP_PORT : 587;
        $mail->CharSet = 'UTF-8';

        $fromEmail = defined('NLETA_FROM_EMAIL') ? NLETA_FROM_EMAIL : $mail->Username;
        $fromName  = defined('NLETA_FROM_NAME') ? NLETA_FROM_NAME : 'NLETA Maintenance System';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($ownerEmail, $ownerName);

        $loginUrl = 'http://localhost/dashboard_original/dashboard_original/login.php';

        $safeName = nleta_sr_h($ownerName);
        $safeReq = nleta_sr_h($requestNo);
        $safeService = nleta_sr_h($serviceName);
        $safeReason = nleta_sr_h($reason);

        if ($decision === 'accepted') {
            $subject = 'NLETA - Service Request Accepted - '.$requestNo;
            $title = 'Service Request Accepted';
            $intro = 'Your Service Request has been accepted by NLETA Admin.';
            $status = 'Accepted';
            $statusColor = '#198754';
            $extra = '
                <div style="margin-top:22px;padding:16px;background:#fff8e1;border:1px solid #ffe69c;border-radius:7px;line-height:22px;">
                    <strong>Offer Accepted — PSGA Generation Soon</strong><br><br>
                    Your Service Request has been accepted.
                    You will receive further
                    notification when the offer is ready.
                </div>';
        } else {
            $subject = 'NLETA - Service Request Rejected - '.$requestNo;
            $title = 'Service Request Rejected';
            $intro = 'Your Service Request has been rejected by NLETA Admin.';
            $status = 'Rejected';
            $statusColor = '#dc3545';
            $extra = '
                <div style="margin-top:22px;padding:16px;background:#fff5f5;border:1px solid #f5c2c7;border-radius:7px;line-height:22px;">
                    <strong>Rejection Reason</strong><br><br>'.
                    ($safeReason !== '' ? $safeReason : 'No rejection reason was provided.')
                .'</div>';
        }

        $mail->Subject = $subject;
        $mail->isHTML(true);

        $mail->Body = '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:30px 10px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border:1px solid #e1e5ea;border-radius:10px;overflow:hidden;">
<tr><td style="background:'.$statusColor.';color:#fff;padding:24px;text-align:center;">
<div style="font-size:25px;font-weight:bold;">NLETA</div>
<div style="margin-top:6px;font-size:14px;">Maintenance Management System</div>
</td></tr>
<tr><td style="padding:30px;color:#222;">
<h2 style="margin:0 0 18px;color:'.$statusColor.';">'.$title.'</h2>
<p style="font-size:15px;line-height:24px;">Dear <strong>'.$safeName.'</strong>,</p>
<p style="font-size:15px;line-height:24px;">'.$intro.'</p>

<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:20px;">
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Request No.</td>
<td style="padding:10px;border:1px solid #e5e7eb;">'.$safeReq.'</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Service</td>
<td style="padding:10px;border:1px solid #e5e7eb;">'.$safeService.'</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Selected Assets</td>
<td style="padding:10px;border:1px solid #e5e7eb;">'.(int)$assetCount.'</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Status</td>
<td style="padding:10px;border:1px solid #e5e7eb;color:'.$statusColor.';font-weight:bold;">'.$status.'</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Action Date</td>
<td style="padding:10px;border:1px solid #e5e7eb;">'.nleta_sr_ist(date('Y-m-d H:i:s')).' IST</td></tr>
</table>

'.$extra.'

<div style="text-align:center;margin-top:28px;">
<a href="'.nleta_sr_h($loginUrl).'" style="display:inline-block;background:#0d6efd;color:#fff;text-decoration:none;padding:13px 25px;border-radius:6px;font-weight:bold;">
Login to Owner Dashboard
</a>
</div>

</td></tr>
<tr><td style="background:#f4f6f9;padding:16px;text-align:center;font-size:12px;color:#777;">
This is an automated email from the NLETA Maintenance Management System.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';

        $mail->AltBody =
            "Dear ".$ownerName.",\n\n".
            $intro."\n\n".
            "Request No: ".$requestNo."\n".
            "Service: ".$serviceName."\n".
            "Selected Assets: ".$assetCount."\n".
            "Status: ".$status."\n".
            "Date: ".nleta_sr_ist(date('Y-m-d H:i:s'))." IST\n\n".
            ($reason !== '' ? "Reason: ".$reason."\n\n" : '').
            "Login: ".$loginUrl;

        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('NLETA Owner Decision Email Error: '.$e->getMessage());
        return false;
    }
}

/* =========================================================
   HARD DELETE HELPERS
========================================================= */
function sr_q(string $name): string {
    return '`'.str_replace('`','``',$name).'`';
}

function sr_db_name(mysqli $conn): string {
    $r = $conn->query("SELECT DATABASE() AS db_name");
    if (!$r) throw new Exception('Unable to determine active database.');
    $row = $r->fetch_assoc();
    $db = trim((string)($row['db_name'] ?? ''));
    if ($db === '') throw new Exception('No active database selected.');
    return $db;
}

function sr_primary_keys(mysqli $conn, string $db, string $table): array {
    $st = $conn->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=?
          AND TABLE_NAME=?
          AND CONSTRAINT_NAME='PRIMARY'
        ORDER BY ORDINAL_POSITION
    ");
    if (!$st) throw new Exception('Unable to inspect primary key for '.$table.'.');
    $st->bind_param('ss',$db,$table);
    $st->execute();
    $res=$st->get_result();
    $out=[];
    while($r=$res->fetch_assoc()) $out[]=$r['COLUMN_NAME'];
    $st->close();
    return $out;
}

function sr_children(mysqli $conn,string $db,string $parentTable,string $parentColumn):array {
    $st=$conn->prepare("
        SELECT TABLE_NAME AS child_table,COLUMN_NAME AS child_column
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=?
          AND REFERENCED_TABLE_SCHEMA=?
          AND REFERENCED_TABLE_NAME=?
          AND REFERENCED_COLUMN_NAME=?
          AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY TABLE_NAME,ORDINAL_POSITION
    ");
    if(!$st) throw new Exception('Unable to inspect foreign keys.');
    $st->bind_param('ssss',$db,$db,$parentTable,$parentColumn);
    $st->execute();
    $res=$st->get_result();
    $out=[];
    while($r=$res->fetch_assoc()) $out[]=$r;
    $st->close();
    return $out;
}

function sr_delete_row(mysqli $conn,string $table,array $pk,array $row):void {
    if(!$pk) throw new Exception('Table '.$table.' has no PRIMARY KEY. Delete stopped safely.');
    $where=[];$types='';$vals=[];
    foreach($pk as $c){
        if(!array_key_exists($c,$row)) throw new Exception('Missing primary key value for '.$table.'.'.$c);
        $where[]=sr_q($c).'=?';
        if(is_int($row[$c])){$types.='i';$vals[]=$row[$c];}
        else{$types.='s';$vals[]=(string)$row[$c];}
    }
    $st=$conn->prepare("DELETE FROM ".sr_q($table)." WHERE ".implode(' AND ',$where)." LIMIT 1");
    if(!$st) throw new Exception('Unable to delete from '.$table.'.');
    $st->bind_param($types,...$vals);
    $st->execute();
    $st->close();
}

function sr_delete_desc(mysqli $conn,string $db,string $parentTable,string $parentColumn,$parentValue,array &$visited):void {
    foreach(sr_children($conn,$db,$parentTable,$parentColumn) as $fk){
        $childTable=$fk['child_table'];
        $childColumn=$fk['child_column'];
        if($childTable===$parentTable && $childColumn===$parentColumn) continue;

        $pk=sr_primary_keys($conn,$db,$childTable);
        if(!$pk) throw new Exception('Table '.$childTable.' references '.$parentTable.' but has no PRIMARY KEY.');

        $st=$conn->prepare("SELECT * FROM ".sr_q($childTable)." WHERE ".sr_q($childColumn)."=?");
        if(!$st) throw new Exception('Unable to read dependent rows from '.$childTable.'.');

        if(is_int($parentValue)) $st->bind_param('i',$parentValue);
        else {$v=(string)$parentValue;$st->bind_param('s',$v);}
        $st->execute();
        $res=$st->get_result();
        $rows=[];
        while($r=$res->fetch_assoc()) $rows[]=$r;
        $st->close();

        foreach($rows as $row){
            $key=$childTable.'|'.implode('|',array_map(fn($c)=>(string)($row[$c]??''),$pk));
            if(isset($visited[$key])) continue;
            $visited[$key]=true;

            /* Follow only FKs pointing into this child table. */
            $st2=$conn->prepare("
                SELECT COLUMN_NAME AS child_column,REFERENCED_COLUMN_NAME AS referenced_column
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA=?
                  AND REFERENCED_TABLE_SCHEMA=?
                  AND TABLE_NAME=?
                  AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            if(!$st2) throw new Exception('Unable to inspect child relationships.');
            $st2->bind_param('sss',$db,$db,$childTable);
            $st2->execute();
            $r2=$st2->get_result();
            $refs=[];
            while($x=$r2->fetch_assoc()) $refs[]=$x;
            $st2->close();

            foreach($refs as $ref){
                $rc=$ref['referenced_column'];
                if(array_key_exists($rc,$row) && $row[$rc]!==null){
                    sr_delete_desc($conn,$db,$childTable,$rc,$row[$rc],$visited);
                }
            }
            sr_delete_row($conn,$childTable,$pk,$row);
        }
    }
}

function sr_hard_delete(mysqli $conn,int $requestId):void {
    $db=sr_db_name($conn);
    $visited=[];
    sr_delete_desc($conn,$db,'service_requests','id',$requestId,$visited);

    $st=$conn->prepare("DELETE FROM service_requests WHERE id=? LIMIT 1");
    if(!$st) throw new Exception('Unable to prepare Service Request deletion.');
    $st->bind_param('i',$requestId);
    $st->execute();
    if($st->affected_rows<=0){$st->close();throw new Exception('Service Request could not be deleted.');}
    $st->close();
}

/* =========================================================
   LIVE OFFER STATUS / DISPLAY STATUS
   ---------------------------------------------------------
   service_requests.status stores the Phase-4 request status.
   Once an offer exists, the Owner's Offer decision is stored
   in nleta_offer_master.status. This helper makes the Admin
   Service Request page show the LIVE workflow status without
   changing the existing Lift/Asset workflow or requiring a
   schema/ENUM change in service_requests.status.

   Mapping:
   - no offer                 -> Submitted / Accepted / Rejected
   - offer Draft/Generated   -> Offer Generated
   - offer Sent/Pending      -> Pending (Owner Action)
   - offer Accepted          -> Offer Accepted
   - offer Rejected          -> Offer Rejected
========================================================= */
function sr_get_live_offer_status(mysqli $conn, int $requestId, string $requestStatus): string {
    $requestStatus = trim($requestStatus);

    $tableExists = false;
    $chk = $conn->query("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'nleta_offer_master'
        LIMIT 1
    ");
    if ($chk) {
        $tableExists = $chk->num_rows > 0;
        $chk->free();
    }

    if (!$tableExists) {
        return $requestStatus;
    }

    $st = $conn->prepare("
        SELECT status
        FROM nleta_offer_master
        WHERE request_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    if (!$st) {
        return $requestStatus;
    }

    $st->bind_param('i', $requestId);
    $st->execute();
    $offer = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$offer) {
        return $requestStatus;
    }

    $offerStatus = strtolower(trim((string)($offer['status'] ?? '')));

    if (in_array($offerStatus, ['accepted', 'offer accepted'], true)) {
        return 'Offer Accepted';
    }

    if (in_array($offerStatus, ['rejected', 'offer rejected'], true)) {
        return 'Offer Rejected';
    }

    if (in_array($offerStatus, ['sent', 'pending', 'offer sent', 'pending owner', 'pending owner acceptance'], true)) {
        return 'Pending';
    }

    if (in_array($offerStatus, ['generated', 'offer generated', 'draft', 'created'], true)) {
        return 'Offer Generated';
    }

    /* Unknown offer status: still indicate that an offer exists. */
    return 'Offer Generated';
}

function sr_live_status_class(string $status): string {
    return match ($status) {
        'Submitted'       => 'bg-warning text-dark',
        'Accepted'        => 'bg-success',
        'Rejected'        => 'bg-danger',
        'Offer Generated' => 'bg-info text-dark',
        'Pending'         => 'bg-warning text-dark',
        'Offer Accepted'  => 'bg-success',
        'Offer Rejected'  => 'bg-danger',
        default           => 'bg-secondary'
    };
}

function sr_status_filter_match(string $liveStatus, string $filter): bool {
    if ($filter === '') return true;

    /*
     * "Accepted" remains the Phase-4 Admin acceptance status.
     * "Offer Accepted" is Owner acceptance of the generated offer.
     * "Rejected" is Admin rejection.
     * "Offer Rejected" is Owner rejection of the generated offer.
     */
    return $liveStatus === $filter;
}

/* =========================================================
   REQUEST VARIABLES
========================================================= */

$requestId=(int)($_GET['id']??0);
$statusFilter=trim((string)($_GET['status']??''));
$successMessage='';
$errorMessage='';

/* =========================================================
   POST ACTIONS
========================================================= */
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=trim((string)($_POST['action']??''));
    $postId=(int)($_POST['request_id']??0);

    /* DELETE */
    if($action==='delete' && $postId>0){
        $chk=$conn->prepare("SELECT id,request_no FROM service_requests WHERE id=? LIMIT 1");
        if($chk){
            $chk->bind_param('i',$postId);
            $chk->execute();
            $row=$chk->get_result()->fetch_assoc();
            $chk->close();

            if(!$row){
                $errorMessage='Service Request not found.';
            }else{
                $conn->begin_transaction();
                try{
                    sr_hard_delete($conn,$postId);
                    $conn->commit();
                    $successMessage='Service Request '.$row['request_no'].' and all linked workflow records were permanently deleted.';
                    $requestId=0;
                }catch(Throwable $e){
                    $conn->rollback();
                    $errorMessage='Unable to permanently delete Service Request: '.$e->getMessage();
                }
            }
        }
    }

    /* EDIT */
    elseif($action==='edit' && $postId>0){
        $postedServiceIds = $_POST['service_ids'] ?? [];
        if (!is_array($postedServiceIds)) $postedServiceIds = [];
        $postedServiceIds = array_values(array_unique(array_filter(
            array_map('intval', $postedServiceIds),
            fn($v) => $v > 0
        )));

        /* Backward compatibility with the old single-service edit form. */
        $legacyServiceId = (int)($_POST['service_id'] ?? 0);
        if (!$postedServiceIds && $legacyServiceId > 0) {
            $postedServiceIds = [$legacyServiceId];
        }

        $serviceId = (int)($postedServiceIds[0] ?? 0);

        $requirements=trim((string)($_POST['requirements']??''));
        $assetIds=$_POST['asset_ids']??[];
        if(!is_array($assetIds)) $assetIds=[];
        $assetIds=array_values(array_unique(array_filter(array_map('intval',$assetIds),fn($v)=>$v>0)));

        if(!$postedServiceIds) $errorMessage='Please select at least one service.';
        elseif(!$assetIds) $errorMessage='Please select at least one asset.';
        else{
            $st=$conn->prepare("SELECT owner_id,status FROM service_requests WHERE id=? LIMIT 1");
            $st->bind_param('i',$postId);$st->execute();$req=$st->get_result()->fetch_assoc();$st->close();

            if(!$req) $errorMessage='Service Request not found.';
            elseif(!in_array($req['status'],['Submitted','Rejected'],true)) $errorMessage='Only Submitted or Rejected requests can be edited.';
            else{
                $servicePh = implode(',', array_fill(0, count($postedServiceIds), '?'));
                $serviceTypes = str_repeat('i', count($postedServiceIds));
                $serviceSt = $conn->prepare("SELECT id FROM service_catalog WHERE is_active=1 AND id IN($servicePh)");
                if (!$serviceSt) {
                    $errorMessage='Unable to validate selected services.';
                } else {
                    $serviceSt->bind_param($serviceTypes, ...$postedServiceIds);
                    $serviceSt->execute();
                    $serviceRes=$serviceSt->get_result();
                    $validServiceIds=[];
                    while($r=$serviceRes->fetch_assoc()) $validServiceIds[]=(int)$r['id'];
                    $serviceSt->close();

                    if(count($validServiceIds)!==count($postedServiceIds)) {
                        $errorMessage='One or more selected services are invalid or inactive.';
                    }
                }

                if ($errorMessage !== '') {
                    /* Stop edit safely. */
                } else {
                $ph=implode(',',array_fill(0,count($assetIds),'?'));
                $types='i'.str_repeat('i',count($assetIds));
                $vals=array_merge([(int)$req['owner_id']],$assetIds);
                $vs=$conn->prepare("SELECT id FROM assets WHERE owner_id=? AND id IN($ph)");
                if(!$vs) $errorMessage='Unable to validate selected assets.';
                else{
                    $vs->bind_param($types,...$vals);$vs->execute();$rr=$vs->get_result();
                    $valid=[];while($r=$rr->fetch_assoc())$valid[]=(int)$r['id'];$vs->close();

                    if(count($valid)!==count($assetIds)) $errorMessage='One or more selected assets do not belong to this owner.';
                    else{
                        $conn->begin_transaction();
                        try{
                            $u=$conn->prepare("UPDATE service_requests SET service_id=?,requirements=? WHERE id=? LIMIT 1");
                            $u->bind_param('isi',$serviceId,$requirements,$postId);$u->execute();$u->close();

                            /* Keep all selected services in the multi-service relation table. */
                            $conn->query("
                                CREATE TABLE IF NOT EXISTS service_request_services (
                                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                    request_id INT NOT NULL,
                                    service_id INT NOT NULL,
                                    created_at DATETIME NOT NULL,
                                    PRIMARY KEY (id),
                                    UNIQUE KEY uq_request_service (request_id, service_id),
                                    KEY idx_request_id (request_id),
                                    KEY idx_service_id (service_id)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                            ");

                            $dss=$conn->prepare("DELETE FROM service_request_services WHERE request_id=?");
                            if ($dss) {
                                $dss->bind_param('i',$postId);
                                $dss->execute();
                                $dss->close();
                            }

                            $iss=$conn->prepare("INSERT INTO service_request_services(request_id,service_id,created_at) VALUES(?,?,NOW())");
                            if (!$iss) throw new Exception('Unable to save selected services.');
                            foreach($postedServiceIds as $sid){
                                $iss->bind_param('ii',$postId,$sid);
                                $iss->execute();
                            }
                            $iss->close();

                            $d=$conn->prepare("DELETE FROM service_request_assets WHERE request_id=?");
                            $d->bind_param('i',$postId);$d->execute();$d->close();

                            $ins=$conn->prepare("INSERT INTO service_request_assets(request_id,asset_id) VALUES(?,?)");
                            foreach($valid as $aid){$ins->bind_param('ii',$postId,$aid);$ins->execute();}
                            $ins->close();

                            if($req['status']==='Rejected'){
                                $s=$conn->prepare("UPDATE service_requests SET status='Submitted' WHERE id=? LIMIT 1");
                                $s->bind_param('i',$postId);$s->execute();$s->close();
                            }

                            $conn->commit();
                            $successMessage='Service Request updated successfully.';
                            $requestId=$postId;
                        }catch(Throwable $e){
                            $conn->rollback();
                            $errorMessage='Unable to update Service Request: '.$e->getMessage();
                        }
                    }
                }
                }
            }
        }
    }

    /* ACCEPT */
    elseif($action==='accept' && $postId>0){
        $st=$conn->prepare("
            SELECT sr.id,sr.request_no,sr.status,sr.service_id,
                   u.name AS owner_name,u.email AS owner_email,
                   (SELECT COUNT(*) FROM service_request_assets x WHERE x.request_id=sr.id) AS asset_count
            FROM service_requests sr
            LEFT JOIN users u ON u.id=sr.owner_id
            WHERE sr.id=? LIMIT 1
        ");
        if($st){
            $st->bind_param('i',$postId);$st->execute();$data=$st->get_result()->fetch_assoc();$st->close();

            if(!$data) $errorMessage='Service Request not found.';
            elseif($data['status']!=='Submitted') $errorMessage='Only Submitted service requests can be accepted.';
            else{
                $decisionServices = sr_get_request_services($conn, $postId, (int)$data['service_id']);
                $decisionServiceName = sr_service_names($decisionServices);
                $u=$conn->prepare("UPDATE service_requests SET status='Accepted' WHERE id=? AND status='Submitted' LIMIT 1");
                $u->bind_param('i',$postId);
                if($u->execute() && $u->affected_rows>0){
                    $mailSent=nletaSendOwnerDecisionEmail(
                        trim((string)$data['owner_email']),
                        trim((string)($data['owner_name']?:'Owner')),
                        trim((string)$data['request_no']),
                        $decisionServiceName,
                        (int)$data['asset_count'],
                        'accepted'
                    );

                    $successMessage='Service Request accepted successfully. Offer Generator is now unlocked.';
                    if(!$mailSent) $successMessage.=' Owner email could not be sent; check your SMTP/App Password and PHP error log.';
                    else $successMessage.=' Acceptance email sent to Owner.';
                    $requestId=$postId;
                }else $errorMessage='Unable to accept the Service Request.';
                $u->close();
            }
        }
    }

    /* REJECT */
    elseif($action==='reject' && $postId>0){
        $st=$conn->prepare("
            SELECT sr.id,sr.request_no,sr.status,sr.service_id,
                   u.name AS owner_name,u.email AS owner_email,
                   (SELECT COUNT(*) FROM service_request_assets x WHERE x.request_id=sr.id) AS asset_count
            FROM service_requests sr
            LEFT JOIN users u ON u.id=sr.owner_id
            WHERE sr.id=? LIMIT 1
        ");
        if($st){
            $st->bind_param('i',$postId);$st->execute();$data=$st->get_result()->fetch_assoc();$st->close();

            if(!$data) $errorMessage='Service Request not found.';
            elseif($data['status']!=='Submitted') $errorMessage='Only Submitted service requests can be rejected.';
            else{
                $decisionServices = sr_get_request_services($conn, $postId, (int)$data['service_id']);
                $decisionServiceName = sr_service_names($decisionServices);
                $reason=trim((string)($_POST['rejection_reason']??''));
                $u=$conn->prepare("UPDATE service_requests SET status='Rejected' WHERE id=? AND status='Submitted' LIMIT 1");
                $u->bind_param('i',$postId);
                if($u->execute() && $u->affected_rows>0){
                    $mailSent=nletaSendOwnerDecisionEmail(
                        trim((string)$data['owner_email']),
                        trim((string)($data['owner_name']?:'Owner')),
                        trim((string)$data['request_no']),
                        $decisionServiceName,
                        (int)$data['asset_count'],
                        'rejected',
                        $reason
                    );

                    $successMessage='Service Request rejected successfully.';
                    if(!$mailSent) $successMessage.=' Owner email could not be sent; check your SMTP/App Password and PHP error log.';
                    else $successMessage.=' Rejection email sent to Owner.';
                    $requestId=$postId;
                }else $errorMessage='Unable to reject the Service Request.';
                $u->close();
            }
        }
    }
}

/* =========================================================
   LOAD DETAIL
========================================================= */
$request=null;
$requestServices=[];
$requestAssets=[];
$assetDetailsMap=[];
$editMode=isset($_GET['edit']) && (int)$_GET['edit']>0;
$editRequestData=null;
$editServices=[];
$editOwnerAssets=[];
$selectedEditAssets=[];

if($requestId>0){
    $st=$conn->prepare("
        SELECT sr.id,sr.request_no,sr.owner_id,sr.service_id,sr.requirements,sr.status,sr.submitted_at,
               sc.service_code,sc.service_name,sc.description AS service_description,
               u.name AS owner_name,u.email AS owner_email,u.phone AS owner_phone,
               u.organization_name AS organization_name,
               u.organization_address AS organization_address
        FROM service_requests sr
        LEFT JOIN service_catalog sc ON sc.id=sr.service_id
        LEFT JOIN users u ON u.id=sr.owner_id
        WHERE sr.id=? LIMIT 1
    ");
    if($st){
        $st->bind_param('i',$requestId);$st->execute();$request=$st->get_result()->fetch_assoc();$st->close();
    }

    if($request){
        $requestServices = sr_get_request_services($conn, $requestId, (int)($request['service_id'] ?? 0));
        $request['live_status'] = sr_get_live_offer_status($conn, $requestId, (string)($request['status'] ?? ''));

        $a=$conn->prepare("
            SELECT a.id,a.display_asset_id,a.asset_name,a.asset_type,a.category,a.location,a.status,a.owner_id
            FROM service_request_assets sra
            INNER JOIN assets a ON a.id=sra.asset_id
            WHERE sra.request_id=?
            ORDER BY a.display_asset_id ASC,a.id ASC
        ");
        if($a){
            $a->bind_param('i',$requestId);$a->execute();$res=$a->get_result();
            while($r=$res->fetch_assoc())$requestAssets[]=$r;
            $a->close();
        }

        /* Display-only asset technical information from Add Lift / Escalator.
           SELECT * is intentional here so the page does not depend on a fixed
           list of optional asset_details columns. Existing workflow/actions
           remain unchanged. */
        if($requestAssets){
            $ad=$conn->prepare("SELECT * FROM asset_details WHERE asset_id=? LIMIT 1");
            if($ad){
                foreach($requestAssets as $assetRow){
                    $assetId=(int)$assetRow['id'];
                    $ad->bind_param('i',$assetId);
                    if($ad->execute()){
                        $detailRow=$ad->get_result()->fetch_assoc();
                        if($detailRow) $assetDetailsMap[$assetId]=$detailRow;
                    }
                }
                $ad->close();
            }
        }

        if($editMode){
            $editId=(int)$_GET['edit'];
            $e=$conn->prepare("SELECT sr.*,sc.service_code,sc.service_name FROM service_requests sr LEFT JOIN service_catalog sc ON sc.id=sr.service_id WHERE sr.id=? LIMIT 1");
            if($e){$e->bind_param('i',$editId);$e->execute();$editRequestData=$e->get_result()->fetch_assoc();$e->close();}
            if($editRequestData){
                $sr=$conn->query("SELECT id,service_code,service_name FROM service_catalog WHERE is_active=1 ORDER BY service_code");
                if($sr)while($r=$sr->fetch_assoc())$editServices[]=$r;

                $editSelectedServices = sr_get_request_services(
                    $conn,
                    $editId,
                    (int)($editRequestData['service_id'] ?? 0)
                );
                $selectedEditServices = sr_service_ids($editSelectedServices);

                $oa=$conn->prepare("SELECT id,display_asset_id,asset_name,asset_type,location,status FROM assets WHERE owner_id=? ORDER BY display_asset_id,id");
                $oid=(int)$editRequestData['owner_id'];
                if($oa){$oa->bind_param('i',$oid);$oa->execute();$res=$oa->get_result();while($r=$res->fetch_assoc())$editOwnerAssets[]=$r;$oa->close();}

                $sa=$conn->prepare("SELECT asset_id FROM service_request_assets WHERE request_id=?");
                if($sa){$sa->bind_param('i',$editId);$sa->execute();$res=$sa->get_result();while($r=$res->fetch_assoc())$selectedEditAssets[]=(int)$r['asset_id'];$sa->close();}
            }
        }
    }
}

/* =========================================================
   HEADER
========================================================= */
include('../includes/header.php');
include('../includes/sidebar-admin.php');

// Explicit template-scope defaults for PHP static analyzers / Intelephense.
$successMessage = isset($successMessage) ? (string)$successMessage : '';
$errorMessage = isset($errorMessage) ? (string)$errorMessage : '';
$statusFilter = isset($statusFilter) ? (string)$statusFilter : '';
$request = isset($request) && is_array($request) ? $request : null;
$editRequestData = isset($editRequestData) && is_array($editRequestData) ? $editRequestData : null;
$editServices = isset($editServices) && is_array($editServices) ? $editServices : [];
$editOwnerAssets = isset($editOwnerAssets) && is_array($editOwnerAssets) ? $editOwnerAssets : [];
$selectedEditAssets = isset($selectedEditAssets) && is_array($selectedEditAssets) ? $selectedEditAssets : [];
$selectedEditServices = isset($selectedEditServices) && is_array($selectedEditServices) ? $selectedEditServices : [];
?>
<style>
.service-page{max-width:1400px;margin:0 auto}
.page-header-card,.filter-card,.request-card,.detail-card{background:#fff;border-radius:14px;box-shadow:0 3px 12px rgba(0,0,0,.08);margin-bottom:20px}
.page-header-card,.filter-card,.detail-card{padding:22px}
.page-title{font-size:23px;font-weight:700}
.page-subtitle{color:#6c757d;font-size:13px}
.request-table th{background:#f8f9fa;white-space:nowrap;font-size:13px}
.request-table td{vertical-align:middle;font-size:13px}
.request-number{font-weight:700;color:#0d6efd}
.service-code{display:inline-block;padding:4px 8px;border-radius:5px;background:#eaf2ff;color:#0d6efd;font-size:11px;font-weight:700}
.info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.info-box{background:#f8f9fa;border-radius:10px;padding:14px}
.info-label{color:#6c757d;font-size:11px;margin-bottom:5px}
.info-value{font-size:14px;font-weight:600;overflow-wrap:anywhere;word-break:break-word}
.service-detail-box{background:#f4f8ff;border:1px solid #d9e7ff;border-radius:12px;padding:18px}
.service-detail-code{display:inline-block;background:#0d6efd;color:#fff;padding:5px 9px;border-radius:5px;font-size:11px;font-weight:700}
.service-detail-name{font-size:18px;font-weight:700;margin-top:8px}
.service-list-badge{display:inline-block;margin:0 6px 6px 0;padding:5px 8px;border-radius:5px;background:#eaf2ff;color:#0d6efd;font-size:11px;font-weight:700}
.asset-card{border:1px solid #e9ecef;border-radius:12px;margin-bottom:15px;overflow:hidden}
.asset-header{background:#f8f9fa;padding:14px 16px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.asset-id{font-weight:700;color:#0d6efd}.asset-name{font-weight:600}.asset-body{padding:16px}
.asset-info-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.asset-info-item,.saved-detail-item{background:#f8f9fa;border-radius:8px;padding:10px}
.asset-info-label,.saved-detail-label{font-size:10px;color:#6c757d;margin-bottom:3px}
.asset-info-value,.saved-detail-value{font-size:12px;font-weight:600;word-break:break-word}
.requirements-box{background:#f8f9fa;border-radius:10px;padding:15px;white-space:pre-wrap;line-height:1.6}
.decision-card{background:#f8f9fa;border:1px solid #e9ecef;border-radius:12px;padding:20px}
.decision-buttons{display:flex;gap:10px;flex-wrap:wrap}
.next-phase{background:#fff8e6;border:1px solid #ffe69c;border-radius:12px;padding:18px}
.next-phase-title{font-weight:700;color:#664d03;margin-bottom:7px}
.next-flow{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-top:12px}
.next-flow-box{background:#fff;border:1px solid #e9ecef;border-radius:7px;padding:7px 10px;font-size:12px;font-weight:600}
.empty-state{text-align:center;padding:60px 20px}.empty-icon{font-size:50px;color:#adb5bd}
@media(max-width:900px){.info-grid{grid-template-columns:1fr}.asset-info-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.asset-info-grid{grid-template-columns:1fr}.decision-buttons{flex-direction:column}.decision-buttons .btn{width:100%}}
</style>

<div class="content">
<div class="service-page">

<?php if($requestId<=0): ?>

<div class="page-header-card">
<div class="page-title"><i class="bi bi-clipboard-check me-2"></i>Service Requests</div>
<div class="page-subtitle">Review Owner service requests before generating an offer.</div>
</div>

<?php if($successMessage!==''): ?>
<div class="alert alert-success alert-dismissible fade show"><?=nleta_sr_h($successMessage)?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if($errorMessage!==''): ?>
<div class="alert alert-danger alert-dismissible fade show"><?=nleta_sr_h($errorMessage)?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="filter-card">
<form method="GET" class="row g-3 align-items-end">
<div class="col-md-4">
<label class="form-label">Status</label>
<select name="status" class="form-select">
<option value="">All Statuses</option>
<?php foreach(['Submitted','Accepted','Rejected','Offer Generated','Pending','Offer Accepted','Offer Rejected'] as $s): ?>
<option value="<?=nleta_sr_h($s)?>" <?=$statusFilter===$s?'selected':''?>><?=nleta_sr_h($s)?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-auto"><button class="btn btn-primary"><i class="bi bi-filter me-1"></i>Filter</button></div>
<?php if($statusFilter!==''): ?><div class="col-md-auto"><a href="service-requests.php" class="btn btn-outline-secondary">Clear</a></div><?php endif; ?>
</form>
</div>

<div class="request-card">
<div class="table-responsive">
<?php
/*
 * Load requests first, then resolve the LIVE offer status in PHP.
 * This lets the page immediately reflect:
 *   Offer Generated -> Pending -> Offer Accepted / Offer Rejected
 * from nleta_offer_master without changing the existing
 * service_requests.status values.
 */
$sql="
SELECT sr.id,sr.request_no,sr.owner_id,sr.service_id,sr.requirements,sr.status,sr.submitted_at,
       sc.service_code,sc.service_name,u.name AS owner_name,
       COUNT(DISTINCT sra.asset_id) AS asset_count
FROM service_requests sr
INNER JOIN service_catalog sc ON sc.id=sr.service_id
LEFT JOIN users u ON u.id=sr.owner_id
LEFT JOIN service_request_assets sra ON sra.request_id=sr.id
GROUP BY sr.id,sr.request_no,sr.owner_id,sr.service_id,sr.requirements,sr.status,sr.submitted_at,
         sc.service_code,sc.service_name,u.name
ORDER BY sr.id DESC";
$st=$conn->prepare($sql);
if($st){
    $st->execute();
    $rawList=$st->get_result();
    $requestRows=[];
    while($row=$rawList->fetch_assoc()){
        $row['live_status']=sr_get_live_offer_status(
            $conn,
            (int)$row['id'],
            (string)$row['status']
        );

        if(sr_status_filter_match((string)$row['live_status'],$statusFilter)){
            $requestRows[]=$row;
        }
    }
    $st->close();

    /*
     * Small result object compatible with the existing template.
     * We render from $requestRows below instead of mysqli_result.
     */
    $listCount=count($requestRows);
}else{
    $requestRows=[];
    $listCount=0;
}
?>
<?php if($listCount>0): ?>
<table class="table table-hover table-bordered request-table mb-0">
<thead><tr><th>Request ID</th><th>Owner</th><th>Service</th><th>Assets</th><th>Request Date</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php foreach($requestRows as $row):
$liveStatus=(string)($row['live_status'] ?? $row['status']);
$cls=sr_live_status_class($liveStatus);
?>
<tr>
<td><span class="request-number"><?=nleta_sr_h($row['request_no'])?></span></td>
<td><?=nleta_sr_h($row['owner_name']??'Unknown Owner')?></td>
<?php $rowServices = sr_get_request_services($conn, (int)$row['id'], (int)$row['service_id']); ?>
<td>
<?php if($rowServices): ?>
<?php foreach($rowServices as $rs): ?>
<div class="mb-1"><span class="service-code"><?=nleta_sr_h($rs['service_code'])?></span><div class="small mt-1"><?=nleta_sr_h($rs['service_name'])?></div></div>
<?php endforeach; ?>
<?php else: ?><span class="text-muted">-</span><?php endif; ?>
</td>
<td><span class="badge bg-secondary"><?= (int)$row['asset_count']?></span></td>
<td><?=nleta_sr_h(nleta_sr_ist($row['submitted_at']))?></td>
<td><span class="badge <?=$cls?>"><?=nleta_sr_h($liveStatus)?></span></td>
<td><a href="service-requests.php?id=<?=(int)$row['id']?>" class="btn btn-sm btn-primary"><i class="bi bi-eye me-1"></i>Review</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?>
<div class="empty-state"><div class="empty-icon"><i class="bi bi-inbox"></i></div><h5 class="mt-3">No Service Requests Found</h5><p class="text-muted mb-0">Owner-submitted service requests will appear here.</p></div>
<?php endif; ?>
<?php if($st)$st->close(); ?>
</div>
</div>

<?php else: ?>

<?php if(!$request): ?>
<div class="detail-card">
<div class="alert alert-danger">Service Request not found.</div>
<a href="service-requests.php" class="btn btn-primary">Back to Service Requests</a>
</div>

<?php elseif($editMode): ?>

<div class="page-header-card">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
<div><div class="page-title"><i class="bi bi-pencil-square me-2"></i>Edit Service Request</div><div class="page-subtitle"><?=nleta_sr_h($editRequestData['request_no'] ?? ($request['request_no'] ?? ''))?></div></div>
<a href="service-requests.php?id=<?=(int)$request['id']?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
</div>
</div>

<?php if($errorMessage!==''): ?><div class="alert alert-danger"><?=nleta_sr_h($errorMessage)?></div><?php endif; ?>
<?php if($successMessage!==''): ?><div class="alert alert-success"><?=nleta_sr_h($successMessage)?></div><?php endif; ?>

<div class="detail-card">
<?php if(!$editRequestData): ?>
<div class="alert alert-danger mb-0">Service Request not found.</div>
<?php elseif(!in_array($editRequestData['status'],['Submitted','Rejected'],true)): ?>
<div class="alert alert-warning mb-0">Only Submitted or Rejected requests can be edited.</div>
<?php else: ?>
<form method="POST">
<input type="hidden" name="action" value="edit">
<input type="hidden" name="request_id" value="<?=(int)$editRequestData['id']?>">
<div class="mb-4">
<label class="form-label fw-bold">Services</label>
<div class="border rounded p-3" style="max-height:320px;overflow-y:auto;">
<?php foreach($editServices as $s): ?>
<div class="form-check border-bottom py-2">
<input class="form-check-input" type="checkbox" name="service_ids[]" value="<?=(int)$s['id']?>" id="edit_service_<?=(int)$s['id']?>" <?=in_array((int)$s['id'],$selectedEditServices,true)?'checked':''?>>
<label class="form-check-label" for="edit_service_<?=(int)$s['id']?>">
<strong><?=nleta_sr_h($s['service_code'])?></strong> — <?=nleta_sr_h($s['service_name'])?>
</label>
</div>
<?php endforeach; ?>
</div>
<div class="form-text">Select all services that belong to this Service Request.</div>
</div>
<div class="mb-4">
<label class="form-label fw-bold">Select Assets</label>
<div class="border rounded p-3" style="max-height:420px;overflow-y:auto;">
<?php foreach($editOwnerAssets as $a): ?>
<div class="form-check border-bottom py-2">
<input class="form-check-input" type="checkbox" name="asset_ids[]" value="<?=(int)$a['id']?>" id="edit_asset_<?=(int)$a['id']?>" <?=in_array((int)$a['id'],$selectedEditAssets,true)?'checked':''?>>
<label class="form-check-label" for="edit_asset_<?=(int)$a['id']?>"><strong><?=nleta_sr_h($a['display_asset_id'])?></strong> — <?=nleta_sr_h($a['asset_name'])?> <span class="text-muted">(<?=nleta_sr_h($a['asset_type'])?>, <?=nleta_sr_h($a['location']??'-')?>)</span></label>
</div>
<?php endforeach; ?>
</div>
</div>
<div class="mb-4"><label class="form-label fw-bold">Requirements / Remarks</label><textarea name="requirements" class="form-control" rows="5"><?=nleta_sr_h($editRequestData['requirements']??'')?></textarea></div>
<button class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Save Changes</button>
<a href="service-requests.php?id=<?=(int)$request['id']?>" class="btn btn-outline-secondary">Cancel</a>
</form>
<?php endif; ?>
</div>

<?php else: ?>

<?php
$detailStatus=trim((string)($request['live_status'] ?? $request['status']));
$detailClass=sr_live_status_class($detailStatus);
?>

<div class="page-header-card">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
<div><div class="page-title"><i class="bi bi-clipboard-check me-2"></i>Service Request</div><div class="page-subtitle"><?=nleta_sr_h($request['request_no'])?></div></div>
<div class="d-flex gap-2 align-items-center flex-wrap">
<span class="badge <?=$detailClass?>" style="font-size:13px;padding:8px 12px"><?=nleta_sr_h($detailStatus)?></span>
<?php if(in_array((string)$request['status'],['Submitted','Rejected'],true) && in_array($detailStatus,['Submitted','Rejected'],true)): ?><a href="service-requests.php?id=<?=(int)$request['id']?>&edit=<?=(int)$request['id']?>" class="btn btn-warning btn-sm"><i class="bi bi-pencil-square me-1"></i>Edit</a><?php endif; ?>
<form method="POST" style="margin:0" onsubmit="return confirm('PERMANENT DELETE WARNING! This will permanently delete this Service Request and linked workflow records. Continue?');">
<input type="hidden" name="action" value="delete"><input type="hidden" name="request_id" value="<?=(int)$request['id']?>">
<button class="btn btn-danger btn-sm"><i class="bi bi-trash3-fill me-1"></i>Permanent Delete</button>
</form>
<a href="service-requests.php" class="btn btn-outline-secondary btn-sm">Back</a>
</div>
</div>
</div>

<?php if($successMessage!==''): ?><div class="alert alert-success alert-dismissible fade show"><?=nleta_sr_h($successMessage)?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if($errorMessage!==''): ?><div class="alert alert-danger alert-dismissible fade show"><?=nleta_sr_h($errorMessage)?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="detail-card">
<div class="detail-title"><i class="bi bi-info-circle me-2"></i>Request Information</div>
<div class="info-grid">
<div class="info-box"><div class="info-label">Request ID</div><div class="info-value"><?=nleta_sr_h($request['request_no'])?></div></div>
<div class="info-box"><div class="info-label">Owner</div><div class="info-value"><?=nleta_sr_h($request['owner_name']??'Unknown Owner')?></div></div>
<div class="info-box"><div class="info-label">Owner Email</div><div class="info-value"><?=nleta_sr_h($request['owner_email']??'-')?></div></div>
<div class="info-box"><div class="info-label">Owner Phone</div><div class="info-value"><?=nleta_sr_h($request['owner_phone']??'-')?></div></div>
<div class="info-box"><div class="info-label">Organization / Association Name</div><div class="info-value"><?=nleta_sr_h(trim((string)($request['organization_name']??''))!==''?$request['organization_name']:'-')?></div></div>
<div class="info-box"><div class="info-label">Organization / Association Address</div><div class="info-value"><?=nl2br(nleta_sr_h(trim((string)($request['organization_address']??''))!==''?$request['organization_address']:'-'))?></div></div>
<div class="info-box"><div class="info-label">Submitted</div><div class="info-value"><?=nleta_sr_h(nleta_sr_ist($request['submitted_at']))?></div></div>
<div class="info-box"><div class="info-label">Status</div><div class="info-value"><?=nleta_sr_h($detailStatus)?></div></div>
</div>
</div>

<div class="detail-card">
<div class="detail-title"><i class="bi bi-clipboard-check me-2"></i>Selected Services</div>
<div class="service-detail-box">
<?php if(!$requestServices): ?>
<div class="text-muted">No service information found.</div>
<?php else: ?>
<?php foreach($requestServices as $idx => $service): ?>
<div style="padding:12px 0;<?=($idx>0?'border-top:1px solid #d9e7ff;':'')?>">
<div class="service-detail-code"><?=nleta_sr_h($service['service_code'] ?? '')?></div>
<div class="service-detail-name"><?=nleta_sr_h($service['service_name'] ?? '')?></div>
<?php if(trim((string)($service['description'] ?? ''))!==''): ?>
<div class="text-muted mt-2"><?=nl2br(nleta_sr_h($service['description']))?></div>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>

<div class="detail-card">
<div class="d-flex justify-content-between align-items-center mb-3">
<div class="detail-title mb-0"><i class="bi bi-building-check me-2"></i>Selected Assets</div>
<span class="badge bg-primary"><?=count($requestAssets)?> <?=count($requestAssets)===1?'Asset':'Assets'?></span>
</div>
<?php if(!$requestAssets): ?><div class="alert alert-warning mb-0">No assets are attached to this request.</div><?php else: ?>
<?php foreach($requestAssets as $a): ?>
<div class="asset-card">
<div class="asset-header"><div><div class="asset-id"><?=nleta_sr_h($a['display_asset_id'])?></div><div class="asset-name"><?=nleta_sr_h($a['asset_name'])?></div></div><span class="badge bg-primary">Asset ID: <?=(int)$a['id']?></span></div>
<div class="asset-body">
<div class="asset-info-grid">
<div class="asset-info-item"><div class="asset-info-label">Asset Type</div><div class="asset-info-value"><?=nleta_sr_h($a['asset_type']??'-')?></div></div>
<div class="asset-info-item"><div class="asset-info-label">Category</div><div class="asset-info-value"><?=nleta_sr_h($a['category']??'-')?></div></div>
<div class="asset-info-item"><div class="asset-info-label">Location</div><div class="asset-info-value"><?=nleta_sr_h($a['location']??'-')?></div></div>
<div class="asset-info-item"><div class="asset-info-label">Status</div><div class="asset-info-value"><?=nleta_sr_h($a['status']??'-')?></div></div>
</div>

<?php
/*
 * DISPLAY ONLY — OWNER ENTERED ASSET DETAILS
 *
 * IMPORTANT:
 * 1. Read technical details by THIS asset's asset_id only.
 * 2. Never mix details between selected assets.
 * 3. Lift fields are shown only for Lift.
 * 4. Escalator fields are shown only for Escalator.
 * 5. Installation is YEAR only.
 * 6. Blank / 0 / 0000 modification year is not shown.
 * 7. Passenger Capacity and Rated Load Capacity are shown
 *    exactly from asset_details.
 */
$assetId = (int)($a['id'] ?? 0);
$adetail = $assetDetailsMap[$assetId] ?? [];

$assetTypeValue = trim((string)($a['asset_type'] ?? ''));
$isLift = strcasecmp($assetTypeValue, 'Lift') === 0;
$isEscalator = strcasecmp($assetTypeValue, 'Escalator') === 0;

/*
 * Fallback only when assets.asset_type is empty.
 * Do not use another asset's details.
 */
if (!$isLift && !$isEscalator) {
    $categoryValue = trim((string)($a['category'] ?? ''));
    $isLift = stripos($categoryValue, 'lift') !== false;
    $isEscalator = stripos($categoryValue, 'escalator') !== false;
}

$technicalItems = [];

/* Owner / Consignee */
$consignee = trim((string)($adetail['consignee_name'] ?? ''));
if ($consignee !== '') {
    $technicalItems['Owner / Consignee Name'] = $consignee;
}

/* Installation — YEAR ONLY */
$installationRaw = trim((string)($adetail['installation_date'] ?? ''));
if (
    $installationRaw !== '' &&
    $installationRaw !== '0000-00-00' &&
    $installationRaw !== '0000-00-00 00:00:00'
) {
    $installationYear = '';

    if (preg_match('/^(\d{4})/', $installationRaw, $match)) {
        $installationYear = $match[1];
    } else {
        $ts = strtotime($installationRaw);
        if ($ts !== false) {
            $installationYear = date('Y', $ts);
        }
    }

    if (
        $installationYear !== '' &&
        $installationYear !== '0000'
    ) {
        $technicalItems['Year of Installation'] = $installationYear;
    }
}

/* =========================================================
   LIFT — ONLY THE FIELDS SAVED FOR THIS LIFT
========================================================= */
if ($isLift) {

    $liftType = trim((string)($adetail['lift_type'] ?? ''));
    if ($liftType !== '') {
        $technicalItems['Lift Type'] = $liftType;
    }

    /*
     * Passenger Capacity
     * Directly from asset_details.passenger_capacity.
     */
    $passengerCapacity = $adetail['passenger_capacity'] ?? null;

    if (
        $passengerCapacity !== null &&
        trim((string)$passengerCapacity) !== '' &&
        (float)$passengerCapacity > 0
    ) {
        $technicalItems['Passenger Capacity'] =
            (int)$passengerCapacity . ' Persons';
    }

    /*
     * Rated Load Capacity
     * Directly from asset_details.load_capacity.
     */
    $loadCapacity = $adetail['load_capacity'] ?? null;

    if (
        $loadCapacity !== null &&
        trim((string)$loadCapacity) !== '' &&
        (float)$loadCapacity > 0
    ) {
        $formattedLoad =
            number_format(
                (float)$loadCapacity,
                2,
                '.',
                ''
            );

        $formattedLoad =
            rtrim(
                rtrim(
                    $formattedLoad,
                    '0'
                ),
                '.'
            );

        $technicalItems['Rated Load Capacity'] =
            $formattedLoad . ' kg';
    }

    $machineType = trim((string)($adetail['machine_type'] ?? ''));
    if ($machineType !== '') {
        $technicalItems['Machine Type'] = $machineType;
    }

    $floors = $adetail['number_of_floors'] ?? null;
    if (
        $floors !== null &&
        trim((string)$floors) !== '' &&
        (float)$floors > 0
    ) {
        $technicalItems['Number of Floors / Levels'] =
            (int)$floors;
    }

    $floorServiceType =
        trim((string)($adetail['floor_service_type'] ?? ''));

    if ($floorServiceType !== '') {
        $technicalItems['Floor Service Pattern'] =
            $floorServiceType;
    }
}

/* =========================================================
   ESCALATOR — ONLY THE FIELDS SAVED FOR THIS ESCALATOR
========================================================= */
if ($isEscalator) {

    $escalatorType =
        trim((string)($adetail['escalator_type'] ?? ''));

    if ($escalatorType !== '') {
        $technicalItems['Escalator Type'] =
            $escalatorType;
    }

    $escalatorRise =
        $adetail['escalator_rise'] ?? null;

    if (
        $escalatorRise !== null &&
        trim((string)$escalatorRise) !== '' &&
        (float)$escalatorRise > 0
    ) {
        $formattedRise =
            number_format(
                (float)$escalatorRise,
                2,
                '.',
                ''
            );

        $formattedRise =
            rtrim(
                rtrim(
                    $formattedRise,
                    '0'
                ),
                '.'
            );

        $technicalItems['Escalator Rise'] =
            $formattedRise . ' m';
    }
}

/* =========================================================
   MODIFICATION YEAR
   Blank / 0 / 0000 = DO NOT DISPLAY
========================================================= */
$modificationYear =
    trim((string)($adetail['modification_year'] ?? ''));

if (
    $modificationYear !== '' &&
    $modificationYear !== '0' &&
    $modificationYear !== '0000' &&
    (int)$modificationYear > 0
) {
    $technicalItems['Modification Year'] =
        (int)$modificationYear;
}

$hasAssetDetails = !empty($technicalItems);
?>

<?php if ($hasAssetDetails): ?>

<div class="mt-3">

    <div class="fw-bold mb-2">
        <i class="bi bi-card-list me-1"></i>
        Asset Technical Information
    </div>

    <div class="asset-info-grid">

        <?php foreach ($technicalItems as $label => $value): ?>

        <div class="asset-info-item">

            <div class="asset-info-label">
                <?= nleta_sr_h($label) ?>
            </div>

            <div class="asset-info-value">
                <?= nl2br(nleta_sr_h($value)) ?>
            </div>

        </div>

        <?php endforeach; ?>

    </div>

</div>

<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<div class="detail-card">
<div class="detail-title"><i class="bi bi-chat-left-text me-2"></i>Requirements / Remarks</div>
<div class="requirements-box"><?=trim((string)$request['requirements'])!==''?nl2br(nleta_sr_h($request['requirements'])):'No additional requirements or remarks were provided.'?></div>
</div>

<?php if($detailStatus==='Submitted'): ?>
<div class="detail-card">
<div class="decision-card">
<div class="fw-bold fs-5 mb-1"><i class="bi bi-shield-check me-2"></i>Admin Review</div>
<div class="text-muted small mb-3">Accept or reject this Owner service request.</div>
<div class="decision-buttons">
<form method="POST" onsubmit="return confirm('Are you sure you want to ACCEPT this service request?');">
<input type="hidden" name="action" value="accept"><input type="hidden" name="request_id" value="<?=(int)$request['id']?>">
<button class="btn btn-success"><i class="bi bi-check-circle-fill me-1"></i>Accept Request</button>
</form>
<form method="POST" onsubmit="return confirm('Are you sure you want to REJECT this service request?');">
<input type="hidden" name="action" value="reject"><input type="hidden" name="request_id" value="<?=(int)$request['id']?>">
<button class="btn btn-danger"><i class="bi bi-x-circle-fill me-1"></i>Reject Request</button>
</form>
</div>
</div>
</div>
<?php endif; ?>

<?php if($detailStatus==='Accepted'): ?>
<div class="detail-card">
<div class="next-phase">
<div class="next-phase-title"><i class="bi bi-check-circle-fill me-2"></i>Service Request Accepted</div>
<div style="font-size:13px;line-height:1.6">Admin has accepted this Service Request. The request is now eligible for Offer Generator.</div>
<div class="next-flow">
<div class="next-flow-box">Owner</div><span>→</span><div class="next-flow-box">Service Request</div><span>→</span><div class="next-flow-box text-success">Accepted</div><span>→</span><div class="next-flow-box text-primary">Offer Generator</div>
</div>
<div class="mt-3"><a href="offer-generator.php?request_id=<?=(int)$request['id']?>" class="btn btn-primary"><i class="bi bi-file-earmark-text me-1"></i>Proceed to Offer Generator</a></div>
</div>
</div>
<?php endif; ?>

<?php if($detailStatus==='Rejected'): ?>
<div class="detail-card"><div class="alert alert-danger mb-0"><strong><i class="bi bi-x-circle-fill me-2"></i>Service Request Rejected</strong><div class="mt-2">This Service Request has been rejected by Admin. Offer Generator is locked for this request.</div></div></div>
<?php endif; ?>

<?php if($detailStatus==='Offer Generated'): ?>
<div class="detail-card"><div class="next-phase">
<div class="next-phase-title"><i class="bi bi-file-earmark-check-fill me-2"></i>Offer Generated</div>
<div style="font-size:13px">An offer has been generated for this Service Request and is available in the Offer Generator.</div>
<div class="mt-3 d-flex gap-2 flex-wrap">
<a href="offer-generator.php?request_id=<?=(int)$request['id']?>" class="btn btn-info text-dark"><i class="bi bi-file-earmark-text me-1"></i>View / Manage Offer</a>
</div>
</div></div>
<?php endif; ?>

<?php if($detailStatus==='Pending'): ?>
<div class="detail-card"><div class="next-phase">
<div class="next-phase-title"><i class="bi bi-hourglass-split me-2"></i>Offer Pending — Owner Action</div>
<div style="font-size:13px;line-height:1.6">
The offer has been sent to the Owner and is currently <strong>Pending</strong>.
The Admin Service Request status is automatically read from the latest Offer.
</div>
<div class="next-flow">
<div class="next-flow-box">Offer Generated</div><span>→</span>
<div class="next-flow-box text-warning">Pending</div><span>→</span>
<div class="next-flow-box">Owner Decision</div>
</div>
<div class="mt-3">
<a href="offer-generator.php?request_id=<?=(int)$request['id']?>" class="btn btn-primary"><i class="bi bi-file-earmark-text me-1"></i>View Offer</a>
</div>
</div></div>
<?php endif; ?>

<?php if($detailStatus==='Offer Accepted'): ?>
<div class="detail-card"><div class="next-phase">
<div class="next-phase-title"><i class="bi bi-check-circle-fill me-2"></i>Offer Accepted by Owner</div>
<div style="font-size:13px;line-height:1.6">
The Owner has accepted the generated offer. The Service Request status is automatically updated here from the latest Offer status.
</div>
<div class="next-flow">
<div class="next-flow-box">Offer Generated</div><span>→</span>
<div class="next-flow-box">Pending</div><span>→</span>
<div class="next-flow-box text-success">Offer Accepted</div><span>→</span>
<div class="next-flow-box text-primary">Payment / PSGA</div>
</div>
<div class="mt-3">
<a href="offer-generator.php?request_id=<?=(int)$request['id']?>" class="btn btn-primary"><i class="bi bi-file-earmark-text me-1"></i>View Offer</a>
</div>
</div></div>
<?php endif; ?>

<?php if($detailStatus==='Offer Rejected'): ?>
<div class="detail-card"><div class="alert alert-danger mb-0">
<strong><i class="bi bi-x-circle-fill me-2"></i>Offer Rejected by Owner</strong>
<div class="mt-2" style="line-height:1.6">
The Owner has rejected the generated offer. The live status is read from the latest Offer record.
</div>
<div class="mt-3">
<a href="offer-generator.php?request_id=<?=(int)$request['id']?>" class="btn btn-outline-danger"><i class="bi bi-file-earmark-text me-1"></i>View Offer</a>
</div>
</div></div>
<?php endif; ?>

<div class="mb-5"><a href="service-requests.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Service Requests</a></div>

<?php endif; ?>
<?php endif; ?>

</div>
</div>

<?php include('../includes/footer.php'); ?>