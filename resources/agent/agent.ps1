<#
    Monitor agent for Windows.

    Reports what this machine looks like from the inside -- what it is, how
    full it is, which updates are waiting -- to a Monitor install, and carries
    out the short list of commands that install is allowed to ask for.

    Windows PowerShell 5.1 and nothing else, because that ships with every
    supported version of Windows. It talks outward only: no port is opened
    here, and there is nothing to connect to.

    Configuration lives in C:\ProgramData\MonitorAgent\agent.conf, readable by
    SYSTEM and Administrators only. The token in it is this machine's whole
    identity. Beside it are agent.log, which is this machine's own copy of what
    the agent has been doing, and outbox, which is the part of that Monitor has
    not been told about yet.
#>

[CmdletBinding()]
param(
    # Stay alive for just under a minute, asking every few seconds whether a
    # command is waiting. This is what the scheduled task runs.
    [switch]$Loop,

    # One full report, then exit.
    [switch]$Once,

    # One knock on the live channel, printed. For working out why a command is
    # not arriving.
    [switch]$Poll,

    [switch]$Enroll,
    [switch]$Dump,
    [switch]$Version,
    [string]$Conf = "$env:ProgramData\MonitorAgent\agent.conf",
    [string]$Key = ''
)

$ErrorActionPreference = 'Stop'

# One POSIX agent for Linux and macOS, and this one for Windows. They carry
# one version between them and move together, so that
# "this machine is on 1.11.0" means the same thing whichever it is running.
# A change to one is a release of both, even when the other needed nothing:
# tools/check-agent.sh and check-agent.ps1 both refuse to pass if they differ.
$AgentVersion = '1.11.0'

# What the last failure was. These are how the installer tells "this machine is
# not who it says it is" from "that did not get through" -- they are not the
# same thing, and acting on the second as though it were the first spends a use
# of an enrolment key and leaves a second row in Monitor for the same computer.
#
#   0  fine
#   2  the token was refused -- this machine has to enrol again
#   3  the server could not read what was sent -- the token is fine
#   1  anything else: unreachable, or an answer nobody expected
$FailureCode = 0

# Where the agent writes what it did, and where it keeps what it has not
# managed to say yet. Overridable so the checks can run against a directory
# that is not the real one.
$LogPath = $env:MONITOR_LOG
if (-not $LogPath) { $LogPath = "$env:ProgramData\MonitorAgent\agent.log" }
$StatePath = $env:MONITOR_STATE
if (-not $StatePath) { $StatePath = "$env:ProgramData\MonitorAgent" }

$LogMaxBytes = 1MB

if ($Version) { Write-Output "monitor-agent $AgentVersion"; exit 0 }

# ------------------------------------------------------------------- JSON ---
#
# Written out by hand rather than with ConvertTo-Json. Two reasons, both
# unpleasant to debug later: PowerShell 5.1 turns a one-element array into a
# bare object, which would make a machine with a single disk look like a
# machine with none; and number formatting follows the machine's own locale, so
# a Danish server would post 7,75 and produce invalid JSON. Both are avoided by
# doing the encoding here, where the rules are visible.

# Native commands and $ErrorActionPreference = 'Stop' do not mix. Anything an
# external program writes to stderr becomes a terminating error the moment it
# is redirected, so "that scheduled task did not exist" would abort the whole
# install. Every external call goes through here, which restores the preference
# afterwards and hands back the exit code to be judged on its merits.
function Invoke-Native {
    param([string]$Command, [string[]]$Arguments = @())

    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $Command @Arguments 2>&1
        return [pscustomobject]@{ ExitCode = $LASTEXITCODE; Output = $output }
    } finally {
        $ErrorActionPreference = $previous
    }
}

function Get-FailureCode {
    param([int]$Code)

    switch ($Code) {
        200 { return 0 }
        201 { return 0 }
        400 { return 3 }
        401 { return 2 }
        default { return 1 }
    }
}

function Set-Failure {
    param([int]$Code)

    $script:FailureCode = $Code
}

function ConvertTo-JsonString {
    param([string]$Text)

    if ($null -eq $Text) { return '""' }

    $builder = New-Object System.Text.StringBuilder
    [void]$builder.Append('"')
    foreach ($char in $Text.ToCharArray()) {
        $code = [int]$char
        switch ($char) {
            '"'  { [void]$builder.Append('\"'); continue }
            '\'  { [void]$builder.Append('\\'); continue }
            "`n" { [void]$builder.Append('\n'); continue }
            "`r" { [void]$builder.Append('\r'); continue }
            "`t" { [void]$builder.Append('\t'); continue }
            default {
                if ($code -lt 32 -or $code -eq 127) {
                    [void]$builder.AppendFormat('\u{0:x4}', $code)
                } else {
                    [void]$builder.Append($char)
                }
            }
        }
    }
    [void]$builder.Append('"')

    return $builder.ToString()
}

function ConvertTo-MonitorJson {
    param($Value)

    if ($null -eq $Value) { return 'null' }
    if ($Value -is [bool]) { if ($Value) { return 'true' } else { return 'false' } }

    if ($Value -is [int] -or $Value -is [long] -or $Value -is [uint32] -or $Value -is [uint64] -or
        $Value -is [double] -or $Value -is [decimal] -or $Value -is [single]) {
        return [string]::Format([System.Globalization.CultureInfo]::InvariantCulture, '{0}', $Value)
    }

    if ($Value -is [System.Collections.IDictionary]) {
        $parts = @()
        foreach ($name in $Value.Keys) {
            $parts += (ConvertTo-JsonString ([string]$name)) + ':' + (ConvertTo-MonitorJson $Value[$name])
        }
        return '{' + ($parts -join ',') + '}'
    }

    if ($Value -is [System.Collections.IEnumerable] -and -not ($Value -is [string])) {
        $parts = @()
        foreach ($item in $Value) { $parts += ConvertTo-MonitorJson $item }
        return '[' + ($parts -join ',') + ']'
    }

    return ConvertTo-JsonString ([string]$Value)
}

# ----------------------------------------------------------------- config ---

function Read-Config {
    param([string]$Path)

    $config = @{
        MONITOR_URL       = ''
        MONITOR_TOKEN     = ''
        MONITOR_DEVICE_ID = ''
        MONITOR_INTERVAL  = '300'
        MONITOR_POLL      = '15'
        MONITOR_COLLECT   = 'disks,updates,packages,services,ports'
        MONITOR_LEVEL     = 'info'
        MONITOR_ALLOW     = ''
        MONITOR_SELF_UPDATE = '1'
        MONITOR_UPDATE_RETRY = '3600'
        MONITOR_INSECURE  = '0'
    }

    if (Test-Path -LiteralPath $Path) {
        foreach ($line in Get-Content -LiteralPath $Path -Encoding UTF8) {
            if ($line -match '^\s*([A-Z_]+)\s*=\s*"?([^"]*)"?\s*$') {
                $config[$Matches[1]] = $Matches[2]
            }
        }
    }

    return $config
}

function Write-Config {
    param([string]$Path, [hashtable]$Config)

    $directory = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }

    $body = @"
# Monitor agent configuration. Written by the installer.
#
# MONITOR_TOKEN is this machine's identity. Anybody holding it can post reports
# as this machine, so this file is readable by SYSTEM and Administrators only.
# If it leaks, revoke the machine in Monitor and run the installer again.
MONITOR_URL="$($Config.MONITOR_URL)"
MONITOR_TOKEN="$($Config.MONITOR_TOKEN)"
MONITOR_DEVICE_ID="$($Config.MONITOR_DEVICE_ID)"
MONITOR_INTERVAL="$($Config.MONITOR_INTERVAL)"

# How often to ask whether a command is waiting. This is the live channel: one
# small request, usually answered with "nothing". Set it to 0 and the agent
# only ever speaks when it reports, which is quieter but means a queued command
# waits for the next report.
MONITOR_POLL="$($Config.MONITOR_POLL)"

# Which of the optional lists to gather. Drop one and it stops being collected
# and stops being shown -- packages is the expensive one.
MONITOR_COLLECT="$($Config.MONITOR_COLLECT)"

# How much the agent has to say for itself. 'debug' includes every poll, which
# is a great deal of nothing most of the time; 'info' records what it actually
# does. Monitor can lower or raise this from the interface.
MONITOR_LEVEL="$($Config.MONITOR_LEVEL)"

# What this machine consents to being asked to do. Empty means Monitor may ask
# it questions but never change it.
MONITOR_ALLOW="$($Config.MONITOR_ALLOW)"

# Whether the agent may replace itself with the version Monitor holds. This is
# the machine's own consent to running code from that server, so it is decided
# here and cannot be granted from there. Set it to 0 and updates arrive by
# running the installer again, by hand.
MONITOR_SELF_UPDATE="$($Config.MONITOR_SELF_UPDATE)"

# How long to leave it between attempts at replacing this agent. Monitor sets
# this from Settings -> Agent and it arrives with every answer; what is here is
# what the last answer said, so a fresh process starts with it.
MONITOR_UPDATE_RETRY="$($Config.MONITOR_UPDATE_RETRY)"

# Skip TLS verification. Self-signed certificates only.
MONITOR_INSECURE="$($Config.MONITOR_INSECURE)"
"@

    $temporary = "$Path.new"
    Set-Content -LiteralPath $temporary -Value $body -Encoding UTF8
    Move-Item -LiteralPath $temporary -Destination $Path -Force
    Protect-ConfigFile -Path $Path
}

# Only SYSTEM and Administrators. Inheritance is switched off first, so a
# permissive ProgramData ACL cannot leave the token readable to everyone.
function Protect-ConfigFile {
    param([string]$Path)

    try {
        Invoke-Native 'icacls.exe' @($Path, '/inheritance:r', '/grant:r', 'SYSTEM:(F)', 'Administrators:(F)') | Out-Null
    } catch {
        Write-Warning "Could not restrict permissions on $Path. Check them by hand."
    }
}

# ---------------------------------------------------------------- logging ---
#
# Two places at once, for two different readers.
#
# The file on this machine is for whoever is sitting at it, and it is the only
# one that still exists when Monitor is the thing that is broken. The outbox
# beside it holds what has not reached Monitor yet, so time offline is a delay
# rather than a hole -- the lines go out with the next run that gets through.
#
# Lines are written where the work happens and shipped while it is still
# happening. That is the whole point: watching Windows Update take four minutes
# is worth something, reading about it afterwards much less.

# At most this many lines in one request, which is what the server accepts, and
# at most this many held for a machine that has been unreachable for days.
$ShipMax = 400
$OutboxMax = 2000

# How eagerly a line is sent. Every line would be a request per update; every
# minute would not be live at all.
$ShipLines = 25
$ShipSeconds = 3

$Outbox = Join-Path $StatePath 'outbox'
$ShippedAt = Join-Path $StatePath 'shipped-at'
$LevelFloor = 1

function Get-LevelRank {
    param([string]$Level)

    switch ($Level) {
        'debug' { return 0 }
        'info'  { return 1 }
        'warn'  { return 2 }
        'error' { return 3 }
        default { return 1 }
    }
}

# One old file kept, so there is something to read either side of a restart
# without asking anybody to configure log rotation for an agent this small.
function Write-LocalLog {
    param([string]$Line)

    try {
        if (Test-Path -LiteralPath $LogPath) {
            $size = (Get-Item -LiteralPath $LogPath).Length
            if ($size -gt $LogMaxBytes) {
                Move-Item -LiteralPath $LogPath -Destination "$LogPath.1" -Force
            }
        }
        Add-Content -LiteralPath $LogPath -Value $Line -Encoding UTF8
    } catch { }
}

# Everything below the configured level is dropped here, before it is written
# anywhere, so turning the level down genuinely costs less rather than just
# hiding lines at the far end.
function Write-AgentLog {
    param([string]$Level, [string]$Message, [string]$Command = '')

    if ((Get-LevelRank $Level) -lt $script:LevelFloor) { return }

    # Both files are line-oriented and Windows Update will hand over text
    # containing absolutely anything, so this is flattened to one line before
    # it is allowed near either of them.
    $text = [regex]::Replace([string]$Message, '[\t\r\n]', ' ')
    $text = [regex]::Replace($text, '[\x00-\x1f]', '')
    if ($text.Length -gt 1000) { $text = $text.Substring(0, 1000) }
    if (-not $text.Trim()) { return }

    $stamp = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    Write-LocalLog "$stamp $Level $text"

    try {
        if (-not (Test-Path -LiteralPath $StatePath)) {
            New-Item -ItemType Directory -Path $StatePath -Force | Out-Null
        }
        Add-Content -LiteralPath $Outbox -Value "$stamp`t$Level`t$Command`t$text" -Encoding UTF8
    } catch { }
}

function ConvertTo-OutboxJson {
    param([string[]]$Lines)

    $rows = @()
    foreach ($line in $Lines) {
        $parts = $line -split "`t", 4
        if ($parts.Count -lt 4) { continue }
        $rows += [ordered]@{ at = $parts[0]; level = $parts[1]; command = $parts[2]; message = $parts[3] }
    }

    return ConvertTo-MonitorJson ([ordered]@{ logs = $rows })
}

# Send what is waiting, if it is worth a request yet.
#
# Called after every line a running command produces and after every knock on
# the live channel, so the decision about whether to actually send has to be
# made here rather than at each call site.
function Send-OutboxLogs {
    param([switch]$Now)

    if (-not (Test-Path -LiteralPath $Outbox)) { return $true }
    if (-not $Config.MONITOR_TOKEN) { return $true }

    $lines = @(Get-Content -LiteralPath $Outbox -Encoding UTF8 -ErrorAction SilentlyContinue)
    if ($lines.Count -eq 0) { return $true }

    if (-not $Now -and $lines.Count -lt $ShipLines) {
        $last = [datetime]::MinValue
        if (Test-Path -LiteralPath $ShippedAt) {
            try { $last = [datetime]::Parse((Get-Content -LiteralPath $ShippedAt -Raw).Trim(), [System.Globalization.CultureInfo]::InvariantCulture, [System.Globalization.DateTimeStyles]::RoundtripKind) } catch { }
        }
        if (((Get-Date).ToUniversalTime() - $last).TotalSeconds -lt $ShipSeconds) { return $true }
    }

    $batch = @($lines | Select-Object -First $ShipMax)
    $rest = @($lines | Select-Object -Skip $ShipMax)

    Set-Content -LiteralPath $Outbox -Value $rest -Encoding UTF8

    $answer = Send-Report -Endpoint '/api/agent/log' -Body (ConvertTo-OutboxJson $batch) -Token $Config.MONITOR_TOKEN

    if ($answer.code -eq 200) {
        try { Set-Content -LiteralPath $ShippedAt -Value (Get-Date).ToUniversalTime().ToString('o') -Encoding UTF8 } catch { }
        return $true
    }

    # Put them back in front of whatever arrived while the request was out, and
    # keep only the newest if this machine has been talking to nobody for days.
    $merged = @($batch + @(Get-Content -LiteralPath $Outbox -Encoding UTF8 -ErrorAction SilentlyContinue))
    if ($merged.Count -gt $OutboxMax) { $merged = @($merged | Select-Object -Last $OutboxMax) }
    Set-Content -LiteralPath $Outbox -Value $merged -Encoding UTF8

    return $false
}

$Config = Read-Config -Path $Conf
$LevelFloor = Get-LevelRank $Config.MONITOR_LEVEL
$Collect = @($Config.MONITOR_COLLECT -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
$Allow = @($Config.MONITOR_ALLOW -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ })

function Test-Collect { param([string]$Name) return $Collect -contains $Name }
function Test-Allow { param([string]$Name) return $Allow -contains $Name }

# ----------------------------------------------------------- what we are ---

function Get-SystemFacts {
    $os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue
    $computer = Get-CimInstance Win32_ComputerSystem -ErrorAction SilentlyContinue
    $bios = Get-CimInstance Win32_BIOS -ErrorAction SilentlyContinue
    $cpu = @(Get-CimInstance Win32_Processor -ErrorAction SilentlyContinue)

    # ProductType says it outright: 1 is a workstation, 2 a domain controller,
    # 3 a server. No guessing needed on this platform.
    $kind = 'server'
    if ($os -and $os.ProductType -eq 1) { $kind = 'client' }

    $uptime = $null
    if ($os -and $os.LastBootUpTime) {
        $uptime = [int]((Get-Date) - $os.LastBootUpTime).TotalSeconds
    }

    $virtualisation = ''
    if ($computer) {
        switch -Regex ($computer.Model) {
            'Virtual Machine' { $virtualisation = 'hyper-v'; break }
            'VMware'          { $virtualisation = 'vmware'; break }
            'VirtualBox'      { $virtualisation = 'virtualbox'; break }
            'KVM|QEMU'        { $virtualisation = 'kvm'; break }
            'Xen'             { $virtualisation = 'xen'; break }
        }
    }

    $address = ''
    try {
        $address = (Get-NetIPConfiguration -ErrorAction Stop |
            Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' } |
            Select-Object -First 1 -ExpandProperty IPv4Address).IPAddress
    } catch {
        $address = (Get-CimInstance Win32_NetworkAdapterConfiguration -ErrorAction SilentlyContinue |
            Where-Object { $_.IPEnabled -and $_.DefaultIPGateway } |
            Select-Object -First 1 -ExpandProperty IPAddress | Select-Object -First 1)
    }

    $fqdn = $env:COMPUTERNAME
    try { $fqdn = [System.Net.Dns]::GetHostEntry($env:COMPUTERNAME).HostName } catch { }

    $cores = 0
    foreach ($processor in $cpu) { $cores += [int]$processor.NumberOfCores }

    return [ordered]@{
        hostname       = $env:COMPUTERNAME
        fqdn           = $fqdn
        kind           = $kind
        os_family      = 'windows'
        os_name        = if ($os) { $os.Caption } else { 'Windows' }
        os_version     = if ($os) { $os.Version } else { '' }
        kernel         = if ($os) { "$($os.Version) build $($os.BuildNumber)" } else { '' }
        arch           = $env:PROCESSOR_ARCHITECTURE
        manufacturer   = if ($computer) { $computer.Manufacturer } else { '' }
        model          = if ($computer) { $computer.Model } else { '' }
        serial         = if ($bios) { $bios.SerialNumber } else { '' }
        cpu_model      = if ($cpu.Count -gt 0) { $cpu[0].Name } else { '' }
        cpu_cores      = $cores
        memory_bytes   = if ($computer) { [long]$computer.TotalPhysicalMemory } else { $null }
        virtualisation = $virtualisation
        primary_ip     = $address
        uptime_seconds = $uptime
        agent_version  = $AgentVersion
    }
}

function Get-Metrics {
    $os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue

    $cpuPercent = $null
    try {
        $samples = @(Get-CimInstance Win32_Processor -ErrorAction Stop | ForEach-Object { [double]$_.LoadPercentage })
        if ($samples.Count -gt 0) {
            $cpuPercent = [math]::Round(($samples | Measure-Object -Average).Average, 2)
        }
    } catch { }

    $memoryTotal = $null
    $memoryUsed = $null
    if ($os) {
        $memoryTotal = [long]$os.TotalVisibleMemorySize * 1024
        $memoryUsed = $memoryTotal - ([long]$os.FreePhysicalMemory * 1024)
    }

    $swapUsed = $null
    try {
        $page = @(Get-CimInstance Win32_PageFileUsage -ErrorAction Stop)
        if ($page.Count -gt 0) {
            $swapUsed = 0
            foreach ($file in $page) { $swapUsed += [long]$file.CurrentUsage * 1MB }
        }
    } catch { }

    $processes = $null
    try { $processes = @(Get-Process -ErrorAction Stop).Count } catch { }

    return [ordered]@{
        cpu_percent        = $cpuPercent
        memory_used_bytes  = $memoryUsed
        memory_total_bytes = $memoryTotal
        swap_used_bytes    = $swapUsed
        load1              = $null
        load5              = $null
        load15             = $null
        process_count      = $processes
    }
}

function Get-Disks {
    $disks = @()
    foreach ($volume in Get-CimInstance Win32_LogicalDisk -Filter 'DriveType = 3' -ErrorAction SilentlyContinue) {
        if (-not $volume.Size) { continue }
        $disks += [ordered]@{
            mount       = $volume.DeviceID
            source      = $volume.VolumeName
            filesystem  = $volume.FileSystem
            total_bytes = [long]$volume.Size
            used_bytes  = [long]$volume.Size - [long]$volume.FreeSpace
        }
    }
    return $disks
}

function Test-RebootPending {
    $keys = @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending',
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired'
    )
    foreach ($key in $keys) {
        if (Test-Path -LiteralPath $key) { return $true }
    }

    try {
        $pending = Get-ItemProperty -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager' -Name PendingFileRenameOperations -ErrorAction Stop
        if ($pending.PendingFileRenameOperations) { return $true }
    } catch { }

    return $false
}

function Get-PendingUpdates {
    $items = @()
    try {
        # Read what Windows already knows, rather than going out to Windows
        # Update for it. A report happens every few minutes and an online scan
        # is a minute of work and a network round trip -- doing that on a
        # schedule would be the most expensive thing this agent does, for an
        # answer that changes once a day. Going out and asking is what **Check
        # for updates** is for, and it is the same split Linux has always had:
        # the report asks apt what it would upgrade, the command refreshes the
        # lists it asks against.
        $session = New-Object -ComObject Microsoft.Update.Session
        $searcher = $session.CreateUpdateSearcher()
        $searcher.Online = $false
        $result = $searcher.Search('IsInstalled=0 AND IsHidden=0')

        foreach ($update in $result.Updates) {
            $security = $false
            foreach ($category in $update.Categories) {
                if ($category.Name -match 'Security|Critical') { $security = $true }
            }

            $items += [ordered]@{
                name              = $update.Title
                current_version   = ''
                available_version = ''
                source            = if ($update.Categories.Count -gt 0) { $update.Categories.Item(0).Name } else { 'Windows Update' }
                is_security       = $security
            }
        }
    } catch {
        # The update agent is not always reachable -- disabled service, a
        # policy, a machine mid-servicing. Say nothing rather than claim there
        # are no updates waiting.
        return $null
    }

    return $items
}

function Get-InstalledPackages {
    # The machine-wide uninstall keys, both architectures. Per-user installs
    # are deliberately left out: they belong to a person, not the machine, and
    # collecting them turns an inventory into surveillance.
    $paths = @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    )

    $packages = @()
    $seen = @{}
    foreach ($path in $paths) {
        foreach ($entry in Get-ItemProperty -Path $path -ErrorAction SilentlyContinue) {
            if (-not $entry.DisplayName) { continue }
            if ($entry.SystemComponent -eq 1) { continue }
            # $seenKey, not $key: that is the -Key parameter, and a string
            # assigns to it without complaint -- which is worse than throwing.
            $seenKey = "$($entry.DisplayName)|$($entry.DisplayVersion)"
            if ($seen.ContainsKey($seenKey)) { continue }
            $seen[$seenKey] = $true

            $packages += [ordered]@{
                name      = [string]$entry.DisplayName
                version   = [string]$entry.DisplayVersion
                publisher = [string]$entry.Publisher
                source    = 'registry'
            }
        }
    }

    return $packages
}

function Get-Services {
    $services = @()
    foreach ($service in Get-CimInstance Win32_Service -ErrorAction SilentlyContinue) {
        $services += [ordered]@{
            name         = $service.Name
            display_name = $service.DisplayName
            state        = ([string]$service.State).ToLower()
            startup      = ([string]$service.StartMode).ToLower()
        }
    }
    return $services
}

function Get-ListeningPorts {
    $ports = @()
    $names = @{}

    try {
        foreach ($process in Get-Process -ErrorAction Stop) { $names[$process.Id] = $process.ProcessName }
    } catch { }

    try {
        foreach ($connection in Get-NetTCPConnection -State Listen -ErrorAction Stop) {
            $ports += [ordered]@{
                protocol = 'tcp'
                address  = $connection.LocalAddress
                port     = [int]$connection.LocalPort
                process  = if ($names.ContainsKey([int]$connection.OwningProcess)) { $names[[int]$connection.OwningProcess] } else { '' }
            }
        }
        foreach ($endpoint in Get-NetUDPEndpoint -ErrorAction Stop) {
            $ports += [ordered]@{
                protocol = 'udp'
                address  = $endpoint.LocalAddress
                port     = [int]$endpoint.LocalPort
                process  = if ($names.ContainsKey([int]$endpoint.OwningProcess)) { $names[[int]$endpoint.OwningProcess] } else { '' }
            }
        }
    } catch {
        # Older Windows without the NetTCPIP module.
        $netstat = Invoke-Native 'netstat.exe' @('-ano')
        foreach ($line in $netstat.Output) {
            if ($line -match '^\s*(TCP|UDP)\s+(\S+):(\d+)\s+\S+\s+(LISTENING|\*:\*)?\s*(\d+)?\s*$') {
                $ports += [ordered]@{
                    protocol = $Matches[1].ToLower()
                    address  = $Matches[2]
                    port     = [int]$Matches[3]
                    process  = if ($Matches[5] -and $names.ContainsKey([int]$Matches[5])) { $names[[int]$Matches[5]] } else { '' }
                }
            }
        }
    }

    return $ports
}

# --------------------------------------------------------------- commands ---

# The whole vocabulary. A name that is not one of these five is refused here,
# before anything runs, and the three that change the machine additionally need
# this machine's own consent, in MONITOR_ALLOW or MONITOR_SELF_UPDATE.
function Invoke-AgentCommand {
    param([string]$Name, [string]$CommandId = '')

    switch ($Name) {
        'report_now' {
            return @{ ok = $true; exit_code = 0; output = 'Reporting.'; error = '' }
        }

        'refresh_updates' {
            # The one command that goes out to Windows Update rather than
            # reading what was cached the last time something did. Online is
            # set here explicitly, and deliberately not set in the reader the
            # report uses: this is apt-get update, and that is apt-get -s
            # upgrade.
            try {
                $said = @()
                $said += Write-CommandLine -Id $CommandId -Text 'Asking Windows Update what is available. This can take a minute.'

                $session = New-Object -ComObject Microsoft.Update.Session
                $searcher = $session.CreateUpdateSearcher()
                $searcher.Online = $true
                $result = $searcher.Search('IsInstalled=0 AND IsHidden=0')

                $security = 0
                foreach ($update in $result.Updates) {
                    foreach ($category in $update.Categories) {
                        if ($category.Name -match 'Security|Critical') { $security++; break }
                    }
                }

                $total = $result.Updates.Count
                if ($total -eq 0) {
                    $last = 'Windows Update answered. Nothing is waiting.'
                } elseif ($security -eq 0) {
                    $last = "Windows Update answered. $total update(s) waiting."
                } else {
                    $last = "Windows Update answered. $total update(s) waiting, $security of them security."
                }

                $said += Write-CommandLine -Id $CommandId -Text $last

                return @{ ok = $true; exit_code = 0; output = ($said -join "`n"); error = '' }
            } catch {
                return @{ ok = $false; exit_code = 1; output = ''; error = "Windows Update could not be queried: $($_.Exception.Message)" }
            }
        }

        'update_agent' {
            # Asked for rather than noticed, so it reinstalls even when the
            # versions already agree. That is the point of asking: it is how a
            # damaged agent gets repaired from the interface.
            return Invoke-SelfUpdate -Reason 'Monitor asked for the agent to be reinstalled.' -Force -CommandId $CommandId
        }

        'install_updates' {
            if (-not (Test-Allow 'updates')) {
                return @{ ok = $false; exit_code = 77; output = ''; error = 'This machine was installed without -AllowUpdates.' }
            }

            try {
                $said = @()
                $session = New-Object -ComObject Microsoft.Update.Session
                $searcher = $session.CreateUpdateSearcher()
                $result = $searcher.Search('IsInstalled=0 AND IsHidden=0')
                if ($result.Updates.Count -eq 0) {
                    return @{ ok = $true; exit_code = 0; output = 'Nothing to install.'; error = '' }
                }

                # Said as it happens rather than at the end. Windows Update has
                # no line-by-line output to stream, so what gets streamed is
                # each step: this is the difference between watching four
                # minutes of work and staring at a spinner for it.
                $wanted = New-Object -ComObject Microsoft.Update.UpdateColl
                foreach ($update in $result.Updates) {
                    if ($update.EulaAccepted -eq $false) { $update.AcceptEula() }
                    [void]$wanted.Add($update)
                    $said += Write-CommandLine -Id $CommandId -Text "Found: $($update.Title)"
                }

                $said += Write-CommandLine -Id $CommandId -Text "Downloading $($wanted.Count) update(s)."
                $downloader = $session.CreateUpdateDownloader()
                $downloader.Updates = $wanted
                [void]$downloader.Download()

                $said += Write-CommandLine -Id $CommandId -Text 'Installing.'
                $installer = $session.CreateUpdateInstaller()
                $installer.Updates = $wanted
                $outcome = $installer.Install()

                $last = "Installed $($wanted.Count) update(s). Result code $($outcome.ResultCode)."
                if ($outcome.RebootRequired) { $last += ' A restart is needed.' }
                $said += Write-CommandLine -Id $CommandId -Text $last

                return @{ ok = ($outcome.ResultCode -in 2, 3); exit_code = [int]$outcome.ResultCode; output = ($said -join "`n"); error = '' }
            } catch {
                return @{ ok = $false; exit_code = 1; output = ''; error = $_.Exception.Message }
            }
        }

        'reboot' {
            if (-not (Test-Allow 'reboot')) {
                return @{ ok = $false; exit_code = 77; output = ''; error = 'This machine was installed without -AllowReboot.' }
            }

            # A minute's grace, so this report finishes and the reason is in
            # the machine's own event log before it goes.
            Invoke-Native 'shutdown.exe' @('/r', '/t', '60', '/c', 'Restart requested from Monitor') | Out-Null
            return @{ ok = $true; exit_code = 0; output = 'Restarting in one minute.'; error = '' }
        }

        default {
            return @{ ok = $false; exit_code = 1; output = ''; error = "Unknown command refused: $Name" }
        }
    }
}

# One line of a running command: kept for the result, written to the log, and
# sent while the command is still going. Hands the line back so the caller can
# collect the lot for the report.
function Write-CommandLine {
    param([string]$Id, [string]$Text, [string]$Level = 'info')

    Write-AgentLog -Level $Level -Message $Text -Command $Id
    Send-OutboxLogs | Out-Null

    return $Text
}

# ---------------------------------------------------------------- updating ---
#
# The agent updates itself by running the installer again, not by downloading
# agent.ps1 and swapping itself for it.
#
# The installer is always the newest version of the whole job -- fetch,
# sanity-check, replace, re-register the scheduled task -- and it already knows
# how to keep this machine's settings. Doing it this way means install and
# update are one code path, so they cannot drift apart, and a bug in the update
# path is a bug somebody would have hit installing.
#
# What protects this machine is not a checksum: whoever controls that server
# controls this agent by design, and a hash they also serve proves nothing
# against them. What protects it is the address pinned in the config, TLS on
# the way, and MONITOR_SELF_UPDATE -- the machine's own veto, set here at
# install time and impossible to grant from there.

# How long to leave it after a failed attempt. Without this, a server stuck
# announcing a version that never arrives would have every machine in the fleet
# reinstalling every fifteen seconds.
#
# Monitor sets it, in Settings -> Agent, and it arrives with every answer. What
# is left here is the floor and the fallback: a minute is the shortest value
# that is still a throttle rather than a loop, and a machine that has never had
# an answer out of this server behaves the way it always did.
$UpdateRetryFloor = 60
$UpdateRetryDefault = 3600

function Get-UpdateRetrySeconds {
    $seconds = 0
    if (-not [int]::TryParse([string]$Config.MONITOR_UPDATE_RETRY, [ref]$seconds)) {
        $seconds = $UpdateRetryDefault
    }
    if ($seconds -lt $UpdateRetryFloor) { $seconds = $UpdateRetryFloor }

    return $seconds
}

# When this machine last started, as a number that changes only when it does.
#
# Not the uptime: an uptime is a different number every second, and what is
# wanted is an identity for the current boot that can be compared with the one
# from the last run. Rounded to the second, because LastBootUpTime is reported
# with more precision than it is measured with and can wobble underneath.
function Get-BootEpoch {
    try {
        $os = Get-CimInstance Win32_OperatingSystem -ErrorAction Stop
        if ($os -and $os.LastBootUpTime) {
            return [long][math]::Floor(($os.LastBootUpTime.ToUniversalTime() - [datetime]'1970-01-01').TotalSeconds)
        }
    } catch { }

    return 0
}

# Whether a full report is owed regardless of the schedule.
#
# Two things make everything this agent knows worth saying again straight away
# rather than at the next interval: the machine has restarted, so the uptime,
# the pending-restart flag and whatever changed across the reboot are all
# stale; and the agent has been replaced, so what it can see may have changed
# and the version on its own page is wrong until it says otherwise.
#
# The stamp is written after a report gets through, not before, so a machine
# that cannot reach the server keeps owing the report rather than losing it.
function Get-RunStamp { return (Join-Path $StatePath 'last-run') }

function Get-ReportOwed {
    $want = '{0} {1}' -f (Get-BootEpoch), $AgentVersion
    $last = ''
    try { $last = (Get-Content -LiteralPath (Get-RunStamp) -Raw -ErrorAction Stop).Trim() } catch { }

    if ($last -eq $want) { return '' }
    if (-not $last) { return 'this agent has not reported from here before' }
    if ($last.StartsWith(($want -split ' ')[0] + ' ')) { return "the agent is now $AgentVersion" }

    return 'this machine has restarted'
}

function Set-Reported {
    try {
        if (-not (Test-Path -LiteralPath $StatePath)) { New-Item -ItemType Directory -Path $StatePath -Force | Out-Null }
        Set-Content -LiteralPath (Get-RunStamp) -Value ('{0} {1}' -f (Get-BootEpoch), $AgentVersion) -Encoding UTF8
    } catch { }
}

# The version Monitor says this machine should be running, out of the answer it
# just gave. Read from inside the "agent" object rather than by looking for
# "version" anywhere in the body.
function Get-OfferedVersion {
    param([string]$Body)

    if ($Body -match '"agent":\s*\{[^}]*"version":"([0-9][0-9.]*)"') { return $Matches[1] }

    return ''
}

function Test-UpdateAttemptedRecently {
    $stamp = Join-Path $StatePath 'updated-at'
    if (-not (Test-Path -LiteralPath $stamp)) { return $false }

    try {
        $last = [datetime]::Parse((Get-Content -LiteralPath $stamp -Raw).Trim(), [System.Globalization.CultureInfo]::InvariantCulture, [System.Globalization.DateTimeStyles]::RoundtripKind)
        return ((Get-Date).ToUniversalTime() - $last).TotalSeconds -lt (Get-UpdateRetrySeconds)
    } catch {
        return $false
    }
}

# Returns the same shape a command does, so it can be one.
function Invoke-SelfUpdate {
    param([string]$Reason, [switch]$Force, [string]$CommandId = '')

    if ($Config.MONITOR_SELF_UPDATE -eq '0') {
        Write-AgentLog -Level 'info' -Message "$Reason Refused: this machine was installed with -NoSelfUpdate." -Command $CommandId
        return @{ ok = $false; exit_code = 77; output = ''; error = 'This machine was installed with -NoSelfUpdate.' }
    }

    if (-not $Force -and (Test-UpdateAttemptedRecently)) {
        $window = Get-UpdateRetrySeconds
        Write-AgentLog -Level 'debug' -Message "$Reason Not trying again yet; the last attempt was less than ${window}s ago."
        return @{ ok = $false; exit_code = 1; output = ''; error = "An update was attempted less than ${window}s ago." }
    }

    try {
        if (-not (Test-Path -LiteralPath $StatePath)) { New-Item -ItemType Directory -Path $StatePath -Force | Out-Null }
        Set-Content -LiteralPath (Join-Path $StatePath 'updated-at') -Value (Get-Date).ToUniversalTime().ToString('o') -Encoding UTF8
    } catch { }

    $source = $Config.MONITOR_URL.TrimEnd('/') + '/agent/windows/install.ps1'
    $staging = Join-Path ([System.IO.Path]::GetTempPath()) 'monitor-agent-install.ps1'

    Write-CommandLine -Id $CommandId -Text "$Reason Fetching $source" | Out-Null

    Initialize-Tls
    try {
        Invoke-WebRequest -Uri $source -OutFile $staging -UseBasicParsing -TimeoutSec 120
    } catch {
        Write-AgentLog -Level 'error' -Message "Could not download the installer: $($_.Exception.Message)" -Command $CommandId
        return @{ ok = $false; exit_code = 1; output = ''; error = "Could not download the installer from $source." }
    }

    # A truncated download would install something that silently does half a
    # job -- the same kind of check the installer makes of the agent.
    $downloaded = Get-Content -LiteralPath $staging -Raw
    if ($downloaded -notmatch 'Installs the Monitor agent' -or $downloaded -notmatch 'MONITOR_TOKEN') {
        Remove-Item -LiteralPath $staging -Force -ErrorAction SilentlyContinue
        Write-AgentLog -Level 'error' -Message "What came back from $source does not look like the installer. Nothing was changed." -Command $CommandId
        return @{ ok = $false; exit_code = 1; output = ''; error = 'What came back does not look like the installer.' }
    }

    # No arguments. Every setting is read back out of this machine's own config
    # by the installer, so an update cannot quietly change what this machine
    # was installed to do -- least of all what it consents to being asked.
    $run = Invoke-Native 'powershell.exe' @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $staging)
    $said = @()
    foreach ($line in @($run.Output)) {
        $text = [string]$line
        if ($text.Trim()) { $said += Write-CommandLine -Id $CommandId -Text "installer: $text" }
    }
    Remove-Item -LiteralPath $staging -Force -ErrorAction SilentlyContinue

    if ($run.ExitCode -ne 0) {
        Write-AgentLog -Level 'error' -Message "The installer failed, exit $($run.ExitCode). This agent is unchanged and still running." -Command $CommandId
        return @{ ok = $false; exit_code = [int]$run.ExitCode; output = ($said -join "`n"); error = "The installer failed, exit $($run.ExitCode)." }
    }

    # So that a run which both carried out an update_agent command and then
    # noticed the version had moved does not install the same thing twice.
    $script:Updated = $true

    Write-AgentLog -Level 'info' -Message "Reinstalled from $source. The next run will be the new agent." -Command $CommandId

    return @{ ok = $true; exit_code = 0; output = ($said -join "`n"); error = '' }
}

$Updated = $false

# Is the agent Monitor holds a different one from this?
#
# Different, not newer: a version that went backwards is a deliberate rollback
# on the server, and a fleet that refuses to follow it is a fleet that cannot
# be rolled back.
#
# Deliberately the last thing a run does. Replacing the script this process was
# started from, and re-registering the task that started it, are both safe once
# there is nothing left to do.
function Invoke-SelfUpdateIfOffered {
    param([string]$Body)

    if ($script:Updated) { return }
    if ($Config.MONITOR_SELF_UPDATE -eq '0') { return }

    $offered = Get-OfferedVersion -Body $Body
    if (-not $offered -or $offered -eq $AgentVersion) { return }

    Invoke-SelfUpdate -Reason "Monitor holds $offered, this is $AgentVersion." | Out-Null
}

# ---------------------------------------------------------------- posting ---

function Initialize-Tls {
    try {
        [System.Net.ServicePointManager]::SecurityProtocol =
            [System.Net.SecurityProtocolType]::Tls12 -bor [System.Net.SecurityProtocolType]::Tls11
    } catch { }

    if ($Config.MONITOR_INSECURE -eq '1') {
        # Asked for explicitly at install time, and only sensible for a Monitor
        # install using a self-signed certificate.
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
    }
}

function Build-Report {
    param([array]$Results = @())

    $report = [ordered]@{
        system  = Get-SystemFacts
        metrics = Get-Metrics
    }

    $collected = @()

    # Every list is wrapped in @(), and it is not decoration.
    #
    # PowerShell unrolls a collection on the way out of a function, so a
    # machine with exactly one fixed disk gets the hashtable itself back rather
    # than an array holding it -- and the encoder, which checks IDictionary
    # before IEnumerable, then writes "disks":{...} where the server is reading
    # an array. It finds no rows in an object and stores none, while
    # "collected" still says disks were gathered, so the machine's page shows
    # no disks at all and nothing anywhere says why.
    #
    # A machine with two disks is fine, which is what kept this hidden: every
    # other list here has hundreds of entries. Zero is wrong too -- an empty
    # array comes back as $null, and "disks":null.
    if (Test-Collect 'disks') { $report['disks'] = @(Get-Disks); $collected += 'disks' }

    if (Test-Collect 'updates') {
        $updates = Get-PendingUpdates
        if ($null -ne $updates) {
            $report['updates'] = [ordered]@{
                reboot_required = (Test-RebootPending)
                security_count  = 0
                items           = @($updates)
            }
            $collected += 'updates'
        }
    }

    if (Test-Collect 'packages') { $report['packages'] = @(Get-InstalledPackages); $collected += 'packages' }
    if (Test-Collect 'services') { $report['services'] = @(Get-Services); $collected += 'services' }
    if (Test-Collect 'ports') { $report['ports'] = @(Get-ListeningPorts); $collected += 'ports' }

    $report['collected'] = $collected
    $report['interval_seconds'] = [int]$Config.MONITOR_INTERVAL
    $report['poll_seconds'] = [int]$Config.MONITOR_POLL
    # Whether this machine consents to the agent being replaced from there.
    # Monitor shows it and honours it; it cannot change it.
    $report['self_update'] = ($Config.MONITOR_SELF_UPDATE -ne '0')
    # And the rest of what it consents to, so Monitor can say up front which of
    # its buttons this machine is going to refuse instead of leaving somebody
    # to find out by pressing one.
    $report['allow'] = @(@('updates', 'reboot') | Where-Object { Test-Allow $_ })
    $report['results'] = $Results

    return $report
}

function Send-Report {
    param([string]$Endpoint, [string]$Body, [string]$Token)

    Initialize-Tls

    $headers = @{
        'Authorization'   = "Bearer $Token"
        'X-Monitor-Token' = $Token
        'Accept'          = 'application/json'
    }

    $uri = $Config.MONITOR_URL.TrimEnd('/') + $Endpoint
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($Body)

    try {
        $response = Invoke-WebRequest -Uri $uri -Method Post -Headers $headers `
            -ContentType 'application/json; charset=utf-8' -Body $bytes `
            -UserAgent "monitor-agent/$AgentVersion" -TimeoutSec 120 -UseBasicParsing
        return @{ code = [int]$response.StatusCode; body = [string]$response.Content }
    } catch [System.Net.WebException] {
        $status = 0
        $content = ''
        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode
            try {
                $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
                $content = $reader.ReadToEnd()
            } catch { }
        }
        return @{ code = $status; body = $content; message = $_.Exception.Message }
    } catch {
        return @{ code = 0; body = ''; message = $_.Exception.Message }
    }
}

function Get-QueuedCommands {
    param([string]$Body)

    $commands = @()
    foreach ($match in [regex]::Matches($Body, '\{"id":"(?<id>[0-9a-fA-F-]{36})","command":"(?<command>[a-z_]{1,40})"\}')) {
        $commands += @{ id = $match.Groups['id'].Value; command = $match.Groups['command'].Value }
    }

    return $commands
}

# The schedule this agent actually runs on.
#
# Changing "report every" in Monitor has to reach the scheduled task, or the
# number on the page is a decoration. The agent owns that task -- the installer
# registered it -- so it adjusts its own schedule and nothing else.
# With the live channel on, the scheduled task only has to keep an agent
# process alive, so it runs every minute and the agent does its own timing
# inside. With it off there is nothing to keep alive, and the task goes back to
# deciding when to report.
function Get-SchedulerCadence {
    # $pollSeconds, not $poll: see the note in the -Loop block below.
    $pollSeconds = 0
    [int]::TryParse([string]$Config.MONITOR_POLL, [ref]$pollSeconds) | Out-Null
    if ($pollSeconds -gt 0) { return 60 }

    $interval = 300
    [int]::TryParse([string]$Config.MONITOR_INTERVAL, [ref]$interval) | Out-Null
    return $interval
}

function Set-AgentSchedule {
    param([int]$Seconds)

    try {
        $task = Get-ScheduledTask -TaskName 'Monitor agent' -ErrorAction Stop
        foreach ($trigger in $task.Triggers) {
            if ($trigger.Repetition -and $trigger.Repetition.Interval) {
                $trigger.Repetition.Interval = [System.Xml.XmlConvert]::ToString([TimeSpan]::FromSeconds($Seconds))
            }
        }
        Set-ScheduledTask -TaskName 'Monitor agent' -Trigger $task.Triggers | Out-Null
    } catch {
        # Not fatal: the agent keeps its old cadence and says so nowhere,
        # because a failed schedule change must not stop a report going out.
    }
}

# A change made in the interface reaches the machine on its next answer,
# whichever door it came through, rather than waiting out the old schedule.
function Update-Cadences {
    param([string]$Body)

    # Kept so that whatever the run does last can see what the server last
    # said -- the version it is offering, in particular.
    $script:LastAnswer = $Body

    $rescheduled = $false
    $rewrite = $false

    if ($Body -match '"interval":\s*(\d+)' -and $Matches[1] -ne $Config.MONITOR_INTERVAL) {
        $Config.MONITOR_INTERVAL = $Matches[1]
        $rescheduled = $true
    }
    if ($Body -match '"poll":\s*(\d+)' -and $Matches[1] -ne $Config.MONITOR_POLL) {
        $Config.MONITOR_POLL = $Matches[1]
        $rescheduled = $true
    }

    if ($rescheduled) {
        Write-AgentLog -Level 'info' -Message "Monitor asked for a report every $($Config.MONITOR_INTERVAL)s and a check every $($Config.MONITOR_POLL)s."
        $rewrite = $true
    }

    # How much to say is Monitor's to decide -- unlike what may be done to this
    # machine, saying less or more is not a permission -- so it arrives with
    # every answer and is kept, so a run that never reaches the server still
    # logs at the level last asked for.
    if ($Body -match '"level":"(error|warn|info|debug)"' -and $Matches[1] -ne $Config.MONITOR_LEVEL) {
        $Config.MONITOR_LEVEL = $Matches[1]
        $script:LevelFloor = Get-LevelRank $Config.MONITOR_LEVEL
        Write-AgentLog -Level 'info' -Message "Monitor set the log level to $($Config.MONITOR_LEVEL)."
        $rewrite = $true
    }

    # How long to leave it between update attempts is Monitor's to decide, for
    # the same reason the log level is: it changes when this machine tries, not
    # what it is willing to do. Kept in the config so a fresh process has it
    # before its first answer arrives.
    if ($Body -match '"update_retry":\s*(\d+)' -and $Matches[1] -ne $Config.MONITOR_UPDATE_RETRY) {
        $Config.MONITOR_UPDATE_RETRY = $Matches[1]
        Write-AgentLog -Level 'info' -Message "Monitor set the update retry to $(Get-UpdateRetrySeconds)s."
        $rewrite = $true
    }

    if ($rewrite) {
        try { Write-Config -Path $Conf -Config $Config } catch { }
    }
    if ($rescheduled) {
        Set-AgentSchedule -Seconds (Get-SchedulerCadence)
    }
}

$LastAnswer = ''

# One knock on the live channel. Cheap enough to do every few seconds: an empty
# body out, a sentence back.
#
# Returns the body, $null if this machine has been switched off, and throws if
# the token was refused.
function Invoke-Poll {
    $answer = Send-Report -Endpoint '/api/agent/poll' -Body '{}' -Token $Config.MONITOR_TOKEN

    switch ($answer.code) {
        200 {
            if ($answer.body -match '"status":"disabled"') {
                Write-Output 'monitor-agent: this machine is switched off in Monitor. Nothing to do.'
                Write-AgentLog -Level 'warn' -Message 'Switched off in Monitor. Nothing to do.'
                return $null
            }
            Update-Cadences -Body $answer.body
            return $answer.body
        }
        400 {
            Set-Failure 3
            Write-AgentLog -Level 'error' -Message "The server could not read the live channel request: $($answer.body)"
            throw 'The server could not read what this machine sent on the live channel.'
        }
        401 {
            Set-Failure 2
            Write-AgentLog -Level 'error' -Message "The live channel was refused: this machine's token is not accepted. Run the installer again."
            throw "This machine's token was refused. It may have been revoked; run the installer again to enrol."
        }
        0 {
            Set-Failure 1
            Write-AgentLog -Level 'debug' -Message "Could not reach $($Config.MONITOR_URL) on the live channel."
            throw "Could not reach $($Config.MONITOR_URL). $($answer.message)"
        }
        default {
            Set-Failure 1
            Write-AgentLog -Level 'debug' -Message "The live channel answered $($answer.code)."
            throw "Server answered $($answer.code)."
        }
    }
}

# Carry out whatever an answer was carrying, and report if anything ran or if a
# report was asked for.
function Invoke-Answer {
    param([string]$Body)

    $queued = @(Get-QueuedCommands -Body $Body)

    if ($queued.Count -gt 0) {
        $results = @()
        foreach ($command in $queued) {
            Write-AgentLog -Level 'info' -Message "Running $($command.command)." -Command $command.id
            Send-OutboxLogs -Now | Out-Null

            $outcome = Invoke-AgentCommand -Name $command.command -CommandId $command.id

            if ($outcome.ok) {
                Write-AgentLog -Level 'info' -Message "$($command.command) finished." -Command $command.id
            } elseif ([int]$outcome.exit_code -eq 77) {
                # Refused here, by this machine, for want of consent. Worth
                # saying plainly: from Monitor's side it looks the same as a
                # failure, and it is not one.
                Write-AgentLog -Level 'warn' -Message "$($command.command) refused: this machine was not installed to allow it." -Command $command.id
            } else {
                Write-AgentLog -Level 'warn' -Message "$($command.command) failed, exit $($outcome.exit_code)." -Command $command.id
            }
            Send-OutboxLogs -Now | Out-Null

            $results += [ordered]@{
                id        = $command.id
                ok        = [bool]$outcome.ok
                exit_code = [int]$outcome.exit_code
                output    = [string]$outcome.output
                error     = [string]$outcome.error
            }
        }
        # Report straight away, so the outcome and the picture it produced
        # arrive together rather than a poll apart.
        Invoke-Report -Results $results | Out-Null
        return
    }

    if ($Body -match '"report":\s*true') {
        Invoke-Report | Out-Null
    }
}

function Invoke-Report {
    param([array]$Results = @())

    $body = ConvertTo-MonitorJson (Build-Report -Results $Results)
    $answer = Send-Report -Endpoint '/api/agent/report' -Body $body -Token $Config.MONITOR_TOKEN

    switch ($answer.code) {
        200 {
            if ($answer.body -match '"status":"disabled"') {
                Write-Output 'monitor-agent: this machine is switched off in Monitor. Nothing to do.'
                Write-AgentLog -Level 'warn' -Message 'Switched off in Monitor. Nothing to do.'
                return $null
            }
            Write-AgentLog -Level 'debug' -Message 'Reported.'
            Set-Reported
            Update-Cadences -Body $answer.body
            return $answer.body
        }
        400 {
            # The server could not read what was sent. Almost always this
            # agent's fault rather than the machine's, and worth saying so in
            # the words that lead somewhere.
            Set-Failure 3
            Write-AgentLog -Level 'error' -Message "The server could not read this report: $($answer.body)"
            throw "The server could not read this report. Run 'agent.ps1 -Dump' here and check it is whole."
        }
        401 {
            Set-Failure 2
            Write-AgentLog -Level 'error' -Message "Reporting was refused: this machine's token is not accepted. Run the installer again."
            throw "This machine's token was refused. It may have been revoked; run the installer again to enrol."
        }
        0 {
            Set-Failure 1
            Write-AgentLog -Level 'warn' -Message "Could not reach $($Config.MONITOR_URL) to report."
            throw "Could not reach $($Config.MONITOR_URL). $($answer.message)"
        }
        default {
            Set-Failure 1
            Write-AgentLog -Level 'warn' -Message "Reporting answered $($answer.code)."
            throw "Server answered $($answer.code)."
        }
    }
}

function Invoke-Enrolment {
    param([string]$EnrolmentKey)

    if (-not $EnrolmentKey) { throw 'No enrolment key given.' }

    $body = ConvertTo-MonitorJson (Build-Report)
    $answer = Send-Report -Endpoint '/api/agent/enroll' -Body $body -Token $EnrolmentKey

    if ($answer.code -ne 201 -and $answer.code -ne 200) {
        switch ($answer.code) {
            401 { throw 'The enrolment key was refused. It may be expired, revoked or used up.' }
            429 { throw 'Too many enrolment attempts from this address. Try again shortly.' }
            0   { throw "Could not reach $($Config.MONITOR_URL). $($answer.message)" }
            default { throw "Enrolment failed, server answered $($answer.code)." }
        }
    }

    if ($answer.body -notmatch '"token":"(mdt_[0-9a-f]+)"') {
        throw 'Enrolment answered without a token.'
    }
    $Config.MONITOR_TOKEN = $Matches[1]

    if ($answer.body -match '"device_id":"([0-9a-f-]{36})"') {
        $Config.MONITOR_DEVICE_ID = $Matches[1]
    }

    Write-Config -Path $Conf -Config $Config
    Write-Output "monitor-agent: enrolled as $($Config.MONITOR_DEVICE_ID)"
    Write-AgentLog -Level 'info' -Message "Enrolled with $($Config.MONITOR_URL) as $($Config.MONITOR_DEVICE_ID)."
}

# ------------------------------------------------------------------- main ---

# The end of every run that got as far as being one.
#
# Whatever is still in the outbox goes now rather than waiting for the next
# run: a machine that reports every five minutes should not take five minutes
# to say that something went wrong. Replacing the agent is considered only
# after that, with the work of the run behind it -- the installer re-registers
# the scheduled task and replaces this very file, so the agent that comes back
# a minute from now is the new one.
function Complete-Run {
    param([int]$Code = 0, [switch]$Update)

    if ($Update) {
        try { Invoke-SelfUpdateIfOffered -Body $script:LastAnswer } catch { }
    }
    try { Send-OutboxLogs -Now | Out-Null } catch { }

    exit $Code
}

if ($Dump) {
    ConvertTo-MonitorJson (Build-Report)
    exit 0
}

if (-not $Config.MONITOR_URL) {
    Write-Error "monitor-agent: not configured. Expected MONITOR_URL in $Conf"
    exit 1
}

if ($Enroll) {
    Invoke-Enrolment -EnrolmentKey $Key
    Complete-Run 0
}

if (-not $Config.MONITOR_TOKEN) {
    Write-Error 'monitor-agent: this machine has not enrolled yet. Run the installer.'
    exit 1
}

$mode = 'once'
if ($Loop) { $mode = 'loop' } elseif ($Poll) { $mode = 'poll' }
Write-AgentLog -Level 'debug' -Message "monitor-agent $AgentVersion starting ($mode)."

# Stay alive for just under a minute, asking every few seconds whether anything
# is waiting, then exit and let the scheduled task start a fresh one.
#
# The same trick the server's own scheduler uses, and it is what makes the live
# channel need no Windows service and no supervisor: a crash costs at most one
# minute, and the process is never long-lived enough to leak.
if ($Loop) {
    # Not $poll. PowerShell variables are case-insensitive, so $poll IS the
    # -Poll switch declared above, and assigning an Int32 to it throws before
    # anything else happens. That killed every scheduled run this agent ever
    # made -- the task runs it with -Loop -- while -Once, which the installer
    # uses, went on working and hid it.
    $pollSeconds = 0
    [int]::TryParse([string]$Config.MONITOR_POLL, [ref]$pollSeconds) | Out-Null

    if ($pollSeconds -le 0) {
        # Live channel off. There is nothing to keep alive, so behave the way
        # this agent always did: one report, then leave.
        try {
            $body = Invoke-Report
        } catch {
            Write-Error -Message $_.Exception.Message -ErrorAction Continue
            Complete-Run $(if ($script:FailureCode -eq 0) { 1 } else { $script:FailureCode })
        }
        if ($null -ne $body) { Invoke-Answer -Body $body }
        Complete-Run 0 -Update
    }

    # Before any of the knocking: if this is the first run since the machine
    # started or since the agent changed, say everything now. On the live
    # channel the alternative is a machine that has just come back describing
    # itself as it was before it went.
    $owed = Get-ReportOwed
    if ($owed) {
        Write-AgentLog -Level 'info' -Message "Reporting in full: $owed."
        try { Invoke-Report | Out-Null } catch { Write-Warning $_.Exception.Message }
    }

    $loopSeconds = 55
    if ($env:MONITOR_LOOP_SECONDS) {
        [int]::TryParse($env:MONITOR_LOOP_SECONDS, [ref]$loopSeconds) | Out-Null
    }
    $deadline = (Get-Date).AddSeconds($loopSeconds)

    while ($true) {
        try {
            $body = Invoke-Poll
            if ($null -eq $body) { Complete-Run 0 }   # switched off here
            Invoke-Answer -Body $body
        } catch {
            # A refused token will not start working within the minute; say so
            # and stop. Anything else might, so keep knocking.
            if ($script:FailureCode -eq 2) {
                Write-Error -Message $_.Exception.Message -ErrorAction Continue
                Complete-Run 2
            }
            Write-Warning $_.Exception.Message
        }

        $remaining = ($deadline - (Get-Date)).TotalSeconds
        if ($remaining -le 0) { break }

        # The cadence may have been changed by the answer just handled.
        [int]::TryParse([string]$Config.MONITOR_POLL, [ref]$pollSeconds) | Out-Null
        if ($pollSeconds -le 0) { break }

        $wait = [math]::Max(1, [math]::Min($pollSeconds, [int][math]::Floor($remaining)))
        Start-Sleep -Seconds $wait
    }

    Complete-Run 0 -Update
}

if ($Poll) {
    try {
        $body = Invoke-Poll
    } catch {
        Write-Error -Message $_.Exception.Message -ErrorAction Continue
        Complete-Run $(if ($script:FailureCode -eq 0) { 1 } else { $script:FailureCode })
    }
    if ($null -ne $body) { Write-Output $body }
    Complete-Run 0
}

# -Once, and the default: one full report, then whatever it came back with.
try {
    $answerBody = Invoke-Report
} catch {
    Write-Error -Message $_.Exception.Message -ErrorAction Continue
    Complete-Run $(if ($script:FailureCode -eq 0) { 1 } else { $script:FailureCode })
}
if ($null -eq $answerBody) { Complete-Run 0 }
Invoke-Answer -Body $answerBody
Complete-Run 0 -Update
