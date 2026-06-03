# 7J English Center — Auto Class Log Task Scheduler Setup
# รัน PowerShell นี้ด้วยสิทธิ์ Administrator

$taskName = "7J_AutoClassLog"
$batFile  = "C:\laragon\www\MyNewShool\run_auto_log.bat"

# ลบ task เก่าถ้ามี
Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue

# สร้าง action (รัน .bat ทุก 5 นาที)
$action   = New-ScheduledTaskAction -Execute $batFile
$trigger  = New-ScheduledTaskTrigger `
    -RepetitionInterval (New-TimeSpan -Minutes 5) `
    -Once `
    -At ([DateTime]::Today)
$settings = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 2) `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew

# ลงทะเบียน task
Register-ScheduledTask `
    -TaskName $taskName `
    -Action   $action `
    -Trigger  $trigger `
    -Settings $settings `
    -RunLevel Highest `
    -Force

# ตรวจสอบ
$task = Get-ScheduledTask -TaskName $taskName
Write-Host ""
Write-Host "✅ Task Scheduler ตั้งค่าสำเร็จ!" -ForegroundColor Green
Write-Host "   Task Name : $($task.TaskName)"
Write-Host "   State     : $($task.State)"
Write-Host "   รันทุก    : 5 นาที"
Write-Host ""
Write-Host "ทดสอบรันทันที:"
Start-ScheduledTask -TaskName $taskName
Write-Host "   ▶ กำลังรัน..." -ForegroundColor Yellow
Start-Sleep -Seconds 3
$lastRun = (Get-ScheduledTaskInfo -TaskName $taskName).LastRunTime
Write-Host "   Last Run: $lastRun" -ForegroundColor Cyan
