<?php

require_once BASE_PATH . '/app/middleware/role_check.php';
require_once __DIR__ . '/../../../config/db.php';

class UserController
{
    public function index()
    {
        // protect route
        RoleCheck::checkRole('admin');

        // future: fetch users from DB
        // $users = ...

        require_once __DIR__ . '/../../views/admin/user_management.php';
    }
}