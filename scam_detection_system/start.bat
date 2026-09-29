@echo off
rem ------------------------------------------------------------------
rem start.bat - Start the Scam Detection System (Windows).
rem Double-click this file. The website opens in your browser.
rem Close this window (or press Ctrl+C) to stop the app.
rem ------------------------------------------------------------------
title Scam Detection System
cd /d "%~dp0"
set "PY=%~dp0venv\Scripts\python.exe"

if not exist "%PY%" goto :needs_setup
if not exist ".env" goto :needs_setup
if not exist "models\model.pkl" goto :needs_setup

echo ============================================================
echo  Scam Detection System is starting...
echo  Website:  http://127.0.0.1:5000
echo  Keep this window open while you use the app.
echo  To stop the app, close this window or press Ctrl+C.
echo ============================================================
echo.

rem Open the browser after a short delay, while the server starts.
start "" /b cmd /c "timeout /t 3 /nobreak >nul & explorer http://127.0.0.1:5000"

"%PY%" app.py

echo.
echo The app has stopped.
pause
exit /b 0

:needs_setup
echo The app is not set up yet (or the model is missing).
echo Please double-click setup.bat first, then run start.bat again.
pause
exit /b 1
