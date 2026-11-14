<#
.SYNOPSIS
    HTTP & Local Load Benchmark for Photobooth (runs from Windows host against Docker/localhost).
.DESCRIPTION
    Measures TTFB/total latency, payload sizes, HTTP compression, and Cache-Control headers
    across key HTML pages, dynamic PHP APIs, and static frontend assets, including the
    ?refresh=1 session cache-busting behavior.
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\bench-local.ps1
    powershell -ExecutionPolicy Bypass -File .\scripts\bench-local.ps1 -BaseUrl http://localhost -Iterations 10
#>

param(
    [string]$BaseUrl = "http://localhost",
    [int]$Iterations = 5,
    [string]$OutputJson = ""
)

$ErrorActionPreference = "Stop"
$BaseUrl = $BaseUrl.TrimEnd("/")

Add-Type -AssemblyName System.Net.Http

$cookieContainer = New-Object System.Net.CookieContainer
$handler = New-Object System.Net.Http.HttpClientHandler
$handler.CookieContainer = $cookieContainer
$handler.AllowAutoRedirect = $false
# Note: keep AutomaticDecompression = None so we can measure actual wire bytes and detect Content-Encoding
$handler.AutomaticDecompression = [System.Net.DecompressionMethods]::None

$client = New-Object System.Net.Http.HttpClient($handler)
$client.Timeout = [TimeSpan]::FromSeconds(15)

function Measure-Endpoint {
    param(
        [string]$Label,
        [string]$Path,
        [int]$Runs = 5
    )

    $url = "$BaseUrl$Path"
    $timesMs = @()
    $status = 0
    $bytes = 0
    $encoding = "none"
    $cacheControl = ""
    $errMsg = $null

    for ($i = 1; $i -le $Runs; $i++) {
        $sw = [System.Diagnostics.Stopwatch]::StartNew()
        try {
            $req = New-Object System.Net.Http.HttpRequestMessage([System.Net.Http.HttpMethod]::Get, $url)
            $req.Headers.TryAddWithoutValidation("Accept-Encoding", "gzip, deflate, br") | Out-Null
            $resp = $client.SendAsync($req).GetAwaiter().GetResult()
            $contentBytes = $resp.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
            $sw.Stop()

            $status = [int]$resp.StatusCode
            $bytes = $contentBytes.Length
            $timesMs += [math]::Round($sw.Elapsed.TotalMilliseconds, 2)

            if ($resp.Content.Headers.ContentEncoding.Count -gt 0) {
                $encoding = ($resp.Content.Headers.ContentEncoding -join ",")
            } else {
                $encoding = "none"
            }

            if ($resp.Headers.CacheControl) {
                $cacheControl = $resp.Headers.CacheControl.ToString()
            } else {
                $cacheControl = "-"
            }
            $resp.Dispose()
        } catch {
            $sw.Stop()
            $errMsg = $_.Exception.Message
            break
        }
    }

    if ($errMsg) {
        return [PSCustomObject]@{
            Label        = $Label
            Path         = $Path
            Status       = "ERR"
            AvgMs        = -1
            MinMs        = -1
            MaxMs        = -1
            P95Ms        = -1
            SizeKB       = 0
            Encoding     = "-"
            CacheControl = $errMsg
        }
    }

    $sorted = $timesMs | Sort-Object
    $avg = [math]::Round((($timesMs | Measure-Object -Average).Average), 2)
    $min = $sorted[0]
    $max = $sorted[$sorted.Count - 1]
    $p95Idx = [math]::Min($sorted.Count - 1, [math]::Floor(($sorted.Count - 1) * 0.95))
    $p95 = $sorted[$p95Idx]

    return [PSCustomObject]@{
        Label        = $Label
        Path         = $Path
        Status       = $status
        AvgMs        = $avg
        MinMs        = $min
        MaxMs        = $max
        P95Ms        = $p95
        SizeKB       = [math]::Round($bytes / 1024.0, 1)
        Encoding     = $encoding
        CacheControl = $cacheControl
    }
}

Write-Host "=== Photobooth Local HTTP Benchmark ($BaseUrl, $Iterations runs/endpoint) ===" -ForegroundColor Cyan

# Quick reachability check
$probe = Measure-Endpoint -Label "Probe" -Path "/" -Runs 1
if ($probe.Status -eq "ERR") {
    Write-Host ""
    Write-Host "[ERROR] Cannot reach $BaseUrl : $($probe.CacheControl)" -ForegroundColor Red
    Write-Host "Hãy bật Docker Desktop và chạy 'docker compose up -d' trước khi chạy benchmark HTTP." -ForegroundColor Yellow
    exit 1
}

$targets = @(
    @{ Label = "Index HTML (normal)";     Path = "/" },
    @{ Label = "Index HTML (?refresh=1)"; Path = "/?refresh=1" },
    @{ Label = "API settings.php";        Path = "/api/settings.php" },
    @{ Label = "API translations.php";    Path = "/api/translations.php" },
    @{ Label = "API sounds.php";          Path = "/api/sounds.php" },
    @{ Label = "API presets.php (GET)";   Path = "/api/presets.php" },
    @{ Label = "API syncStatus.php";      Path = "/api/syncStatus.php" },
    @{ Label = "API gallery.php?status";  Path = "/api/gallery.php?status" },
    @{ Label = "API qrcode.php (latest)"; Path = "/api/qrcode.php?filename=latest&json=1" },
    @{ Label = "JS core.js";              Path = "/resources/js/core.js" },
    @{ Label = "JS presets-ui.js";        Path = "/resources/js/presets-ui.js" },
    @{ Label = "JS tools.js";             Path = "/resources/js/tools.js" },
    @{ Label = "CSS framework.css";       Path = "/resources/css/framework.css" },
    @{ Label = "IMG background.png";      Path = "/resources/img/background.png" }
)

$results = @()
foreach ($t in $targets) {
    $res = Measure-Endpoint -Label $t.Label -Path $t.Path -Runs $Iterations
    $results += $res
}

$results | Format-Table -AutoSize Label, Status, AvgMs, MinMs, P95Ms, MaxMs, SizeKB, Encoding, CacheControl

# Calculate key aggregated metrics
$bootApis = $results | Where-Object { $_.Label -in @("Index HTML (normal)", "API settings.php", "API translations.php", "API sounds.php") -and $_.AvgMs -ge 0 }
$staticAssets = $results | Where-Object { $_.Label -like "JS *" -or $_.Label -like "CSS *" -or $_.Label -like "IMG *" }

$bootSequentialMs = [math]::Round((($bootApis | Measure-Object -Property AvgMs -Sum).Sum), 2)
$totalStaticKB = [math]::Round((($staticAssets | Measure-Object -Property SizeKB -Sum).Sum), 1)
$uncompressedText = ($results | Where-Object { ($_.Label -like "Index*" -or $_.Label -like "API*" -or $_.Label -like "JS*" -or $_.Label -like "CSS*") -and $_.Encoding -eq "none" }).Count

Write-Host "--- Summary ---" -ForegroundColor Green
Write-Host ("Critical boot sequence (Index + settings + translations + sounds, avg sum): {0} ms" -f $bootSequentialMs)
Write-Host ("Measured static sample size (core+presets+tools+framework+bg): {0} KB" -f $totalStaticKB)
Write-Host ("Uncompressed text/API/asset endpoints (Encoding=none): {0}" -f $uncompressedText)

if ($OutputJson -ne "") {
    $payload = [PSCustomObject]@{
        timestamp   = (Get-Date).ToString("o")
        base_url    = $BaseUrl
        iterations  = $Iterations
        summary     = [PSCustomObject]@{
            boot_sequential_ms       = $bootSequentialMs
            measured_static_kb       = $totalStaticKB
            uncompressed_text_count  = $uncompressedText
        }
        endpoints   = $results
    }
    $payload | ConvertTo-Json -Depth 4 | Set-Content -Path $OutputJson -Encoding UTF8
    Write-Host "Saved benchmark JSON to $OutputJson" -ForegroundColor Cyan
}

$client.Dispose()
$handler.Dispose()

