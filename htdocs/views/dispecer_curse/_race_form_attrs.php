<?php
/**
 * Atributele de configurare ale formularului de cursa (tarife, mapari, url-uri).
 * initRaceForm() din dispecer-curse.js citeste totul de aici, deci formularul unei
 * FAZE le poarta pe aceleasi, ca sa se comporte identic cu formularul cursei.
 *
 * $attrsMode = 'phase' scoate verificarile care au sens doar la o cursa NOUA:
 * aprobarile pentru resurse inactive si panoul de curse nou adaugate.
 *
 * Verificarea de suprapunere RAMANE si pe faze, dar cu `data-inactive-trip-id` =
 * cursa parinte: JS-ul o trimite ca `trip_id`, serverul o exclude, deci faza nu se
 * mai "suprapune" cu propria cursa — insa o ciocnire cu ALTA cursa tot se semnaleaza.
 *
 * Se include in interiorul tagului <form ...>.
 */

$attrsMode = isset($attrsMode) && $attrsMode === 'phase' ? 'phase' : 'trip';
$attrsIsPhase = $attrsMode === 'phase';
?>
data-zone-tariffs='<?= e($zoneTariffJson ?? '{}') ?>' data-zone-extra-km-costs='<?= e($zoneExtraKmJson ?? '{}') ?>' data-distribution-route-tariffs='<?= e($distributionRouteTariffMapJson ?? '{}') ?>' data-primary-route-km-map='<?= e($primaryRouteKmMapJson ?? '{}') ?>' data-beneficiary-pricing='<?= e($beneficiaryPricingJson ?? '{}') ?>' data-primary-extended-beneficiaries='<?= e($primaryExtendedBeneficiaryJson ?? '[]') ?>' data-load-location-tariffs='<?= e($loadLocationTariffJson ?? '{}') ?>' data-vehicle-default-load-locations='<?= e($vehicleDefaultLoadLocationJson ?? '{}') ?>' data-vehicle-default-distribution-zones='<?= e($vehicleDefaultDistributionZoneJson ?? '{}') ?>' data-vehicle-garages='<?= e($vehicleGarageJson ?? '{}') ?>' data-load-locations-by-beneficiary='<?= e($loadLocationsByBeneficiaryJson ?? '[]') ?>' data-distribution-zones-by-beneficiary='<?= e($distributionZonesByBeneficiaryJson ?? '[]') ?>' data-vehicle-default-load-locations-by-beneficiary='<?= e($vehicleDefaultLoadLocationByBeneficiaryJson ?? '[]') ?>' data-vehicle-default-distribution-zones-by-beneficiary='<?= e($vehicleDefaultDistributionZoneByBeneficiaryJson ?? '[]') ?>' data-compresor-vehicles-by-beneficiary='<?= e($compressorVehicleByBeneficiaryJson ?? '[]') ?>' data-active-driver-vehicle-ids='<?= e($activeDriverVehicleIdsJson ?? '[]') ?>' data-drivers-by-vehicle='<?= e($driversByVehicleJson ?? '{}') ?>' data-all-drivers='<?= e($allDriversJson ?? '{}') ?>'
data-trip-conflict-check-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'trip_conflict_check'])) ?>"
<?php if (!$attrsIsPhase): ?>
data-inactive-resource-status-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'inactive_resource_status'])) ?>" data-inactive-approval-mode="<?= (function_exists('can') && can('inactive_approvals', 'review')) ? 'admin' : 'user' ?>" data-inactive-approval-request-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'request_inactive_vehicle_approval'])) ?>" data-inactive-approval-cancel-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'cancel_inactive_vehicle_approval'])) ?>" data-races-activity-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'races_activity'])) ?>" data-races-activity-since="<?= e(date('Y-m-d H:i:s')) ?>"
<?php endif; ?>
<?php
// Modul se reseteaza dupa fiecare include: altfel o faza randata inaintea
// formularului de cursa i-ar taia si lui verificarile.
$attrsMode = 'trip';
?>
