<?php

class LandingController extends Controller
{
    public function index()
    {
        // kalau dah login → redirect terus dashboard
        if (isset($_SESSION['user'])) {
            header("Location: " . BASE_URL . "/dashboard");
            exit;
        }

        $data = [
            'page_title' => 'Welcome to OphthaMind AI'
        ];

        $this->view('auth/landing', $data);
    }
}