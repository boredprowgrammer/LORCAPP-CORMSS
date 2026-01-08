-- Add search index columns for faster searching
-- Run each ALTER TABLE separately, ignore errors if columns exist

ALTER TABLE tarheta_control ADD COLUMN search_name VARCHAR(255) DEFAULT NULL;
ALTER TABLE tarheta_control ADD COLUMN search_registry VARCHAR(50) DEFAULT NULL;
CREATE INDEX idx_search_name ON tarheta_control(search_name);
CREATE INDEX idx_search_registry ON tarheta_control(search_registry);
CREATE INDEX idx_cfo_classification ON tarheta_control(cfo_classification);
CREATE INDEX idx_cfo_status ON tarheta_control(cfo_status);

ALTER TABLE officers ADD COLUMN search_name VARCHAR(255) DEFAULT NULL;
CREATE INDEX idx_officer_search_name ON officers(search_name);
CREATE INDEX idx_officer_status ON officers(is_active);
