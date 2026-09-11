@echo off
REM Sobe o Painel Bora pra Obra localmente usando o PHP portatil.
REM Acesse depois em: http://localhost:8899

cd /d "%~dp0public"
"C:\BoraPraObra\tools\php\php.exe" -S localhost:8899
