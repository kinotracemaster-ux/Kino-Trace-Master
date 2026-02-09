-- Database Structure for KINO-TRACE (MySQL/Railway Compatible)
-- Generated based on config.php and helpers/tenant.php

-- --------------------------------------------------------
-- Table: control_clientes
-- Description: Central table to manage tenants/clients.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS control_clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) UNIQUE,
    nombre VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    titulo VARCHAR(255),
    color_primario VARCHAR(50),
    color_secundario VARCHAR(50),
    activo TINYINT DEFAULT 1,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- --------------------------------------------------------
-- NOTE: The following tables are designed for the Client Data.
-- In the current SQLite architecture, these exist in separate .db files per client.
-- If migrating to a single Central MySQL database on Railway, you should strictly
-- ensure that the application logic connects to this single DB.
-- You might consider adding a 'client_id' column to separating data if merging 
-- all tenants into one table, but the schema below reflects the current per-tenant structure.
-- --------------------------------------------------------

-- --------------------------------------------------------
-- Table: documentos
-- Description: Stores document metadata (invoices, manifests, etc.)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL,
    numero VARCHAR(50) NOT NULL,
    fecha DATE NOT NULL,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    proveedor VARCHAR(255),
    naviera VARCHAR(255),
    peso_kg DECIMAL(10,2),
    valor_usd DECIMAL(10,2),
    ruta_archivo TEXT NOT NULL,
    hash_archivo VARCHAR(255),
    datos_extraidos TEXT,
    ai_confianza DECIMAL(5,4),
    requiere_revision TINYINT DEFAULT 0,
    estado VARCHAR(50) DEFAULT 'pendiente',
    notas TEXT
);

-- --------------------------------------------------------
-- Table: codigos
-- Description: Extracted codes/items from documents.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS codigos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    documento_id INT NOT NULL,
    codigo VARCHAR(255) NOT NULL,
    descripcion TEXT,
    cantidad INT,
    valor_unitario DECIMAL(10,2),
    validado TINYINT DEFAULT 0,
    alerta TEXT,
    FOREIGN KEY(documento_id) REFERENCES documentos(id) ON DELETE CASCADE
);

-- --------------------------------------------------------
-- Table: vinculos
-- Description: Links between documents (e.g., manifest -> declaration).
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS vinculos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    documento_origen_id INT NOT NULL,
    documento_destino_id INT NOT NULL,
    tipo_vinculo VARCHAR(50) NOT NULL,
    codigos_coinciden INT DEFAULT 0,
    codigos_faltan INT DEFAULT 0,
    codigos_extra INT DEFAULT 0,
    discrepancias TEXT,
    FOREIGN KEY(documento_origen_id) REFERENCES documentos(id) ON DELETE CASCADE,
    FOREIGN KEY(documento_destino_id) REFERENCES documentos(id) ON DELETE CASCADE
);
