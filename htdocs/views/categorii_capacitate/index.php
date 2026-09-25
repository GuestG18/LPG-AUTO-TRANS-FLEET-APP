<?php
/**
 * Pagina "Categorii capacitate".
 *
 * Categoria este DOAR o eticheta de grupare pentru selectoarele de vehicule.
 * Capacitatea tehnica reala sta in fisa vehiculului si este singura folosita
 * in calcule. Pagina are trei parti:
 *   1. formular + lista categoriilor (cu numarul de vehicule pe fiecare);
 *   2. raportul "capacitati reale de verificat", cu dovezile din curse;
 *   3. jurnalul ultimelor modificari de capacitate / categorie.
 */
$categories = is_array($categories ?? null) ? $categories : [];
$migrationReport = is_array($migrationReport ?? null) ? $migrationReport : [];
$verificationRows = is_array($verificationRows ?? null) ? $verificationRows : [];
$verificationSummary = is_array($verificationSummary ?? null) ? $verificationSummary : [];
$auditTrail = is_array($auditTrail ?? null) ? $auditTrail : [];
$formData = is_array($formData ?? null) ? $formData : [];
$formErrors = is_array($formErrors ?? null) ? $formErrors : [];
$editingId = (int) ($editingId ?? 0);
$canManage = !empty($canManage);

$pageUrl = build_query_url(['page' => 'categorii_capacitate']);
$field = static fn(string $key): string => (string) ($formData[$key] ?? '');
$invalid = static fn(string $key): string => isset($formErrors[$key]) ? ' is-invalid' : '';
$isActiveForm = $formData === [] || (string) ($formData['activ'] ?? '1') === '1';
$tons = static function (mixed $value): string {
    if ($value === null || $value === '' || (float) $value <= 0) {
        return '-';
    }

    return format_number_ro((float) $value, 2) . ' t';
};
$priorityBadge = [
    'contrazisa' => ['danger', 'Contrazisa de curse'],
    'lipsa' => ['secondary', 'Fara capacitate'],
    'de_confirmat' => ['warning', 'De confirmat'],
];
?>

<div class="capacity-categories-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h2 class="h4 mb-1">Categorii capacitate</h2>
            <p class="text-muted mb-0">
                Etichetele dupa care se grupeaza vehiculele in selectoare (Configurare transport, Carburanti, filtre).
                <strong>Categoria este doar o grupare.</strong> Capacitatea tehnica reala se editeaza in fisa vehiculului
                si este singura folosita in calcule (grad de umplere, validarea incarcarii, KPI).
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'vehicule_grele'])) ?>">
            <i class="bi bi-truck" aria-hidden="true"></i>
            Vehicule grele
        </a>
    </div>

    <div class="alert alert-info d-flex gap-2 align-items-start">
        <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
        <div class="small">
            Doua vehicule din aceeasi categorie pot avea capacitati reale diferite &mdash; exact asta este scopul separarii.
            Numarul din numele unei categorii („18.5 TONE”) nu este citit niciodata ca fiind capacitatea unui vehicul.
        </div>
    </div>

    <div class="row g-3 align-items-start">
        <?php if ($canManage): ?>
            <div class="col-12 col-xl-4">
                <div class="card border-0 shadow-sm" id="formular-categorie">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h3 class="h6 mb-0"><?= $editingId > 0 ? 'Editeaza categoria' : 'Adauga categorie' ?></h3>
                        <?php if ($editingId > 0): ?>
                            <a class="small" href="<?= e($pageUrl) ?>">Anuleaza editarea</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form method="post" action="<?= e(build_query_url(['page' => 'categorii_capacitate', 'action' => $editingId > 0 ? 'update' : 'store'])) ?>" novalidate>
                            <?= csrf_field() ?>
                            <?php if ($editingId > 0): ?>
                                <input type="hidden" name="id" value="<?= e((string) $editingId) ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label" for="cap_cat_nume">Nume categorie <span class="text-danger">*</span></label>
                                <input type="text" class="form-control<?= $invalid('nume') ?>" id="cap_cat_nume" name="nume" maxlength="80" value="<?= e($field('nume')) ?>" placeholder="Ex: 18.5 TONE" required>
                                <?php if (isset($formErrors['nume'])): ?><div class="invalid-feedback"><?= e($formErrors['nume']) ?></div><?php endif; ?>
                                <div class="form-text">Text liber: „18.5 TONE”, „7+”, „Capacitate mare”. Redenumirea pastreaza vehiculele asignate.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="cap_cat_descriere">Descriere</label>
                                <input type="text" class="form-control<?= $invalid('descriere') ?>" id="cap_cat_descriere" name="descriere" maxlength="255" value="<?= e($field('descriere')) ?>">
                                <?php if (isset($formErrors['descriere'])): ?><div class="invalid-feedback"><?= e($formErrors['descriere']) ?></div><?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="cap_cat_ordine">Ordine de afisare</label>
                                <input type="number" min="0" step="1" class="form-control<?= $invalid('ordine_afisare') ?>" id="cap_cat_ordine" name="ordine_afisare" value="<?= e($field('ordine_afisare') !== '' ? $field('ordine_afisare') : '0') ?>">
                                <?php if (isset($formErrors['ordine_afisare'])): ?><div class="invalid-feedback"><?= e($formErrors['ordine_afisare']) ?></div><?php endif; ?>
                                <div class="form-text">Numar mai mic = mai sus in dropdown-uri.</div>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="cap_cat_activ" name="activ" value="1" <?= $isActiveForm ? 'checked' : '' ?>>
                                <label class="form-check-label" for="cap_cat_activ">Categorie activa</label>
                                <div class="form-text">Categoriile inactive nu mai apar in fisa vehiculului, dar vehiculele deja asignate raman asignate.</div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <?= $editingId > 0 ? 'Salveaza modificarile' : 'Adauga categoria' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-12 <?= $canManage ? 'col-xl-8' : '' ?>">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h3 class="h6 mb-0">Categorii (<?= e((string) count($categories)) ?>)</h3>
                    <span class="text-muted small">
                        Migrare: <?= e((string) ($migrationReport['vehicule_procesate'] ?? 0)) ?> vehicule procesate,
                        <?= e((string) ($migrationReport['vehicule_asignate'] ?? 0)) ?> asignate,
                        <?= e((string) ($migrationReport['capacitati_confirmate'] ?? 0)) ?> capacitati confirmate.
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Categorie</th>
                                <th>Descriere</th>
                                <th class="text-end">Ordine</th>
                                <th class="text-end">Vehicule</th>
                                <th>Stare</th>
                                <?php if ($canManage): ?><th class="text-end">Actiuni</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($categories === []): ?>
                            <tr><td colspan="<?= $canManage ? 6 : 5 ?>" class="text-muted">Nu exista inca nicio categorie.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($categories as $category): ?>
                            <tr>
                                <td class="fw-semibold"><?= e((string) $category['nume']) ?></td>
                                <td class="text-muted small"><?= e((string) ($category['descriere'] ?? '')) ?></td>
                                <td class="text-end"><?= e((string) $category['ordine_afisare']) ?></td>
                                <td class="text-end">
                                    <?= e((string) $category['vehicule']) ?>
                                    <?php if ((int) $category['vehicule'] !== (int) $category['vehicule_active']): ?>
                                        <span class="text-muted small">(<?= e((string) $category['vehicule_active']) ?> active)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($category['activ']): ?>
                                        <span class="badge bg-success-subtle text-success-emphasis">Activa</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canManage): ?>
                                    <td class="text-end">
                                        <div class="d-flex gap-2 justify-content-end align-items-center">
                                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'categorii_capacitate', 'edit' => (int) $category['id']])) ?>#formular-categorie">Editeaza</a>
                                            <form method="post" action="<?= e(build_query_url(['page' => 'categorii_capacitate', 'action' => 'delete'])) ?>" class="d-flex gap-1 align-items-center" onsubmit="return confirm('Stergi categoria &quot;<?= e((string) $category['nume']) ?>&quot;? Capacitatile reale ale vehiculelor nu se modifica.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $category['id']) ?>">
                                                <?php if ((int) $category['vehicule'] > 0): ?>
                                                    <select class="form-select form-select-sm" name="reassign_to" aria-label="Muta vehiculele in">
                                                        <option value="">-- Muta vehiculele in... --</option>
                                                        <option value="0">Fara categorie</option>
                                                        <?php foreach ($categories as $target): ?>
                                                            <?php if ((int) $target['id'] === (int) $category['id']) { continue; } ?>
                                                            <option value="<?= e((string) $target['id']) ?>"><?= e((string) $target['nume']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Sterge</button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white">
            <h3 class="h6 mb-1">Capacitati reale de verificat (<?= e((string) ($verificationSummary['total'] ?? 0)) ?>)</h3>
            <p class="text-muted small mb-0">
                Vehiculele a caror capacitate stocata provine din perioada in care campul servea si la grupare, deci nu poate
                fi considerata capacitate tehnica confirmata. Aplicatia <strong>nu ghiceste</strong> capacitatea din numele
                categoriei: mai jos sunt doar dovezile din curse, ca sa poti corecta valoarea in fisa vehiculului.
                Din
                <?= e((string) ($verificationSummary['contrazise_de_curse'] ?? 0)) ?> sunt contrazise de curse reale,
                <?= e((string) ($verificationSummary['fara_capacitate'] ?? 0)) ?> nu au deloc capacitate,
                <?= e((string) ($verificationSummary['doar_de_confirmat'] ?? 0)) ?> asteapta doar confirmarea.
            </p>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Vehicul</th>
                        <th>Categorie</th>
                        <th class="text-end">Capacitate stocata</th>
                        <th class="text-end">Max. transportat (curse)</th>
                        <th class="text-end">Curse</th>
                        <th>Prioritate</th>
                        <th class="text-end">Actiune</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($verificationRows === []): ?>
                    <tr><td colspan="7" class="text-muted">Toate capacitatile reale sunt verificate.</td></tr>
                <?php endif; ?>
                <?php foreach ($verificationRows as $row): ?>
                    <?php [$badgeClass, $badgeLabel] = $priorityBadge[$row['prioritate']] ?? ['secondary', '-']; ?>
                    <tr>
                        <td>
                            <span class="fw-semibold"><?= e((string) $row['nr_inmatriculare']) ?></span>
                            <div class="text-muted small"><?= e(trim((string) $row['marca'] . ' ' . (string) $row['model'])) ?> &middot; <?= e(vehicle_type_label((string) $row['tip_vehicul'])) ?><?= (string) $row['status'] !== 'activ' ? ' &middot; inactiv' : '' ?></div>
                        </td>
                        <td><?= $row['categorie'] !== null ? e((string) $row['categorie']) : '<span class="text-muted">-</span>' ?></td>
                        <td class="text-end"><?= e($tons($row['capacitate_transport'])) ?></td>
                        <td class="text-end <?= $row['prioritate'] === 'contrazisa' ? 'text-danger fw-semibold' : '' ?>"><?= e($tons($row['tone_max_inregistrate'])) ?></td>
                        <td class="text-end"><?= e((string) $row['curse']) ?></td>
                        <td><span class="badge bg-<?= e($badgeClass) ?>-subtle text-<?= e($badgeClass) ?>-emphasis"><?= e($badgeLabel) ?></span></td>
                        <td class="text-end">
                            <?php
                            // Raportul contine doar vehicule de marfa, deci toate stau
                            // sub ruta "Vehicule grele"; fisa este singurul loc de unde
                            // se poate corecta capacitatea reala.
                            $vehicleRoute = in_array((string) $row['tip_vehicul'], ['autovehicul', 'autoutilitara'], true)
                                ? 'vehicule_usoare'
                                : 'vehicule_grele';
                            ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => $vehicleRoute, 'action' => 'edit', 'id' => (int) $row['id']])) ?>">Corecteaza capacitatea</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($auditTrail !== []): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white">
                <h3 class="h6 mb-1">Ultimele modificari de capacitate / categorie</h3>
                <p class="text-muted small mb-0">Jurnal de audit. Snapshot-ul dinainte de migrare este pastrat separat si permite revenirea la valorile initiale.</p>
            </div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Cand</th>
                            <th>Vehicul</th>
                            <th>Capacitate reala</th>
                            <th>Categorie</th>
                            <th>Verificare</th>
                            <th>Cine</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($auditTrail as $entry): ?>
                        <tr>
                            <td class="text-muted small"><?= e(format_datetime_ro((string) $entry['created_at'])) ?></td>
                            <td><?= e((string) $entry['nr_inmatriculare']) ?></td>
                            <td>
                                <?php if ((string) $entry['capacitate_veche'] === (string) $entry['capacitate_noua']): ?>
                                    <span class="text-muted">neschimbata (<?= e($tons($entry['capacitate_noua'])) ?>)</span>
                                <?php else: ?>
                                    <?= e($tons($entry['capacitate_veche'])) ?> &rarr; <strong><?= e($tons($entry['capacitate_noua'])) ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((string) ($entry['categorie_veche'] ?? '') === (string) ($entry['categorie_noua'] ?? '')): ?>
                                    <span class="text-muted">neschimbata</span>
                                <?php else: ?>
                                    <?= e((string) ($entry['categorie_veche'] ?? '-')) ?> &rarr; <strong><?= e((string) ($entry['categorie_noua'] ?? '-')) ?></strong>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $entry['confirmata_noua'] === 1 ? 'verificata' : 'de verificat' ?></td>
                            <td class="text-muted small"><?= e((string) ($entry['utilizator'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
