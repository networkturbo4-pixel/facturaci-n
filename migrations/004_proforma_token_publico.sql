-- Migración 004: Agregar token público a proformas para acceso y compartición segura
ALTER TABLE proformas ADD COLUMN IF NOT EXISTS token_publico VARCHAR(64) NULL AFTER numero;
