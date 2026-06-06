<?php

require_once BASE_PATH . '/app/middleware/role_check.php';

class DashboardController
{
    public function index()
    {
        RoleCheck::checkRole('admin');

        require_once __DIR__ . '/../../views/admin/dashboard.php';
    }
}