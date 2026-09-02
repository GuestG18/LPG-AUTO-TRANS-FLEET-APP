-- Permite consemnarea in istoricul de tarife a stergerii unei versiuni
-- (rollback la tariful anterior). Fara 'deleted' in ENUM, insertul de audit
-- al actiunii de stergere ar esua silentios cu valoare goala.
ALTER TABLE transport_tariff_history
    MODIFY action ENUM('created','scheduled','superseded','dismissed','reviewed','deleted') NOT NULL;
