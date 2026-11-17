<#
.SYNOPSIS
    Capture One Pro Windows Host Companion Bridge for Photobooth
.DESCRIPTION
    Runs on the Windows Host alongside Capture One Pro.
    - Publishes a live host heartbeat to data/c1_host_heartbeat.json so the Docker backend
      knows Capture One / Host Bridge is active.
    - Watches data/c1_input/*.job.json for incoming captured poses.
    - In Production Mode: copies the incoming capture into the configured Capture One Session
      Hot Folder ("Capture" folder) and triggers Capture One Process Recipe export to data/c1_output/.
    - In Simulation Mode (-SimulateExport): processes the incoming image directly into data/c1_output/
      for end-to-end pipeline verification without requiring a physical Capture One dongle.
#>
param(
    [string]$InputFolder = "data/c1_input",
    [string]$OutputFolder = "data/c1_output",
    [string]$CaptureOneExe = "C:\Program Files\Capture One\Capture One\CaptureOne.exe",
    [string]$SessionCaptureFolder = "data/c1_session/Capture",
    [switch]$SimulateExport = $false,
    [switch]$RunOnce = $false,
    [int]$MaxIterations = 0
)

$ErrorActionPreference = "SilentlyContinue"
$RepoRoot = Split-Path -Parent $PSScriptRoot
$AbsInput = Join-Path $RepoRoot $InputFolder
$AbsOutput = Join-Path $RepoRoot $OutputFolder
$AbsSessionCapture = Join-Path $RepoRoot $SessionCaptureFolder
$HeartbeatFile = Join-Path $RepoRoot "data/c1_host_heartbeat.json"
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

foreach ($dir in @($AbsInput, $AbsOutput, $AbsSessionCapture)) {
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
}

function Update-Heartbeat {
    $c1Installed = Test-Path $CaptureOneExe
    $c1Proc = Get-Process -Name "CaptureOne" -ErrorAction SilentlyContinue
    $payload = @{
        updated_at = [int][DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        host_os = [System.Environment]::OSVersion.VersionString
        capture_one_installed = [bool]$c1Installed
        capture_one_running = [bool]($null -ne $c1Proc)
        executable_path = $CaptureOneExe
        simulate_mode = [bool]$SimulateExport
    }
    $json = $payload | ConvertTo-Json -Depth 4
    [System.IO.File]::WriteAllText($HeartbeatFile, $json, $Utf8NoBom)
}

Write-Host "[CaptureOne Host Bridge] Watching $AbsInput -> $AbsOutput (Simulate=$SimulateExport)" -ForegroundColor Cyan

$iteration = 0
$wsh = $null
try {
    $wsh = New-Object -ComObject Wscript.Shell
} catch {}

do {
    $iteration++
    Update-Heartbeat

    $c1Proc = Get-Process -Name "CaptureOne" -ErrorAction SilentlyContinue
    $c1Running = [bool]($null -ne $c1Proc)

    $jobs = Get-ChildItem -Path $AbsInput -Filter "*.job.json" -File
    foreach ($jobFile in $jobs) {
        try {
            $job = Get-Content $jobFile.FullName -Raw | ConvertFrom-Json
            if (-not $job) { continue }

            $srcImg = Join-Path $AbsInput $job.expected_filename
            $destImg = Join-Path $AbsOutput $job.expected_filename

            if ((Test-Path $srcImg) -and -not (Test-Path $destImg)) {
                if ($SimulateExport -or -not $c1Running -or -not (Test-Path $CaptureOneExe)) {
                    # Capture One is not running or simulate mode: copy directly to output immediately
                    $tmpDest = "$destImg.writing.tmp"
                    Copy-Item -Path $srcImg -Destination $tmpDest -Force
                    Move-Item -Path $tmpDest -Destination $destImg -Force
                    Remove-Item -Path $jobFile.FullName -Force
                    Write-Host "[Host Bridge] Immediate fallback copy to output: $($job.expected_filename)" -ForegroundColor Yellow
                } else {
                    # Capture One is running: Forward into Capture One Session Hot Folder
                    $c1HotTargets = @($AbsSessionCapture)
                    $nestedCapture = Join-Path $RepoRoot "data/c1_session/Photobooth/Capture"
                    if (Test-Path $nestedCapture) {
                        $c1HotTargets += $nestedCapture
                    }
                    foreach ($hotTarget in $c1HotTargets) {
                        $c1HotFile = Join-Path $hotTarget $job.expected_filename
                        Copy-Item -Path $srcImg -Destination $c1HotFile -Force
                    }
                    Write-Host "[Host Bridge] Forwarded to C1 Hot Folder: $($job.expected_filename)" -ForegroundColor Green

                    # Trigger Capture One Export via Ctrl+D
                    try {
                        if ($wsh) {
                            $activated = $wsh.AppActivate("Capture One")
                            if ($activated) {
                                Start-Sleep -Milliseconds 250
                                $wsh.SendKeys("^d")
                            }
                        }
                    } catch {}

                    # Wait up to 5 seconds for Capture One to export to data/c1_output
                    $exported = $false
                    for ($s = 0; $s -lt 25; $s++) {
                        Start-Sleep -Milliseconds 200
                        if (Test-Path $destImg) {
                            $exported = $true
                            break
                        }
                    }

                    # If Capture One did not export within 5 seconds, copy as fallback so Photobooth never hangs
                    if (-not $exported -and -not (Test-Path $destImg)) {
                        $tmpDest = "$destImg.writing.tmp"
                        Copy-Item -Path $srcImg -Destination $tmpDest -Force
                        Move-Item -Path $tmpDest -Destination $destImg -Force
                        Write-Host "[Host Bridge] C1 export timeout fallback copy: $($job.expected_filename)" -ForegroundColor Yellow
                    }

                    Remove-Item -Path $jobFile.FullName -Force
                }
            }
        } catch {
            # Continue on next tick
        }
    }

    if ($RunOnce) { break }
    if ($MaxIterations -gt 0 -and $iteration -ge $MaxIterations) { break }
    Start-Sleep -Milliseconds 150
} while ($true)
