param(
    [ValidateSet('start','stop')][string]$Action='start',
    [string]$Php='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
)
$ErrorActionPreference='Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$env:MCA_WIDGET_TEST='1'
$extensions=Join-Path (Split-Path $Php -Parent) 'ext'
$baseArgs=@('-n','-d',"extension_dir=$extensions")
foreach($extension in @('mbstring','openssl','pdo_mysql','fileinfo','intl','gd','pdo_sqlite','curl','zip')){$baseArgs+=@('-d',"extension=$extension")}
$baseArgs+=@('-d','zend_extension=opcache','-d','opcache.enable_cli=1','-d','upload_max_filesize=25M','-d','post_max_size=30M')
if($Action -eq 'stop'){
    if(Test-Path .testing/phase8-processes.json){
        foreach($item in @(Get-Content .testing/phase8-processes.json|ConvertFrom-Json)){
            $proc=Get-CimInstance Win32_Process -Filter "ProcessId = $($item.id)"
            if($proc -and $proc.CommandLine.Contains('tests/TeamChatBrowserServer.php')){Stop-Process -Id $item.id -Force}
        }
    }
    if(Test-Path .testing/phase8-hosts.pid){
        $hostPidValue=[int](Get-Content .testing/phase8-hosts.pid)
        $proc=Get-CimInstance Win32_Process -Filter "ProcessId = $hostPidValue"
        if($proc -and $proc.CommandLine.Contains('tests/TeamChatWidgetHosts.cjs')){Stop-Process -Id $hostPidValue -Force}
    }
    if(Test-Path .testing/teamchat-browser.json){& $Php @baseArgs tests/TeamChatBrowserServer.php cleanup;if($LASTEXITCODE -ne 0){throw 'Cleanup failed'}}
    exit
}
$ports=33318..33326
$used=Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue|Where-Object {$_.LocalPort -in $ports}
if($used){throw 'Test ports already in use; stop only the owning test processes first.'}
& $Php @baseArgs tests/TeamChatBrowserServer.php seed
if($LASTEXITCODE -ne 0){throw 'Fixture seed refused. Requires isolated MySQL already running on 33317.'}
$records=@()
foreach($mode in @('http0','http1','http2','http3','reverb','drain')){
    $runArgs=if($mode.StartsWith('http')){@('-S',('127.0.0.1:'+(33323+[int]$mode.Substring(4))),'-t','public','tests/TeamChatBrowserServer.php')}else{@('tests/TeamChatBrowserServer.php',$mode)}
    $p=Start-Process $Php -ArgumentList ($baseArgs+$runArgs) -WorkingDirectory $PWD.Path -WindowStyle Hidden -RedirectStandardOutput ".testing/phase8-$mode.out.log" -RedirectStandardError ".testing/phase8-$mode.err.log" -PassThru
    $records+=@{id=$p.Id;mode=$mode}
    $records|ConvertTo-Json|Set-Content .testing/phase8-processes.json
}
$node=(Get-Command node).Source
$p=Start-Process $node -ArgumentList 'tests/TeamChatWidgetHosts.cjs' -WorkingDirectory $PWD.Path -WindowStyle Hidden -RedirectStandardOutput '.testing/phase8-hosts.out.log' -RedirectStandardError '.testing/phase8-hosts.err.log' -PassThru
$p.Id|Set-Content .testing/phase8-hosts.pid
$records|ConvertTo-Json
