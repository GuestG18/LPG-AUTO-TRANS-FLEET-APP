<?php
declare(strict_types=1);

/**
 * Canalele externe (WhatsApp etc.) prin care un utilizator vorbeste cu Fleet Assistant.
 * Tabela assistant_channels exista deja in productie; modelul doar o citeste.
 */
class AssistantChannelModel extends BaseModel
{
    public function findActiveChannel(string $provider, string $externalIdentifier): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, employee_id, provider, external_identifier
             FROM assistant_channels
             WHERE provider = :provider
               AND external_identifier = :external_identifier
               AND active = 1
             LIMIT 1'
        );
        $stmt->execute([
            ':provider' => $provider,
            ':external_identifier' => $externalIdentifier,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
