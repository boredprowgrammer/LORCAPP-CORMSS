ALTER TABLE tarheta_control ADD COLUMN marriage_date DATE DEFAULT NULL COMMENT 'Marriage date (for Buklod members from Lipat-Kapisanan)';

ALTER TABLE tarheta_control ADD COLUMN classification_change_date DATE DEFAULT NULL COMMENT 'Date of last classification change (Lipat-Kapisanan)';

ALTER TABLE tarheta_control ADD COLUMN classification_change_reason VARCHAR(255) DEFAULT NULL COMMENT 'Reason for classification change';
