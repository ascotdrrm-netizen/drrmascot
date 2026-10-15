' Runs the command line given as the first argument with no window (so the
' scheduled snapshot doesn't flash a console every hour), waits for it, and
' returns its exit code to Task Scheduler. Used by register-snapshot-task.ps1.
Dim shell : Set shell = CreateObject("WScript.Shell")
WScript.Quit shell.Run(WScript.Arguments(0), 0, True)
