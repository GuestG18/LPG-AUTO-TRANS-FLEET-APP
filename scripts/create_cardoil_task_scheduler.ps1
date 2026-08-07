param(
    [string] $TaskName = "FleetApp CardOil Sync",
    [string] $At = "03:00"
)

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$AppDir = Resolve-Path (Join-Path $ScriptDir "..")
$BatPath = Join-Path $AppDir "scripts\run_sync_cardoil.bat"

if (-not (Test-Path -LiteralPath $BatPath)) {
    throw "Nu exista scriptul BAT: $BatPath"
}

$Action = New-ScheduledTaskAction -Execute $BatPath -WorkingDirectory $AppDir
$Trigger = New-ScheduledTaskTrigger -Daily -At $At
$Settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $Action `
    -Trigger $Trigger `
    -Settings $Settings `
    -Description "Sincronizeaza zilnic alimentarile CardOil Avantaj pentru Fleet Management." `
    -Force

Write-Host "Task creat/actualizat: $TaskName"
Write-Host "Ora zilnica: $At"
Write-Host "Script: $BatPath"
Write-Host "Log: $(Join-Path $AppDir 'storage\logs\cardoil_sync.log')"
Write-Host ""
Write-Host "Pentru rulare chiar daca userul nu este logat: deschide Task Scheduler, intra in Properties pentru task, tab General, selecteaza 'Run whether user is logged on or not' si salveaza cu credentialele Windows."
