<?php
// Tab-urile din Documente vehicule: lista documentelor / configurarea documentelor obligatorii.
$vehicleDocumentsActiveTab = (string) ($vehicleDocumentsActiveTab ?? 'documente');
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $vehicleDocumentsActiveTab === 'documente' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'documente'])) ?>">
            <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Documente
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $vehicleDocumentsActiveTab === 'obligatorii' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'required_documents'])) ?>">
            <i class="bi bi-ui-checks-grid me-1" aria-hidden="true"></i>Documente obligatorii
        </a>
    </li>
</ul>
