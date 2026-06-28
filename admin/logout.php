<?php
require_once __DIR__ . '/../config/session.php';
admin_logout();
redirect(APP_BASE . '/admin/login.php');
