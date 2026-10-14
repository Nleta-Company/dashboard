<?php
require '../config/db.php';
require '../config/auth.php';
require_role('admin');

$otp = trim($_POST['otp'] ?? '');
$id  = (int)($_POST['id'] ?? 0);

if (!isset($_SESSION['delete_otp']) || !isset($_SESSION['otp_time'])) {
  echo json_encode(['success' => false, 'message' => 'OTP not sent. Click Send OTP first.']);
  exit;
}

if (time() - $_SESSION['otp_time'] > 300) {
  unset($_SESSION['delete_otp'], $_SESSION['otp_time']);
  echo json_encode(['success' => false, 'message' => 'OTP expired. Please request a new one.']);
  exit;
}

if ($otp === (string)$_SESSION['delete_otp']) {
  unset($_SESSION['delete_otp'], $_SESSION['otp_time']);
  $del = $conn->prepare("DELETE FROM news WHERE id=?");
  $del->bind_param("i", $id);
  $del->execute();
  echo json_encode(['success' => true]);
} else {
  echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Try again.']);
}
