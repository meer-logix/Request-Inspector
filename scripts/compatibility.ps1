$ErrorActionPreference = 'Continue'
$workspace = Split-Path $PSScriptRoot -Parent
$baseIni = [IO.File]::ReadAllText((Join-Path $workspace '.runtime/php.ini')).Replace('extension=zip',';extension=zip')
$results = @()
foreach ($runtime in @('php-8.1.29+0','php-8.2.27+1','php-8.3.17+1','php-8.4.10+0','php-8.5.1+1')) {
    $binary = Join-Path $env:APPDATA "Local/lightning-services/$runtime/bin/win64/php.exe"
    $ini = Join-Path $workspace ".runtime/$runtime.ini"
    [IO.File]::WriteAllText($ini, $baseIni.Replace('php-8.2.27+1', $runtime))
    foreach ($suite in @('tests/integration.php')) {
        $output = & rtk proxy $binary -c $ini $suite 2>&1 | Out-String
        $exitCode = $LASTEXITCODE
        $results += [pscustomobject]@{runtime=$runtime;suite=$suite;exit_code=$exitCode;output=$output}
        Write-Output "$runtime $suite exit=$exitCode"
    }
}
$results | ConvertTo-Json -Depth 5 | Set-Content -Encoding UTF8 (Join-Path $workspace '.runtime/compatibility.json')
if ($results.Where({$_.exit_code -ne 0}).Count -gt 0) { exit 1 }
