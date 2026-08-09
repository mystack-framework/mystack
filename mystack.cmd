@echo off
setlocal
php "%~dp0mystack" %*
exit /b %errorlevel%
