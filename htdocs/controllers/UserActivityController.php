<?php
declare(strict_types=1);

/**
 * Pagina "Activitate utilizatori" — jurnal de audit al actiunilor utilizatorilor.
 * Vizibila doar administratorilor.
 */
class UserActivityController
{
    private UserActivityModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new UserActivityModel($db);
    }

    public function handle(string $action): void
    {
        require_admin_or_403();

        switch ($action) {
            case 'export':
                $this->exportAction();
                return;
            case 'index':
            case 'list':
            default:
                $this->indexAction();
                return;
        }
    }

    private function indexAction(): void
    {
        $filters = $this->filtersFromRequest();

        try {
            $data = $this->model->getDashboard($filters);
        } catch (Throwable $exception) {
            error_log('[UserActivityController][index] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut încărca activitatea utilizatorilor.');
            $data = [
                'filters'       => $filters,
                'rows'          => [],
                'rowsTotal'     => 0,
                'timelineLimit' => 0,
                'kpis'          => [],
                'topUsers'      => [],
                'distribution'  => ['total' => 0, 'items' => []],
                'userOptions'   => [],
                'moduleOptions' => [],
                'updatedAt'     => date('Y-m-d H:i:s'),
            ];
        }

        render('activitate_utilizatori/index.php', array_merge($data, [
            'pageTitle'   => 'Activitate utilizatori',
            'currentPage' => 'activitate_utilizatori',
            'subtitle'    => 'Jurnal de audit pentru acțiunile utilizatorilor aplicației — creări, modificări, ștergeri și autentificări, pe module.',
        ]));
    }

    private function exportAction(): void
    {
        $filters = $this->filtersFromRequest();

        try {
            $rows = $this->model->getExportRows($filters);
        } catch (Throwable $exception) {
            error_log('[UserActivityController][export] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut genera exportul.');
            redirect(build_query_url(['page' => 'activitate_utilizatori']));
        }

        $actionLabels = [
            'create'  => 'Creare',
            'update'  => 'Modificare',
            'delete'  => 'Ștergere',
            'restore' => 'Restaurare',
            'status'  => 'Status',
            'login'   => 'Autentificare',
        ];

        $filename = 'activitate_utilizatori_' . $filters['date_start'] . '_' . $filters['date_end'] . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'wb');
        // BOM UTF-8 pentru Excel
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Data', 'Utilizator', 'Rol', 'Modul', 'Actiune', 'Descriere', 'Inregistrare']);

        foreach ($rows as $row) {
            fputcsv($out, [
                (string) ($row['ts'] ?? ''),
                (string) ($row['user_name'] ?? ''),
                function_exists('role_display_name') ? role_display_name((string) ($row['user_role'] ?? '')) : (string) ($row['user_role'] ?? ''),
                (string) ($row['module_label'] ?? ''),
                $actionLabels[(string) ($row['action_key'] ?? '')] ?? (string) ($row['action_key'] ?? ''),
                (string) ($row['description'] ?? ''),
                $row['record_id'] !== null ? ('#' . (int) $row['record_id']) : '',
            ]);
        }

        fclose($out);
        exit;
    }

    private function filtersFromRequest(): array
    {
        return [
            'date_start' => (string) ($_GET['date_start'] ?? ''),
            'date_end'   => (string) ($_GET['date_end'] ?? ''),
            'user_id'    => (int) ($_GET['user'] ?? 0),
            'module'     => (string) ($_GET['module'] ?? ''),
            'action'     => (string) ($_GET['action_type'] ?? ''),
            'search'     => (string) ($_GET['q'] ?? ''),
        ];
    }
}
