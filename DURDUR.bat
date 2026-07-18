@echo off
title Telefoncu Takip - Durdur
set BULUNDU=0
for /f "tokens=5" %%p in ('netstat -ano ^| findstr ":47810 " ^| findstr "LISTENING"') do (
    taskkill /f /pid %%p >nul 2>&1
    set BULUNDU=1
)
if %BULUNDU%==1 (
    echo Sunucu durduruldu. Programi tekrar acmak icin BASLAT.bat calistirin.
) else (
    echo Sunucu zaten kapali.
)
ping -n 4 127.0.0.1 >nul
