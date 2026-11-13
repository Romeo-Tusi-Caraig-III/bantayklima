$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$php = $null
# Prefer XAMPP's PHP when running inside its htdocs folder.
$xamppPhp = Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'php\php.exe'
if (Test-Path -LiteralPath $xamppPhp) { $php = $xamppPhp }
elseif (Test-Path -LiteralPath 'C:\xampp\php\php.exe') { $php = 'C:\xampp\php\php.exe' }
elseif (Get-Command php -ErrorAction SilentlyContinue) { $php = (Get-Command php).Source }
if (!$php) { throw 'PHP was not found. Install XAMPP or add PHP to PATH, then run setup again.' }
Write-Host "Using $php"
if (!(Test-Path -LiteralPath 'config.local.php')) {
    $email = Read-Host 'Contact email for the privacy notice'
    $url = Read-Host 'Site address (e.g. http://localhost/bantayklima or http://localhost:8000)'
    $secret = Read-Host 'Choose admin password (at least 8 characters)' -AsSecureString
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
    try {
        $payload = @{email=$email; url=$url; password=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)} | ConvertTo-Json -Compress
        $oldEncoding = $OutputEncoding
        try {
            $OutputEncoding = New-Object System.Text.UTF8Encoding($false)
            $payload | & $php cli/setup.php
            if ($LASTEXITCODE -ne 0) { throw 'Setup did not finish. Correct the reported problem and run again.' }
        } finally { $OutputEncoding = $oldEncoding; $payload = $null }
    } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
} else { Write-Host 'Keeping existing config.local.php and admin password.' }
& $php cli/check.php
if ($LASTEXITCODE -ne 0) { throw 'Fix the FAIL items above before continuing.' }
Write-Host 'Setup complete. Start Apache, or run the following from this folder:'
Write-Host "& '$php' -S localhost:8000 router.php"
Write-Host 'Optional demo data (simulated conditions):'
Write-Host "& '$php' cli/refresh.php --demo=normal"
