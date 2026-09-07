-- Auditoría del override de control de escaneo (Logistica.OmitirControlEscaneo).
-- Complementa 2026_..._omitir_control_escaneo: guarda QUIÉN (operador de
-- sistema.caddy.com.ar) y CUÁNDO habilitó/deshabilitó el bypass por recorrido.
--
-- El toggle vive en sistema.caddy.com.ar > Logística > Hoja de Ruta.
-- El chofer ya queda registrado en logs/control_escaneo_bypass.log cada vez
-- que efectivamente pasa el gate; esto agrega la trazabilidad del lado del
-- operador que lo autorizó.
--
-- Idempotente-ish: si las columnas ya existen, MySQL tira error 1060 y listo.

ALTER TABLE Logistica
  ADD COLUMN OmitirControlEscaneo_Por   VARCHAR(80) NULL
    COMMENT 'App reparto: operador que cambió OmitirControlEscaneo por última vez',
  ADD COLUMN OmitirControlEscaneo_Fecha DATETIME   NULL
    COMMENT 'App reparto: fecha/hora del último cambio de OmitirControlEscaneo';
