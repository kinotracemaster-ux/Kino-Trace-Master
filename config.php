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
define('APP_VERSION', substr(md5_file(__FILE__), 0, 8)); // Cache buster estable basado en contenido
define('APP_BRANCH', getenv('APP_BRANCH') ?: 'main'); // Rama activa (para indicador visual)

// Directorio donde se almacenan los datos de cada cliente
define('CLIENTS_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'clients');

// Archivo de base de datos central para almacenar información de clientes
// NOTA: Se coloca DENTRO de CLIENTS_DIR para que un solo volumen persista todo.
define('CENTRAL_DB', CLIENTS_DIR . DIRECTORY_SEPARATOR . 'central.db');

// Crear el directorio de clientes si no existe
if (!file_exists(CLIENTS_DIR)) {
    mkdir(CLIENTS_DIR, 0777, true);
}

// AUTO-INICIALIZACIÓN: Copiar estructura completa desde database_initial/
// Esto permite que Railway funcione deployando no solo central.db sino también
// las carpetas de clientes (ej: kino/kino.db)

/**
 * Copia recursiva de archivos y directorios
 */
function recursive_copy($src, $dst)
{
    $dir = opendir($src);
    @mkdir($dst, 0777, true);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                recursive_copy($src . '/' . $file, $dst . '/' . $file);
            } else {
                // Solo copiar si no existe o si el destino tiene tamaño 0
                if (!file_exists($dst . '/' . $file) || filesize($dst . '/' . $file) == 0) {
                    copy($src . '/' . $file, $dst . '/' . $file);
                    chmod($dst . '/' . $file, 0666);
                }
            }
        }
    }
    closedir($dir);
}

// Ejecutar copia recursiva SOLO si es el primer arranque (no existe central.db)
// Esto evita la sobrecarga de escanear 100+ archivos en cada request
$initialDir = BASE_DIR . '/database_initial';
if (is_dir($initialDir) && !file_exists(CENTRAL_DB)) {
    recursive_copy($initialDir, CLIENTS_DIR);
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
    // Migración: agregar columnas de email y recuperación de contraseña
    $newColumns = [
        'email' => "ALTER TABLE control_clientes ADD COLUMN email TEXT",
        'reset_token' => "ALTER TABLE control_clientes ADD COLUMN reset_token TEXT",
        'reset_token_expiry' => "ALTER TABLE control_clientes ADD COLUMN reset_token_expiry TEXT",
        'subdominio' => "ALTER TABLE control_clientes ADD COLUMN subdominio TEXT",
    ];
    foreach ($newColumns as $col => $sql) {
        try {
            $centralDb->exec($sql);
        } catch (PDOException $e) { /* columna ya existe */
        }
    }

    // Migración: tabla para contenido de página pública por cliente
    $centralDb->exec(
        "CREATE TABLE IF NOT EXISTS pagina_publica (
            codigo TEXT PRIMARY KEY,
            intro_titulo TEXT,
            intro_texto TEXT,
            instrucciones TEXT,
            footer_texto TEXT,
            footer_ubicacion TEXT,
            footer_telefono TEXT,
            footer_url TEXT,
            aviso_legal TEXT
        )"
    );
} catch (PDOException $e) {
    // Si la conexión falla, detener la ejecución con un mensaje claro
    die('❌ Error conectando a la base central: ' . $e->getMessage());
}