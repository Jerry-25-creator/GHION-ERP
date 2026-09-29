@echo off
rem ------------------------------------------------------------------
rem setup.bat - One-time setup for the Scam Detection System (Windows).
rem Double-click this file. It is safe to run again: steps that are
rem already done (venv, .env, trained model) are skipped.
rem ------------------------------------------------------------------
title Scam Detection System - Setup
cd /d "%~dp0"

echo ============================================================
echo  Scam Detection System - one-time setup
echo ============================================================
echo.

rem --- 1. Check that Python 3.10 or newer is installed -----------------
python --version >nul 2>&1
if errorlevel 1 goto :no_python
python -c "import sys; sys.exit(0 if sys.version_info >= (3, 10) else 1)"
if errorlevel 1 goto :old_python
for /f "delims=" %%v in ('python --version') do echo [OK] Found %%v

rem --- 2. Create the virtual environment ---------------------------------
if exist "venv\Scripts\python.exe" goto :venv_ready
echo.
echo [1/5] Creating the virtual environment (venv)...
python -m venv venv
if errorlevel 1 goto :failed
:venv_ready
set "PY=%~dp0venv\Scripts\python.exe"

rem --- 3. Install the Python packages --------------------------------------
echo.
echo [2/5] Installing Python packages. This can take a few minutes...
"%PY%" -m pip install --upgrade pip --quiet --disable-pip-version-check
"%PY%" -m pip install -r requirements.txt --quiet --disable-pip-version-check
if errorlevel 1 goto :failed
echo [OK] Packages installed.

rem --- 4. Create .env with a random secret key -----------------------------
echo.
echo [3/5] Preparing the configuration file (.env)...
if exist ".env" goto :env_exists
copy /y ".env.example" ".env" >nul
if errorlevel 1 goto :failed
"%PY%" -c "import pathlib, secrets; p = pathlib.Path('.env'); p.write_text(p.read_text(encoding='utf-8').replace('replace-me-with-a-long-random-value', secrets.token_hex(32)), encoding='utf-8')"
if errorlevel 1 goto :failed
echo [OK] .env created with a new random SECRET_KEY.
goto :env_done
:env_exists
echo [OK] .env already exists - left unchanged.
:env_done

rem --- 5. Train the model -----------------------------------------------------
echo.
echo [4/5] Training the machine learning model...
if exist "models\model.pkl" if exist "models\vectorizer.pkl" goto :model_exists
"%PY%" train_model.py
if errorlevel 1 goto :failed
goto :model_done
:model_exists
echo [OK] A trained model already exists - skipped.
echo      To retrain, run:  venv\Scripts\python.exe train_model.py
:model_done

rem --- 6. Admin account (optional) ------------------------------------------
echo.
echo [5/5] Admin account for the dashboard.
choice /c YN /m "Create or update an admin account now"
if errorlevel 2 goto :skip_admin
"%PY%" create_admin.py
goto :admin_done
:skip_admin
echo Skipped. You can do it later with:  venv\Scripts\python.exe create_admin.py
:admin_done

rem --- 7. Quick self-test ------------------------------------------------------
echo.
echo Running the automated tests to check everything works...
"%PY%" -m pytest -q
if errorlevel 1 echo [WARNING] Some tests failed. The app may still run - see the messages above.

echo.
echo ============================================================
echo  Setup complete. Double-click start.bat to run the app.
echo ============================================================
pause
exit /b 0

:no_python
echo [ERROR] Python was not found.
echo Install Python 3.10 or newer from https://www.python.org/downloads/
echo and tick "Add python.exe to PATH" during installation. Then run setup.bat again.
pause
exit /b 1

:old_python
echo [ERROR] Python 3.10 or newer is required.
python --version
echo Install a newer version from https://www.python.org/downloads/
pause
exit /b 1

:failed
echo.
echo [ERROR] Setup stopped because the step above failed.
echo Check your internet connection and read the message above, then run setup.bat again.
pause
exit /b 1
