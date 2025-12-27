-- Add requirements checklist columns to officer_requests table

ALTER TABLE officer_requests 
ADD COLUMN has_r515 TINYINT(1) DEFAULT 0 COMMENT 'R5-15/04 Application Form' AFTER r513_pdf_file_id,
ADD COLUMN has_patotoo_katiwala TINYINT(1) DEFAULT 0 COMMENT 'Patotoo ng Katiwala' AFTER has_r515,
ADD COLUMN has_patotoo_kapisanan TINYINT(1) DEFAULT 0 COMMENT 'Patotoo ng Kapisanan' AFTER has_patotoo_katiwala,
ADD COLUMN has_salaysay_magulang TINYINT(1) DEFAULT 0 COMMENT 'Salaysay ng Magulang' AFTER has_patotoo_kapisanan,
ADD COLUMN has_salaysay_pagtanggap TINYINT(1) DEFAULT 0 COMMENT 'Salaysay ng Pagtanggap' AFTER has_salaysay_magulang,
ADD COLUMN has_picture TINYINT(1) DEFAULT 0 COMMENT '2x2 Picture' AFTER has_salaysay_pagtanggap;
