<?php

function routes($router)
{
    // ================= PUBLIC =================
    $router->get('login', function () {
        require_once __DIR__ . '/../app/controllers/Auth/LoginController.php';
        (new LoginController())->index();
    });

    $router->post('login', function () {
        require_once __DIR__ . '/../app/controllers/Auth/LoginController.php';
        (new LoginController())->login();
    });

    $router->get('landing', function () {
        require BASE_PATH . '/app/views/auth/landing.php';
    });

    $router->get('/', function () {
        require BASE_PATH . '/app/views/auth/landing.php';
    });

    $router->post('register', function () {
        require_once __DIR__ . '/../app/controllers/Auth/RegisterController.php';
        (new RegisterController())->register();
    });


    // ================= ADMIN =================
    $router->get('admin/dashboard', function () {
        require_once __DIR__ . '/../app/controllers/Admin/DashboardController.php';
        (new DashboardController())->index();
    });

    $router->get('admin/user_management', function () {
        require_once __DIR__ . '/../app/controllers/Admin/UserController.php';
        (new UserController())->index();
    });

    $router->get('admin/analytics', function () {
        require_once __DIR__ . '/../app/controllers/Admin/AnalyticsController.php';
        (new AnalyticsController())->index();
    });


    // ============== OPHTHALMOLOGIST UI ==============
    $router->get('ophthalmologist/dashboard', function () {
        require_once __DIR__ . '/../app/controllers/Ophthalmologist/DashboardController.php';
        (new DashboardController())->index();
    });

    $router->get('ophthalmologist/dashboardSummary', 'Ophthalmologist\\DashboardController@summary');
    $router->get('ophthalmologist/dashboardCharts', 'Ophthalmologist\\DashboardController@charts');
    $router->get('ophthalmologist/dashboardAnalytics', 'Ophthalmologist\\DashboardController@analytics');
    $router->get('ophthalmologist/dashboardAiPing', 'Ophthalmologist\\DashboardController@aiPing');

    $router->get('ophthalmologist/patient', function () {
        require_once __DIR__ . '/../app/controllers/Ophthalmologist/PatientController.php';
        (new PatientController())->index();
    });

    $router->get('ophthalmologist/predict', function () {
        require_once __DIR__ . '/../app/controllers/Ophthalmologist/PredictController.php';
        (new PredictController())->index();
    });

    $router->get('ophthalmologist/history', function () {
        require_once __DIR__ . '/../app/controllers/Ophthalmologist/HistoryController.php';
        (new HistoryController())->index();
    });


    // ================= API (PREDICT SYSTEM) =================

    // patient check + register
    $router->post('ophthalmologist/patientData', 'Ophthalmologist\\PatientController@data');
    $router->get('ophthalmologist/patientGet', 'Ophthalmologist\\PatientController@get');
    $router->post('ophthalmologist/patientUpdate', 'Ophthalmologist\\PatientController@update');
    $router->post('ophthalmologist/patientDelete', 'Ophthalmologist\\PatientController@delete');
    $router->post('ophthalmologist/checkIC', 'Ophthalmologist\\PredictController@checkIC');
    $router->post('ophthalmologist/register', 'Ophthalmologist\\PredictController@register');

    // prediction core
    $router->post('ophthalmologist/predict', 'Ophthalmologist\\PredictController@predict');

    // history
    $router->get('ophthalmologist/getPredictions', 'Ophthalmologist\\PredictController@getPredictions');
    $router->get('ophthalmologist/getPredictionDetail', 'Ophthalmologist\\PredictController@getPredictionDetail');
    $router->get('ophthalmologist/exportPDF', 'Ophthalmologist\\PredictController@exportPredictionPDF');

    // actions
    $router->post('ophthalmologist/rerunPrediction', 'Ophthalmologist\\PredictController@rerunPrediction');
    $router->post('ophthalmologist/deletePrediction', 'Ophthalmologist\\PredictController@deletePrediction');

    $router->get('ophthalmologist/aiStatus', 'Ophthalmologist\\PredictController@aiStatus');
}