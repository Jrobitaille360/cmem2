@echo off
REM Script de maintenance des occurrences pour Windows
REM À utiliser avec le Planificateur de tâches Windows

echo ========================================
echo Maintenance des occurrences CMEM2
echo %DATE% %TIME%
echo ========================================

cd /d "%~dp0"

REM Vérifier si PHP est disponible
php --version >nul 2>&1
if errorlevel 1 (
    echo ERREUR: PHP n'est pas disponible dans le PATH
    exit /b 1
)

REM Exécuter la maintenance
echo Execution de la maintenance...
php src\ics\maintenance_occurrences.php

if errorlevel 1 (
    echo ERREUR: La maintenance a échoué
    exit /b 1
)

echo.
echo Maintenance terminée avec succès
echo ========================================

REM Pause si exécuté manuellement (enlever pour cron job)
REM pause