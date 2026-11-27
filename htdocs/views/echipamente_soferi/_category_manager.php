<?php
/**
 * Administrarea categoriilor de echipamente.
 *
 * Categoria nu mai este text liber scris în formularul articolului: se alege
 * dintr-o listă, iar lista se întreține aici. Tot de aici se fixează zona de pe
 * siluetă, pentru categoriile pe care potrivirea automată pe cuvinte-cheie nu le
 * nimerește.
 *
 * Se include din catalog.php, cu $categoryRecords.
 */

$categoryRecords = is_array($categoryRecords ?? null) ? $categoryRecords : [];
$categoryAction = static fn(string $action): string => build_query_url(['page' => 'echipamente_soferi', 'action' => $action]);
?>
<div class="modal fade des-modal" id="desCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">Categorii echipamente</h2>
                    <p>Lista din care se alege categoria unui articol. Zona decide unde se leagă eticheta pe siluetă.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>

            <div class="modal-body">
                <?php // Formular unic: gol = adaugă, completat din tabel = editează. ?>
                <form method="post" action="<?= e($categoryAction('save_categorie')) ?>" class="dcc-form" data-dcc-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="" data-dcc-field="id">

                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="dccName">Denumire</label>
                            <input class="form-control" id="dccName" type="text" name="nume" maxlength="60" required
                                   placeholder="ex. Protecție" data-dcc-field="nume">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="dccZone">Zonă pe siluetă</label>
                            <select class="form-select" id="dccZone" name="zona" data-dcc-field="zona">
                                <option value="">Automat (după denumire)</option>
                                <?php foreach (DriverEquipmentModel::ANCHOR_LABELS as $zone => $label): ?>
                                    <option value="<?= e((string) $zone) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-8 col-md-3">
                            <label class="form-label" for="dccDescription">Descriere</label>
                            <input class="form-control" id="dccDescription" type="text" name="descriere" maxlength="255"
                                   placeholder="opțional" data-dcc-field="descriere">
                        </div>
                        <div class="col-4 col-md-1">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" value="1"
                                       id="dccActive" name="activ" checked data-dcc-field="activ">
                                <label class="form-check-label" for="dccActive">Activă</label>
                            </div>
                        </div>
                    </div>

                    <div class="dcc-form-actions">
                        <button class="des-btn des-btn-sm" type="button" data-dcc-reset hidden>
                            <i class="bi bi-x-lg" aria-hidden="true"></i>Renunță la editare
                        </button>
                        <button class="des-btn des-btn-primary des-btn-sm" type="submit">
                            <i class="bi bi-check2" aria-hidden="true"></i><span data-dcc-submit-label>Adaugă categoria</span>
                        </button>
                    </div>
                </form>

                <div class="des-table-wrap dcc-list">
                    <table class="des-subtable">
                        <thead>
                            <tr>
                                <th>Categorie</th>
                                <th>Zonă pe siluetă</th>
                                <th class="des-col-num">Articole</th>
                                <th>Status</th>
                                <th class="des-col-actions">Acțiuni</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($categoryRecords === []): ?>
                            <tr><td colspan="5" class="des-empty">Nicio categorie definită încă.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($categoryRecords as $record): ?>
                            <tr>
                                <td class="des-item-name">
                                    <span class="des-item-icon"><i class="bi <?= e((string) $record['icon']) ?>" aria-hidden="true"></i></span><?= e((string) $record['nume']) ?>
                                    <?php if (trim((string) ($record['descriere'] ?? '')) !== ''): ?>
                                        <span class="des-note"><?= e((string) $record['descriere']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= e(DriverEquipmentModel::ANCHOR_LABELS[(string) $record['zona_efectiva']] ?? '—') ?>
                                    <?php if (!empty($record['zona_automata'])): ?>
                                        <span class="des-note">automat</span>
                                    <?php endif; ?>
                                </td>
                                <td class="des-col-num"><span class="des-pill"><?= e((string) $record['articole']) ?></span></td>
                                <td>
                                    <span class="des-badge <?= (int) $record['activ'] === 1 ? 'is-success' : 'is-muted' ?>">
                                        <?= (int) $record['activ'] === 1 ? 'Activă' : 'Inactivă' ?>
                                    </span>
                                    <?php if ((int) $record['id'] === 0): ?>
                                        <span class="des-note">nesalvată</span>
                                    <?php endif; ?>
                                </td>
                                <td class="des-col-actions">
                                    <div class="dcc-row-actions">
                                        <button class="des-btn des-btn-sm" type="button"
                                                data-dcc-edit='<?= e(json_encode([
                                                    'id' => (int) $record['id'],
                                                    'nume' => (string) $record['nume'],
                                                    'zona' => (string) ($record['zona'] ?? ''),
                                                    'descriere' => (string) ($record['descriere'] ?? ''),
                                                    'activ' => (int) $record['activ'],
                                                ], JSON_UNESCAPED_UNICODE)) ?>'>
                                            <i class="bi bi-pencil" aria-hidden="true"></i><?= (int) $record['id'] === 0 ? 'Adoptă' : 'Editează' ?>
                                        </button>
                                        <?php if ((int) $record['id'] > 0 && (int) $record['articole'] === 0): ?>
                                            <form method="post" action="<?= e($categoryAction('delete_categorie')) ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $record['id']) ?>">
                                                <button class="des-btn des-btn-sm text-danger" type="submit">
                                                    <i class="bi bi-trash3" aria-hidden="true"></i>Șterge
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="des-callout is-neutral dcc-hint">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <div>
                        <strong>Cum ajunge categoria pe siluetă</strong>
                        <span>Fiecare categorie cu articole primește o etichetă lângă figură. Lăsată pe „Automat”, zona se deduce din denumire; aleasă manual, firul merge exact unde spui.</span>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button class="des-btn" type="button" data-bs-dismiss="modal">Închide</button>
            </div>
        </div>
    </div>
</div>
