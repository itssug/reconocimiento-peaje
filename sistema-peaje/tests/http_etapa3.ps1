# Pruebas HTTP ETAPA 3 (PowerShell). Uso: powershell -File tests/http_etapa3.ps1
$php = "C:\xampp\php\php.exe"
$root = Split-Path $PSScriptRoot -Parent
Set-Location $root
& $php tests/etapa3.php | Select-String "FAIL"
$tok = Get-Content storage/database/test_tokens.json | ConvertFrom-Json

$env:DB_PATH = "storage/database/test_etapa3.sqlite"
$env:GOOGLE_CLIENT_ID = "test-client-id.apps.googleusercontent.com"
$env:APP_KEY = ("ab" * 32)
$proc = Start-Process $php -ArgumentList "-S","127.0.0.1:8099","-t","public","public/index.php" -PassThru -WindowStyle Hidden
for ($i = 0; $i -lt 30; $i++) {
    & curl.exe -s -o NUL http://127.0.0.1:8099/login.php
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Milliseconds 500
}
$fail = 0
function Check($name, $url, $method, $expect, $extra = @(), $needle = $null) {
    $cargs = @("-s","-X",$method,"-o","$env:TEMP\r.txt","-w","%{http_code}") + $extra + @($url)
    Remove-Item "$env:TEMP\r.txt" -ErrorAction SilentlyContinue
    $code = & curl.exe @cargs
    $body = if (Test-Path "$env:TEMP\r.txt") { Get-Content "$env:TEMP\r.txt" -Raw } else { "" }
    $ok = ($code -eq "$expect") -and (($null -eq $needle) -or ($body -match $needle))
    if (-not $ok) { $script:fail++ }
    "{0} {1} (HTTP {2}, esperado {3}) {4}" -f $(if($ok){"[PASS]"}else{"[FAIL]"}), $name, $code, $expect, $(if(-not $ok){$body})
}
$u = "http://127.0.0.1:8099"
$bAdmin = @("-H","Authorization: Bearer $($tok.admin)")
$bOp    = @("-H","Authorization: Bearer $($tok.op)")
try {
    Check "GET /auth/me sin token => 401 JSON" "$u/api/v1/auth/me" GET 401 @() '"success":false.*"errors"'
    Check "Token invalido => 401" "$u/api/v1/auth/me" GET 401 @("-H","Authorization: Bearer $('0'*64)")
    Check "GET /auth/me con Bearer admin" "$u/api/v1/auth/me" GET 200 $bAdmin '"rol":"ADMIN"'
    Check "GET /auth/me con Bearer operador" "$u/api/v1/auth/me" GET 200 $bOp '"rol":"OPERADOR"'
    Check "Cookie POST sin CSRF => 403" "$u/api/v1/auth/logout" POST 403 @("-H","Cookie: peaje_session=$($tok.op)")
    Check "POST /auth/google sin credencial => 422" "$u/api/v1/auth/google" POST 422 @("-H","Content-Type: application/json","-d","{}")
    Check "POST /auth/google token basura => 401" "$u/api/v1/auth/google" POST 401 @("-H","Content-Type: application/json","-d",'{\"credential\":\"abc\"}')
    Check "POST /auth/google JSON invalido => 400" "$u/api/v1/auth/google" POST 400 @("-H","Content-Type: application/json","-d","{no")
    Check "Ruta inexistente => 404" "$u/api/v1/nada" GET 404 $bAdmin
    Check "Metodo no permitido => 405" "$u/api/v1/auth/google" GET 405 @()
    Check "Pagina privada sin sesion => redirect 302" "$u/dashboard.php" GET 302
    Check "Pagina privada con cookie valida => 200" "$u/dashboard.php" GET 200 @("-H","Cookie: peaje_session=$($tok.admin)") 'ADMIN'
    Check "Login page => 200" "$u/login.php" GET 200 @() 'g_id_onload'
    Check "Logout con cookie + CSRF" "$u/api/v1/auth/logout" POST 200 @("-H","Cookie: peaje_session=$($tok.op)","-H","X-CSRF-Token: $(& $php -r "echo hash_hmac('sha256','csrf|'.hash('sha256','$($tok.op)'),str_repeat('ab',32));")")
    Check "Sesion revocada tras logout => 401" "$u/api/v1/auth/me" GET 401 $bOp
} finally {
    Stop-Process -Id $proc.Id -Force
    Remove-Item storage/database/test_etapa3.sqlite*, storage/database/test_tokens.json -ErrorAction SilentlyContinue
}
"Fallos HTTP: $fail"
exit $fail
