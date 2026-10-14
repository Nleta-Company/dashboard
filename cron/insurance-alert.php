<?php
include('../config/db.php');

$stmt = $conn->prepare("
    INSERT INTO alerts (asset_id, message, severity)
    SELECT asset_id, 'Insurance Expiring', 'Warning'
    FROM insurance
    WHERE expiry_date < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
");
if ($stmt) {
    $stmt->execute();
}
