@echo off
title Sistema Medico Portable - Alex Avila
set "PHP_BIN=%~dp0php\php.exe"
set "PHP_INI=%~dp0php\php.ini"

echo ---------------------------------------------------
echo INICIANDO SISTEMA CON DRIVER SQLITE
echo ---------------------------------------------------

:: Inicia el servidor especificando la ruta del archivo de configuracion (-c)
start http://127.0.0.1:8080
"%PHP_BIN%" -c "%PHP_INI%" -S 127.0.0.1:8080
pause