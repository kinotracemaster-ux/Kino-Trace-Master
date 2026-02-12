---
description: Sincronizar rama pruebas a main cuando todo funciona correctamente
---

# Sincronizar Pruebas → Main

Solo ejecutar cuando pruebas está verificada y funcional.

// turbo-all

1. Asegurar que estamos en pruebas y todo está committed:
```bash
git checkout pruebas && git status
```

2. Crear backup de main antes de sincronizar:
```bash
git tag pre-sync-main-$(Get-Date -Format 'yyyy-MM-dd-HHmm') main
```

3. Hacer merge a main:
```bash
git checkout main && git merge pruebas --no-edit
```

4. Push a main:
```bash
git push origin main
```

5. Volver a pruebas:
```bash
git checkout pruebas
```

6. Confirmar estado:
```bash
git log --oneline -3
```
