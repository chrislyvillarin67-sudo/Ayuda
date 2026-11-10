param(
    [switch]$Watch,
    [switch]$NoOpen,
    [switch]$Stop
)

# Ayuda dev environment launcher + watchdog.
#   - Ensures MySQL is running
#   - Ensures the built-in PHP web server is serving the app (via router.php,
#     which blocks sql/, _keep/, logs/ and .sql/.log files the same way
#     .htaccess does under Apache)
#   - With -Watch, keeps both alive (auto-restarts) until the window closes
#   - With -Stop, shuts the web server down so the folder can be copied or
#     archived. The server's own logs (php.out.log / php.err.log) live in
#     %TEMP%\ayuda, NOT in this folder: the server keeps those files open for
#     its whole lifetime, and a log file inside the folder makes archiving fail
#     with "the process cannot access the file because it is being used by
#     another process". Only logs\services.log stays in the folder (it is opened
#     and closed on every write, so it never blocks a copy or an archive).

$appRoot     = $PSScriptRoot
$url         = "http://127.0.0.1:8099"

# ── Locate PHP + MySQL (XAMPP/WAMP/Laragon, or PATH) ────────────────────────
# The app folder travels, but PHP/MySQL live in whatever stack this machine
# has. Search the common install locations first, then PATH, so the folder
# still runs after being copied to a laptop whose XAMPP is somewhere else.
function Find-FirstExe([string[]]$dirs, [string]$exe) {
    foreach ($d in $dirs) {
        if ($d -and (Test-Path $d)) {
            $p = Join-Path $d $exe
            if (Test-Path $p) { return $p }
        }
    }
    return $null
}

$phpDirs = @(
    'C:\xampp\php',
    'C:\xampp8\php',
    'C:\Program Files\XAMPP\php',
    "$env:SystemDrive\xampp\php"
)
$phpDirs += Get-ChildItem 'C:\wamp64\bin\php','C:\laragon\bin\php' -Directory -ErrorAction SilentlyContinue |
    ForEach-Object { $_.FullName }

$mysqlDirs = @(
    'C:\xampp\mysql\bin',
    'C:\xampp8\mysql\bin',
    'C:\Program Files\XAMPP\mysql\bin',
    "$env:SystemDrive\xampp\mysql\bin"
)
$mysqlDirs += Get-ChildItem 'C:\wamp64\bin\mysql','C:\laragon\bin\mysql' -Directory -ErrorAction SilentlyContinue |
    ForEach-Object { Join-Path $_.FullName 'bin' }

# XAMPP is not always on C: - scan every fixed drive for a standard install
# (D:\xampp, E:\xampp, a portable stick, ...) so a copied folder still finds it.
foreach ($psd in (Get-PSDrive -PSProvider FileSystem -ErrorAction SilentlyContinue)) {
    $base = $psd.Root
    if ($base) {
        $phpDirs   += Join-Path $base 'xampp\php'
        $mysqlDirs += Join-Path $base 'xampp\mysql\bin'
    }
}

$phpExe     = Find-FirstExe $phpDirs   'php.exe'
$mysqlExe   = Find-FirstExe $mysqlDirs 'mysqld.exe'
$mysqlAdmin = Find-FirstExe $mysqlDirs 'mysqladmin.exe'

if (-not $phpExe)     { $phpExe     = (Get-Command php.exe -ErrorAction SilentlyContinue).Source }
if (-not $mysqlExe)   { $mysqlExe   = (Get-Command mysqld.exe -ErrorAction SilentlyContinue).Source }
if (-not $mysqlAdmin) { $mysqlAdmin = (Get-Command mysqladmin.exe -ErrorAction SilentlyContinue).Source }

# my.ini usually sits next to mysqld.exe, sometimes one level up.
$myIni = $null
if ($mysqlExe) {
    $binDir = Split-Path $mysqlExe -Parent
    foreach ($cand in @((Join-Path $binDir 'my.ini'),
                        (Join-Path (Split-Path $binDir -Parent) 'my.ini'))) {
        if (Test-Path $cand) { $myIni = $cand; break }
    }
}

if (-not $phpExe) {
    Write-Host "PHP (php.exe) not found. Install XAMPP, or set the paths near the top of start-ayuda.ps1." -ForegroundColor Red
    exit 1
}
if (-not $mysqlExe -or -not $mysqlAdmin) {
    Write-Host "MySQL (mysqld.exe / mysqladmin.exe) not found. Install XAMPP, or set the paths near the top of start-ayuda.ps1." -ForegroundColor Red
    exit 1
}
$logDir = Join-Path $appRoot "logs"
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir | Out-Null }
$logFile = Join-Path $logDir "services.log"

# The built-in PHP server keeps its stdout/stderr handles open for its entire
# lifetime, so those two logs must live OUTSIDE this folder - a log file inside
# the folder makes every copy/archive of the project fail with "the process
# cannot access the file because it is being used by another process".
$phpLogDir = Join-Path $env:TEMP "ayuda"
if (-not (Test-Path $phpLogDir)) { New-Item -ItemType Directory -Path $phpLogDir -Force | Out-Null }

function Write-Log($msg) {
    $line = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $msg"
    Add-Content -Path $logFile -Value $line
    Write-Host $line
}

Write-Log "PHP:   $phpExe"
Write-Log "MySQL: $mysqlExe $(if ($myIni) { "(config $myIni)" } else { '(no my.ini found)' })"

function Test-MySql {
    & $mysqlAdmin -u root status *> $null
    return ($LASTEXITCODE -eq 0)
}

function Test-Web {
    try {
        $r = Invoke-WebRequest -Uri "$url/login.php" -UseBasicParsing -TimeoutSec 3 -ErrorAction Stop
        return ($r.StatusCode -eq 200)
    } catch { return $false }
}

function Start-MySql {
    if (Test-MySql) { Write-Log "MySQL: already running"; return $true }
    Write-Log "MySQL: starting mysqld ..."
    if ($myIni) {
        Start-Process -FilePath $mysqlExe -ArgumentList "--defaults-file=`"$myIni`"" -WindowStyle Hidden
    } else {
        Start-Process -FilePath $mysqlExe -WindowStyle Hidden
    }
    for ($i = 0; $i -lt 15; $i++) {
        Start-Sleep -Seconds 2
        if (Test-MySql) { Write-Log "MySQL: started OK"; return $true }
    }
    Write-Log "MySQL: FAILED to start - open XAMPP Control Panel / check my.ini"
    return $false
}

function Get-WebProcs {
    # Only this app's server: php -S started from this folder.
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine -like "*$appRoot*" }
}

function Stop-Web {
    $procs = @(Get-WebProcs)
    if ($procs.Count -eq 0) { Write-Log "Web: not running"; return }
    foreach ($p in $procs) {
        Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
    }
    Start-Sleep -Milliseconds 400
    Write-Log "Web: stopped (pid $($procs.ProcessId -join ', ')) - released the web server and its log files"
}

function Start-Web {
    if (Test-Web) { Write-Log "Web: already serving $url"; return $true }
    Write-Log "Web: starting PHP server on $url ..."
    # The built-in server writes a verbose access log to stderr; keep it from
    # growing without bound (it reached ~1.8 MB).
    foreach ($f in @('php.out.log', 'php.err.log')) {
        $lp = Join-Path $phpLogDir $f
        if ((Test-Path $lp) -and (Get-Item $lp).Length -gt 1MB) {
            Set-Content -LiteralPath $lp -Value '' -NoNewline
            Write-Log "Web: rotated $f (was over 1 MB)"
        }
    }
    Start-Process -FilePath $phpExe -ArgumentList "-S","127.0.0.1:8099","-t","`"$appRoot`"","`"$appRoot\router.php`"" -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $phpLogDir "php.out.log") -RedirectStandardError (Join-Path $phpLogDir "php.err.log")
    for ($i = 0; $i -lt 15; $i++) {
        Start-Sleep -Seconds 2
        if (Test-Web) { Write-Log "Web: started OK"; return $true }
    }
    Write-Log "Web: FAILED to serve $url - see $phpLogDir\php.err.log"
    Write-Log "Web: if that log says 'bind: Only one usage of each socket address' another program owns port 8099 - close it, or edit `$url near the top of start-ayuda.ps1"
    return $false
}

Write-Log "=== Ayuda services check ($url) ==="

function Stop-Watchdog {
    # A running -Watch instance restarts the web server within ~12 seconds,
    # which immediately re-locks the server's log files. It has to be stopped
    # first, otherwise -Stop looks like it did nothing.
    $dogs = @(Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" -ErrorAction SilentlyContinue |
        Where-Object {
            $_.ProcessId -ne $PID -and $_.CommandLine -and
            $_.CommandLine -match 'start-ayuda\.ps1' -and $_.CommandLine -match '-Watch'
        })
    if ($dogs.Count -eq 0) { Write-Log "Watchdog: not running"; return }
    foreach ($d in $dogs) {
        Stop-Process -Id $d.ProcessId -Force -ErrorAction SilentlyContinue
        Write-Log "Watchdog: stopped (pid $($d.ProcessId)) - it was auto-restarting the web server"
    }
    Start-Sleep -Milliseconds 400
}

if ($Stop) {
    # MySQL is deliberately left running: it only holds files under
    # C:\xampp\mysql\data, never anything inside this project folder.
    Stop-Watchdog
    Stop-Web
    Write-Host ""
    Write-Host "Web server stopped and watchdog disabled. The folder is now free of file locks." -ForegroundColor Green
    Write-Host "Archive/copy it now, then start again with:  .\start-ayuda.ps1" -ForegroundColor DarkGray
    exit 0
}

$mysqlOk = Start-MySql
$webOk   = Start-Web
if ((-not $mysqlOk) -or (-not $webOk)) {
    if (-not $Watch) {
        Write-Host ""
        Write-Host "Startup failed - see the messages above. Most common cause: XAMPP (PHP + MySQL) is not installed on this computer." -ForegroundColor Red
        exit 1
    }
}

if (-not $NoOpen) {
    try { Start-Process $url } catch { }
}

if ($Watch) {
    Write-Host ""
    Write-Host "Watchdog running - keeping MySQL and the web server alive." -ForegroundColor Cyan
    Write-Host "Close this window to stop everything." -ForegroundColor DarkGray
    while ($true) {
        Start-Sleep -Seconds 12
        if (-not (Test-MySql)) { Write-Log "MySQL went down - restarting";  $null = Start-MySql }
        if (-not (Test-Web))   { Write-Log "Web went down - restarting";    $null = Start-Web }
    }
}