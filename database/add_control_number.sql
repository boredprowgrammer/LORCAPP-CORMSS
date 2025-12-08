-- Add legacy control number to officers table

ALTER TABLE officers 
ADD COLUMN control_number_encrypted TEXT NULL AFTER registry_number_encrypted,
ADD COLUMN legacy_officer_id INT NULL AFTER tarheta_control_id,
ADD INDEX idx_legacy_officer (legacy_officer_id);

-- Add foreign key constraint
ALTER TABLE officers
ADD CONSTRAINT fk_officers_legacy
FOREIGN KEY (legacy_officer_id) REFERENCES legacy_officers(id) ON DELETE SET NULL;
