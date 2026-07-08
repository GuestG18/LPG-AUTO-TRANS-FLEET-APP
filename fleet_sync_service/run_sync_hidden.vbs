Option Explicit

Dim fso, shell, serviceDir, projectRoot, pythonExe, syncScript, logDir, outputLog
Dim quote, command, exitCode

Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

serviceDir = fso.GetParentFolderName(WScript.ScriptFullName)
projectRoot = fso.GetParentFolderName(serviceDir)
pythonExe = serviceDir & "\.venv\Scripts\python.exe"
syncScript = serviceDir & "\sync_data.py"
logDir = serviceDir & "\logs"
outputLog = logDir & "\scheduled_task_console.log"
quote = Chr(34)

If Not fso.FolderExists(logDir) Then
    fso.CreateFolder(logDir)
End If

shell.CurrentDirectory = projectRoot
command = "%ComSpec% /d /c " & quote & quote & pythonExe & quote & " " & quote & syncScript & quote & " > " & quote & outputLog & quote & " 2>&1" & quote
exitCode = shell.Run(command, 0, True)

WScript.Quit exitCode
