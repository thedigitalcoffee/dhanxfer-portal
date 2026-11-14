<?php
require __DIR__ . '/inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (me()) audit('logout', 'user', me()['id']);
    session_unset();
    session_destroy();
}
redirect('/login.php');
