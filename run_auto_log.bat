@echo off
REM 7J English Center — Auto Class Log Runner
REM รันโดย Windows Task Scheduler ทุก 5 นาที

set PHP="C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
set SCRIPT="C:\laragon\www\MyNewShool\auto_class_log.php"
set LOGDIR="C:\laragon\www\MyNewShool\uploads\auto_log"

REM สร้างโฟลเดอร์ log ถ้าไม่มี
if not exist %LOGDIR% mkdir %LOGDIR%

REM รัน PHP script
%PHP% %SCRIPT% >> "%LOGDIR%\task_scheduler.log" 2>&1
