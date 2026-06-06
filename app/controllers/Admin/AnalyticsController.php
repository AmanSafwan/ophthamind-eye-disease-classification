<?php

require_once BASE_PATH . '/app/middleware/role_check.php';
require_once __DIR__ . '/../../../config/db.php';

class AnalyticsController
{
    public function index()
    {
        // protect route
        RoleCheck::checkRole('admin');

        // future: analytics data
        // $totalUsers = ...
        // $totalPredictions = ...

        require_once __DIR__ . '/../../views/admin/analytics.php';
    }
}