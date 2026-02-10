# Script de Migración a Railway - KINO-TRACE
# Ejecutar en PowerShell como Administrador

Write-Host "=== Migración de Base de Datos a Railway ===" -ForegroundColor Cyan
Write-Host ""

# Configuración
$projectPath = "c:\Users\Usuario\Desktop\MASTER KINO-TRACE version F1"
$backupDir = "$projectPath\backups"

# Paso 1: Crear backup
Write-Host "[1/6] Creando backup de bases de datos..." -ForegroundColor Yellow
if (-not (Test-Path $backupDir)) {
    New-Item -ItemType Directory -Path $backupDir | Out-Null
}

$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
Copy-Item "$projectPath\clients\central.db" "$backupDir\central_$timestamp.db" -ErrorAction SilentlyContinue
Copy-Item "$projectPath\clients\logs\logs.db" "$backupDir\logs_$timestamp.db" -ErrorAction SilentlyContinue

Write-Host "✓ Backup creado en: $backupDir" -ForegroundColor Green
Write-Host ""

# Paso 2: Verificar Railway CLI
Write-Host "[2/6] Verificando Railway CLI..." -ForegroundColor Yellow
$railwayInstalled = Get-Command railway -ErrorAction SilentlyContinue

if (-not $railwayInstalled) {
    Write-Host "⚠ Railway CLI no encontrado. Instalando..." -ForegroundColor Red
    Write-Host "Ejecutando: npm install -g @railway/cli" -ForegroundColor Gray
    npm install -g @railway/cli
    
    if ($LASTEXITCODE -ne 0) {
        Write-Host "❌ Error instalando Railway CLI. Por favor instálalo manualmente:" -ForegroundColor Red
        Write-Host "   npm install -g @railway/cli" -ForegroundColor White
        exit 1
    }
}

Write-Host "✓ Railway CLI disponible" -ForegroundColor Green
Write-Host ""

# Paso 3: Login a Railway
Write-Host "[3/6] Autenticación en Railway..." -ForegroundColor Yellow
Write-Host "Se abrirá tu navegador para autenticarte..." -ForegroundColor Gray
railway login

if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Error en autenticación. Abortando." -ForegroundColor Red
    exit 1
}

Write-Host "✓ Autenticado correctamente" -ForegroundColor Green
Write-Host ""

# Paso 4: Vincular proyecto
Write-Host "[4/6] Vinculando proyecto..." -ForegroundColor Yellow
Set-Location $projectPath
railway link

if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Error vinculando proyecto. Abortando." -ForegroundColor Red
    exit 1
}

Write-Host "✓ Proyecto vinculado" -ForegroundColor Green
Write-Host ""

# Paso 5: Subir bases de datos
Write-Host "[5/6] Subiendo bases de datos a Railway..." -ForegroundColor Yellow

Write-Host "  → Subiendo central.db..." -ForegroundColor Gray
Get-Content "clients\central.db" -Raw -AsByteStream | railway run -- sh -c "cat > /var/www/html/clients/central.db"

Write-Host "  → Creando directorio logs..." -ForegroundColor Gray
railway run -- sh -c "mkdir -p /var/www/html/clients/logs"

Write-Host "  → Subiendo logs.db..." -ForegroundColor Gray
Get-Content "clients\logs\logs.db" -Raw -AsByteStream | railway run -- sh -c "cat > /var/www/html/clients/logs/logs.db"

Write-Host "✓ Bases de datos subidas" -ForegroundColor Green
Write-Host ""

# Paso 6: Verificar
Write-Host "[6/6] Verificando migración..." -ForegroundColor Yellow
railway run -- ls -lh /var/www/html/clients

Write-Host ""
Write-Host "=== Migración Completada ===" -ForegroundColor Green
Write-Host ""
Write-Host "Próximos pasos:" -ForegroundColor Cyan
Write-Host "1. Verifica que los archivos aparecen en la salida anterior"
Write-Host "2. Accede a tu aplicación en Railway"
Write-Host "3. Prueba el login con usuarios existentes"
Write-Host ""
Write-Host "Backups guardados en: $backupDir" -ForegroundColor Yellow
