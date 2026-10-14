<?php
include('../config/db.php');
include('../config/auth.php');

require_role('technician');

die("Direct close is disabled. Please submit report to close the callback.");
