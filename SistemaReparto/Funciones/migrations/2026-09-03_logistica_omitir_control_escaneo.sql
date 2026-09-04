-- Control de escaneo obligatorio en la app de reparto.
-- Override por recorrido: 1 = ese recorrido puede entregar sin escaneo previo
-- (se prende a mano cuando falla un escáner en la calle; cada bypass se
-- registra en SistemaReparto/logs/control_escaneo_bypass.log).
--
-- Idempotente-ish: si la columna ya existe, MySQL tira error 1060 y no pasa nada.

ALTER TABLE Logistica
  ADD COLUMN OmitirControlEscaneo TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'App reparto: 1 = permite entregar sin escaneo previo (warehouse/retiro/colecta)';
