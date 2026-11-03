<#
.SYNOPSIS
    Auto-detect real Windows Wi-Fi / LAN IPv4 address, SSID, and open Windows Firewall for Photobooth (Mode 1 & Mode 2).
#>

param (
    [string]$Mode = "", # 'venue_wifi' or 'cloud_web'
    [switch]$OpenFirewall = $true
)

$ConfigPath = Join-Path -Path $PSScriptRoot -ChildPath "..\config\network_mode.json"

# 1. Detect connected Wi-Fi SSID on Windows
$wifiSsid = ""
try {
    $wlanInfo = netsh wlan show interfaces | Out-String
    if ($wlanInfo -match "(?m)^\s*SSID\s*:\s*(.+)$") {
        $wifiSsid = $Matches[1].Trim()
    }
} catch {}

# 2. Detect real physical Wi-Fi or Ethernet IPv4 (Exclude vEthernet, WSL, Docker, Loopback)
$realIp = ""
try {
    $candidates = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object {
        $_.IPAddress -ne "127.0.0.1" -and
        $_.IPAddress -notmatch "^169\.254\." -and
        $_.InterfaceAlias -notmatch "vEthernet|WSL|Docker|Loopback|VMware|VirtualBox" -and
        ($_.PrefixOrigin -eq "Dhcp" -or $_.PrefixOrigin -eq "Manual")
    } | Sort-Object -Property @{Expression = { if ($_.InterfaceAlias -match "Wi-Fi|Wireless|WLAN") { 0 } else { 1 } }}

    if ($candidates -and $candidates.Count -gt 0) {
        $realIp = $candidates[0].IPAddress
    }
} catch {}

if ([string]::IsNullOrWhiteSpace($realIp)) {
    $ipconfigOut = ipconfig | Out-String
    if ($ipconfigOut -match "IPv4 Address[.\s]*:\s*(192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+)") {
        $realIp = $Matches[1].Trim()
    }
}

$displaySsid = if ($wifiSsid) { $wifiSsid } else { "(Ethernet / Unknown SSID)" }
$displayIp = if ($realIp) { $realIp } else { "Not detected" }

Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host "   PHOTOBOOTH VENUE NETWORK & CLOUD DETECTOR         " -ForegroundColor Cyan
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host " -> Venue Wi-Fi SSID   : $displaySsid" -ForegroundColor Green
Write-Host " -> Real Host LAN IP   : $displayIp" -ForegroundColor Green

# 3. Read and update config/network_mode.json
$configData = [ordered]@{
    mode = "venue_wifi"
    venue_ip = $realIp
    venue_ssid = $wifiSsid
    cloud_url = ""
    cloud_api_key = "tys_secret_key_2026"
    auto_compress_cloud = $true
    updated_at = [int][double]::Parse((Get-Date -UFormat %s))
}

if (Test-Path $ConfigPath) {
    try {
        $existing = Get-Content -Path $ConfigPath -Raw | ConvertFrom-Json
        if ($existing.mode) { $configData.mode = $existing.mode }
        if ($existing.cloud_url) { $configData.cloud_url = $existing.cloud_url }
        if ($existing.cloud_api_key) { $configData.cloud_api_key = $existing.cloud_api_key }
        if ($null -ne $existing.auto_compress_cloud) { $configData.auto_compress_cloud = [bool]$existing.auto_compress_cloud }
    } catch {}
}

if (-not [string]::IsNullOrWhiteSpace($Mode)) {
    $configData.mode = $Mode
}
if (-not [string]::IsNullOrWhiteSpace($realIp)) {
    $configData.venue_ip = $realIp
}
if (-not [string]::IsNullOrWhiteSpace($wifiSsid)) {
    $configData.venue_ssid = $wifiSsid
}

$jsonString = $configData | ConvertTo-Json -Depth 4
[System.IO.File]::WriteAllText($ConfigPath, $jsonString, (New-Object System.Text.UTF8Encoding($false)))
Write-Host " -> Saved config file  : $ConfigPath" -ForegroundColor Yellow
Write-Host " -> Active QR Mode     : $($configData.mode)" -ForegroundColor Yellow

# 4. Open Windows Firewall Port 80 & 443 if running as Administrator
if ($OpenFirewall) {
    $isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    if ($isAdmin) {
        $ruleName = "Photobooth Venue LAN HTTP (Port 80 & 443)"
        $existingRule = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
        if (-not $existingRule) {
            New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80,443 -Profile Any | Out-Null
            Write-Host " -> [Firewall] Opened TCP Ports 80 & 443 for Venue LAN!" -ForegroundColor Green
        } else {
            Write-Host " -> [Firewall] Rule for TCP Ports 80 & 443 is already active." -ForegroundColor Green
        }
    } else {
        Write-Host " -> [Firewall Note] Run as Administrator once to auto-open Windows Firewall port 80." -ForegroundColor DarkYellow
    }
}
Write-Host "=====================================================" -ForegroundColor Cyan

