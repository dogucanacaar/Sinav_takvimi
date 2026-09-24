@echo off
rem Windows'ta cift tiklayarak ya da "guncelle" yazarak calistirmak icin.
rem
rem PowerShell varsayilan olarak betik calistirmayi kapatir; bu sarmalayici
rem politikayi sadece bu calistirma icin atlar, sistemde kalici bir
rem degisiklik yapmaz.

setlocal
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0guncelle.ps1"
echo.
pause
