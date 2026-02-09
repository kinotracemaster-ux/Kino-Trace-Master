<?php
/**
 * Configuración central para la aplicación multi‑cliente.
 *
 * Este archivo define los directorios base para almacenar los datos de cada
 * cliente y crea una base de datos SQLite central que mantiene un listado
 * de clientes registrados. Cada cliente tendrá su propio archivo de base
 * de datos SQLite dentro de su carpeta bajo el directorio `clients`.
 *
 * Este enfoque elimina la necesidad de un servidor MySQL externo, ya que
 * SQLite opera sobre archivos locales. Además, la portabilidad es total: un
 * respaldo de un cliente consiste simplemente en copiar su carpeta.
 */

// Ruta base del proyecto
define('BASE_DIR', __DIR__);
define('APP_VERSION', time()); // Cache buster auto-generado

// Directorio donde se almacenan los datos de cada cliente
define('CLIENTS_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'clients');

// Archivo de base de datos central para almacenar información de clientes
// NOTA: Se coloca DENTRO de CLIENTS_DIR para que un solo volumen persista todo.
define('CENTRAL_DB', CLIENTS_DIR . DIRECTORY_SEPARATOR . 'central.db');

// Crear el directorio de clientes si no existe
if (!file_exists(CLIENTS_DIR)) {
    mkdir(CLIENTS_DIR, 0777, true);
}

// Conectar a la base de datos central (SQLite)
try {
    $centralDsn = 'sqlite:' . CENTRAL_DB;
    $centralDb = new PDO($centralDsn);
    $centralDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Crear la tabla de control de clientes si aún no existe
    $centralDb->exec(
        "CREATE TABLE IF NOT EXISTS control_clientes (\n"
        . "    id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "    codigo TEXT UNIQUE,\n"
        . "    nombre TEXT NOT NULL,\n"
        . "    password_hash TEXT NOT NULL,\n"
        . "    titulo TEXT,\n"
        . "    color_primario TEXT,\n"
        . "    color_secundario TEXT,\n"
        . "    activo INTEGER DEFAULT 1,\n"
        . "    fecha_creacion TEXT DEFAULT (datetime('now'))\n"
        . ");"
    );
} catch (PDOException $e) {
    // Si la conexión falla, detener la ejecución con un mensaje claro
    die('❌ Error conectando a la base central: ' . $e->getMessage());
}