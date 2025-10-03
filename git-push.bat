@echo off
setlocal

REM Script de push Git simplifié
REM Usage : git-push-simple.bat "Message de commit"

if "%~1"=="" (
    echo Usage: %0 "Message de commit"
    exit /b 1
)

echo Ajout des fichiers...
git add -A

echo Status:
git status --short

echo Commit avec message: %1
git commit -m %1

if errorlevel 1 (
    echo Erreur lors du commit
    exit /b 1
)

echo Push vers origin...
git push origin main

echo Terminé.
endlocal
