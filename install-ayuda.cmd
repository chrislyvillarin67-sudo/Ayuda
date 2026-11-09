@echo off
title Ayuda - Database Setup
rem One-click install on a fresh machine: starts MySQL + the PHP server,
rem then opens the built-in installer (setup_database.php), which creates the
rem ayuda_db database and imports sql\ayuda_db_full.sql.
echo Starting Ayuda services, then opening the database installer...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-ayuda.ps1" -NoOpen
if errorlevel 1 (
    echo.
    echo ============================================================
    echo  Ayuda could NOT start on this computer. Read the message
    echo  above this window - it says exactly what is missing.
    echo.
    echo  Most common cause: XAMPP is not installed yet. Install it
    echo  from https://www.apachefriends.org/ using the default
    echo  settings, then double-click install-ayuda.cmd again.
    echo ============================================================
    echo.
    pause
    exit /b 1
)
start "" "http://127.0.0.1:8099/setup_database.php"