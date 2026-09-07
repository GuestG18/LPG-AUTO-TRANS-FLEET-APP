<?php
declare(strict_types=1);

/**
 * Controller pentru pagina "Echipamente șoferi" (page=echipamente_soferi).
 *
 * Ecranul principal ține evidența a ceea ce are fiecare șofer plus stocul
 * nealocat; acțiunile POST mută articole între șofer și stoc (predare,
 * returnare, înlocuire, marcaje de stare) și întrețin catalogul.
 */
class DriverEquipmentController
{
    /** Rezultatele căutării pe obiecte se pagineaza, ca să suporte liste mari. */
    private const SEARCH_PER_PAGE = 25;

    private DriverEquipmentModel $model;

    public function __construct(private PDO $db)
    {
        $this->model = new DriverEquipmentModel($db);
    }

    public function handle(string $action): void
    {
        match ($action) {
            'tesa' => $this->indexAction(DriverEquipmentModel::OWNER_STAFF),
            'catalog' => $this->catalogAction(),
            'save_catalog' => $this->saveCatalogAction(),
            'delete_catalog' => $this->deleteCatalogAction(),
            'stoc' => $this->stockAction(),
            'predare' => $this->assignAction(),
            'returnare' => $this->returnAction(),
            'marcheaza' => $this->markAction(),
            'inlocuieste' => $this->replaceAction(),
            'stoc_intrare' => $this->stockInAction(),
            'stoc_iesire' => $this->stockOutAction(),
            'stoc_setari' => $this->stockSettingsAction(),
            'export' => $this->exportAction(),
            'export_stoc' => $this->exportStockAction(),
            default => $this->indexAction(),
        };
    }

    // ------------------------------------------------------------------
    // Ecrane
    // ------------------------------------------------------------------

    /**
     * Ecranul de alocări. Aceeași structură pentru ambele categorii de personal;
     * se schimbă doar cine este deținătorul și ce coloane are tabelul.
     */
    private function indexAction(string $ownerType = DriverEquipmentModel::OWNER_DRIVER): void
    {
        $this->guard('view');

        $isStaff = $ownerType === DriverEquipmentModel::OWNER_STAFF;
        $filters = $this->collectFilters();
        $filters['detinator_tip'] = $ownerType;

        // Două moduri de citire a aceleiași liste:
        //   fără cuvânt cheie  -> orientat pe deținător (ce are șoferul X);
        //   cu cuvânt cheie    -> orientat pe obiect (ce articole se potrivesc).
        // Utilizatorul poate cere explicit gruparea pe deținători în timpul
        // căutării, dar implicit primește rezultatele plate.
        $search = (string) $filters['q'];
        $searchView = (string) ($_GET['vizualizare'] ?? '') === 'detinatori' ? 'detinatori' : 'echipamente';
        $itemSearch = $search !== '' && $searchView === 'echipamente';

        $searchResults = $itemSearch
            ? $this->model->getSearchResults($filters, (int) ($_GET['p'] ?? 1), self::SEARCH_PER_PAGE)
            : null;

        // Catalogul și stocul sunt comune; formularul de predare arată doar
        // destinatarii paginii curente și articolele potrivite lor.
        $data = [
            'pageTitle' => $isStaff ? 'Echipamente TESA' : 'Echipamente șoferi',
            'currentPage' => 'echipamente_soferi',
            'ownerType' => $ownerType,
            'filters' => $filters,
            'kpis' => $this->model->getKpis($ownerType),
            'driverRows' => $itemSearch ? [] : $this->model->getOwnerRows($ownerType, $filters),
            'searchResults' => $searchResults,
            'searchView' => $searchView,
            'stockMap' => $this->model->getStockAvailabilityMap(),
            'catalogItems' => $this->model->getCatalog(true),
            'ownerOptions' => $isStaff ? $this->model->getStaffOptions() : $this->model->getDriverOptions(),
            'driverOptions' => $isStaff ? $this->model->getStaffOptions() : $this->model->getDriverOptions(),
            'monthOptions' => $this->model->getMonthOptions(),
            'categories' => $this->model->getCategories(),
        ];

        if ($isStaff) {
            $data['departments'] = $this->model->getStaffDepartments();
            $data['functions'] = $this->model->getStaffFunctions();
        }

        render($isStaff ? 'echipamente_soferi/tesa.php' : 'echipamente_soferi/index.php', $data);
    }

    private function catalogAction(): void
    {
        $this->guard('manage_catalog');

        $search = trim((string) ($_GET['q'] ?? ''));
        $catalogItems = $this->model->getCatalog(false, $search);

        // Catalogul definește tipul, dar coloana „Stoc” cere un răspuns concret:
        // care sunt bucățile și unde se află (gestiune, mărime, la ce șofer).
        render('echipamente_soferi/catalog.php', [
            'pageTitle' => 'Catalog echipamente șoferi',
            'currentPage' => 'echipamente_soferi',
            'filters' => ['q' => $search],
            'catalogItems' => $catalogItems,
            'catalogBreakdown' => $this->model->getCatalogBreakdown(array_column($catalogItems, 'id')),
            'categories' => $this->model->getCategories(),
            'locations' => $this->model->getLocations(),
        ]);
    }

    /** Ecranul de inventar: ce avem disponibil, nealocat niciunui șofer. */
    private function stockAction(): void
    {
        $this->guard('view');

        $filters = $this->collectStockFilters();
        $stockRows = $this->model->getStockRows($filters);

        render('echipamente_soferi/stoc.php', [
            'pageTitle' => 'Stoc echipamente',
            'currentPage' => 'echipamente_soferi',
            'filters' => $filters,
            'stockRows' => $stockRows,
            'stockSummary' => $this->model->getStockSummary($stockRows),
            'catalogItems' => $this->model->getCatalog(true),
            'locations' => $this->model->getLocations(),
            'categories' => $this->model->getCategories(),
            'movements' => $this->model->getRecentMovements(12),
        ]);
    }

    // ------------------------------------------------------------------
    // Acțiuni
    // ------------------------------------------------------------------

    private function assignAction(): void
    {
        $this->guardPost('manage_assignments');

        $this->run(function (): string {
            $allocationId = $this->model->assign([
                'detinator_tip' => (string) ($_POST['detinator_tip'] ?? DriverEquipmentModel::OWNER_DRIVER),
                'driver_id' => (int) ($_POST['driver_id'] ?? 0),
                'catalog_id' => (int) ($_POST['catalog_id'] ?? 0),
                'cantitate' => (int) ($_POST['cantitate'] ?? 1),
                'marime' => $_POST['marime'] ?? null,
                'identificator' => $_POST['identificator'] ?? null,
                'operator' => $_POST['operator'] ?? null,
                'data_predarii' => $_POST['data_predarii'] ?? null,
                'cost_unitar' => $_POST['cost_unitar'] ?? '',
                'stare' => $_POST['stare'] ?? 'noua',
                'data_expirarii' => $_POST['data_expirarii'] ?? null,
                'observatii' => $_POST['observatii'] ?? null,
            ], ($_POST['sursa'] ?? 'stoc') === 'stoc');

            return 'Echipamentul a fost predat (alocarea #' . $allocationId . ').';
        });
    }

    private function returnAction(): void
    {
        $this->guardPost('manage_assignments');

        $this->run(function (): string {
            $allocationId = (int) ($_POST['alocare_id'] ?? 0);
            $outcome = (string) ($_POST['rezultat'] ?? 'stoc');
            $allocation = $this->model->requireAllocation($allocationId);

            $this->model->returnItem($allocationId, $outcome, (string) ($_POST['observatii'] ?? ''));

            if ($outcome === 'stoc' && (int) $allocation['returnabil'] === 1) {
                return 'Articolul "' . (string) $allocation['denumire'] . '" a fost returnat în stoc.';
            }
            if ($outcome === 'stoc') {
                return 'Articolul "' . (string) $allocation['denumire'] . '" a fost închis (nereturnabil, nu intră în stoc).';
            }

            return 'Articolul "' . (string) $allocation['denumire'] . '" a fost scos din uz și nu a intrat în stocul disponibil.';
        });
    }

    private function markAction(): void
    {
        $this->guardPost('manage_assignments');

        $this->run(function (): string {
            $allocationId = (int) ($_POST['alocare_id'] ?? 0);
            $mark = (string) ($_POST['marcaj'] ?? '');
            $this->model->markAllocation($allocationId, $mark, (string) ($_POST['observatii'] ?? ''));

            return match ($mark) {
                'deteriorat' => 'Articolul a fost marcat ca deteriorat și necesită înlocuire.',
                'de_inlocuit' => 'Articolul a fost marcat pentru înlocuire.',
                'pierdut' => 'Articolul a fost marcat ca pierdut.',
                default => 'Starea articolului a fost actualizată.',
            };
        });
    }

    private function replaceAction(): void
    {
        $this->guardPost('manage_assignments');

        $this->run(function (): string {
            $result = $this->model->replaceFromStock((int) ($_POST['alocare_id'] ?? 0), [
                'catalog_id' => (int) ($_POST['catalog_id'] ?? 0),
                'cantitate' => (int) ($_POST['cantitate'] ?? 1),
                'marime' => $_POST['marime'] ?? null,
                'identificator' => $_POST['identificator'] ?? null,
                'motiv' => $_POST['motiv'] ?? 'deteriorat',
                'data_predarii' => $_POST['data_predarii'] ?? null,
                'observatii' => $_POST['observatii'] ?? null,
            ]);

            $label = $result['marime'] !== ''
                ? $result['denumire'] . '" mărimea ' . $result['marime']
                : $result['denumire'] . '"';

            return 'Înlocuire realizată din stoc pentru "' . $label . '. Stoc rămas: '
                . $result['stoc_ramas'] . ' buc.';
        });
    }

    private function stockInAction(): void
    {
        $this->guardPost('manage_stock');

        $this->run(function (): string {
            $result = $this->model->stockIn([
                'catalog_id' => (int) ($_POST['catalog_id'] ?? 0),
                'cantitate' => (int) ($_POST['cantitate'] ?? 1),
                'marime' => $_POST['marime'] ?? null,
                'cost_unitar' => $_POST['cost_unitar'] ?? '',
                'data_intrarii' => $_POST['data_intrarii'] ?? null,
                'locatie' => $_POST['locatie'] ?? '',
                'furnizor' => $_POST['furnizor'] ?? null,
                'document' => $_POST['document'] ?? null,
                'serie' => $_POST['serie'] ?? null,
                'iccid' => $_POST['iccid'] ?? null,
                'numar_telefon' => $_POST['numar_telefon'] ?? null,
                'operator' => $_POST['operator'] ?? null,
                'tip_abonament' => $_POST['tip_abonament'] ?? 'n/a',
                'cost_lunar' => $_POST['cost_lunar'] ?? null,
                'observatii' => $_POST['observatii'] ?? null,
            ]);

            $message = 'Intrare în stoc: ' . $result['cantitate'] . ' buc.';

            return $result['unitati'] > 0
                ? $message . ' (' . $result['unitati'] . ' unități înregistrate individual).'
                : $message . '.';
        }, ['action' => 'stoc']);
    }

    private function stockOutAction(): void
    {
        $this->guardPost('manage_stock');

        $this->run(function (): string {
            $quantity = $this->model->stockOut([
                'catalog_id' => (int) ($_POST['catalog_id'] ?? 0),
                'unitate_id' => (int) ($_POST['unitate_id'] ?? 0),
                'cantitate' => (int) ($_POST['cantitate'] ?? 1),
                'marime' => $_POST['marime'] ?? null,
                'locatie' => $_POST['locatie'] ?? '',
                'motiv' => $_POST['motiv'] ?? 'corectie',
                'observatii' => $_POST['observatii'] ?? null,
            ]);

            $reason = DriverEquipmentModel::REMOVAL_REASONS[(string) ($_POST['motiv'] ?? '')] ?? 'Corecție inventar';

            return 'Ieșire din stoc: ' . $quantity . ' buc. (' . mb_strtolower($reason) . ').';
        }, ['action' => 'stoc']);
    }

    private function stockSettingsAction(): void
    {
        $this->guardPost('manage_stock');

        $this->run(function (): string {
            $this->model->saveStockSettings((int) ($_POST['stoc_id'] ?? 0), [
                'prag_minim' => $_POST['prag_minim'] ?? 0,
                'cost_unitar' => $_POST['cost_unitar'] ?? 0,
                'utilizabil_inlocuire' => $_POST['utilizabil_inlocuire'] ?? 0,
                'observatii' => $_POST['observatii'] ?? null,
            ]);

            return 'Setările de stoc au fost salvate.';
        }, ['action' => 'stoc']);
    }

    private function saveCatalogAction(): void
    {
        $this->guardPost('manage_catalog');

        $this->run(function (): string {
            $this->model->saveCatalogItem($_POST);

            return 'Articolul din catalog a fost salvat.';
        }, ['action' => 'catalog']);
    }

    private function deleteCatalogAction(): void
    {
        $this->guardPost('manage_catalog');

        $this->run(function (): string {
            $this->model->deleteCatalogItem((int) ($_POST['id'] ?? 0));

            return 'Articolul a fost eliminat din catalog (sau dezactivat dacă are alocări).';
        }, ['action' => 'catalog']);
    }

    private function exportAction(): void
    {
        $this->guard('export');

        $filters = $this->collectFilters();
        $rows = $this->model->getDriverRows($filters);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="echipamente-soferi-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, ['Sofer', 'Echipament', 'Categorie', 'Grupa', 'Cantitate', 'Marime',
            'Identificare', 'Data predarii', 'Cost/buc', 'Valoare', 'Stare', 'Status', 'Termen', 'Returnabil'], ';');

        foreach ($rows as $driver) {
            foreach (array_merge($driver['echipamente_fizice'], $driver['comunicatii']) as $item) {
                fputcsv($output, [
                    $driver['nume'],
                    $item['denumire'],
                    $item['categorie'],
                    $item['grupa'] === 'comunicatii' ? 'Comunicații' : 'Echipament fizic',
                    $item['cantitate'],
                    (string) ($item['marime'] ?? ''),
                    (string) ($item['identificator'] ?? ''),
                    format_date_ro((string) $item['data_predarii']),
                    format_number_ro((float) $item['cost_unitar'], 2),
                    format_number_ro((float) $item['valoare_totala'], 2),
                    DriverEquipmentModel::CONDITIONS[(string) $item['stare']] ?? '',
                    DriverEquipmentModel::STATUSES[(string) $item['status']] ?? '',
                    $item['termen'] !== null ? format_date_ro((string) $item['termen']) : '',
                    (int) $item['returnabil'] === 1 ? 'Da' : 'Nu',
                ], ';');
            }
        }

        fclose($output);
        exit;
    }

    /** Export separat pentru inventar: ce avem, unde și în ce cantitate. */
    private function exportStockAction(): void
    {
        $this->guard('export');

        $rows = $this->model->getStockRows($this->collectStockFilters());

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="stoc-echipamente-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'wb');
        fwrite($output, "ï»¿");

        fputcsv($output, ['Echipament', 'Categorie', 'Tip logic', 'Marime', 'Disponibil', 'Rezervat',
            'Total', 'Prag minim', 'Cost/buc', 'Valoare stoc', 'Locatie', 'Status'], ';');

        foreach ($rows as $row) {
            $sizes = $row['marimi'] !== [] ? $row['marimi'] : [null];

            foreach ($sizes as $size) {
                fputcsv($output, [
                    $row['denumire'],
                    $row['categorie'],
                    DriverEquipmentModel::LOGIC_TYPES[(string) $row['tip_logic']] ?? $row['tip_logic'],
                    $size !== null ? (string) $size['marime'] : '',
                    $size !== null ? (int) $size['disponibil'] : (int) $row['disponibil'],
                    $size !== null ? (int) $size['rezervat'] : (int) $row['rezervat'],
                    $size !== null
                        ? (int) $size['disponibil'] + (int) $size['rezervat']
                        : (int) $row['total'],
                    $size !== null ? (int) $size['prag_minim'] : (int) $row['prag_minim'],
                    format_number_ro((float) $row['cost_unitar'], 2),
                    // Valoarea se raportează pe linia exportată, nu pe articol:
                    // altfel fiecare mărime ar repeta totalul articolului.
                    $row['valoare'] === null
                        ? ''
                        : format_number_ro(
                            ($size !== null ? (int) $size['disponibil'] : (int) $row['disponibil']) * (float) $row['cost_unitar'],
                            2
                        ),
                    $row['locatie'],
                    $row['status']['label'],
                ], ';');
            }
        }

        fclose($output);
        exit;
    }

    // ------------------------------------------------------------------
    // Infrastructură
    // ------------------------------------------------------------------

    /**
     * Rulează o operațiune de scriere și traduce rezultatul într-un mesaj flash,
     * păstrând filtrele curente la redirect.
     */
    private function run(callable $operation, array $extraQuery = []): void
    {
        try {
            flash_set('success', $operation());
        } catch (InvalidArgumentException $exception) {
            flash_set('warning', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[echipamente_soferi] ' . $exception->getMessage());
            flash_set('danger', $exception->getMessage());
        }

        redirect($this->backUrl($extraQuery));
    }

    private function backUrl(array $extraQuery = []): string
    {
        $query = ['page' => 'echipamente_soferi'];

        foreach (['luna', 'categorie', 'tip_activ', 'status', 'returnabil', 'locatie', 'tip', 'q',
                  'vizualizare', 'departament', 'functie'] as $key) {
            $value = trim((string) ($_POST[$key] ?? $_GET[$key] ?? ''));
            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        // Filtrul de șofer vine sub alt nume, ca să nu fie confundat cu șoferul
        // care primește echipamentul în formularul de predare.
        $driverFilter = (int) ($_POST['filtru_driver_id'] ?? $_GET['driver_id'] ?? 0);
        if ($driverFilter > 0) {
            $query['driver_id'] = (string) $driverFilter;
        }

        // Operațiunile pornite din pagina TESA se întorc tot acolo.
        if ($extraQuery === [] && (string) ($_POST['detinator_tip'] ?? '') === DriverEquipmentModel::OWNER_STAFF) {
            $extraQuery = ['action' => 'tesa'];
        }

        $url = build_query_url(array_merge($query, $extraQuery));
        $anchor = trim((string) ($_POST['anchor'] ?? ''));

        return $anchor !== '' ? $url . '#' . $anchor : $url;
    }

    private function collectFilters(): array
    {
        return [
            'luna' => trim((string) ($_GET['luna'] ?? '')),
            'departament' => trim((string) ($_GET['departament'] ?? '')),
            'functie' => trim((string) ($_GET['functie'] ?? '')),
            'driver_id' => (int) ($_GET['driver_id'] ?? 0),
            'categorie' => trim((string) ($_GET['categorie'] ?? '')),
            'tip_activ' => trim((string) ($_GET['tip_activ'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'returnabil' => trim((string) ($_GET['returnabil'] ?? '')),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
    }

    /** Filtrele proprii paginii de stoc (altele decât cele de pe alocări). */
    private function collectStockFilters(): array
    {
        return [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'categorie' => trim((string) ($_GET['categorie'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'locatie' => trim((string) ($_GET['locatie'] ?? '')),
            'tip' => trim((string) ($_GET['tip'] ?? '')),
        ];
    }

    private function guard(string $action): void
    {
        if (function_exists('can') && !can('echipamente_soferi', $action)) {
            access_deny_403();
        }
    }

    private function guardPost(string $action): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'echipamente_soferi']));
        }

        $this->guard($action);
        ensure_csrf_or_redirect(build_query_url(['page' => 'echipamente_soferi']));
    }
}
