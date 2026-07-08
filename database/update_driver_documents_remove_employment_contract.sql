DELETE FROM configurare_documente_obligatorii_soferi
WHERE document_type IN ('Contract de munca', 'Contract de angajare')
   OR LOWER(document_type) LIKE 'contract de munc%';
