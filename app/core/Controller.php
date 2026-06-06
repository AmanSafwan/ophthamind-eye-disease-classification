<?php

require_once BASE_PATH . '/app/helpers/AuditHelper.php';

class Controller
{
    protected $db;
    protected ?AuditHelper $audit = null;

    public function __construct()
    {
        $this->db = require BASE_PATH . '/config/db.php';

        if (!$this->db instanceof PDO) {
            die('Database not initialized properly.');
        }

        $this->audit = new AuditHelper($this->db);
    }

    protected function auditLog(string $action, array $context = []): void
    {
        $this->audit?->logCurrentUser($action, $context);
    }

    protected function view($view, $data = [])
    {
        if (!empty($data)) extract($data);

        $viewPath = BASE_PATH . "/app/views/" . $view . ".php";

        if (file_exists($viewPath)) {
            require_once $viewPath;
        } else {
            die("View not found: " . $view);
        }
    }

    protected function model($model)
    {
        require_once BASE_PATH . "/app/models/" . $model . ".php";
        return new $model($this->db); // 🔥 IMPORTANT FIX
    }

    protected function redirect($url)
    {
        header("Location: " . $url);
        exit;
    }

    protected function json($data, $httpCode = 200)
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode($data);
        exit;
    }
}