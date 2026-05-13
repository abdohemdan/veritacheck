-- VeritàCheck — database.sql
-- Esegui questo file su phpMyAdmin o MySQL CLI
-- per creare il database del progetto

CREATE DATABASE IF NOT EXISTS veritacheck
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE veritacheck;

-- Tabella analisi
CREATE TABLE IF NOT EXISTS analisi (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tipo         ENUM('testo','url','immagine') NOT NULL,
  contenuto    TEXT NOT NULL,
  url_originale VARCHAR(2048),
  verdetto     ENUM('FAKE','VERO','INCERTO') NOT NULL,
  score        TINYINT UNSIGNED NOT NULL,
  tono         VARCHAR(50),
  segnali      TINYINT UNSIGNED DEFAULT 0,
  spiegazione  TEXT,
  ip_utente    VARCHAR(45),
  creato_il    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_verdetto (verdetto),
  INDEX idx_creato   (creato_il)
) ENGINE=InnoDB;

-- Tabella fonti per ogni analisi
CREATE TABLE IF NOT EXISTS fonti (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  analisi_id INT NOT NULL,
  nome       VARCHAR(200) NOT NULL,
  url        VARCHAR(2048),
  rating     VARCHAR(100),
  colore     ENUM('verde','rosso','giallo') DEFAULT 'giallo',
  FOREIGN KEY (analisi_id) REFERENCES analisi(id) ON DELETE CASCADE
) ENGINE=InnoDB;
