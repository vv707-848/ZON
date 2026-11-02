-- Data node, once: the database and the application account (architecture §6 and §13).
-- 10.10.0.10 = the app node's private address. Passwords come from the secrets store, never from the repository.
CREATE DATABASE IF NOT EXISTS zone CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Laravel: data plus the DDL its migrations need (tables, indexes, the ai_v_* views of the SuperAgent).
CREATE USER IF NOT EXISTS 'zon'@'10.10.0.10' IDENTIFIED BY '***';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE VIEW, SHOW VIEW, CREATE TEMPORARY TABLES, LOCK TABLES
   ON zone.* TO 'zon'@'10.10.0.10';

-- Nightly dump on the data node itself (backup/zon-backup.sh): read-only.
CREATE USER IF NOT EXISTS 'zon_backup'@'localhost' IDENTIFIED BY '***';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON zone.* TO 'zon_backup'@'localhost';

-- The SuperAgent's read-only account (SELECT on the ai_v_* views only) is created by mysql/ai_reader.sql,
-- generated with the views it may read. It never gets any right on the tables themselves.
