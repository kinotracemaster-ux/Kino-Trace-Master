<#
  local/construir/construir.ps1 — Arma el paquete Windows de un cliente desde un PC Windows
  del ADMINISTRADOR, sin tener PHP instalado. Guía: docs/modo_local/DESPLIEGUE_PC.md

  Uso (PowerShell, desde la carpeta del repo):
    powershell -ExecutionPolicy Bypass -File local\construir\construir.ps1 -Datos C:\ruta\losmonta_20261022.ktdata
    powershell -ExecutionPolicy Bypass -File local\construir\construir.ps1 -Comando llaves
    powershell -ExecutionPolicy Bypass -File local\construir\construir.ps1 -Comando licencia -Cliente losmonta -Equipo KT-1A2B-3C4D-5E6F-7A8B

  Descarga PHP para Windows (verificando su SHA-256 publicado por php.net) a
  %LOCALAPPDATA%\KinoTraceConstructor y con él ejecuta local\herramientas\kt.php.
#>
param(
    [string]$Comando = 'paquete',
    [string]$Datos,
    [string]$Salida = (Join-Path (Get-Location) 'paquetes'),
    [string]$Nube = 'https://kino-trace.com',
    [string]$Cliente,
    [string]$Equipo,
    [int]$Dias = 30
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$repo  = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$cache = Join-Path $env:LOCALAPPDATA 'KinoTraceConstructor'
$phpDir = Join-Path $cache 'php'
$php   = Join-Path $phpDir 'php.exe'
$phpZip = $null

if (-not (Test-Path $php)) {
    New-Item -ItemType Directory -Force $cache | Out-Null
    Write-Host 'Descargando PHP para Windows (solo la primera vez)...'
    $rel = Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json'
    $zipInfo = $null
    foreach ($v in @('8.3', '8.2', '8.4')) {
        if ($rel.$v) {
            $p = $rel.$v.PSObject.Properties | Where-Object { $_.Name -match '^nts-vs\d+-x64$' } | Select-Object -First 1
            if ($p) { $zipInfo = $p.Value.zip; break }
        }
    }
    if (-not $zipInfo) { throw 'No se encontró PHP para Windows en releases.json' }
    $phpZip = Join-Path $cache (Split-Path $zipInfo.path -Leaf)
    Invoke-WebRequest "https://windows.php.net/downloads/releases/$($zipInfo.path)" -OutFile $phpZip -UseBasicParsing
    $hash = (Get-FileHash $phpZip -Algorithm SHA256).Hash
    if ($hash -ne $zipInfo.sha256.ToUpper()) { Remove-Item $phpZip; throw 'El PHP descargado no coincide con su SHA-256. No se usa.' }
    Expand-Archive $phpZip -DestinationPath $phpDir -Force
    # Ruta con "/" y entre comillas; archivo en UTF-8 SIN BOM (en ASCII una carpeta de usuario
    # con acentos, p. ej. C:\Users\José, quedaba como "Jos?" y no cargaba ninguna extensión)
    $extDir = (Join-Path $phpDir 'ext') -replace '\\', '/'
    $ini = @('; php.ini del constructor de paquetes KINO TRACE', "extension_dir = `"$extDir`"")
    foreach ($e in @('curl', 'fileinfo', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'sodium', 'zip')) {
        if (Test-Path (Join-Path $phpDir "ext\php_$e.dll")) { $ini += "extension=$e" }
    }
    $ini += 'memory_limit=2048M'
    [IO.File]::WriteAllLines((Join-Path $phpDir 'php.ini'), [string[]]$ini, (New-Object System.Text.UTF8Encoding $false))
} else {
    $phpZip = Get-ChildItem $cache -Filter 'php-*.zip' | Select-Object -First 1 -ExpandProperty FullName
}

$kt = Join-Path $repo 'local\herramientas\kt.php'
$iniPath = Join-Path $phpDir 'php.ini'

switch ($Comando) {
    'paquete' {
        if (-not $Datos) { throw 'Indique -Datos con el archivo .ktdata del cliente' }
        $ktArgs = @('-c', $iniPath, $kt, 'paquete', '--datos', $Datos, '--salida', $Salida, '--nube', $Nube, '--cache', (Join-Path $cache 'descargas'))
        if ($phpZip) { $ktArgs += @('--php-zip', $phpZip) }
        New-Item -ItemType Directory -Force $Salida | Out-Null
        & $php @ktArgs
    }
    'licencia' {
        & $php -c $iniPath $kt licencia --cliente $Cliente --equipo $Equipo --dias $Dias
    }
    default {
        & $php -c $iniPath $kt $Comando
    }
}
