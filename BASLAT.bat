@echo off
title Telefoncu Takip - Baslat
cd /d "%~dp0"

rem Eski sunucu calısıyorsa kapat (port 47810)
for /f "tokens=5" %%p in ('netstat -ano ^| findstr ":47810 " ^| findstr "LISTENING"') do (
    taskkill /PID %%p /F >nul 2>&1
)
ping -n 1 127.0.0.1 >nul

rem Yeni oturum tokeni yaz
powershell -NoProfile -Command "[System.IO.File]::WriteAllText('%~dp0data\server_token.txt', [System.Guid]::NewGuid().ToString())"

rem Sunucuyu gizli pencerede baslat
set PHP_EXE=%~dp0bin\php\php.exe
set PRJ_DIR=%~dp0
powershell -NoProfile -Command "Start-Process -WindowStyle Hidden -FilePath $env:PHP_EXE -ArgumentList '-S','127.0.0.1:47810','-t','public' -WorkingDirectory $env:PRJ_DIR"

ping -n 2 127.0.0.1 >nul
start "" http://127.0.0.1:47810
