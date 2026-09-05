<#
    Installs the Monitor agent on this machine.

        .\install.ps1 -Key mek_...

    Puts the agent in C:\Program Files\MonitorAgent, writes a config readable
    only by SYSTEM and Administrators, exchanges the enrolment key for a token
    belonging to this machine, and registers a scheduled task that reports
    every few minutes as SYSTEM.

    By default the agent only reports. Nothing on this machine can be changed
    from Monitor unless you pass -AllowUpdates or -AllowReboot, and that
    decision is recorded here, on the machine, not there.

    Safe to run twice. If this machine already holds a token that still works,
    the installer keeps it and repairs whatever else is missing rather than
    enrolling the same computer a second time. Pass -Force to insist on a new
    identity.

    A second run also keeps the settings of the first. This is the same script
    the agent runs to update itself, so a run with no arguments has to come out
    the other side the way it went in: anything not named on the command line is
    read back out of the config and written again unchanged. Otherwise an update
    would quietly hand back a permission this machine was deliberately installed
    without. Say -NoAllowUpdates to actually take one away.
#>

[CmdletBinding()]
param(
    # The enrolment key from Monitor, under Servers or Clients.
    [string]$Key = '',

    # The Monitor address. Defaults to the site this script came from.
    [string]$Url = '__MONITOR_URL__',

    # How often to send a full report, in seconds. Minimum 60.
    [int]$Interval = 300,

    # How often to check whether a command is waiting, in seconds. This is the
    # live channel; 0 switches it off and commands then wait for a report.
    [int]$Poll = 15,

    # Do not check between reports.
    [switch]$NoLive,

    # Which optional lists to gather.
    [string]$Collect = 'disks,updates,packages,services,ports',

    # How much the agent has to say for itself: error, warn, info or debug.
    [ValidateSet('error', 'warn', 'info', 'debug')]
    [string]$Level = 'info',

    # Let Monitor install Windows updates when asked.
    [switch]$AllowUpdates,

    # Let Monitor restart this machine when asked.
    [switch]$AllowReboot,

    # Take one of those permissions back from a machine that had it.
    [switch]$NoAllowUpdates,
    [switch]$NoAllowReboot,

    # Whether the agent may replace itself with the version Monitor holds. This
    # is the machine's own consent to running code from that server, so it is
    # decided here and cannot be granted from there.
    [switch]$SelfUpdate,
    [switch]$NoSelfUpdate,

    # Skip TLS verification. Self-signed certificates only.
    [switch]$Insecure,
    [switch]$NoInsecure,

    # Enrol again even if this machine already has a working token. Gives it a
    # new identity in Monitor and leaves the old one behind as a stale entry.
    [switch]$Force,

    # Remove the agent and stop reporting.
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'

$InstallDir = Join-Path $env:ProgramFiles 'MonitorAgent'
$ConfDir = Join-Path $env:ProgramData 'MonitorAgent'
$Conf = Join-Path $ConfDir 'agent.conf'
$Agent = Join-Path $InstallDir 'agent.ps1'
$TaskName = 'Monitor agent'

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

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-Administrator)) {
    throw 'Run this from an elevated PowerShell. The config must be readable by administrators only, and the scheduled task runs as SYSTEM.'
}

function Remove-Schedule {
    # Get-ScheduledTask is a cmdlet, not a command line, so on a Windows old
    # enough not to have the ScheduledTasks module this fails at name
    # resolution rather than as a cmdlet error -- which -ErrorAction cannot
    # catch. Hence the try, and the schtasks pass afterwards for a task this
    # installer may have created that way.
    try {
        $existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
        if ($existing) {
            Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        }
    } catch { }

    Invoke-Native 'schtasks.exe' @('/Delete', '/TN', $TaskName, '/F') | Out-Null
}

if ($Uninstall) {
    Remove-Schedule
    if (Test-Path -LiteralPath $InstallDir) { Remove-Item -LiteralPath $InstallDir -Recurse -Force }
    Write-Output "Agent removed. The config is left at $Conf in case you want the token back;"
    Write-Output 'delete it yourself, and revoke the machine in Monitor so the token stops working.'
    exit 0
}

# ------------------------------------------------------ what is already here ---

function Read-ExistingConfig {
    param([string]$Path)

    $existing = @{}
    if (-not (Test-Path -LiteralPath $Path)) { return $existing }

    foreach ($line in (Get-Content -LiteralPath $Path -Encoding UTF8 -ErrorAction SilentlyContinue)) {
        if ($line -match '^\s*(MONITOR_[A-Z_]+)\s*=\s*"?([^"]*)"?\s*$') {
            $existing[$Matches[1]] = $Matches[2]
        }
    }

    return $existing
}

# A config may have been hand-edited, or written by an installer old enough not
# to have known about a setting. A value there that makes no sense is dropped in
# favour of the default rather than being fatal -- this script is how a machine
# updates itself, and it must not be stopped by a setting it is about to
# rewrite. A nonsensical value on the command line still stops it: that is
# somebody making a mistake, and they should hear about it.
function Get-SaneSeconds {
    param([string]$Value, [int]$Floor)

    if ($Value -notmatch '^\d+$') { return $null }
    $number = [int]$Value
    if ($number -lt $Floor) { return $null }

    return $number
}

$existingConfig = Read-ExistingConfig -Path $Conf

# The token is what makes a second run a repair rather than a duplicate.
$existingToken = ''
$existingDevice = ''
if (-not $Force) {
    if ($existingConfig['MONITOR_TOKEN'] -match '^(mdt_[0-9a-f]+)$') { $existingToken = $Matches[1] }
    if ($existingConfig['MONITOR_DEVICE_ID'] -match '^([0-9a-f-]{36})$') { $existingDevice = $Matches[1] }
}

# The settings are read whether or not -Force was given: a new identity is still
# the same machine, with the same opinion about what may be done to it. Asked
# for on the command line, then already here, then the parameter default.
$installedBefore = [bool]$existingConfig['MONITOR_URL']

if (-not $PSBoundParameters.ContainsKey('Url') -and $installedBefore) {
    $Url = $existingConfig['MONITOR_URL']
}

if (-not $PSBoundParameters.ContainsKey('Interval')) {
    $keep = Get-SaneSeconds $existingConfig['MONITOR_INTERVAL'] 60
    if ($null -ne $keep) { $Interval = $keep }
}

if (-not $PSBoundParameters.ContainsKey('Poll') -and -not $NoLive) {
    # Zero is a legitimate poll -- it means the live channel is off -- so it
    # cannot go through the same floor as everything else.
    if ($existingConfig['MONITOR_POLL'] -eq '0') {
        $Poll = 0
    } else {
        $keep = Get-SaneSeconds $existingConfig['MONITOR_POLL'] 5
        if ($null -ne $keep) { $Poll = $keep }
    }
}

# Collecting nothing is a real answer, so this one cannot lean on emptiness.
if (-not $PSBoundParameters.ContainsKey('Collect') -and $existingConfig.ContainsKey('MONITOR_COLLECT')) {
    $Collect = $existingConfig['MONITOR_COLLECT']
}

if (-not $PSBoundParameters.ContainsKey('Level') -and
    @('error', 'warn', 'info', 'debug') -contains $existingConfig['MONITOR_LEVEL']) {
    $Level = $existingConfig['MONITOR_LEVEL']
}

# Neither permission is ever granted by the absence of an argument. Each one is
# whatever this machine already consented to, unless this run says otherwise.
#
# Named nothing like the switches that decide them: PowerShell variables are
# case-insensitive, so a $allowUpdates here would *be* the -AllowUpdates
# parameter, and reading the config would quietly overwrite what was asked for.
$was = ',' + [string]$existingConfig['MONITOR_ALLOW'] + ','
$mayUpdate = $was.Contains(',updates,')
$mayReboot = $was.Contains(',reboot,')
if ($AllowUpdates) { $mayUpdate = $true }
if ($NoAllowUpdates) { $mayUpdate = $false }
if ($AllowReboot) { $mayReboot = $true }
if ($NoAllowReboot) { $mayReboot = $false }

$selfUpdating = $existingConfig['MONITOR_SELF_UPDATE'] -ne '0'
if ($SelfUpdate) { $selfUpdating = $true }
if ($NoSelfUpdate) { $selfUpdating = $false }

$insecurely = $existingConfig['MONITOR_INSECURE'] -eq '1'
if ($Insecure) { $insecurely = $true }
if ($NoInsecure) { $insecurely = $false }

$reported = $false

if (-not $Key -and -not $existingToken) {
    throw 'No enrolment key. Get one from Monitor under Servers or Clients, then pass -Key mek_...'
}
if (-not $Url) {
    throw 'No Monitor address. Pass -Url https://monitor.example.com'
}
if ($Interval -lt 60) {
    throw '-Interval must be at least 60 seconds.'
}
if ($NoLive) { $Poll = 0 }
if ($Poll -gt 0 -and $Poll -lt 5) {
    throw '-Poll must be at least 5 seconds. Anything faster is more load than it is worth.'
}

if ($Url -notmatch '^https?://') {
    throw '-Url must start with http:// or https://'
}
if ($Url -match '^http://') {
    Write-Warning "$Url is not encrypted. This machine's token will cross the network in the clear."
}

Write-Output "Installing the Monitor agent from $($Url.TrimEnd('/'))"

try {
    [System.Net.ServicePointManager]::SecurityProtocol =
        [System.Net.SecurityProtocolType]::Tls12 -bor [System.Net.SecurityProtocolType]::Tls11
} catch { }

if ($insecurely) {
    [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
}

New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null
New-Item -ItemType Directory -Path $ConfDir -Force | Out-Null

$source = $Url.TrimEnd('/') + '/agent/windows/agent.ps1'
$staging = "$Agent.new"

try {
    Invoke-WebRequest -Uri $source -OutFile $staging -UseBasicParsing -TimeoutSec 60
} catch {
    throw "Could not download the agent from $source. $($_.Exception.Message)"
}

# A truncated download would install something that silently does half a job.
$downloaded = Get-Content -LiteralPath $staging -Raw
if ($downloaded -notmatch 'Monitor agent for Windows' -or $downloaded -notmatch 'ConvertTo-MonitorJson') {
    Remove-Item -LiteralPath $staging -Force
    throw 'The downloaded agent does not look right. Nothing was installed.'
}

Move-Item -LiteralPath $staging -Destination $Agent -Force

$allow = @()
if ($mayUpdate) { $allow += 'updates' }
if ($mayReboot) { $allow += 'reboot' }

# The config is rewritten whole, from the values settled on above: whatever was
# asked for on the command line, and whatever this machine already had for
# everything that was not. The enrolment key is passed as an argument and never
# written down -- it is spent once and has no business surviving here.
#
# The same file the agent writes at enrolment, comments and all, so a config
# looks the same however it was last written.
$body = @"
# Monitor agent configuration. Written by the installer.
#
# MONITOR_TOKEN is this machine's identity. Anybody holding it can post reports
# as this machine, so this file is readable by SYSTEM and Administrators only.
# If it leaks, revoke the machine in Monitor and run the installer again.
MONITOR_URL="$($Url.TrimEnd('/'))"
MONITOR_TOKEN="$existingToken"
MONITOR_DEVICE_ID="$existingDevice"
MONITOR_INTERVAL="$Interval"

# How often to ask whether a command is waiting. This is the live channel: one
# small request, usually answered with "nothing". Set it to 0 and the agent
# only ever speaks when it reports, which is quieter but means a queued command
# waits for the next report.
MONITOR_POLL="$Poll"

# Which of the optional lists to gather. Drop one and it stops being collected
# and stops being shown -- packages is the expensive one.
MONITOR_COLLECT="$Collect"

# How much the agent has to say for itself. 'debug' includes every poll, which
# is a great deal of nothing most of the time; 'info' records what it actually
# does. Monitor can lower or raise this from the interface.
MONITOR_LEVEL="$Level"

# What this machine consents to being asked to do. Empty means Monitor may ask
# it questions but never change it.
MONITOR_ALLOW="$($allow -join ',')"

# Whether the agent may replace itself with the version Monitor holds. This is
# the machine's own consent to running code from that server, so it is decided
# here and cannot be granted from there. Set it to 0 and updates arrive by
# running the installer again, by hand.
MONITOR_SELF_UPDATE="$(if ($selfUpdating) { '1' } else { '0' })"

# Skip TLS verification. Only for a Monitor install using a self-signed
# certificate, and it does mean the token can be read by anything in the path.
MONITOR_INSECURE="$(if ($insecurely) { '1' } else { '0' })"
"@

Set-Content -LiteralPath $Conf -Value $body -Encoding UTF8
Invoke-Native 'icacls.exe' @($Conf, '/inheritance:r', '/grant:r', 'SYSTEM:(F)', 'Administrators:(F)') | Out-Null

# Enrol, unless this machine already has a token that works.
#
# The installer has to be safe to run twice. Anything after enrolment can fail
# -- and the first version of this script did fail here, on a Windows that
# would not take one of the scheduling options -- which leaves a machine
# enrolled but never reporting. Running the installer again then has to repair
# that, not spend another use of the key and create a second row for the same
# computer. So a token that still works is kept, and -Force is how you ask for
# a genuinely new identity.
$enrolled = $false

if ($existingToken -and -not $Force) {
    Write-Output 'Found an existing token. Checking whether it still works...'
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $Agent -Once -Conf $Conf
    $check = $LASTEXITCODE

    if ($check -eq 0) {
        $enrolled = $true
        $reported = $true
        Write-Output '  it does. Keeping this machine as it already is in Monitor.'
    } elseif ($check -eq 2) {
        Write-Output '  it does not. Enrolling afresh.'
    } else {
        # The report did not get through, which is not the same as the token
        # being refused: the machine may be off the network, or the server may
        # not have been able to read what it sent. Enrolling on the strength of
        # that would spend a use of the key and leave a second row in Monitor
        # for this same computer.
        $enrolled = $true
        Write-Output '  it could not say -- the report did not get through.'
        Write-Output '  Keeping the token this machine already has rather than enrolling it twice.'
        Write-Output "  If it never reports, run:  powershell -File `"$Agent`" -Once"
    }
}

if (-not $enrolled -and -not $Key) {
    throw "This machine's saved token no longer works, and no enrolment key was given. Get a key from Monitor and run this again with -Key mek_..."
}

if (-not $enrolled) {
    Write-Output 'Enrolling...'
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $Agent -Enroll -Key $Key -Conf $Conf
    if ($LASTEXITCODE -ne 0) {
        throw 'Enrolment failed. Nothing was scheduled; fix the problem and run this again.'
    }
}

# With the live channel on, the task only has to keep an agent process alive,
# so it runs every minute and the agent does its own timing inside. With it off
# there is nothing to keep alive, and the task goes back to deciding when to
# report.
$cadence = if ($Poll -gt 0) { 60 } else { $Interval }
$jitter = if ($Poll -gt 0) { 'PT5S' } else { 'PT30S' }

$taskArguments = "-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$Agent`" -Loop"

# Registering the task is where Windows versions differ most.
#
# The repetition duration is the awkward part. Task Scheduler validates it when
# the task is registered, not when the trigger object is built, so a bad value
# sails through New-ScheduledTaskTrigger and only fails later -- which is how
# the previous version of this script shipped a broken first attempt. Hence the
# whole registration is retried, once per candidate, rather than just the
# trigger.
#
# The candidates, in the order most likely to work: no duration at all, which
# Windows 10 and Server 2016 onwards read as "repeat indefinitely"; then ten
# years, which is dull and always in range; then TimeSpan.MaxValue, which is
# the documented idiom on older builds but which the module renders as
# P99999999DT23H59M59S -- a value the task XML schema rejects outright.
$durations = @(
    @{ Label = 'no end';   Value = $null },
    @{ Label = '10 years'; Value = (New-TimeSpan -Days 3650) },
    @{ Label = 'no limit'; Value = [TimeSpan]::MaxValue }
)

$scheduled = $false
$scheduleKind = ''
$lastError = ''

foreach ($duration in $durations) {
    if ($scheduled) { break }

    try {
        Remove-Schedule

        $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $taskArguments

        # One trigger at startup and one repeating, so a machine that was off
        # reports as soon as it is back rather than waiting out the interval.
        $startup = New-ScheduledTaskTrigger -AtStartup
        try { $startup.Delay = 'PT1M' } catch { }

        if ($null -eq $duration.Value) {
            $repeating = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
                -RepetitionInterval (New-TimeSpan -Seconds $cadence)
        } else {
            $repeating = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
                -RepetitionInterval (New-TimeSpan -Seconds $cadence) `
                -RepetitionDuration $duration.Value
        }

        # Spread a fleet out so a hundred machines do not all arrive on the
        # minute. This belongs to the trigger, not to the settings.
        try { $repeating.RandomDelay = $jitter } catch { }

        $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

        $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
            -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
            -MultipleInstances IgnoreNew

        Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startup, $repeating) `
            -Principal $principal -Settings $settings `
            -Description "Reports this machine's state to $($Url.TrimEnd('/'))" | Out-Null

        # "The task exists" is not the same as "the task will repeat". Read it
        # back and look for the repetition, because a registration that quietly
        # dropped it would leave a machine reporting exactly once.
        $registered = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
        $repeats = $false
        if ($registered) {
            foreach ($t in $registered.Triggers) {
                if ($t.Repetition -and $t.Repetition.Interval) { $repeats = $true }
            }
        }

        if ($repeats) {
            $scheduled = $true
            $scheduleKind = "Task Scheduler, every $cadence seconds"
        } else {
            $lastError = "the task registered but without a repetition ($($duration.Label))"
        }
    } catch {
        $lastError = "$($duration.Label): $($_.Exception.Message)"
    }
}

if (-not $scheduled) {
    if ($lastError) { Write-Warning "Task Scheduler would not take the schedule -- $lastError" }

    # schtasks has taken the same arguments since XP. It counts in whole
    # minutes, so a shorter interval rounds up to one.
    $minutes = [math]::Max(1, [math]::Min(1439, [int][math]::Round($cadence / 60)))
    $runs = 'powershell.exe -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File \"' + $Agent + '\" -Loop'

    Remove-Schedule
    $fallback = Invoke-Native 'schtasks.exe' @(
        '/Create', '/TN', $TaskName, '/TR', $runs,
        '/SC', 'MINUTE', '/MO', "$minutes",
        '/RU', 'SYSTEM', '/RL', 'HIGHEST', '/F'
    )

    if ($fallback.ExitCode -eq 0) {
        $scheduled = $true
        $scheduleKind = "schtasks, every $minutes minute(s)"
        Write-Warning "Falling back to schtasks. It works, but it has no start-up trigger and will not catch up a run missed while the machine was off."
    }
}

if (-not $scheduled) {
    Write-Output ''
    Write-Warning 'This machine is enrolled in Monitor but has no schedule, so it will not report on its own.'
    Write-Warning 'Its token is saved, so running this installer again is safe and will not enrol it twice.'
    Write-Warning "You can also report by hand with:  powershell -File `"$Agent`" -Once"
}

if (-not $reported) {
    Write-Output 'Sending the first report...'
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $Agent -Once -Conf $Conf
    if ($LASTEXITCODE -ne 0) {
        Write-Warning 'The first report did not go through. The schedule is in place; it will try again.'
    }
}

Write-Output ''
Write-Output 'Done. This machine now appears in Monitor.'
Write-Output "  config     $Conf (SYSTEM and Administrators only)"
Write-Output "  agent      $Agent"
if ($scheduled) {
    Write-Output "  reports    every $Interval seconds"
    if ($Poll -gt 0) {
        Write-Output "  commands   checked for every $Poll seconds"
    } else {
        Write-Output '  commands   only collected when it reports (-NoLive)'
    }
    Write-Output "  task       $scheduleKind, as '$TaskName'"
} else {
    Write-Output "  reports    NOT SCHEDULED -- run this installer again"
}
if ($allow.Count -eq 0) {
    Write-Output '  commands   report and refresh only; nothing here can be changed from Monitor'
} else {
    Write-Output "  commands   report, refresh, and: $($allow -join ', ')"
}
if ($selfUpdating) {
    Write-Output '  agent      updates itself when Monitor holds a newer one'
} else {
    Write-Output '  agent      stays as it is; run this installer again to update it'
}
Write-Output ''
Write-Output "To remove it later:  .\install.ps1 -Uninstall"
