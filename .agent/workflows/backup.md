---
description: Crear un punto de respaldo (tag) antes de hacer cambios arriesgados
---

# Crear Backup (Punto de Guardado)

Crea un tag de Git con la fecha actual para poder volver a este estado si algo sale mal.

// turbo-all

1. Verificar que no hay cambios sin guardar:
```bash
git status
```

2. Si hay cambios pendientes, hacer commit primero:
```bash
git add -A && git commit -m "backup: estado actual antes de cambios"
```

3. Crear el tag de respaldo con fecha y hora:
```bash
git tag backup-$(Get-Date -Format 'yyyy-MM-dd-HHmm')
```

4. Mostrar el tag creado:
```bash
git tag --list 'backup-*' --sort=-creatordate | Select-Object -First 5
```

5. Informar al usuario qué tag se creó y cómo restaurarlo.
