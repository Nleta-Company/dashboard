<?php
/*
 * NLETA INSPECTOR compatibility entry point.
 *
 * Some previously rendered/cached Inspector pages may still point to
 * saf-start-inspection.php. Keep this small wrapper so that old links
 * cannot accidentally open the Technician module.
 */

$query = $_SERVER['QUERY_STRING'] ?? '';

header(
    'Location: start-inspection.php' .
    ($query !== '' ? '?' . $query : '')
);

exit;
?>
