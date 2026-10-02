<?php
// Выход — только POST с CSRF-токеном: по GET любой сторонний сайт мог выкидывать из панели картинкой.
require __DIR__.'/lib.php';
if($_SERVER['REQUEST_METHOD']==='POST' && crm_csrf_ok()) crm_logout();
header('Location: login.php');
