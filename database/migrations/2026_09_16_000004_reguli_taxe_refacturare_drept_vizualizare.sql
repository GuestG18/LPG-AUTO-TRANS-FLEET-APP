-- Cine vede Refacturari curse vede si "Reguli taxe refacturare" (doar vizualizare).
-- Drepturile personalizate au fost salvate inainte ca pagina sa existe, asa ca
-- operatorii nu o aveau in meniu. Modificarea regulilor ramane doar pentru admin.
INSERT IGNORE INTO access_permissions (user_id, page_key, action_key, created_at)
SELECT user_id, 'reguli_taxe_refacturare', 'view', NOW()
FROM access_permissions
WHERE page_key = 'dispecer_curse' AND action_key = 'refacturari_view';

INSERT IGNORE INTO access_template_permissions (template_id, page_key, action_key)
SELECT template_id, 'reguli_taxe_refacturare', 'view'
FROM access_template_permissions
WHERE page_key = 'dispecer_curse' AND action_key = 'refacturari_view';
