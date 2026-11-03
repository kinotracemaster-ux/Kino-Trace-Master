# KINO-TRACE 🚀

Sistema de Gestión Documental Multi-cliente con Dashboard Moderno.

## Características

- 🎨 **Dashboard moderno** con sidebar colapsable e iconos minimalistas
- 📤 **Gestor de Documentos** con búsqueda inteligente, subida y consulta
- 🖍️ **Resaltador de PDF** - marca texto con patrones de inicio/fin
- 🔍 **Búsqueda voraz** de códigos en documentos
- 📥 **Importación de datos** desde CSV/SQL
- 🤖 **Integración con IA** (Google Gemini) para extracción inteligente
- 👥 **Multi-cliente** con bases de datos SQLite aisladas
- 🔗 **Vinculación de documentos** con detección de discrepancias

## 🚀 Despliegue en Railway

### Requisitos Previos
1. Cuenta en [Railway.app](https://railway.app/)
2. Este proyecto en un repositorio de GitHub

### Pasos de Despliegue

#### 1. Crear Proyecto
- En Railway: "New Project" → "Deploy from GitHub repo"
- Seleccionar este repositorio

#### 2. Configurar Volumen (CRÍTICO)

> ⚠️ **SIN VOLUMEN SE PERDERÁN LOS DATOS EN CADA DESPLIEGUE**

1. Ve a la configuración del servicio → "Settings"
2. Sección **Volumes** → "New Volume"
3. **Mount Path**: `/var/www/html/clients`

Esto persiste:
- ✅ Base de datos central (`central.db`)
- ✅ Bases de datos de cada cliente (`{codigo}.db`)
- ✅ Archivos PDF subidos

#### 3. Variables de Entorno (Opcionales)
| Variable | Descripción |
|----------|-------------|
| `GEMINI_API_KEY` | Clave API de Google Gemini (para IA) |
| `ADMIN_SECRET` | **Obligatoria para el panel admin.** Mínimo 12 caracteres. Sin ella el acceso admin queda apagado |

#### 4. Crear el cliente inicial
Después del primer despliegue, desde la terminal de Railway (no desde el navegador: los scripts
de mantenimiento solo funcionan por línea de comandos):
```
railway run php migrate.php
```

Esto crea el cliente `kino` (contraseña `kino123`) si la base está vacía.

> 🔐 Cambia la contraseña después del primer login.

---

## Configuración Local

```bash
# Clonar
git clone https://github.com/kino14n/MULTI-CLIEN-KINO-NEW.git
cd MULTI-CLIEN-KINO-NEW

# Crear usuario admin
php migrate.php

# Iniciar servidor
php -S localhost:8080

# Visitar http://localhost:8080
```

---

## Estructura del Proyecto

```
kino-trace/
├── api.php                    # API unificada
├── config.php                 # Configuración SQLite
├── login.php                  # Login moderno
├── migrate.php                # Crear admin
├── includes/
│   ├── sidebar.php            # Navegación lateral
│   ├── header.php             # Header de página
│   └── footer.php             # Footer
├── helpers/
│   ├── pdf_extractor.php      # Extracción de códigos
│   ├── search_engine.php      # Búsqueda voraz
│   ├── gemini_ai.php          # Integración IA
│   ├── import_engine.php      # Importación CSV/SQL
│   └── tenant.php             # Multi-tenancy
├── modules/
│   ├── busqueda/              # Gestor de Documentos (4 tabs)
│   ├── resaltar/              # Resaltador de PDF
│   ├── recientes/             # Documentos recientes
│   ├── manifiestos/           # Gestión manifiestos
│   ├── declaraciones/         # Gestión declaraciones
│   ├── subir/                 # Subida con extracción
│   ├── importar/              # Importación datos
│   └── trazabilidad/          # Dashboard y validación
├── assets/css/styles.css      # Sistema de diseño
└── clients/                   # Datos (VOLUMEN EN RAILWAY)
```

---

## Arquitectura de Base de Datos

```
clients/
├── central.db                 # Control de clientes
├── admin/
│   ├── admin.db               # BD del admin
│   └── uploads/               # Archivos
├── kino/
│   ├── kino.db                # BD de KINO
│   └── uploads/
└── [otros clientes]/
```

### ¿Por qué SQLite?
- ✅ Sin servidor MySQL externo
- ✅ Portabilidad total (backup = copiar carpeta)
- ✅ Un solo volumen persiste todo
- ✅ Aislamiento completo por cliente

---

## Licencia

MIT License - Elaborado por **KINO GENIUS**

<!-- Test push 2026-02-09 14:32 -->
