<?php
declare(strict_types=1);

class TechnicalHealthController
{
    private TechnicalHealthModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new TechnicalHealthModel($db);
    }

    public function handle(string $action): void
    {
        if (!in_array($action, ['index', 'show'], true)) {
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => 'Actiune inexistenta',
                'currentPage' => 'stare_tehnica',
            ]);
            return;
        }

        $vehicleId = max(0, (int) ($_GET['vehicle_id'] ?? $_GET['id'] ?? 0));
        $categoryId = max(0, (int) ($_GET['category_id'] ?? 0));

        render('technical_health/index.php', [
            'pageTitle' => 'Stare tehnica vehicul',
            'currentPage' => 'stare_tehnica',
            'technicalData' => $this->model->getPageData($vehicleId, $categoryId),
        ]);
    }
}
