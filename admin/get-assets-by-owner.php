<?php
include('../config/db.php');
include('../config/auth.php');
require_role('admin');

$owner_id = (int)($_GET['owner_id'] ?? 0);
if (!$owner_id) { echo json_encode([]); exit; }

$stmt = $conn->prepare("SELECT id, asset_name, asset_type, location FROM assets WHERE owner_id=? ORDER BY asset_name");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

header('Content-Type: application/json');
echo json_encode($data);
