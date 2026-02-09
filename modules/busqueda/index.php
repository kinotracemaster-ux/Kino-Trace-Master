<?php
/**
 * Redirección a la interfaz principal unificada (Búsqueda Voraz)
 * * Esto asegura que siempre se use la versión más actualizada ubicada en la raíz,
 * evitando duplicidad de código y errores de versiones.
 */

// Redirigir a la raíz del sistema (ajustar la profundidad según sea necesario)
header("Location: ../../index.php?tab=voraz");
exit;
?>