<div class="modal fade inactive-resource-warning-modal" id="inactiveResourceWarningModal" tabindex="-1" aria-labelledby="inactiveResourceWarningTitle" aria-hidden="true" data-inactive-resource-modal>
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div class="inactive-resource-warning-title-wrap">
                    <span class="inactive-resource-warning-icon" aria-hidden="true" data-inactive-resource-modal-icon>
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </span>
                    <h5 class="modal-title" id="inactiveResourceWarningTitle" data-inactive-resource-modal-title>Resursa inactiva utilizata</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide" data-inactive-resource-cancel></button>
            </div>
            <div class="modal-body">
                <div class="inactive-resource-warning-body" data-inactive-resource-modal-body></div>
                <label class="inactive-resource-warning-session-option" data-inactive-resource-session-option>
                    <input class="form-check-input" type="checkbox" value="1" data-inactive-resource-session-dismiss>
                    <span data-inactive-resource-session-label>Nu mai afisa pentru aceasta selectie in aceasta sesiune</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-inactive-resource-cancel data-inactive-resource-admin-cancel>Anuleaza</button>
                <button type="button" class="btn btn-outline-primary" data-inactive-resource-approve-later>Aproba ulterior</button>
                <button type="button" class="btn btn-primary" data-inactive-resource-approve-now>Aproba acum</button>
                <button type="button" class="btn btn-primary d-none" data-inactive-resource-request-approval>Solicita aprobare</button>
                <button type="button" class="btn btn-outline-secondary d-none" data-bs-dismiss="modal" data-inactive-resource-postpone>Amana</button>
                <button type="button" class="btn btn-outline-secondary d-none" data-bs-dismiss="modal" data-inactive-resource-close>Inchide</button>
                <button type="button" class="btn btn-outline-danger d-none" data-inactive-resource-cancel-request>
                    <i class="bi bi-trash" aria-hidden="true"></i>
                    <span>Anuleaza solicitarea</span>
                </button>
            </div>
        </div>
    </div>
</div>
