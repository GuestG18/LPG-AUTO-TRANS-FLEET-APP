<?php
declare(strict_types=1);

require_once __DIR__ . '/EtollApiClient.php';
require_once __DIR__ . '/InvoiceTripMatcher.php';

/**
 * Sesiunile eToll in curs (isOngoing), pe nr. auto: semnalul ca soferul a pornit sesiunea in
 * aplicatia eToll. Folosit de notificarea primei miscari (SAS: camionul merge; eToll: are sesiune?).
 *
 * Raspunsul e pe trei stari: true (sesiune activa), false (nicio sesiune), null (necunoscut:
 * eToll neconfigurat sau indisponibil). Apelantii trateaza null ca inainte de verificare.
 *
 * O singura cerere eToll (ultimele LOOKBACK_HOURS, de obicei 1-2 pagini) pe instanta; rezultatul
 * se tine in memorie, deci o rulare a cron-ului intreaba eToll o data pentru toata flota.
 */
class EtollSessionService
{
    /** O sesiune in curs apare in lista de la ora pornirii; acoperim si sesiunile de mai multe zile. */
    private const LOOKBACK_HOURS = 72;

    /** @var array<string, string>|null nr. auto normalizat => pornita la (ISO) */
    private ?array $active = null;
    private bool $loaded = false;
    private ?string $error = null;

    /** @param array<string, string>|null $active doar pentru teste / simulari: lista fixa (null = necunoscut) */
    public function __construct(private ?EtollApiClient $client, ?array $active = null)
    {
        if ($client === null) {
            $this->loaded = true;
            $this->active = $active;
            $this->error = $active === null ? 'eToll neconfigurat' : null;
        }
    }

    /** Din .env (ETOLL_USER / ETOLL_PASS / ETOLL_TOLL_ID); fara chei -> mereu "necunoscut". */
    public static function fromEnv(): self
    {
        try {
            return new self(EtollApiClient::fromEnv(10));
        } catch (InvalidArgumentException) {
            return new self(null);
        }
    }

    /** Pentru teste / simulari: nr. auto cu sesiune activa (null = eToll indisponibil). */
    public static function fixed(?array $plates): self
    {
        if ($plates === null) {
            return new self(null);
        }
        $active = [];
        foreach ($plates as $plate) {
            $active[InvoiceTripMatcher::normalizePlate((string) $plate)] = date('c');
        }

        return new self(null, $active);
    }

    /** true / false, sau null daca eToll nu a putut fi intrebat. */
    public function isActive(string $plate): ?bool
    {
        $active = $this->activePlates();

        return $active === null ? null : isset($active[InvoiceTripMatcher::normalizePlate($plate)]);
    }

    /** Ora pornirii sesiunii active (ISO), sau null. */
    public function activeSince(string $plate): ?string
    {
        return $this->activePlates()[InvoiceTripMatcher::normalizePlate($plate)] ?? null;
    }

    /** Nr. de inmatriculare al vehiculului din Fleet ('' daca lipseste). */
    public static function plateOfVehicle(PDO $db, int $vehicleId): string
    {
        $stmt = $db->prepare('SELECT nr_inmatriculare FROM vehicule WHERE id = :id');
        $stmt->execute([':id' => $vehicleId]);

        return trim((string) ($stmt->fetchColumn() ?: ''));
    }

    public function error(): ?string
    {
        $this->activePlates();

        return $this->error;
    }

    /** @return array<string, string>|null */
    public function activePlates(): ?array
    {
        if ($this->loaded) {
            return $this->active;
        }
        $this->loaded = true;

        try {
            $now = new DateTimeImmutable('now');
            $active = [];
            foreach ($this->client->transactions($now->modify('-' . self::LOOKBACK_HOURS . ' hours'), $now) as $transaction) {
                $plate = InvoiceTripMatcher::normalizePlate((string) ($transaction['plateNumber'] ?? ''));
                if ($plate === '' || empty($transaction['isOngoing'])) {
                    continue;
                }
                $since = (string) ($transaction['date'] ?? '');
                // Cea mai recenta sesiune a camionului (lista vine descrescator, dar nu ne bazam pe asta).
                if (!isset($active[$plate]) || strcmp($since, $active[$plate]) > 0) {
                    $active[$plate] = $since;
                }
            }
            $this->active = $active;
        } catch (Throwable $exception) {
            $this->active = null;
            $this->error = $exception->getMessage();
        }

        return $this->active;
    }
}
