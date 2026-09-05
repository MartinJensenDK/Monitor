<#
    Checks the Windows agent without a Windows machine.

        pwsh tools/check-agent.ps1

    The agent and its installer ship as PowerShell, and a self-hosted Monitor
    is very unlikely to be running on Windows -- so the scripts most in need of
    testing are the ones the server cannot execute. This closes as much of that
    gap as can be closed from Linux:

      1. Both scripts are parsed with PowerShell's own parser, so a syntax
         error cannot reach a machine.
      2. The parts that are pure logic are executed: the JSON encoder, the
         config reader, the patterns that read the server's answers, and the
         guard around native commands.
      3. Every cmdlet parameter is checked against the cmdlet where it can be
         resolved, and the rest are listed by name -- those are Windows-only
         and rest on review against the published reference.

    What this cannot do is call the Windows APIs themselves: scheduled tasks,
    CIM, the Windows Update agent. Point (3) exists because a wrong parameter
    on one of those is exactly the failure this file was written after.
#>

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$scripts = @(
    (Join-Path $root 'resources/agent/agent.ps1'),
    (Join-Path $root 'resources/agent/install.ps1')
)

$failures = 0
function Check {
    param([string]$What, $Got, $Want)
    $ok = ([string]$Got -ceq [string]$Want)
    if (-not $ok) { $script:failures++ }
    "{0}  {1,-48} {2}" -f $(if ($ok) { 'PASS' } else { 'FAIL' }), $What, $(if ($ok) { '' } else { "got [$Got] want [$Want]" })
}

# ------------------------------------------------------------- 1. it parses ---

'-- syntax --'
$definitions = @()
$calls = @()

foreach ($file in $scripts) {
    $tokens = $null; $errors = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseFile($file, [ref]$tokens, [ref]$errors)

    if ($errors.Count -gt 0) {
        $failures += $errors.Count
        foreach ($e in $errors) { "FAIL  {0}:{1} {2}" -f (Split-Path -Leaf $file), $e.Extent.StartLineNumber, $e.Message }
        continue
    }
    "PASS  {0,-48} {1} tokens" -f (Split-Path -Leaf $file), $tokens.Count

    $definitions += $ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $true)
    foreach ($c in $ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.CommandAst] }, $true)) {
        $name = $c.GetCommandName()
        if (-not $name -or $name -like '*.exe') { continue }
        $named = @()
        foreach ($el in $c.CommandElements) {
            if ($el -is [System.Management.Automation.Language.CommandParameterAst]) { $named += $el.ParameterName }
        }
        $calls += [pscustomobject]@{ Command = $name; Parameters = $named }
    }
}

if ($failures -gt 0) { "`n$failures syntax problem(s); stopping here."; exit $failures }

# The functions are lifted out and defined on their own, so the Windows-only
# code around them does not run.
$library = ($definitions | ForEach-Object { $_.Extent.Text }) -join "`n`n"
. ([scriptblock]::Create($library))

# --------------------------------------------------------- 2. the pure logic ---

''
'-- the JSON encoder --'

# It exists because PowerShell 5.1 would otherwise turn a one-element array
# into a bare object, making a machine with one disk look like one with none.
Check 'one-element array stays an array' (ConvertTo-MonitorJson @(@{ a = 1 })) '[{"a":1}]'
Check 'empty array'                      (ConvertTo-MonitorJson @())           '[]'
Check 'empty object'                     (ConvertTo-MonitorJson ([ordered]@{})) '{}'
Check 'null'                             (ConvertTo-MonitorJson $null)         'null'
Check 'booleans'                         (ConvertTo-MonitorJson @($true, $false)) '[true,false]'
Check 'nested'                           (ConvertTo-MonitorJson ([ordered]@{ a = [ordered]@{ b = @(1) } })) '{"a":{"b":[1]}}'
Check 'double quote escaped'             (ConvertTo-MonitorJson 'say "hi"')     '"say \"hi\""'
Check 'backslash escaped'                (ConvertTo-MonitorJson 'C:\Program Files') '"C:\\Program Files"'
Check 'newline and tab escaped'          (ConvertTo-MonitorJson "a`nb`tc")      '"a\nb\tc"'
Check 'control character escaped'        (ConvertTo-MonitorJson ([string][char]7)) '"\u0007"'
Check 'unicode passes through'           (ConvertTo-MonitorJson 'Ørestad')      '"Ørestad"'

# And because number formatting must not follow the machine's locale: a Danish
# server writing 7,75 would post a document nothing can read.
$culture = [System.Threading.Thread]::CurrentThread.CurrentCulture
try {
    [System.Threading.Thread]::CurrentThread.CurrentCulture = [System.Globalization.CultureInfo]::new('da-DK')
    Check 'decimal under a comma locale'  (ConvertTo-MonitorJson ([double]7.75)) '7.75'
    Check 'decimal property under it too' (ConvertTo-MonitorJson ([ordered]@{ cpu = [double]10.33 })) '{"cpu":10.33}'
    Check 'long integer under it'         (ConvertTo-MonitorJson ([long]8326926336)) '8326926336'
} finally {
    [System.Threading.Thread]::CurrentThread.CurrentCulture = $culture
}

$report = [ordered]@{
    system    = [ordered]@{ hostname = 'WIN-«test»'; cpu_cores = 4; memory_bytes = [long]8326926336; uptime_seconds = $null }
    metrics   = [ordered]@{ cpu_percent = [double]12.5; load1 = $null }
    disks     = @([ordered]@{ mount = 'C:'; total_bytes = [long]512000000000; used_bytes = [long]256000000000 })
    collected = @('disks')
    results   = @()
}
$round = ConvertTo-MonitorJson $report | ConvertFrom-Json
Check 'a whole report round-trips' (($round.disks.Count -eq 1) -and ($null -eq $round.metrics.load1)) $true

''
'-- reading the server''s answers --'
$body = '{"ok":true,"status":"online","interval":300,"commands":[{"id":"09306bc4-a5d0-4cbc-958e-6355728d5bf0","command":"report_now"},{"id":"3a40e8a6-c537-49bc-8da7-9e20374d6808","command":"install_updates"}]}'
$queued = @(Get-QueuedCommands -Body $body)
Check 'two queued commands read'      $queued.Count 2
Check '  the first one'               $queued[0].command 'report_now'
Check '  the second uuid'             $queued[1].id '3a40e8a6-c537-49bc-8da7-9e20374d6808'
Check 'an empty queue reads as none'  (@(Get-QueuedCommands -Body '{"ok":true,"commands":[]}')).Count 0
Check 'prose invents no command'      (@(Get-QueuedCommands -Body '{"error":"command not found"}')).Count 0
Check 'interval is found'             ('{"interval":300}' -match '"interval":\s*(\d+)') $true
Check 'disabled is found'             ('{"status":"disabled"}' -match '"status":"disabled"') $true
Check 'a token is found'              ('{"token":"mdt_deadbeef00"}' -match '"token":"(mdt_[0-9a-f]+)"') $true

''
'-- the config file --'
$conf = Join-Path ([System.IO.Path]::GetTempPath()) 'monitor-agent-check.conf'
Set-Content -LiteralPath $conf -Encoding UTF8 -Value @'
MONITOR_URL="https://monitor.example.com"
MONITOR_TOKEN="mdt_aabbccddeeff00112233445566778899aabbccddeeff0011"
MONITOR_DEVICE_ID="0114b123-8bc8-4aff-b8b8-28b98e7e19c2"
MONITOR_INTERVAL="120"
MONITOR_COLLECT="disks,updates"
MONITOR_ALLOW="updates"
MONITOR_INSECURE="0"

# a comment, which is not a setting
'@
$cfg = Read-Config -Path $conf
Check 'url read'          $cfg.MONITOR_URL 'https://monitor.example.com'
Check 'token read'        $cfg.MONITOR_TOKEN 'mdt_aabbccddeeff00112233445566778899aabbccddeeff0011'
Check 'interval read'     $cfg.MONITOR_INTERVAL '120'
Check 'defaults when the file is missing' (Read-Config -Path "$conf.nope").MONITOR_INTERVAL '300'

# The installer recovers a token from a config it is about to overwrite. That
# is what makes running it twice a repair rather than a duplicate machine.
$token = ''
foreach ($line in (Get-Content -LiteralPath $conf -Encoding UTF8)) {
    if ($line -match '^\s*MONITOR_TOKEN\s*=\s*"?(mdt_[0-9a-f]+)"?\s*$') { $token = $Matches[1] }
}
Check 'installer recovers the token' $token 'mdt_aabbccddeeff00112233445566778899aabbccddeeff0011'
Check 'an empty token is not recovered' ('MONITOR_TOKEN=""' -match '^\s*MONITOR_TOKEN\s*=\s*"?(mdt_[0-9a-f]+)"?\s*$') $false
Remove-Item -LiteralPath $conf -Force -ErrorAction SilentlyContinue

''
'-- the guard around native commands --'
# Under $ErrorActionPreference = 'Stop', Windows PowerShell turns a native
# command's redirected stderr into a terminating error. Invoke-Native is what
# stops "that scheduled task did not exist" from aborting an install.
$shell = if ($IsWindows) { 'cmd.exe' } else { '/bin/sh' }
$args  = if ($IsWindows) { @('/c', 'echo oops 1>&2 & exit 3') } else { @('-c', 'echo oops >&2; exit 3') }
$result = Invoke-Native $shell $args
Check 'survives stderr and returns the code' $result.ExitCode 3
Check 'restores the preference afterwards'   $ErrorActionPreference 'Stop'

''
'-- logging and the outbox --'
#
# The agent's logging is a handful of functions over two files, so it can be
# driven directly. Send-Report is replaced: what is being tested is what the
# agent does with an answer, not Invoke-WebRequest.

$state = Join-Path ([System.IO.Path]::GetTempPath()) 'monitor-agent-state'
$Outbox = Join-Path $state 'outbox'
$ShippedAt = Join-Path $state 'shipped-at'
$StatePath = $state
$LogPath = Join-Path $state 'agent.log'
$LogMaxBytes = 1MB
$ShipMax = 400
$OutboxMax = 2000
$ShipLines = 25
$ShipSeconds = 3
$LevelFloor = 1
$UpdateRetrySeconds = 3600
$Config = @{ MONITOR_URL = 'https://monitor.example.com'; MONITOR_TOKEN = 'mdt_aabbcc'; MONITOR_LEVEL = 'info'; MONITOR_SELF_UPDATE = '1' }

$script:posted = 200
function Send-Report { param($Endpoint, $Body, $Token) return @{ code = $script:posted; body = '{"ok":true}' } }

function Reset-LogState {
    if (Test-Path -LiteralPath $state) { Remove-Item -LiteralPath $state -Recurse -Force }
    New-Item -ItemType Directory -Path $state -Force | Out-Null
    $script:LevelFloor = 1
    $script:posted = 200
}

function Get-OutboxFields {
    param([int]$Field = 1)
    return @(Get-Content -LiteralPath $Outbox -Encoding UTF8 | ForEach-Object { ($_ -split "`t")[$Field] })
}

Check 'levels rank in the right order' (((Get-LevelRank 'debug') -lt (Get-LevelRank 'info')) -and ((Get-LevelRank 'warn') -lt (Get-LevelRank 'error'))) $true
Check 'an unknown level reads as info'  (Get-LevelRank 'chatty') 1

Reset-LogState
Write-AgentLog -Level 'debug' -Message 'not at info'
Write-AgentLog -Level 'info' -Message 'plain'
Write-AgentLog -Level 'warn' -Message "two`nlines and a`ttab"
Check 'the level floor holds'      ((Get-OutboxFields 1) -join ',') 'info,warn'
Check 'and a line is a line'       ((Get-OutboxFields 3) -join '|') 'plain|two lines and a tab'
Check 'and it is on this machine too' ((Get-Content -LiteralPath $LogPath) -join '|' -like '*info plain*') $true

Reset-LogState
Write-AgentLog -Level 'info' -Message 'output of a command' -Command '3a40e8a6-c537-49bc-8da7-9e20374d6808'
$shipped = (ConvertTo-OutboxJson (Get-Content -LiteralPath $Outbox -Encoding UTF8) | ConvertFrom-Json)
Check 'a line belonging to a command says so' $shipped.logs[0].command '3a40e8a6-c537-49bc-8da7-9e20374d6808'
Check '  and carries its message'             $shipped.logs[0].message 'output of a command'

Reset-LogState
Write-AgentLog -Level 'info' -Message 'quotes " and \ backslashes'
Write-AgentLog -Level 'error' -Message ("a`tb" + [char]1 + 'c')
Check 'awkward output still comes out as JSON' ((ConvertTo-OutboxJson (Get-Content -LiteralPath $Outbox -Encoding UTF8) | ConvertFrom-Json).logs.Count) 2

Reset-LogState
Write-AgentLog -Level 'info' -Message 'one'
Write-AgentLog -Level 'info' -Message 'two'
Send-OutboxLogs -Now | Out-Null
Check 'the outbox empties when the server takes it' (@(Get-Content -LiteralPath $Outbox -Encoding UTF8 -ErrorAction SilentlyContinue)).Count 0

Reset-LogState
$script:posted = 0
Write-AgentLog -Level 'info' -Message 'one'
Write-AgentLog -Level 'info' -Message 'two'
Send-OutboxLogs -Now | Out-Null
Check 'and keeps them, in order, when it does not' ((Get-OutboxFields 3) -join '|') 'one|two'

# The first line after a quiet spell goes at once -- that is what makes a
# machine's first word arrive quickly. It is the second that has to wait, or
# streaming Windows Update would be a request per update.
Reset-LogState
Write-AgentLog -Level 'info' -Message 'one'
Send-OutboxLogs | Out-Null
Write-AgentLog -Level 'info' -Message 'two'
Send-OutboxLogs | Out-Null
Check 'the first line goes at once, the next waits' (@(Get-Content -LiteralPath $Outbox -Encoding UTF8 -ErrorAction SilentlyContinue)).Count 1

Reset-LogState
1..30 | ForEach-Object { Write-AgentLog -Level 'info' -Message "line $_" }
Send-OutboxLogs | Out-Null
Check 'but a command talking steadily is sent without waiting' (@(Get-Content -LiteralPath $Outbox -Encoding UTF8 -ErrorAction SilentlyContinue)).Count 0

Reset-LogState
$script:posted = 0
1..2100 | ForEach-Object { Write-AgentLog -Level 'info' -Message "line $_" }
Send-OutboxLogs -Now | Out-Null
$kept = @(Get-Content -LiteralPath $Outbox -Encoding UTF8)
Check 'a week offline is capped'        $kept.Count 2000
Check '  and it is the newest that stay' (($kept[0] -split "`t")[3]) 'line 101'

''
'-- what the server offers --'
Check 'the version it wants this machine on is read' `
    (Get-OfferedVersion -Body '{"ok":true,"poll":15,"level":"info","agent":{"version":"1.4.2","url":"/agent/windows/agent.ps1","sha256":"abc"},"commands":[]}') '1.4.2'
Check 'an answer without one offers nothing' `
    (Get-OfferedVersion -Body '{"ok":true,"commands":[{"id":"3a40e8a6-c537-49bc-8da7-9e20374d6808","command":"report_now"}]}') ''

Reset-LogState
$Config.MONITOR_SELF_UPDATE = '0'
$refused = Invoke-SelfUpdate -Reason 'Asked.' -Force
Check 'a machine that refuses updates refuses them' $refused.exit_code 77
Check '  and says why'                              $refused.error 'This machine was installed with -NoSelfUpdate.'
$Config.MONITOR_SELF_UPDATE = '1'

Remove-Item -LiteralPath $state -Recurse -Force -ErrorAction SilentlyContinue

# ------------------------------------------------- 3. the scheduling retry ---
#
# The installer's scheduling block is run against stubs that behave like
# Windows Task Scheduler, including the rejection seen in the field: a
# repetition duration of P99999999DT23H59M59S, which the module produces from
# TimeSpan.MaxValue and the task XML schema refuses. The trigger object builds
# happily either way -- validation happens at registration -- which is why the
# retry has to wrap the whole registration and not just the trigger.

''
'-- the scheduling retry --'

$installerText = Get-Content -LiteralPath $scripts[1] -Raw
$from = $installerText.IndexOf('$cadence = if ($Poll -gt 0)')
$to = $installerText.IndexOf('if (-not $reported) {')
if ($from -lt 0 -or $to -le $from) {
    $failures++
    'FAIL  could not find the scheduling block in install.ps1'
} else {
    $block = $installerText.Substring($from, $to - $from)

    $stubs = @'
function Remove-Schedule { $script:registered = $null }
function Invoke-Native { param($Command, $Arguments) [pscustomobject]@{ ExitCode = 0; Output = '' } }
function New-ScheduledTaskAction { param($Execute, $Argument) [pscustomobject]@{} }
function New-ScheduledTaskPrincipal { param($UserId, $LogonType, $RunLevel) [pscustomobject]@{} }
function New-ScheduledTaskSettingsSet {
    param([switch]$AllowStartIfOnBatteries, [switch]$DontStopIfGoingOnBatteries, [switch]$StartWhenAvailable,
          $ExecutionTimeLimit, $MultipleInstances)
    [pscustomobject]@{}
}
function New-ScheduledTaskTrigger {
    param([switch]$AtStartup, [switch]$Once, $At, $RepetitionInterval, $RepetitionDuration)
    [pscustomobject]@{
        Delay = ''
        RandomDelay = ''
        Repetition = [pscustomobject]@{
            Interval = if ($RepetitionInterval) { [System.Xml.XmlConvert]::ToString($RepetitionInterval) } else { '' }
            Duration = if ($RepetitionDuration) { [System.Xml.XmlConvert]::ToString($RepetitionDuration) } else { '' }
        }
    }
}
function Get-ScheduledTask { param($TaskName, $ErrorAction) $script:registered }
'@

    $flavours = @{
        # Rejects the enormous duration, accepts anything else.
        'current Windows' = @'
function Register-ScheduledTask {
    param($TaskName, $Action, $Trigger, $Principal, $Settings, $Description)
    $repeat = $Trigger | Where-Object { $_.Repetition.Interval }
    $duration = $repeat.Repetition.Duration
    $script:attempts += "duration=[$duration]"
    if ($duration -like 'P9999999*') { throw "out of range: $duration" }
    $script:registered = [pscustomobject]@{ Triggers = $Trigger }
}
'@
        # Older build: an omitted duration silently loses the repetition.
        'older Windows' = @'
function Register-ScheduledTask {
    param($TaskName, $Action, $Trigger, $Principal, $Settings, $Description)
    $repeat = $Trigger | Where-Object { $_.Repetition.Interval }
    $duration = $repeat.Repetition.Duration
    $script:attempts += "duration=[$duration]"
    if ($duration -like 'P9999999*') { throw 'out of range' }
    if ([string]::IsNullOrEmpty($duration)) { foreach ($t in $Trigger) { $t.Repetition.Interval = '' } }
    $script:registered = [pscustomobject]@{ Triggers = $Trigger }
}
'@
        'refuses everything' = @'
function Register-ScheduledTask {
    param($TaskName, $Action, $Trigger, $Principal, $Settings, $Description)
    $script:attempts += 'refused'
    throw 'Task Scheduler is not having any of it'
}
'@
    }

    function Invoke-Scheduling {
        param([string]$Flavour)
        $shell = [powershell]::Create()
        [void]$shell.AddScript(@"
`$ErrorActionPreference = 'Stop'
`$TaskName = 'Monitor agent'
`$Agent = 'C:\Program Files\MonitorAgentgent.ps1'
`$Url = 'https://monitor.example.com'
`$Interval = 300
`$Poll = 15
`$script:attempts = @()
$stubs
$Flavour
$block
[pscustomobject]@{ Scheduled = `$scheduled; Kind = `$scheduleKind; Attempts = `$script:attempts }
"@)
        $out = $shell.Invoke()
        $shell.Dispose()
        if ($out.Count -eq 0) { return $null }
        return $out[-1]
    }

    # With the live channel on the task keeps an agent alive every minute; the
    # report interval is the server's business, not the scheduler's.
    $r = Invoke-Scheduling $flavours['current Windows']
    Check 'current Windows: schedules'          $r.Scheduled $true
    Check '  every minute, not every interval'  $r.Kind 'Task Scheduler, every 60 seconds'
    Check '  on the first candidate, no end date' $r.Attempts[0] 'duration=[]'

    $r = Invoke-Scheduling $flavours['older Windows']
    Check 'older Windows: still schedules'      $r.Scheduled $true
    Check '  through Task Scheduler'            $r.Kind 'Task Scheduler, every 60 seconds'
    Check '  after falling back to ten years'   $r.Attempts[1] 'duration=[P3650D]'

    $r = Invoke-Scheduling $flavours['refuses everything']
    Check 'hopeless machine: tries all three'   $r.Attempts.Count 3
    Check '  then schtasks takes over'          $r.Kind 'schtasks, every 1 minute(s)'

    # And with the live channel off, the task goes back to carrying the report
    # interval itself.
    $r = Invoke-Scheduling ($flavours['current Windows'] + "`n`$Poll = 0")
    Check 'live channel off: task carries the interval' $r.Kind 'Task Scheduler, every 300 seconds'
}

# ------------------------------------------------- 4. what a run settles on ---
#
# The installer is also how the agent updates itself, so a run with no arguments
# has to come out the other side the way it went in. Getting this wrong is quiet
# in a way the scheduling failures were not -- an installer that resets a
# machine's permissions still prints "Done" -- which is why it is tested here
# rather than trusted.

''
'-- the settings a run ends up with --'

$from = $installerText.IndexOf('function Read-ExistingConfig {')
$to = $installerText.IndexOf('$reported = $false')
if ($from -lt 0 -or $to -le $from) {
    $failures++
    'FAIL  could not find the settings block in install.ps1'
} else {
    $settingsBlock = $installerText.Substring($from, $to - $from)
    $settingsConf = Join-Path ([System.IO.Path]::GetTempPath()) 'monitor-agent-settings.conf'

    # The block reads $PSBoundParameters to tell "not asked for" from "asked for
    # the same thing the default happens to be". Outside a cmdlet that is just a
    # hashtable, so the arguments of a hypothetical run are written as one.
    function Get-Settings {
        param([hashtable]$Asked = @{}, [string[]]$Switches = @(), [switch]$NoConfig)

        $shell = [powershell]::Create()
        [void]$shell.AddScript(@"
`$ErrorActionPreference = 'Stop'
`$Conf = '$(if ($NoConfig) { "$settingsConf.nope" } else { $settingsConf })'
`$Url = 'https://monitor.example.com'
`$Interval = 300
`$Poll = 15
`$Collect = 'disks,updates,packages,services,ports'
`$Level = 'info'
foreach (`$name in @('Force','NoLive','AllowUpdates','AllowReboot','NoAllowUpdates','NoAllowReboot','SelfUpdate','NoSelfUpdate','Insecure','NoInsecure')) {
    Set-Variable -Name `$name -Value ([switch]`$false)
}
foreach (`$name in @($(($Switches | ForEach-Object { "'$_'" }) -join ','))) {
    Set-Variable -Name `$name -Value ([switch]`$true)
}
`$PSBoundParameters = $(
    if ($Asked.Count -eq 0) { '@{}' }
    else { '@{ ' + (($Asked.Keys | ForEach-Object { "$_ = '$($Asked[$_])'" }) -join '; ') + ' }' }
)
foreach (`$name in `$PSBoundParameters.Keys) { Set-Variable -Name `$name -Value `$PSBoundParameters[`$name] }
foreach (`$name in @($(($Switches | ForEach-Object { "'$_'" }) -join ','))) { `$PSBoundParameters[`$name] = `$true }
if (`$NoLive) { `$Poll = 0 }
$settingsBlock
'url={0} interval={1} poll={2} collect={3} level={4} allow={5} self={6} insecure={7} token={8}' -f `$Url, `$Interval, `$Poll, `$Collect, `$Level, `$(if (`$mayUpdate -or `$mayReboot) { ((@(if (`$mayUpdate) { 'updates' }) + @(if (`$mayReboot) { 'reboot' })) -join ',') } else { '(none)' }), `$(if (`$selfUpdating) { 1 } else { 0 }), `$(if (`$insecurely) { 1 } else { 0 }), `$(if (`$existingToken) { `$existingToken } else { '(none)' })
"@)
        $out = $shell.Invoke()
        $shell.Dispose()
        if ($out.Count -eq 0) { return '' }
        return [string]$out[-1]
    }

    Remove-Item -LiteralPath $settingsConf -Force -ErrorAction SilentlyContinue
    Check 'a new machine takes the defaults' (Get-Settings -NoConfig) `
        'url=https://monitor.example.com interval=300 poll=15 collect=disks,updates,packages,services,ports level=info allow=(none) self=1 insecure=0 token=(none)'
    Check '  and the arguments it was given' `
        (Get-Settings -NoConfig -Asked @{ Interval = 600; Level = 'debug' } -Switches @('NoLive','AllowUpdates','AllowReboot','NoSelfUpdate','Insecure')) `
        'url=https://monitor.example.com interval=600 poll=0 collect=disks,updates,packages,services,ports level=debug allow=updates,reboot self=0 insecure=1 token=(none)'

    Set-Content -LiteralPath $settingsConf -Encoding UTF8 -Value @'
MONITOR_URL="https://monitor.example.com"
MONITOR_TOKEN="mdt_aabbccddeeff00112233445566778899"
MONITOR_DEVICE_ID="0114b123-8bc8-4aff-b8b8-28b98e7e19c2"
MONITOR_INTERVAL="900"
MONITOR_POLL="0"
MONITOR_COLLECT="disks,updates"
MONITOR_LEVEL="warn"
MONITOR_ALLOW="updates"
MONITOR_SELF_UPDATE="1"
MONITOR_INSECURE="1"
'@

    Check 'an update with no arguments keeps everything' (Get-Settings) `
        'url=https://monitor.example.com interval=900 poll=0 collect=disks,updates level=warn allow=updates self=1 insecure=1 token=mdt_aabbccddeeff00112233445566778899'
    Check '  one argument changes that one thing only' (Get-Settings -Asked @{ Interval = 120 }) `
        'url=https://monitor.example.com interval=120 poll=0 collect=disks,updates level=warn allow=updates self=1 insecure=1 token=mdt_aabbccddeeff00112233445566778899'
    Check '  a permission is added, not replaced' (Get-Settings -Switches @('AllowReboot')) `
        'url=https://monitor.example.com interval=900 poll=0 collect=disks,updates level=warn allow=updates,reboot self=1 insecure=1 token=mdt_aabbccddeeff00112233445566778899'
    Check '  and taken away only when asked' (Get-Settings -Switches @('NoAllowUpdates')) `
        'url=https://monitor.example.com interval=900 poll=0 collect=disks,updates level=warn allow=(none) self=1 insecure=1 token=mdt_aabbccddeeff00112233445566778899'
    Check '  -Force keeps the settings, drops the token' (Get-Settings -Switches @('Force')) `
        'url=https://monitor.example.com interval=900 poll=0 collect=disks,updates level=warn allow=updates self=1 insecure=1 token=(none)'

    # A config written by an installer that had never heard of half of this.
    Set-Content -LiteralPath $settingsConf -Encoding UTF8 -Value @'
MONITOR_URL="https://monitor.example.com"
MONITOR_TOKEN="mdt_aabbccddeeff00112233445566778899"
MONITOR_INTERVAL="ten minutes"
MONITOR_POLL="2"
MONITOR_LEVEL="chatty"
MONITOR_SELF_UPDATE="yes"
MONITOR_COLLECT=""
'@
    Check 'nonsense in the config falls back to the defaults' (Get-Settings) `
        'url=https://monitor.example.com interval=300 poll=15 collect= level=info allow=(none) self=1 insecure=0 token=mdt_aabbccddeeff00112233445566778899'

    Remove-Item -LiteralPath $settingsConf -Force -ErrorAction SilentlyContinue
}

# Every setting the installer writes has to be one the agent writes back when it
# rewrites the config at enrolment, or enrolling would quietly drop it.
$agentText = Get-Content -LiteralPath $scripts[0] -Raw
$installerKeys = [regex]::Matches($installerText, '(?m)^(MONITOR_[A-Z_]+)="') | ForEach-Object { $_.Groups[1].Value }
$agentKeys = [regex]::Matches($agentText, '(?m)^(MONITOR_[A-Z_]+)="\$\(\$Config\.') | ForEach-Object { $_.Groups[1].Value }
$dropped = @($installerKeys | Where-Object { $agentKeys -notcontains $_ } | Sort-Object -Unique)
Check 'every setting survives enrolment' ($dropped -join ',') ''

''
'-- each script recognises the other --'
#
# Both directions of a download are sanity-checked against a couple of strings
# that must appear in the file. Renaming one of them is a two-line change that
# would silently brick every install and every self-update in the fleet.
$agentText = Get-Content -LiteralPath $scripts[0] -Raw
Check 'the installer would accept the agent it downloads' `
    (($agentText -match 'Monitor agent for Windows') -and ($agentText -match 'ConvertTo-MonitorJson')) $true
Check 'and the agent would accept the installer it downloads' `
    (($installerText -match 'Installs the Monitor agent') -and ($installerText -match 'MONITOR_TOKEN')) $true

# ------------------------------------------------------- 5. cmdlet parameters ---

''
'-- cmdlet parameters --'
$ourFunctions = $definitions | ForEach-Object { $_.Name }
$unresolved = @()

foreach ($group in ($calls | Group-Object Command | Sort-Object Name)) {
    $name = $group.Name
    if ($ourFunctions -contains $name) { continue }
    $passed = @($group.Group.Parameters | Sort-Object -Unique)
    $shown = ($passed | ForEach-Object { "-$_" }) -join ' '

    # COM is Windows-only, so this parameter does not exist on Linux even
    # though the call is correct for the platform it runs on.
    $windowsOnly = ($name -eq 'New-Object' -and $passed -contains 'ComObject')
    $cmd = if ($windowsOnly) { $null } else { Get-Command $name -ErrorAction SilentlyContinue }

    if (-not $cmd) {
        $unresolved += '{0,-30} {1}' -f $name, $shown
        continue
    }

    $known = @($cmd.Parameters.Keys)
    foreach ($p in $cmd.Parameters.Values) { $known += $p.Aliases }
    $wrong = @($passed | Where-Object { $one = $_; -not ($known | Where-Object { $_ -like "$one*" }) })

    if ($wrong.Count -gt 0) {
        $failures++
        'FAIL  {0}: no parameter matches {1}' -f $name, (($wrong | ForEach-Object { "-$_" }) -join ', ')
    } else {
        'PASS  {0,-30} {1}' -f $name, $shown
    }
}

if ($unresolved.Count -gt 0) {
    ''
    'Windows-only, so checked against the published reference rather than run:'
    $unresolved | Sort-Object -Unique | ForEach-Object { "  $_" }
}

''
if ($failures -eq 0) { 'Everything checked here passes.' } else { "$failures FAILED" }
exit $failures
