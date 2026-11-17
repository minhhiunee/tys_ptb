$wsh = New-Object -ComObject Wscript.Shell
$res = $wsh.AppActivate("Capture One")
Write-Host "AppActivate Capture One result: $res"
