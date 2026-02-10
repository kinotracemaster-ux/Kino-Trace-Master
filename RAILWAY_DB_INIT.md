# Instrucciones: Inicializar Base de Datos en Railway

## 📋 Resumen

Hemos creado un script `init_volume.php` que copia las bases de datos iniciales al volumen persistente de Railway.

## ✅ Pasos a Seguir

### 1. Esperar el Redeploy

Railway está redesple desplegando automáticamente después del push. Espera a que el servicio vuelva a estar "Online" (30-60 segundos).

### 2. Ejecutar el Script de Inicialización

Una vez que el servicio esté online, ejecutar desde la consola de Railway:

```bash
railway run -- php /var/www/html/init_volume.php
```

### 3. Verificar la Salida

El script mostrará:
- ✅ Bases de datos copiadas exitosamente
- ⏭ Si ya existían (ejecuciones posteriores)
- ❌ Errores (si los hay)

## 🔍 Verificación

Para verificar que las bases de datos están en el volumen:

```bash
# Listar archivos en el volumen
railway run -- ls -lh /var/www/html/clients

# Verificar base de datos central
railway run -- sqlite3 /var/www/html/clients/central.db "SELECT COUNT(*) FROM control_clientes;"
```

## 📝 Archivos Incluidos

| Archivo | Descripción |
|---------|-------------|
| `init_volume.php` | Script de inicialización |
| `database_initial/central.db` | Base de datos central inicial |
| `database_initial/logs.db` | Base de datos de logs inicial |

## ⚠️ Importante

- El script solo copia las bases de datos **si NO existen** en el volumen
- Es seguro ejecutarlo múltiples veces
- Los datos en el volumen **persisten** entre despliegues
- Los datos en `database_initial/` son solo para inicialización

## 🔄 Alternativa: Desde la Web

Si prefieres ejecutar desde la interfaz web de Railway:

1. Railway → Tu proyecto → Deployments
2. Click en los tres puntos → "Open Shell"
3. Ejecutar:
   ```bash
   php init_volume.php
   ```

## 🎯 Próximos Pasos

1. ✅ Esperar redeploy
2. ✅ Ejecutar `init_volume.php`
3. ✅ Verificar que las bases de datos se copiaron
4. ✅ Probar el login en la aplicación
