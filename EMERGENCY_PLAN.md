# 🚨 Plan de Emergencia y Recuperación - KINO TRACE

Este documento detalla los procedimientos críticos para recuperar el sistema en caso de fallos, corrupción de datos o errores en actualizaciones.

---

## 🛑 Nivel 1: Fallo de Código (Pantalla blanca / Errores PHP)

Si una actualización rompe el sistema, puedes revertir inmediatamente a la última versión funcional estable.

### Procedimiento de Rollback Rápido

1.  **Abrir Terminal** en la carpeta del proyecto.
2.  **Verificar versiones disponibles**:
    ```bash
    git tag
    ```
    *Busca tags como `v2026-01-27-functional`*.

3.  **Revertir a versión segura**:
    ```bash
    git checkout v2026-01-27-functional
    ```
    *(Reemplaza la fecha con la última conocida buena)*.

4.  **Deshacer cambios locales recientes** (Si es necesario):
    ```bash
    git reset --hard v2026-01-27-functional
    ```
    **⚠️ ADVERTENCIA:** Esto borrará cualquier cambio de código NO guardado desde esa fecha.

---

## 💾 Nivel 2: Corrupción de Base de Datos

Si la base de datos de un cliente (`data.db`) se corrompe o se borran datos por error.

### Opción A: Restauración Automática (Recomendada)
Requiere tener un Backup ZIP creado con `create_backup.php`.

1.  Navega a `/restore_pro.php`.
2.  Ingresa el **Código del Cliente**.
3.  Sube el archivo **ZIP de respaldo** más reciente.
4.  El sistema restaurará la `data.db` y los archivos `uploads/`.

### Opción B: Restauración Manual
Si no tienes el ZIP pero tienes el archivo `.db`.

1.  Accede al servidor/carpeta.
2.  Ve a `clients/<CODIGO_CLIENTE>/`.
3.  Renombra `data.db` actual a `data.db.corrupt`.
4.  Copia tu respaldo de `data.db` a esta carpeta.

---

## 🛡️ Rutina de Prevención (Mantenimiento)

Para evitar desastres, sigue esta rutina antes de cualquier cambio grande:

1.  **Generar Backup del Cliente**:
    - Ve a `/create_backup.php`.
    - Descarga el ZIP del cliente principal.

2.  **Guardar Punto de Control en Git**:
    ```bash
    git add .
    git commit -m "Punto de control antes de [CAMBIO]"
    git tag v[FECHA]-pre-[CAMBIO]
    ```

---

## 📞 Contacto y Recursos

- **Repositorio GitHub**: https://github.com/WILBIdon/MULTI-CLIEN-KINO-NEW2
- **Documentación Técnica**: `DOCUMENTACION_TECNICA.md`
- **Script de Diagnóstico**: `analyze_kino_db_enhanced.php`
