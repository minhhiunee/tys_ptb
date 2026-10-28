<#
.SYNOPSIS
    Universal Multi-Brand Windows Print Spooler for Photobooth
    Supports DNP (DS620/RX1), Canon Selphy (CP1300/1500), Citizen (CX-02), Epson, HiTi, etc.
.DESCRIPTION
    Monitors data/print/*.job.json created by the Photobooth web app and sends jobs
    to the configured or default Windows printer.
#>

param (
    [string]$PrinterName = "", # Leave blank to use Windows Default Printer
    [string]$PrintDir = "$PSScriptRoot\..\data\print",
    [string]$LogFile = "$PSScriptRoot\..\var\log\spooler.log",
    [int]$PollIntervalMs = 1000
)

function Write-SpoolerLog {
    param ([string]$Message, [string]$Level = "INFO")
    $timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    $line = "[$timestamp][spooler][$Level] $Message"
    Write-Host $line
    try {
        $logDir = Split-Path -Path $LogFile -Parent
        if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
        Add-Content -Path $LogFile -Value $line
    } catch {}
}

# Resolve target printer
if ([string]::IsNullOrWhiteSpace($PrinterName)) {
    $defaultPrinter = Get-CimInstance Win32_Printer | Where-Object { $_.Default -eq $true }
    if ($defaultPrinter) {
        $PrinterName = $defaultPrinter.Name
        Write-SpoolerLog "No specific printer specified. Using Windows Default Printer: '$PrinterName'"
    } else {
        Write-SpoolerLog "WARNING: No default printer found in Windows! Using first available printer." "WARN"
        $anyPrinter = Get-CimInstance Win32_Printer | Select-Object -First 1
        if ($anyPrinter) { $PrinterName = $anyPrinter.Name }
    }
} else {
    Write-SpoolerLog "Configured Target Printer: '$PrinterName'"
}

$resolvedPrintDir = (Resolve-Path -Path $PrintDir -ErrorAction SilentlyContinue).Path
if (-not $resolvedPrintDir) {
    New-Item -ItemType Directory -Path $PrintDir -Force | Out-Null
    $resolvedPrintDir = (Resolve-Path -Path $PrintDir).Path
}

Write-SpoolerLog "Photobooth Universal Spooler started."
Write-SpoolerLog "Watching directory: $resolvedPrintDir"
Write-SpoolerLog "Active Printer: $PrinterName"
Write-SpoolerLog "Press Ctrl+C to stop the spooler."

# Process existing or incoming jobs
while ($true) {
    try {
        $jobFiles = Get-ChildItem -Path $resolvedPrintDir -Filter "*.job.json" -File | Sort-Object CreationTime
        foreach ($job in $jobFiles) {
            try {
                $rawContent = Get-Content -Path $job.FullName -Raw
                $jobData = $rawContent | ConvertFrom-Json

                $imgName = Split-Path -Path $jobData.printFile -Leaf
                $imgFullPath = Join-Path -Path $resolvedPrintDir -ChildPath $imgName

                if (-not (Test-Path $imgFullPath)) {
                    # Fallback check
                    $imgFullPath = $jobData.printFile
                }

                if (Test-Path $imgFullPath) {
                    $copies = if ($jobData.copies) { [int]$jobData.copies } else { 1 }
                    Write-SpoolerLog "Processing print job: $($job.Name) | File: $imgName | Copies: $copies"

                    for ($c = 1; $c -le $copies; $c++) {
                        Write-SpoolerLog "Sending copy $c of $copies to '$PrinterName'..."
                        
                        # Use Windows Shell Verb PrintTo for target printer
                        $pinfo = New-Object System.Diagnostics.ProcessStartInfo
                        $pinfo.FileName = $imgFullPath
                        $pinfo.Verb = "PrintTo"
                        $pinfo.Arguments = "`"$PrinterName`""
                        $pinfo.CreateNoWindow = $true
                        $pinfo.WindowStyle = [System.Diagnostics.ProcessWindowStyle]::Hidden

                        $proc = [System.Diagnostics.Process]::Start($pinfo)
                        if ($proc) {
                            $proc.WaitForExit(5000)
                        }
                    }

                    # Mark job as completed
                    $donePath = [System.IO.Path]::ChangeExtension($job.FullName, ".done")
                    Move-Item -Path $job.FullName -Destination $donePath -Force
                    Write-SpoolerLog "Completed job: $($job.Name) -> $donePath"
                } else {
                    Write-SpoolerLog "Image file not found for job: $($job.FullName)" "ERROR"
                    $errPath = [System.IO.Path]::ChangeExtension($job.FullName, ".missing")
                    Move-Item -Path $job.FullName -Destination $errPath -Force
                }
            } catch {
                Write-SpoolerLog "Error executing job $($job.Name): $($_.Exception.Message)" "ERROR"
            }
        }
    } catch {
        Write-SpoolerLog "Spooler loop exception: $($_.Exception.Message)" "ERROR"
    }

    Start-Sleep -Milliseconds $PollIntervalMs
}
