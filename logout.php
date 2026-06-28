<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';
patient_logout();
redirect(APP_BASE . '/login.php');
