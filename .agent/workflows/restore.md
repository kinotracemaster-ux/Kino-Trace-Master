---
description: Restaurar el código a un punto de respaldo anterior si algo salió mal
---

# Restaurar desde Backup

Vuelve al estado de un backup anterior si los cambios causaron problemas.

// turbo-all

1. Listar los backups disponibles (más recientes primero):
```bash
git tag --list 'backup-*' --sort=-creatordate
```

2. Preguntar al usuario cuál backup quiere restaurar. Si no especifica, usar el más reciente.

3. Restaurar al backup elegido (reemplazar `TAG_NAME` con el tag):
```bash
git reset --hard TAG_NAME
```

4. Si la rama pruebas necesita actualizarse en remoto:
```bash
git push origin pruebas --force
```

5. Confirmar el estado actual:
```bash
git log --oneline -5
```
