<?php
require_once __DIR__ . '/../config/db.php';
$owner_id = 1;

echo "=== TOP 2 non-fortnightly jobs ===\n";
$stmt = $conn->prepare("
    SELECT mj.id, mj.created_at, a.asset_name
    FROM maintenance_jobs mj
    JOIN assets a ON a.id = mj.asset_id
    WHERE a.owner_id = ?
      AND IFNULL(mj.job_type, '') != 'fortnightly'
    ORDER BY mj.created_at DESC
    LIMIT 2
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$top2 = $stmt->get_result();
while ($r = $top2->fetch_assoc()) {
    echo "  id={$r['id']} created_at={$r['created_at']} asset={$r['asset_name']}\n";
}
echo "top2 rows: {$top2->num_rows}\n\n";

echo "=== All non-fortnightly jobs ===\n";
$all = $conn->query("
    SELECT mj.id, mj.created_at, a.asset_name, IFNULL(mj.job_type,'')
    FROM maintenance_jobs mj
    JOIN assets a ON a.id = mj.asset_id
    ORDER BY mj.created_at DESC
    LIMIT 20
");
while ($r = $all->fetch_assoc()) {
    echo "  id={$r['id']} job_type='{$r[3]}' created_at={$r['created_at']} asset={$r['asset_name']}\n";
}
