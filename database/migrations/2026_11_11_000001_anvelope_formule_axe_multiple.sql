-- Anvelope: "Formula axelor" devine o lista de formule compatibile (ex. "2 axe,3 axe"); NULL = orice formula.
ALTER TABLE anvelope MODIFY COLUMN target_axle_config VARCHAR(120) NULL;
