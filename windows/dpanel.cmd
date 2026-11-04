@echo off
rem dPanel for Windows launcher: double-click for the menu, or run "dpanel install", "dpanel start", ...
rem It only runs dpanel.ps1 next to it without changing the PowerShell execution policy.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0dpanel.ps1" %*
set "rc=%errorlevel%"
if "%~1"=="" pause
exit /b %rc%
