@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"

set "MSG=%*"
if "%MSG%"=="" set "MSG=chore: sync %DATE% %TIME%"

call :pushRepo "%ROOT%" "master" "PUBLIC" "%MSG%" || exit /b 1
call :pushRepo "%ROOT%\Helix" "master" "HELIX" "%MSG%" || exit /b 1
call :pushRepo "%ROOT%\ripair-ecommerce" "main" "ECOMMERCE" "%MSG%" || exit /b 1

echo.
echo Push terminÃ©.
exit /b 0

:pushRepo
set "DIR=%~1"
set "BRANCH=%~2"
set "NAME=%~3"
set "MESSAGE=%~4"

echo.
echo === %NAME% ===
if not exist "%DIR%\.git\" (
  echo ERROR: "%DIR%" n'est pas un repo git (dossier .git introuvable).
  exit /b 1
)

pushd "%DIR%" >nul || (echo ERROR: impossible d'entrer dans "%DIR%" & exit /b 1)

for /f "delims=" %%A in ('git branch --show-current 2^>nul') do set "CUR=%%A"
if not "!CUR!"=="%BRANCH%" (
  echo Switch vers "%BRANCH%"...
  git switch "%BRANCH%" || (echo ERROR: impossible de switch sur "%BRANCH%" & popd >nul & exit /b 1)
)

git add -A || (echo ERROR: git add a Ã©chouÃ© & popd >nul & exit /b 1)

git diff --cached --quiet
if errorlevel 1 (
  git commit -m "%MESSAGE%"
  if errorlevel 1 (
    echo ERROR: commit Ã©chouÃ©.
    popd >nul
    exit /b 1
  )
) else (
  echo Aucun changement Ã  commit.
)

git push
if errorlevel 1 (
  echo ERROR: push Ã©chouÃ© (peut-Ãªtre besoin d'un pull/rebase).
  popd >nul
  exit /b 1
)

popd >nul
exit /b 0

