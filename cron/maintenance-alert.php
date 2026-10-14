<?php
include('../config/db.php');

$today = new DateTime('now', new DateTimeZone('UTC'));

$q = $conn->query("
    SELECT a.id AS asset_id, a.maintenance_interval,
           (SELECT MAX(maintenance_date) FROM maintenance_records WHERE asset_id=a.id) AS last_maintenance
    FROM assets a
    WHERE a.status IN ('Active', 'Under Maintenance')
");
while ($r = $q->fetch_assoc()) {
    $lastMaint = $r['last_maintenance'];
    if ($lastMaint) {
        $lastMaintDt = new DateTime($lastMaint . ' +00:00');
        $daysSinceLastMaint = (int)$today->diff($lastMaintDt)->format('%a');
        // Check if maintenance is overdue based on interval
        $interval = (int)$r['maintenance_interval'];
        $dueDate = clone $lastMaintDt;
        $dueDate->modify('+' . $interval . ' days');
        if ($today >= $dueDate) {
            $asset_id = (int)$r['asset_id'];
            $daysOverdue = (int)$today->diff($dueDate)->format('%a');
            $msg = "Maintenance overdue by {$daysOverdue} days (last done {$daysSinceLastMaint} days ago)";
            $sev = 'Critical';
            
            $stmt = $conn->prepare("INSERT INTO alerts (asset_id, message, severity) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("iss", $asset_id, $msg, $sev);
                $stmt->execute();
            }
        }
    }
}
