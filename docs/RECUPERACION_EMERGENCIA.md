# 🆘 Recuperación de Emergencia (Admin)

Si pierdes acceso al correo de administrador (`kinotracemaster@gmail.com`) y olvidaste la contraseña, no podrás usar el enlace de "¿Olvidaste tu contraseña?".

Como tienes acceso al servidor (Railway/Código), puedes **forzar** un cambio de correo o contraseña directamente en la base de datos.

## Pasos para recuperar acceso

### 1. Crear un script de emergencia
Crea un archivo llamado `emergency_fix.php` en la raíz de tu proyecto con este contenido:

```php
<?php
require 'config.php';

// 1. Pon aquí tu NUEVO correo
$new_email = 'tucorreo_nuevo@gmail.com';

// 2. (Opcional) Si quieres resetear la clave directamente a '123456' descomenta esto:
// $new_pass = password_hash('123456', PASSWORD_DEFAULT);
// $centralDb->exec("UPDATE control_clientes SET password_hash = '$new_pass' WHERE codigo = 'admin'");

try {
    // Actualizar el correo del admin
    $stmt = $centralDb->prepare("UPDATE control_clientes SET email = ? WHERE codigo = 'admin'");
    $stmt->execute([$new_email]);
    
    echo "✅ Correo de admin actualizado a: $new_email <br>";
    echo "Ahora ve a /forgot_password.php y pide el enlace de nuevo.";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
```

### 2. Subir y Ejecutar
1. Haz commit y push de este archivo a `main`.
2. Abre en tu navegador: `https://kino-trace.com/emergency_fix.php`
3. Verás el mensaje de "Correo actualizado".

### 3. ¡IMPORTANTE! Borrar el archivo
Una vez recuperes el acceso, **borra inmediatamente** el archivo `emergency_fix.php` del repositorio y haz push de nuevo. Dejarlo ahí es un riesgo de seguridad grave.
