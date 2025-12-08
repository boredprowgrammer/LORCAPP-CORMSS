-- Add registry_number column and tarheta linking to officers table

ALTER TABLE officers 
ADD COLUMN registry_number_encrypted TEXT COMMENT 'Encrypted registry/control number from Tarheta' AFTER control_number,
ADD COLUMN tarheta_control_id INT NULL COMMENT 'FK to tarheta_control if linked' AFTER registry_number_encrypted,
ADD INDEX idx_tarheta_control (tarheta_control_id),
ADD FOREIGN KEY (tarheta_control_id) REFERENCES tarheta_control(id) ON DELETE SET NULL;
