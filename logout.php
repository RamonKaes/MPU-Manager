<?php
declare(strict_types=1);

require_once __DIR__ . '/logic/request.php';
session_starten();

require_once __DIR__ . '/auth.php';

$username = angemeldeter_benutzer();
auth_log_event('logout', $username);

session_unset();
session_destroy();
// Zum Löschen müssen die Attribute denen beim Setzen entsprechen, sonst
// bleibt das Cookie in manchen Browsern stehen.
setcookie(session_name(), '', ['expires' => time() - 3600] + session_cookie_parameter());

header('Location: login.php');
exit;
