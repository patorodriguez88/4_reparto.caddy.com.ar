let db = null;

function abrirDB(callback) {
  const request = indexedDB.open("caddyWarehouse", 5); // 👈 subí versión (se agregó índice meli_id)

  request.onupgradeneeded = function (e) {
    const db = e.target.result;

    if (!db.objectStoreNames.contains("expected")) {
      const expected = db.createObjectStore("expected", { keyPath: "code" });
      expected.createIndex("estado", "estado", { unique: false });
      expected.createIndex("retirado", "retirado", { unique: false });
      expected.createIndex("meli_id", "meli_id", { unique: false });
    } else {
      // La tabla ya existía de una versión anterior - le agregamos el índice
      // nuevo sobre la conexión de ESTA transacción de upgrade (no se puede
      // usar db.createObjectStore de nuevo sobre un store existente).
      const expected = e.target.transaction.objectStore("expected");
      if (!expected.indexNames.contains("meli_id")) {
        expected.createIndex("meli_id", "meli_id", { unique: false });
      }
    }

    // ✅ scanned como EVENTOS (no por code)
    if (db.objectStoreNames.contains("scanned")) {
      db.deleteObjectStore("scanned"); // 👈 recreamos
    }
    const scanned = db.createObjectStore("scanned", { keyPath: "id" });
    scanned.createIndex("code", "code", { unique: false });
    scanned.createIndex("ts", "ts", { unique: false });

    if (!db.objectStoreNames.contains("meta")) {
      db.createObjectStore("meta", { keyPath: "key" });
    }

    if (!db.objectStoreNames.contains("bases_done")) {
      db.createObjectStore("bases_done", { keyPath: "base" });
    }
  };

  request.onsuccess = function (e) {
    db = e.target.result;
    callback();
  };

  request.onerror = function (e) {
    console.error("Error abriendo IndexedDB", e);
    alert("No se pudo inicializar almacenamiento local");
  };
}

function tx(storeName, mode = "readonly") {
  return db.transaction(storeName, mode).objectStore(storeName);
}
