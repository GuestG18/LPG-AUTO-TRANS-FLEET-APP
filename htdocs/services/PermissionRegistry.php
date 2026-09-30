<?php
declare(strict_types=1);

/**
 * Registrul central de permisiuni (module / pagini + actiuni).
 *
 * Fiecare modul ACL isi declara singur metadatele intr-un fisier propriu din
 * permissions/modules/<cheie>.php (sau prin PermissionRegistry::register()).
 * Pagina "Drepturi de acces", garda din router si can() citesc DOAR de aici —
 * nimic nu mai trebuie adaugat manual in pagina de drepturi.
 *
 * Chei stabile:
 *   - cheia modulului (ex. 'dispecer_curse') si cheia actiunii (ex. 'edit')
 *     formeaza cheia permisiunii 'dispecer_curse.edit', salvata in access_permissions.
 *   - Eticheta, descrierea, sectiunea, grupul si iconita se pot schimba oricand
 *     fara sa afecteze drepturile salvate.
 *
 * Formatul unui modul: vezi permissions/README.md.
 */
final class PermissionRegistry
{
    public const VIEW = 'view';
    public const DEFAULT_GROUP = 'general';
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    private const SCOPES = ['all', 'accountancy', 'admin'];

    private static ?self $instance = null;

    /** @var list<array<string,mixed>> module inregistrate din cod, in afara fisierelor */
    private static array $registered = [];

    private string $baseDir;
    private bool $loaded = false;

    /** @var array<string,array{key:string,label:string,icon:string,order:int,declared:bool}> */
    private array $sections = [];

    /** @var array<string,array<string,mixed>> */
    private array $modules = [];

    /** @var array<string,list<string>> ruta ?page=... -> chei de modul */
    private array $routeMap = [];

    /** @var list<string> */
    private array $errors = [];

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = rtrim($baseDir ?? dirname(__DIR__) . '/permissions', '/\\');
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Inregistrare programatica a unui modul (alternativa la fisierul din
     * permissions/modules). Trebuie apelata inainte de prima citire a registrului.
     *
     * @param array<string,mixed> $module
     */
    public static function register(array $module): void
    {
        self::$registered[] = $module;
        if (self::$instance !== null) {
            self::$instance->loaded = false;
        }
    }

    /** Doar pentru teste: uita instanta si modulele inregistrate programatic. */
    public static function reset(): void
    {
        self::$instance = null;
        self::$registered = [];
    }

    // ------------------------------------------------------------------ citire

    /** @return array<string,array{key:string,label:string,icon:string,order:int,declared:bool}> */
    public function sections(): array
    {
        $this->load();

        return $this->sections;
    }

    /**
     * Modulele, ordonate dupa sectiune, ordine si eticheta.
     *
     * @return array<string,array<string,mixed>>
     */
    public function modules(): array
    {
        $this->load();

        return $this->modules;
    }

    /** @return array<string,mixed>|null */
    public function module(string $key): ?array
    {
        $this->load();

        return $this->modules[$key] ?? null;
    }

    /** @return array<string,mixed>|null metadatele unei actiuni */
    public function action(string $moduleKey, string $actionKey): ?array
    {
        $this->load();

        return $this->modules[$moduleKey]['actions'][$actionKey] ?? null;
    }

    public function has(string $moduleKey, string $actionKey = self::VIEW): bool
    {
        return $this->action($moduleKey, $actionKey) !== null;
    }

    public function isAdminOnly(string $moduleKey, string $actionKey): bool
    {
        return (bool) ($this->action($moduleKey, $actionKey)['admin_only'] ?? false);
    }

    public static function permissionKey(string $moduleKey, string $actionKey): string
    {
        return $moduleKey . '.' . $actionKey;
    }

    /**
     * Cheile de modul care guverneaza o ruta ?page=... (de obicei una singura;
     * o ruta comuna, ex. 'vehicule', poate apartine mai multor module).
     *
     * @return list<string>
     */
    public function modulesForRoute(string $route): array
    {
        $this->load();

        return $this->routeMap[$route] ?? [];
    }

    /**
     * Actiunea ACL ceruta de un endpoint (?action=...) al modulului, sau null
     * daca endpoint-ul cere doar accesul la pagina.
     */
    public function endpointAction(string $moduleKey, string $routeAction): ?string
    {
        $this->load();

        return $this->modules[$moduleKey]['endpoints'][$routeAction] ?? null;
    }

    /**
     * Lista plata a permisiunilor, pentru sincronizarea catalogului din BD.
     *
     * @return list<array{permission_key:string,module_key:string,action_key:string,module_label:string,label:string,section_key:string,action_group:string,admin_only:int,sort_order:int}>
     */
    public function flatPermissions(): array
    {
        $rows = [];
        $sort = 0;
        foreach ($this->modules() as $moduleKey => $module) {
            foreach ($module['actions'] as $actionKey => $action) {
                $rows[] = [
                    'permission_key' => self::permissionKey($moduleKey, $actionKey),
                    'module_key'     => $moduleKey,
                    'action_key'     => $actionKey,
                    'module_label'   => (string) $module['label'],
                    'label'          => (string) $action['label'],
                    'section_key'    => (string) $module['section'],
                    'action_group'   => (string) $action['group'],
                    'admin_only'     => $action['admin_only'] ? 1 : 0,
                    'sort_order'     => ++$sort,
                ];
            }
        }

        return $rows;
    }

    /**
     * Forma istorica a catalogului (['groups' => ..., 'pages' => ...]) folosita de
     * permission_catalog() / permission_pages() — pastrata pentru compatibilitate.
     *
     * @return array{groups:array<string,array<string,mixed>>,pages:array<string,array<string,mixed>>}
     */
    public function legacyCatalog(): array
    {
        $groups = [];
        foreach ($this->sections() as $key => $section) {
            $groups[$key] = ['label' => $section['label'], 'icon' => $section['icon']];
        }
        $pages = [];
        foreach ($this->modules() as $key => $module) {
            $page = $module;
            $page['group'] = $module['section'];
            $pages[$key] = $page;
        }

        return ['groups' => $groups, 'pages' => $pages];
    }

    /** @return list<string> probleme gasite la incarcare (module invalide, chei duplicate) */
    public function errors(): array
    {
        $this->load();

        return $this->errors;
    }

    // --------------------------------------------------------------- incarcare

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $this->sections = [];
        $this->modules = [];
        $this->routeMap = [];
        $this->errors = [];

        $sectionsFile = $this->baseDir . '/sections.php';
        $declared = is_file($sectionsFile) ? (require $sectionsFile) : [];
        foreach ((array) $declared as $key => $section) {
            $key = (string) $key;
            if (!preg_match(self::KEY_PATTERN, $key) || !is_array($section)) {
                $this->errors[] = "Secțiune invalidă: {$key}";
                continue;
            }
            $this->sections[$key] = [
                'key'      => $key,
                'label'    => (string) ($section['label'] ?? $key),
                'icon'     => (string) ($section['icon'] ?? 'bi-folder'),
                'order'    => (int) ($section['order'] ?? 1000),
                'declared' => true,
            ];
        }

        $definitions = [];
        $files = glob($this->baseDir . '/modules/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $data = require $file;
            if (!is_array($data)) {
                $this->errors[] = 'Fișier de permisiuni invalid: ' . basename($file);
                continue;
            }
            // un fisier poate declara un modul sau o lista de module
            foreach (array_is_list($data) ? $data : [$data] as $definition) {
                $definitions[] = [$definition, basename($file)];
            }
        }
        foreach (self::$registered as $definition) {
            $definitions[] = [$definition, 'register()'];
        }

        foreach ($definitions as [$definition, $source]) {
            $module = is_array($definition) ? $this->normalizeModule($definition, $source) : null;
            if ($module === null) {
                continue;
            }
            if (isset($this->modules[$module['key']])) {
                $this->errors[] = "Modul duplicat „{$module['key']}” ({$source}) — ignorat.";
                continue;
            }
            if (!isset($this->sections[$module['section']])) {
                $this->sections[$module['section']] = [
                    'key'      => $module['section'],
                    'label'    => ucfirst(str_replace('_', ' ', $module['section'])),
                    'icon'     => 'bi-folder',
                    'order'    => 10000,
                    'declared' => false,
                ];
            }
            $this->modules[$module['key']] = $module;
        }

        uasort($this->sections, static fn(array $a, array $b): int => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);
        $sectionOrder = array_flip(array_keys($this->sections));
        uasort($this->modules, static fn(array $a, array $b): int =>
            [$sectionOrder[$a['section']], $a['order'], $a['label']] <=> [$sectionOrder[$b['section']], $b['order'], $b['label']]);

        foreach ($this->modules as $key => $module) {
            foreach ($module['routes'] as $route) {
                $this->routeMap[$route][] = $key;
            }
        }

        foreach ($this->errors as $error) {
            error_log('[PermissionRegistry] ' . $error);
        }
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    private function normalizeModule(array $raw, string $source): ?array
    {
        $key = (string) ($raw['key'] ?? '');
        if (!preg_match(self::KEY_PATTERN, $key)) {
            $this->errors[] = "Modul fără cheie validă ({$source}).";
            return null;
        }

        $scope = (string) ($raw['scope'] ?? 'all');
        if (!in_array($scope, self::SCOPES, true)) {
            $this->errors[] = "Modul „{$key}”: scope necunoscut „{$scope}”, folosesc 'admin'.";
            $scope = 'admin'; // fail-closed
        }

        $groups = [];
        foreach ((array) ($raw['groups'] ?? []) as $groupKey => $groupLabel) {
            $groups[(string) $groupKey] = (string) $groupLabel;
        }

        // admin_only la nivel de modul = toata pagina ramane doar a adminului
        $moduleAdminOnly = ($raw['admin_only'] ?? false) === true;

        $actions = [];
        $rawActions = (array) ($raw['actions'] ?? []);
        if (!isset($rawActions[self::VIEW])) {
            // accesul la pagina exista mereu, chiar daca modulul nu il declara
            $rawActions = [self::VIEW => ['label' => 'Acces pagină']] + $rawActions;
        }
        foreach ($rawActions as $actionKey => $meta) {
            $actionKey = (string) $actionKey;
            if (!preg_match(self::KEY_PATTERN, $actionKey)) {
                $this->errors[] = "Modul „{$key}”: acțiune cu cheie invalidă „{$actionKey}” — ignorată.";
                continue;
            }
            $meta = is_array($meta) ? $meta : ['label' => (string) $meta];
            $group = $actionKey === self::VIEW ? '' : (string) ($meta['group'] ?? self::DEFAULT_GROUP);
            if ($group !== '' && !isset($groups[$group])) {
                $groups[$group] = $group === self::DEFAULT_GROUP ? 'Acțiuni' : ucfirst(str_replace('_', ' ', $group));
            }
            $adminOnly = $moduleAdminOnly || (($meta['admin_only'] ?? false) === true);
            // 'admin' / 'accountancy' = numele istorice ale flag-urilor default_*
            $defaultAdmin = !$adminOnly && (($meta['default_admin'] ?? $meta['admin'] ?? false) === true);
            $defaultAccountancy = ($meta['default_accountancy'] ?? $meta['accountancy'] ?? false) === true;
            $actions[$actionKey] = [
                'key'                 => $actionKey,
                'label'               => (string) ($meta['label'] ?? $actionKey),
                'group'               => $group,
                'admin_only'          => $adminOnly,
                'default_admin'       => $defaultAdmin,
                'default_accountancy' => $defaultAccountancy,
                'sensitive'           => ($meta['sensitive'] ?? false) === true,
                // alias-uri citite de codul mai vechi
                'admin'               => $adminOnly || $defaultAdmin,
                'accountancy'         => $defaultAccountancy,
            ];
        }
        // pastreaza doar grupurile folosite, in ordinea declarata
        $usedGroups = array_flip(array_filter(array_column($actions, 'group')));
        $groups = array_intersect_key($groups, $usedGroups);

        $routes = array_values(array_unique(array_map('strval', array_merge([$key], (array) ($raw['routes'] ?? [])))));

        $endpoints = [];
        foreach ((array) ($raw['endpoints'] ?? []) as $routeAction => $actionKey) {
            $actionKey = (string) $actionKey;
            if (!isset($actions[$actionKey])) {
                $this->errors[] = "Modul „{$key}”: endpoint „{$routeAction}” cere acțiunea nedeclarată „{$actionKey}” — ignorat.";
                continue;
            }
            $endpoints[(string) $routeAction] = $actionKey;
        }

        return [
            'key'         => $key,
            'label'       => (string) ($raw['label'] ?? $key),
            'description' => (string) ($raw['description'] ?? ''),
            'section'     => preg_match(self::KEY_PATTERN, (string) ($raw['section'] ?? '')) ? (string) $raw['section'] : 'altele',
            'icon'        => (string) ($raw['icon'] ?? 'bi-file-earmark'),
            'order'       => (int) ($raw['order'] ?? 1000),
            'scope'       => $scope,
            'admin_only'  => $moduleAdminOnly,
            'routes'      => $routes,
            'groups'      => $groups,
            'actions'     => $actions,
            'endpoints'   => $endpoints,
            'source'      => $source,
        ];
    }
}
