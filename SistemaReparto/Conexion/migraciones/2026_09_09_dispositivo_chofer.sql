-- ============================================================================
-- Bloqueo mono-dispositivo para la app de reparto.
-- Generado 2026-09-09.
--
-- Un mismo chofer no puede quedar operando en dos telefonos a la vez. En cada
-- login la app manda un device_id (aleatorio, guardado en el telefono). El
-- servidor deja registrado SOLO el ultimo: si despues llega un request con la
-- sesion de un telefono cuyo device_id ya no es el vigente, esa sesion se
-- cierra ("gana el ultimo login").
--
-- Una fila por usuario. Sin columnas nuevas en tablas existentes.
-- Idempotente.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `DispositivoChofer` (
  `idUsuario`  INT(11)      NOT NULL,
  `device_id`  VARCHAR(64)  NOT NULL,
  `ip`         VARCHAR(64)   DEFAULT NULL,
  `user_agent` VARCHAR(255)  DEFAULT NULL,
  `ts`         DATETIME     NOT NULL,
  PRIMARY KEY (`idUsuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
