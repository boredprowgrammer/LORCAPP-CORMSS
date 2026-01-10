ALTER TABLE tarheta_control ADD COLUMN registration_type ENUM('transfer-in', 'newly-baptized', 'others') DEFAULT NULL COMMENT 'Type of registration';

ALTER TABLE tarheta_control ADD COLUMN registration_date DATE DEFAULT NULL COMMENT 'Date of registration (for Transfer-In)';

ALTER TABLE tarheta_control ADD COLUMN registration_others_specify VARCHAR(255) DEFAULT NULL COMMENT 'Specify details if registration type is Others';

ALTER TABLE tarheta_control ADD COLUMN transfer_out_date DATE DEFAULT NULL COMMENT 'Date when member was transferred out';

ALTER TABLE tarheta_control ADD INDEX idx_registration_type (registration_type);

ALTER TABLE tarheta_control ADD INDEX idx_registration_date (registration_date);
