# Puntos de Restauración - Búsqueda Voraz y PDF Unificado

Este archivo documenta los commits del trabajo realizado el **29 de Enero de 2026**, los cuales quedaron "desconectados" (detached) tras el Hard Reset al estado del 23 de Enero.

Si se desea recuperar alguna de estas funcionalidades, se puede usar `git cherry-pick <hash>` o `git reset --hard <hash>` (con precaución).

## 🟢 Opciones de Restauración (De más reciente a más antiguo)

### 1. Simplificación de Rutas PDF (Recomendado)
**Hash:** `f11b202`
**Descripción:** Elimina la lógica compleja de búsqueda y usa rutas directas (`clients/CODE/uploads/...`) tal como lo hace el visor original. Soluciona el problema de "dar vueltas" y tiempos de carga.
**Comando para restaurar este estado:**
```bash
git reset --hard f11b202
git push --force
```

### 2. Fix Rutas Duplicadas + Script Debug
**Hash:** `988b9bf`
**Descripción:** Incluye la lógica para evitar `uploads/uploads/` y agrega el script `modules/resaltar/list_pdfs.php` para diagnóstico.

### 3. Manejo Robusto de Errores
**Hash:** `d0c8dea`
**Descripción:** Implementación inicial de manejo de errores JSON y búsqueda de rutas base.

### 4. Aislamiento Búsqueda Voraz (Estado Limpio)
**Hash:** `9555e02`
**Descripción:** Contiene toda la lógica de aislamiento (`voraz_` prefix, CSS, headers) pero SIN los intentos de arreglar el PDF unificado. Es un buen punto de partida si se quiere re-hacer la lógica del PDF desde cero.
**Comando para restaurar:**
```bash
git reset --hard 9555e02
git push --force
```

---

**Nota:** Estos commits existen en el historial de Git pero no están en la rama `main` actual.
