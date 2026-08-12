ALTER TABLE inactive_resource_approvals
    MODIFY COLUMN resource_type ENUM('vehicle', 'driver', 'repair') NOT NULL;

UPDATE inactive_resource_approvals
SET resource_type = 'repair'
WHERE resource_type = 'vehicle'
  AND inactive_reason = 'repair';
