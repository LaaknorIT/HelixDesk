<#
.SYNOPSIS
    HelixDesk Active Directory collector.

.DESCRIPTION
    Reads computer objects from Active Directory and sends them to HelixDesk over HTTPS. It only needs outbound
    access to HelixDesk; nothing connects to this machine. It reads, and never changes, Active Directory.

    Install once (as an administrator), with the collector key from the integration page in HelixDesk:

        .\HelixDesk-ADCollector.ps1 -Install -CollectorKey 'hdc_...'

    This stores the key encrypted for this machine (DPAPI, LocalMachine) and creates a scheduled task that runs the
    collector as SYSTEM. SYSTEM uses the computer account, which can read computer objects in its own domain.
    Use -RunAsUser 'DOMAIN\gmsa-helixdesk$' to run the task as a group managed service account instead.

    Run one collection by hand to test (add -WhatIf to read AD and print a summary without sending anything):

        .\HelixDesk-ADCollector.ps1

.NOTES
    Version: __COLLECTOR_VERSION__
    Requires Windows PowerShell 5.1 or PowerShell 7 on a domain-joined Windows machine.
#>
[CmdletBinding()]
param(
    [string]$HelixDeskUrl = '__HELIXDESK_URL__',
    [string]$IntegrationId = '__INTEGRATION_ID__',
    [string]$KeyFile = (Join-Path $env:ProgramData 'HelixDesk\ad-collector.key'),
    [switch]$Install,
    [string]$CollectorKey,
    [string]$RunAsUser,
    [switch]$Uninstall,
    [switch]$WhatIf
)

$ErrorActionPreference = 'Stop'
$CollectorVersion = '__COLLECTOR_VERSION__'
$TaskName = "HelixDesk AD collector ($IntegrationId)"
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
Add-Type -AssemblyName System.Security

function Save-CollectorKey([string]$Key) {
    $dir = Split-Path $KeyFile -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $bytes = [Text.Encoding]::UTF8.GetBytes($Key)
    $protected = [Security.Cryptography.ProtectedData]::Protect($bytes, $null, [Security.Cryptography.DataProtectionScope]::LocalMachine)
    [IO.File]::WriteAllBytes($KeyFile, $protected)

    # Only administrators, SYSTEM and the task account may read the key file.
    $acl = New-Object Security.AccessControl.FileSecurity
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($identity in @('BUILTIN\Administrators', 'NT AUTHORITY\SYSTEM') + @($RunAsUser | Where-Object { $_ })) {
        $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($identity, 'Read,Write', 'Allow')))
    }
    Set-Acl -Path $KeyFile -AclObject $acl
}

function Get-CollectorKey {
    if (-not (Test-Path $KeyFile)) { throw "No collector key found at $KeyFile. Run the script with -Install -CollectorKey first." }
    $protected = [IO.File]::ReadAllBytes($KeyFile)
    $bytes = [Security.Cryptography.ProtectedData]::Unprotect($protected, $null, [Security.Cryptography.DataProtectionScope]::LocalMachine)
    return [Text.Encoding]::UTF8.GetString($bytes)
}

function Invoke-HelixDesk([string]$Method, [string]$Path, $Body) {
    $uri = '{0}/api/v1/collectors/active-directory/{1}/{2}' -f $HelixDeskUrl.TrimEnd('/'), $IntegrationId, $Path
    $headers = @{ Authorization = "Bearer $script:Key"; Accept = 'application/json' }
    $params = @{ Method = $Method; Uri = $uri; Headers = $headers; UseBasicParsing = $true; TimeoutSec = 120 }
    if ($null -ne $Body) {
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 6 -Compress))
        $params.ContentType = 'application/json; charset=utf-8'
    }
    $response = Invoke-RestMethod @params
    return $response.data
}

function ConvertTo-IsoTime($Value) {
    if ($null -eq $Value) { return $null }
    if ($Value -is [DateTime]) { return $Value.ToUniversalTime().ToString('o') }
    $fileTime = [Int64]$Value
    if ($fileTime -le 0 -or $fileTime -eq [Int64]::MaxValue) { return $null }
    return [DateTime]::FromFileTimeUtc($fileTime).ToString('o')
}

function Get-FirstValue($Properties, [string]$Name) {
    $values = $Properties[$Name]
    if ($null -eq $values -or $values.Count -eq 0) { return $null }
    return $values[0]
}

function Get-ComputerObjects($Config) {
    $bases = @($Config.search_bases)
    if ($bases.Count -eq 0) {
        $domainDn = 'DC=' + (($Config.domain_fqdn -split '\.') -join ',DC=')
        $bases = @($domainDn)
    }
    $excluded = @($Config.excluded_ous | ForEach-Object { $_.ToLowerInvariant() })
    $seen = @{}

    foreach ($base in $bases) {
        $root = New-Object DirectoryServices.DirectoryEntry("LDAP://$($Config.domain_fqdn)/$base")
        $searcher = New-Object DirectoryServices.DirectorySearcher($root)
        $searcher.Filter = '(objectCategory=computer)'
        $searcher.PageSize = 500
        $searcher.SearchScope = 'Subtree'
        foreach ($p in 'objectGUID', 'objectSid', 'name', 'dNSHostName', 'operatingSystem', 'operatingSystemVersion',
            'userAccountControl', 'lastLogonTimestamp', 'pwdLastSet', 'whenCreated', 'distinguishedName', 'description') {
            [void]$searcher.PropertiesToLoad.Add($p)
        }

        $results = $searcher.FindAll()
        try {
            foreach ($result in $results) {
                $props = $result.Properties
                $dn = [string](Get-FirstValue $props 'distinguishedname')
                $dnLower = $dn.ToLowerInvariant()
                if ($excluded | Where-Object { $dnLower.EndsWith($_) }) { continue }

                $guid = (New-Object Guid (, [byte[]](Get-FirstValue $props 'objectguid'))).ToString()
                if ($seen.ContainsKey($guid)) { continue }
                $seen[$guid] = $true

                $sidBytes = Get-FirstValue $props 'objectsid'
                $sid = if ($sidBytes) { (New-Object Security.Principal.SecurityIdentifier ([byte[]]$sidBytes, 0)).Value } else { $null }
                $uac = [int](Get-FirstValue $props 'useraccountcontrol')

                [ordered]@{
                    object_guid              = $guid
                    object_sid               = $sid
                    name                     = [string](Get-FirstValue $props 'name')
                    dns_host_name            = [string](Get-FirstValue $props 'dnshostname')
                    operating_system         = [string](Get-FirstValue $props 'operatingsystem')
                    operating_system_version = [string](Get-FirstValue $props 'operatingsystemversion')
                    enabled                  = -not [bool]($uac -band 2)
                    is_domain_controller     = [bool]($uac -band 8192)
                    last_logon_timestamp     = ConvertTo-IsoTime (Get-FirstValue $props 'lastlogontimestamp')
                    pwd_last_set             = ConvertTo-IsoTime (Get-FirstValue $props 'pwdlastset')
                    when_created             = ConvertTo-IsoTime (Get-FirstValue $props 'whencreated')
                    distinguished_name       = $dn
                    description              = [string](Get-FirstValue $props 'description')
                }
            }
        } finally {
            $results.Dispose()
        }
    }
}

if ($Uninstall) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Remove-Item $KeyFile -Force -ErrorAction SilentlyContinue
    Write-Host 'HelixDesk AD collector removed.'
    return
}

if ($Install) {
    if (-not $CollectorKey) { throw 'Pass the collector key from HelixDesk with -CollectorKey.' }
    Save-CollectorKey $CollectorKey
    $script:Key = $CollectorKey
    $config = Invoke-HelixDesk 'GET' 'config' $null

    $scriptPath = Join-Path (Split-Path $KeyFile -Parent) 'HelixDesk-ADCollector.ps1'
    if ($PSCommandPath -ne $scriptPath) { Copy-Item -Path $PSCommandPath -Destination $scriptPath -Force }

    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -ExecutionPolicy Bypass -File "{0}"' -f $scriptPath)
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(2) -RepetitionInterval (New-TimeSpan -Hours ([int]$config.sync_interval_hours))
    $principal = if ($RunAsUser) {
        New-ScheduledTaskPrincipal -UserId $RunAsUser -LogonType Password
    } else {
        New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    }
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Force | Out-Null
    Write-Host "Installed. The collector runs every $($config.sync_interval_hours) hours as $(if ($RunAsUser) { $RunAsUser } else { 'SYSTEM' }). First run in about two minutes."
    return
}

$script:Key = Get-CollectorKey
$hostName = [Net.Dns]::GetHostName()

try {
    $config = Invoke-HelixDesk 'GET' 'config' $null
    $objects = @(Get-ComputerObjects $config)

    if ($WhatIf) {
        $enabled = @($objects | Where-Object { $_.enabled }).Count
        Write-Host "Read $($objects.Count) computer objects ($enabled enabled) from $($config.domain_fqdn). Nothing was sent."
        return
    }

    $run = Invoke-HelixDesk 'POST' 'runs' @{ collector_version = $CollectorVersion; host = $hostName }
    $batchSize = [int]$config.max_objects_per_batch
    $sequence = 0
    for ($i = 0; $i -lt $objects.Count; $i += $batchSize) {
        $batch = $objects[$i..([Math]::Min($i + $batchSize, $objects.Count) - 1)]
        [void](Invoke-HelixDesk 'POST' "runs/$($run.run_id)/objects" @{ sequence = $sequence; objects = @($batch) })
        $sequence++
    }
    $result = Invoke-HelixDesk 'POST' "runs/$($run.run_id)/complete" @{ object_count = $objects.Count }
    [void](Invoke-HelixDesk 'POST' 'heartbeat' @{ collector_version = $CollectorVersion; host = $hostName; last_error = $null })
    Write-Host "Sent $($objects.Count) computer objects to HelixDesk (run $($result.status))."
} catch {
    $message = $_.Exception.Message
    try { [void](Invoke-HelixDesk 'POST' 'heartbeat' @{ collector_version = $CollectorVersion; host = $hostName; last_error = $message }) } catch { }
    throw
}
