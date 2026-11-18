<?php
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /billing/' . ($query === '' ? '' : '?' . $query), true, 308);
exit;
