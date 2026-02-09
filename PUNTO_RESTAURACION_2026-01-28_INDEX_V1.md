# PUNTO DE RESTAURACIÓN: INDEXADOR FUNCIONAL V1
**Fecha:** 28 de Enero de 2026
**Estado:** ESTABLE / FUNCIONAL

## 📝 Descripción
Este punto de restauración marca el hito donde el sistema de indexación y reindexación de documentos PDF funciona de manera rápida, robusta y eficiente. Se han resuelto errores críticos de duplicación de funciones y optimizado el rendimiento del backend y frontend.

## 🔧 Cambios y Mejoras Clave Implementados

### 1. Backend (PHP)
*   **Corrección de Error Fatal:** Eliminada la redeclaración de `resolve_pdf_path` en `helpers/file_manager.php`. Ahora usa correctamente `require_once` hacia `helpers/tenant.php`.
*   **SystemController::reindex Optimizado:**
    *   **Batch Scaling:** Aumentado el tamaño del lote por defecto a **120 documentos** (máximo 150) para procesamiento masivo en una sola petición.
    *   **Gestión de Recursos:** Implementado `gc_collect_cycles()` (recolección de basura) y liberación de sesión (`session_write_close()`) para evitar bloqueos y fugas de memoria.
    *   **Supresión de Ruido:** Variable de entorno `FONTCONFIG_PATH=/tmp` y supresión temporal de warnings de PHP para evitar logs basura de `pdftotext`.
    *   **Lógica SQL Eficiente:** Filtrado directo en base de datos (`NOT LIKE '%"text":%'`) y conteo optimizado (`SELECT COUNT(*)`), eliminando loops innecesarios en PHP.

### 2. Frontend (JavaScript/Dashboard)
*   **Bucle de Indexación Recursivo:** Implementada lógica robusta `do...while` que solicita lotes secuencialmente hasta completar la cola.
*   **Manejo de Errores V1:** Verificación estricta de respuestas JSON. Si el servidor devuelve HTML o vacío, se captura y muestra en consola sin romper la UI.
*   **Feedback Visual:** Barra de progreso real y recarga automática al finalizar.

## 📂 Archivos Críticos Modificados
1.  `src/Api/SystemController.php` (Lógica central de reindexado)
2.  `modules/trazabilidad/dashboard.php` (Interfaz y lógica JS del dashboard)
3.  `helpers/file_manager.php` (Limpieza de duplicados)
4.  `api.php` (Manejo de errores JSON global)

## 🚀 Cómo Restaurar a este Punto
Si se rompe algo en el futuro, revertir a este commit (identificado en git log con esta fecha) restaurará la funcionalidad de indexación rápida.

---
**Notas Adicionales:**
El sistema ahora es capaz de procesar ~120 documentos en segundos/minutos sin timeouts ni errores de memoria.
