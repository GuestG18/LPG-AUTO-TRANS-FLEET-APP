<?php
/**
 * Modalul „Simulare calcul revizie” (comun). Logica: assets/js/revizii-common.js.
 * Asteapta: $vehicles, $simTab (categoria preselectata), $simDefaultInterval, $defaultWarn,
 * $factor, $hourSource.
 */
$simHourSources = ReviziiModel::HOUR_SOURCES;
?>
<div class="modal fade" id="rvSimModal" tabindex="-1" aria-labelledby="rvSimTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rvSimTitle"><i class="bi bi-calculator me-1"></i>Simulare calcul revizie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <p class="rv-modal-intro">Validează calculul pe date istorice SasFleet de la un T0 ales. Simularea nu modifică configurarea, T0-urile sau istoricul. Zilele nesincronizate local se citesc direct din SasFleet (maxim <?= ReviziiService::SIMULATION_MAX_CALLS ?> cereri, <?= ReviziiService::SIMULATION_MAX_DAYS ?> de zile).</p>
                <form class="row g-2 align-items-end" id="rvSimForm" data-url="<?= e(build_query_url(['page' => 'revizii', 'action' => 'simulate'])) ?>" novalidate>
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <div class="col-md-3">
                        <label class="form-label" for="rvSimType">Revizie</label>
                        <select class="form-select" id="rvSimType" name="tip">
                            <?php foreach (ReviziiModel::TABS as $key => $meta): ?>
                                <option value="<?= e($key) ?>" data-hours="<?= $meta['ore'] ? '1' : '0' ?>" <?= $key === $simTab ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="rvSimVehicle">Vehicul</label>
                        <select class="form-select" id="rvSimVehicle" name="vehicle_id" required>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?= (int) $vehicle['id'] ?>"><?= e($vehicle['nr_inmatriculare']) ?> · <?= e(vehicle_type_label((string) $vehicle['tip_vehicul'])) ?><?= ($vehicle['status'] ?? 'activ') !== 'activ' ? ' (inactiv)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label" for="rvSimFrom">T0 (început)</label>
                        <input type="datetime-local" class="form-control" id="rvSimFrom" name="de_la" required>
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label" for="rvSimTo">Sfârșit</label>
                        <input type="datetime-local" class="form-control" id="rvSimTo" name="pana_la" required>
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label" for="rvSimInterval">Interval (km)</label>
                        <input type="number" class="form-control" id="rvSimInterval" name="interval_km" min="500" max="300000" step="100" value="<?= (int) $simDefaultInterval ?>" required>
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label" for="rvSimWarn">Prag (km)</label>
                        <input type="number" class="form-control" id="rvSimWarn" name="prag_km" min="0" step="100" value="<?= (int) $defaultWarn ?>" required>
                    </div>
                    <div class="col-md-2 col-6" data-sim-hours>
                        <label class="form-label" for="rvSimFactor">Factor (km/oră)</label>
                        <input type="number" class="form-control" id="rvSimFactor" name="factor" min="0" max="500" step="1" value="<?= e((string) (0 + $factor)) ?>" required>
                    </div>
                    <div class="col-md-4 col-6" data-sim-hours>
                        <label class="form-label" for="rvSimSource">Ore eligibile</label>
                        <select class="form-select" id="rvSimSource" name="sursa_ore">
                            <?php foreach ($simHourSources as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $key === $hourSource ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-play-fill"></i> Calculează</button>
                    </div>
                </form>
                <div class="rv-sim-result" id="rvSimResult" aria-live="polite"></div>
            </div>
        </div>
    </div>
</div>
