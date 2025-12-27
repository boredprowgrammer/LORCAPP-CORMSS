-- Add kapisanan column to officers table
-- Kapisanan: Buklod, Kadiwa, Binhi

ALTER TABLE `officers`
ADD COLUMN `kapisanan` ENUM('Buklod', 'Kadiwa', 'Binhi') DEFAULT NULL 
COMMENT 'Kapisanan classification: Buklod, Kadiwa, or Binhi'
AFTER `grupo`;
