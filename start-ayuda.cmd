@echo off
title Ayuda Services - close this window to stop
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-ayuda.ps1" -Watch
if errorlevel 1 (
    echo.
    echo Ayuda services could not start - see the message above.
    echo If PHP or MySQL was not found, install XAMPP first, then
    echo run this file again.
    echo.
    pause
)