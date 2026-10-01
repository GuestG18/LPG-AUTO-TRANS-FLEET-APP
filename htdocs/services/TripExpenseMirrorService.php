<?php
declare(strict_types=1);

/**
 * Randul-oglinda din `curse_cheltuieli` al unei inregistrari care traieste in alt
 * modul (o cazare, o factura din pagina Facturi).
 *
 * Proprietarul (modulul) decide CE trebuie sa contina randul; serviciul doar il
 * creeaza / actualizeaza / sterge si ii rescrie documentele. Astfel randul apare in
 * toate rapoartele care citesc deja curse_cheltuieli, fara sa le modificam.
 *
 * La actualizare se scriu DOAR coloanele primite: un proprietar care nu trimite,
 * de exemplu, `locatie` nu calca peste ce a completat operatorul in Dispecer curse.
 * Partea de refacturare (refacturare_*) nu e atinsa niciodata.
 */
class TripExpenseMirrorService
{
    /** Coloanele pe care un proprietar le poate scrie. */
    private const WRITABLE_COLUMNS = [
        'cursa_id' => PDO::PARAM_INT,
        'tip_cheltuiala' => PDO::PARAM_STR,
        'categorie_id' => PDO::PARAM_INT,
        'cazare_id' => PDO::PARAM_INT,
        'locatie' => PDO::PARAM_STR,
        'bucati' => PDO::PARAM_STR,
        'pret_unitar' => PDO::PARAM_STR,
        'suma' => PDO::PARAM_STR,
        'data_cheltuiala' => PDO::PARAM_STR,
        'observatii' => PDO::PARAM_STR,
        'added_by' => PDO::PARAM_INT,
    ];

    /** Obligatorii la insert (NOT NULL fara default in curse_cheltuieli). */
    private const REQUIRED_ON_INSERT = ['cursa_id', 'tip_cheltuiala', 'suma', 'data_cheltuiala'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Creeaza randul (daca $expenseId e null sau randul nu mai exista) sau il
     * actualizeaza, apoi ii rescrie documentele. Intoarce id-ul randului.
     *
     * @param array<string, mixed> $fields coloane din WRITABLE_COLUMNS
     * @param array<int, array{file_path: string, original_name: string, mime_type?: ?string, file_size: int, created_at?: ?string}> $documents
     */
    public function upsert(?int $expenseId, array $fields, array $documents): int
    {
        $fields = array_intersect_key($fields, self::WRITABLE_COLUMNS);
        $now = date('Y-m-d H:i:s');

        if ($expenseId !== null && $expenseId > 0 && $this->exists($expenseId)) {
            $assignments = [];
            foreach (array_keys($fields) as $column) {
                $assignments[] = $column . ' = :' . $column;
            }
            $assignments[] = 'updated_at = :updated_at';

            $stmt = $this->db->prepare('UPDATE curse_cheltuieli SET ' . implode(', ', $assignments) . ' WHERE id = :id');
            $this->bindFields($stmt, $fields);
            $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
            $stmt->bindValue(':id', $expenseId, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            foreach (self::REQUIRED_ON_INSERT as $column) {
                if (!array_key_exists($column, $fields) || $fields[$column] === null) {
                    throw new InvalidArgumentException('TripExpenseMirrorService: lipseste ' . $column . ' pentru randul nou.');
                }
            }

            $columns = array_keys($fields);
            $stmt = $this->db->prepare(
                'INSERT INTO curse_cheltuieli (' . implode(', ', $columns) . ', created_at, updated_at) VALUES (:'
                . implode(', :', $columns) . ', :created_at, :updated_at)'
            );
            $this->bindFields($stmt, $fields);
            $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
            $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
            $stmt->execute();
            $expenseId = (int) $this->db->lastInsertId();
        }

        // Si la insert: inainte, primul rand-oglinda al unei cazari ramanea fara documente.
        $this->replaceDocuments($expenseId, $documents);

        return $expenseId;
    }

    /**
     * Rescrie documentele randului (sunt doar o proiectie). Fisierele fizice NU se
     * ating: apartin proprietarului.
     *
     * @param array<int, array{file_path: string, original_name: string, mime_type?: ?string, file_size: int, created_at?: ?string}> $documents
     */
    public function replaceDocuments(int $expenseId, array $documents): void
    {
        $stmt = $this->db->prepare('DELETE FROM curse_cheltuieli_documente WHERE cheltuiala_id = :cheltuiala_id');
        $stmt->bindValue(':cheltuiala_id', $expenseId, PDO::PARAM_INT);
        $stmt->execute();

        if ($documents === []) {
            return;
        }

        $insert = $this->db->prepare("
            INSERT INTO curse_cheltuieli_documente (cheltuiala_id, file_path, original_name, mime_type, file_size, created_at)
            VALUES (:cheltuiala_id, :file_path, :original_name, :mime_type, :file_size, :created_at)
        ");

        foreach ($documents as $document) {
            $insert->bindValue(':cheltuiala_id', $expenseId, PDO::PARAM_INT);
            $insert->bindValue(':file_path', (string) $document['file_path'], PDO::PARAM_STR);
            $insert->bindValue(':original_name', (string) $document['original_name'], PDO::PARAM_STR);
            $insert->bindValue(':mime_type', (string) (($document['mime_type'] ?? '') ?: 'application/octet-stream'), PDO::PARAM_STR);
            $insert->bindValue(':file_size', (int) $document['file_size'], PDO::PARAM_INT);
            $insert->bindValue(':created_at', (string) (($document['created_at'] ?? '') ?: date('Y-m-d H:i:s')), PDO::PARAM_STR);
            $insert->execute();
        }
    }

    /** Sterge randul si documentele lui oglindite (fisierele raman ale proprietarului). */
    public function delete(int $expenseId): void
    {
        $stmt = $this->db->prepare('DELETE FROM curse_cheltuieli_documente WHERE cheltuiala_id = :cheltuiala_id');
        $stmt->bindValue(':cheltuiala_id', $expenseId, PDO::PARAM_INT);
        $stmt->execute();

        $stmt = $this->db->prepare('DELETE FROM curse_cheltuieli WHERE id = :id');
        $stmt->bindValue(':id', $expenseId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function exists(int $expenseId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM curse_cheltuieli WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $expenseId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $fields */
    private function bindFields(PDOStatement $stmt, array $fields): void
    {
        foreach ($fields as $column => $value) {
            if ($value === null) {
                $stmt->bindValue(':' . $column, null, PDO::PARAM_NULL);
                continue;
            }

            $type = self::WRITABLE_COLUMNS[$column];
            $stmt->bindValue(':' . $column, $type === PDO::PARAM_INT ? (int) $value : (string) $value, $type);
        }
    }
}
