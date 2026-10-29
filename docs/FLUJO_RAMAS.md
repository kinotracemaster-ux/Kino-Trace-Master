# Flujo de trabajo con ramas (KINO TRACE)

Guía corta para trabajar con ramas en `kinotracemaster-ux/Kino-Trace-Master`.
Carpeta local (Windows): `C:\Users\USAURIO\OneDrive\Documents\PROGRAMAS LOCAL\KINO TRACE\Kino-Trace-Master-main`

## La idea en una frase

`main` es la versión buena (la que se despliega en Railway). Cada cambio nuevo se hace en
una **rama** aparte; cuando está listo se abre un **Pull Request (PR)**, se revisa y se
**fusiona (merge)** a `main`. Si algo sale mal en una rama, `main` no se toca.

```
main ──●──────────────●────────  (siempre estable)
        \            /
         ●──●──●────●            rama: claude/resaltado-rapido
         cambios    PR + merge
```

## Reglas del proyecto

1. **Una rama por tarea.** Nombre corto y claro: `claude/<tema>` o `fix/<tema>`.
2. **Nunca trabajar directo en `main`.**
3. **Todo entra a `main` por PR.** El PR describe qué cambió y cómo probarlo.
4. Tras fusionar un PR, **actualizar la carpeta local** (ver paso 6).
5. Si la tarea cambia la arquitectura o descubre algo importante, **actualizar `CLAUDE.md`** en la misma rama.

## Paso a paso (comandos en PowerShell o Git Bash)

```bash
# 0. Ir a la carpeta del proyecto
cd "C:\Users\USAURIO\OneDrive\Documents\PROGRAMAS LOCAL\KINO TRACE\Kino-Trace-Master-main"

# 1. Partir de main actualizado
git checkout main
git pull origin main

# 2. Crear la rama de la tarea
git checkout -b fix/nombre-tarea

# 3. Hacer cambios y guardarlos (commit)
git add .
git commit -m "fix: describe el cambio en una línea"

# 4. Subir la rama a GitHub
git push -u origin fix/nombre-tarea

# 5. Abrir el PR en GitHub (botón "Compare & pull request"), revisarlo y "Merge"

# 6. Volver a main y traer lo fusionado
git checkout main
git pull origin main
git branch -d fix/nombre-tarea   # opcional: borra la rama local ya fusionada
```

## Cuando Claude hace el trabajo

Claude trabaja en la nube: crea la rama, hace commit, push y abre el PR. Tú solo:

1. Abres el link del PR y revisas los cambios ("Files changed").
2. Pruebas si hace falta (Railway puede desplegar la rama, ver `APP_BRANCH`).
3. Pulsas **Merge pull request**.
4. En tu PC: `git checkout main` y `git pull origin main`.

## Comandos de rescate

| Situación | Comando |
|---|---|
| ¿En qué rama estoy? | `git status` |
| Ver todas las ramas | `git branch -a` |
| Cambiar a otra rama | `git checkout nombre-rama` |
| Probar la rama de un PR en mi PC | `git fetch origin` y luego `git checkout nombre-rama` |
| Descartar cambios no guardados de un archivo | `git restore ruta/archivo` |
| Tengo cambios sin commit y quiero cambiar de rama | `git stash`, cambiar, luego `git stash pop` |

## Memoria para ahorrar tokens

- `CLAUDE.md` (raíz): resumen de arquitectura y reglas. Cada hilo de Claude lo lee primero.
- `MAPA_TECNICO.md`: mapa detallado (acciones de `api.php`, esquema SQLite, helpers).
- `docs/FLUJO_RAMAS.md`: este archivo.

Mantenerlos cortos y verdaderos: si un cambio los vuelve falsos, se corrigen en el mismo PR.
