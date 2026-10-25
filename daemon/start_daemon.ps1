<#
.SYNOPSIS
    Lanzador y Gestor en PowerShell para FlixGrab OTT Daemon.
.PARAMETER Action
    start, stop, restart, status, run
#>
param (
    [string]$Action = "status"
)

$PSScriptRootDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Split-Path -Parent $PSScriptRootDirectory
Set-Location -Path $ProjectRoot

switch ($Action.ToLower()) {
    "start" {
        python "$PSScriptRootDirectory/flixgrab_daemon.py" start
        Start-Sleep -Seconds 1
        python "$PSScriptRootDirectory/flixgrab_daemon.py" status
    }
    "stop" {
        python "$PSScriptRootDirectory/flixgrab_daemon.py" stop
    }
    "restart" {
        python "$PSScriptRootDirectory/flixgrab_daemon.py" restart
    }
    "run" {
        python "$PSScriptRootDirectory/flixgrab_daemon.py" run
    }
    default {
        python "$PSScriptRootDirectory/flixgrab_daemon.py" status
    }
}
