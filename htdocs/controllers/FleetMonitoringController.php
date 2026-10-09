<?php
declare(strict_types=1);

/**
 * Pagina "Monitorizare flotă" - harta 3D (MapLibre GL JS + OpenFreeMap).
 *
 * Etapa 1: doar fundatia hartii (camera, cladiri 3D, comenzi). Fara date GPS:
 * pozitiile vehiculelor vor veni ulterior printr-un endpoint al acestui controller
 * (proxy catre SAS pe backend), niciodata direct din JavaScript.
 *
 * Rute:
 *   ?page=monitorizare_flota  -> pagina cu harta
 */
class FleetMonitoringController
{
    public function handle(string $action): void
    {
        render('monitorizare_flota/index.php', [
            'pageTitle' => 'Monitorizare flotă',
            'currentPage' => 'monitorizare_flota',
        ]);
    }
}
