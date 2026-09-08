<?php
/**
 * Modalele pentru regulile de cursa dubla, verificate inainte de trimiterea
 * formularului (vezi actiunea trip_conflict_check):
 *  - suprapunere de interval pe acelasi vehicul -> blocaj, doar inchidere;
 *  - curse asemanatoare in aceeasi zi -> confirmare explicita si salvare.
 */
?>
<div class="modal fade" id="tripOverlapBlockModal" tabindex="-1" aria-labelledby="tripOverlapBlockTitle" aria-hidden="true" data-trip-overlap-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tripOverlapBlockTitle">Vehiculul este deja pe o cursa in acest interval</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2" data-trip-overlap-message></p>
                <p class="mb-2">
                    <a class="btn btn-sm btn-outline-primary d-none" data-trip-overlap-link href="#" target="_blank" rel="noopener">Deschide cursa</a>
                </p>
                <p class="text-muted small mb-0">Modifica intervalul cursei sau alege alt vehicul. Daca este aceeasi cursa introdusa a doua oara, nu mai este nevoie sa o salvezi.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Am inteles</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="tripSimilarConfirmModal" tabindex="-1" aria-labelledby="tripSimilarConfirmTitle" aria-hidden="true" data-trip-similar-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tripSimilarConfirmTitle">Exista deja o cursa asemanatoare</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">In aceeasi zi, cu acelasi vehicul, acelasi beneficiar si acelasi loc de incarcare:</p>
                <ul class="mb-2" data-trip-similar-list></ul>
                <p class="text-muted small mb-0">Daca este al doilea drum pe aceeasi ruta, poti salva. Daca este aceeasi cursa introdusa din nou, renunta si verifica lista.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Nu, verific lista</button>
                <button type="button" class="btn btn-primary" data-trip-similar-confirm>Da, este o cursa noua</button>
            </div>
        </div>
    </div>
</div>
