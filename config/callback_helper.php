<?php
// Generates: NLETA0107052026 (NLETA + sequential 2 digits + day + month + year)
function generateCallbackNumber($conn) {
    $datePart = date('dmY');
    $prefix   = 'NLETA';
    $like     = $prefix . '%' . $datePart;
    $stmt = $conn->prepare("SELECT COUNT(*) t FROM callbacks WHERE callback_number LIKE ?");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['t'];
    return $prefix . str_pad($count + 1, 2, '0', STR_PAD_LEFT) . $datePart;
}
?>
