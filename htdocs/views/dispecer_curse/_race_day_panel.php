<?php
/**
 * "Curse deja inregistrate": tot istoricul vehiculului ales, fara fereastra de
 * timp. Orice limita (ziua, luna) lasa afara exact cursa dubla introdusa peste
 * marginea ei, asa ca lista este completa, cea mai recenta prima, iar filtrul
 * "doar asemanatoare" o reduce la cursele care conteaza pentru cea in lucru.
 * Continutul vine din actiunea races_activity.
 */
?>
<div class="card border-0 shadow-sm mt-3 d-none" data-race-day-panel>
    <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h3 class="h6 mb-0">
            Curse deja inregistrate
            <span class="text-muted fw-normal small ms-1" data-race-day-context></span>
        </h3>
        <div class="d-flex align-items-center gap-2">
            <div class="form-check form-switch mb-0 d-none" data-race-day-filter-wrap>
                <input class="form-check-input" type="checkbox" id="race_day_similar_only" data-race-day-similar-only>
                <label class="form-check-label small" for="race_day_similar_only">Doar asemanatoare</label>
            </div>
            <span class="badge bg-warning text-dark d-none" data-race-day-similar-count></span>
            <span class="badge bg-secondary" data-race-day-count>0</span>
        </div>
    </div>
    <div class="table-responsive" data-race-day-table style="max-height: 360px; overflow-y: auto;">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light position-sticky top-0">
                <tr>
                    <th scope="col">Data</th>
                    <th scope="col">Cursa</th>
                    <th scope="col">Interval</th>
                    <th scope="col">Tip</th>
                    <th scope="col">Traseu</th>
                    <th scope="col">Sofer</th>
                    <th scope="col" class="text-end">Km</th>
                    <th scope="col" class="text-end">Cantitate</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody data-race-day-rows></tbody>
        </table>
    </div>
    <?php /* Rezultatul gol se afiseaza explicit: altfel operatorul nu stie daca
             verificarea a rulat sau daca panoul e stricat. */ ?>
    <div class="card-body text-muted small d-none" data-race-day-empty>
        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
        <span data-race-day-empty-text>Nicio cursa inregistrata pentru acest vehicul in luna selectata.</span>
    </div>
    <div class="card-footer bg-white text-muted small" data-race-day-footer>
        Randurile marcate <span class="badge bg-warning text-dark">Seamana</span> au acelasi beneficiar si acelasi loc de incarcare ca al cursei pe care o introduci. Verifica-le inainte de a salva.
        <span class="d-none" data-race-day-truncated></span>
    </div>
</div>
