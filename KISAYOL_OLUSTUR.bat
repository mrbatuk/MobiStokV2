@echo off
rem Proje yolu (sondaki ters bolu atilir)
set "PROJE=%~dp0"
set "PROJE=%PROJE:~0,-1%"

rem Yollar ortam degiskeni olarak PowerShell'e verilir (bosluklu yol sorun cikarmaz).
set "MOBI_PROJE=%PROJE%"
set "MOBI_BASLAT=%PROJE%\BASLAT.bat"
set "MOBI_IKON=%PROJE%\public\assets\icon.ico"

rem Tek satir PowerShell. Sonuc kucuk bir açilir pencerede gosterilir:
rem  - basarida 2 sn sonra kendiliginden kapanir (pause YOK, pencere kapanir)
rem  - hatada mesaj OK'e basilana kadar durur.
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $ws=New-Object -ComObject WScript.Shell; try { $desk=[Environment]::GetFolderPath('Desktop'); if([string]::IsNullOrEmpty($desk)){ $desk=Join-Path $env:USERPROFILE 'Desktop' }; $lnk=Join-Path $desk 'MobiStokV2.lnk'; $s=$ws.CreateShortcut($lnk); $s.TargetPath=$env:MOBI_BASLAT; $s.WorkingDirectory=$env:MOBI_PROJE; if(Test-Path $env:MOBI_IKON){ $s.IconLocation=$env:MOBI_IKON }; $s.Description='MobiStokV2 - Telefon Stok Takip'; $s.Save(); [void]$ws.Popup(('Masaustu kisayolu olusturuldu.' + [char]10 + $lnk), 2, 'MobiStokV2', 64) } catch { [void]$ws.Popup(('Kisayol olusturulamadi.' + [char]10 + $_.Exception.Message), 0, 'MobiStokV2 - HATA', 16) }"
