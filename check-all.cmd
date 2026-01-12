@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"

call :checkRepo "%ROOT%" "PUBLIC"
call :checkRepo "%ROOT%\Helix" "HELIX"
call :checkRepo "%ROOT%\ripair-ecommerce" "ECOMMERCE"

echo.
echo OK.
exit /b 0

:checkRepo
set "DIR=%~1"
set "NAME=%~2"

echo.
echo === %NAME% ===
if not exist "%DIR%\.git\" (
  echo ERROR: "%DIR%" n'est pas un repo git (dossier .git introuvable).
  exit /b 1
)

pushd "%DIR%" >nul || (echo ERROR: impossible d'entrer dans "%DIR%" & exit /b 1)
git status -sb
git remote -v
echo Branche: 
git branch --show-current
popd >nul
exit /b 0

