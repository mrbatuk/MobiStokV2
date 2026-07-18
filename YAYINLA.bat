@echo off
chcp 65001 >nul
cd /d "%~dp0"
title MobiStokV2 - Surum Yayinla

rem === Mevcut surumu oku ===
set "CUR="
if exist VERSION set /p CUR=<VERSION
echo.
echo   Mevcut surum: %CUR%
echo.

rem === Yeni surum + not al ===
set /p "NEWV=Yeni surum (orn: 1.0.1): "
if "%NEWV%"=="" (
  echo   Surum numarasi bos olamaz. Iptal edildi.
  echo.
  pause
  exit /b
)
set /p "NOT=Degisiklik notu (kullanicilar gorecek): "
if "%NOT%"=="" set "NOT=Kucuk iyilestirmeler ve duzeltmeler"

echo.
echo   ----------------------------------------
echo   Yeni surum : v%NEWV%
echo   Not        : %NOT%
echo   ----------------------------------------
echo.
set /p "ONAY=Yayinlansin mi? (E/H): "
if /i not "%ONAY%"=="E" (
  echo   Iptal edildi.
  echo.
  pause
  exit /b
)

rem === VERSION dosyasini guncelle ===
>VERSION echo %NEWV%

echo.
echo   Yayinlaniyor: v%NEWV% ...
echo.

git add -A
git commit -m "v%NEWV%: %NOT%"
git push
if errorlevel 1 (
  echo.
  echo   HATA: git push basarisiz. Internet / GitHub erisimini kontrol edin.
  echo.
  pause
  exit /b
)

gh release create v%NEWV% --title "v%NEWV%" --notes "%NOT%"
if errorlevel 1 (
  echo.
  echo   HATA: Surum olusturulamadi. (Ayni surum zaten var olabilir.)
  echo.
  pause
  exit /b
)

echo.
echo   ========================================
echo   BASARILI  -  v%NEWV% yayinlandi.
echo   Dukkandaki bilgisayarlar Ayarlar ^> Guncelle
echo   ile bu surumu cekebilir.
echo   ========================================
echo.
pause
