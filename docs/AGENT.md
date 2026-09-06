# Servers and clients

Everything else in Monitor is watched from the outside: the scheduler reaches
out and asks. A machine cannot be asked that way. The things worth knowing about
a server — how full the disk is, which updates are waiting, whether it wants a
reboot — are only visible from the inside.

So the direction is reversed. A small agent runs on the machine and posts in.
**Servers** and **Clients** in the sidebar are the two lists it feeds.

---

## What it reports

Every few minutes, one HTTPS request:

| | |
|---|---|
| **What it is** | Hostname, operating system and version, kernel, architecture, manufacturer, model, serial, processor, memory, whether it is virtualised, and its address |
| **How it is** | Processor load, memory and swap in use, load averages, process count, uptime |
| **Storage** | Every real filesystem: mount point, device, type, size, used |
| **Updates** | What the package manager has waiting, which of those are security updates, and whether a restart is outstanding |
| **Software** | The installed packages, with versions |
| **Services** | What is running, what has failed, what starts at boot |
| **Ports** | What is listening, and which process is listening on it |

Any of the last five can be switched off per machine with `--collect`. A list
that was not gathered is left alone rather than being read as "there are none of
these", so switching packages off does not make a machine look empty.

## The live channel

A full report is expensive at both ends -- the agent reads the package manager,
the service list and every listening socket -- so sending one often enough for a
queued command to feel immediate would be absurd.

The two are separated instead. The agent still sends a full report on its own
interval, and in between it knocks on a much smaller door every few seconds:

```
POST /api/agent/poll   ->   {"ok":true,"poll":15,"interval":300,"report":false,"commands":[]}
```

Eighty-odd bytes, one row touched, and usually nothing to do. A command queued
in the interface is picked up within that cadence rather than waiting out the
report interval, and silence becomes detectable in under a minute instead of
half an hour -- which is the more valuable half, because a machine that has
gone away is the thing you actually want to hear about.

The agent has no daemon and no service. One invocation stays alive for just
under a minute, polling, and then exits; the scheduler starts a fresh one each
minute. That is the same trick the server's own scheduler uses, and it means a
crash costs at most a minute and there is nothing to supervise.

**What it costs.** One knock is a single indexed lookup and one small write,
about 10 ms. A hundred machines at fifteen seconds is roughly seven requests a
second — a few per cent of one PHP worker. Raise the cadence per machine if
that is too much, or switch it off with `--no-live` and commands go back to
being collected when the machine reports. The floor is five seconds.

## What it does not do

It listens on nothing. There is no port to open on the machine, no inbound rule
to write, and nothing to reach even if this Monitor install is compromised —
beyond the short list of commands below, each of which the machine has to have
agreed to in advance. That list includes replacing the agent itself, which is
the one worth being clear about: a machine that would rather not run code this
server hands it is installed with `--no-self-update`, and then does not.

It does not read per-user data. On Windows, only the machine-wide uninstall keys
are collected, not per-user installs: those belong to a person, not to the
machine, and collecting them turns an inventory into surveillance.

---

## Installing it

Make an enrolment key first, under **Enrolment** in the sidebar. Then, on the
machine:

**Linux** — needs `curl`, and root:

```sh
curl -fsSLO https://monitor.example.com/agent/linux/install.sh
sudo sh install.sh --key mek_...
```

**macOS** — needs `sudo`:

```sh
curl -fsSLO https://monitor.example.com/agent/macos/install.sh
sudo sh install.sh --key mek_...
```

**Windows** — needs an elevated PowerShell:

```powershell
irm https://monitor.example.com/agent/windows/install.ps1 -OutFile install.ps1
.\install.ps1 -Key mek_...
```

The Enrolment page prints both lines with your address and key already in them,
along with the SHA-256 of each installer so you can check it before running it.

The installer downloads the agent, writes a config file only root can read,
exchanges the key for a token belonging to this machine, schedules a report with
systemd (or cron, or a Windows scheduled task), and sends the first one. The
machine appears in Monitor within a minute.

### Options

| Flag | Windows | Does |
|---|---|---|
| `--key` | `-Key` | The enrolment key. Required |
| `--url` | `-Url` | The Monitor address. Defaults to the site the script came from |
| `--interval 300` | `-Interval 300` | Seconds between full reports. Minimum 60 |
| `--poll 15` | `-Poll 15` | Seconds between command checks. Minimum 5 |
| `--no-live` | `-NoLive` | Do not check between reports |
| `--collect disks,updates` | `-Collect disks,updates` | Which optional lists to gather |
| `--level info` | `-Level info` | How much the agent says: error, warn, info or debug |
| `--allow-updates` | `-AllowUpdates` | Let Monitor install updates when asked |
| `--allow-reboot` | `-AllowReboot` | Let Monitor restart the machine when asked |
| `--no-allow-updates` | `-NoAllowUpdates` | Take that permission back |
| `--no-allow-reboot` | `-NoAllowReboot` | Take that permission back |
| `--no-self-update` | `-NoSelfUpdate` | Do not let the agent replace itself from Monitor |
| `--self-update` | `-SelfUpdate` | Let it again |
| `--insecure` | `-Insecure` | Skip TLS verification. Self-signed certificates only |
| `--no-insecure` | `-NoInsecure` | Verify certificates again |
| `--force` | `-Force` | Enrol again even if this machine already has a working token |
| `--uninstall` | `-Uninstall` | Remove the agent and stop reporting |

The installer is safe to run twice. If the machine already holds a token that
still works it keeps it and repairs whatever else is missing, rather than
enrolling the same computer a second time and spending another use of the key.
That is what to reach for if an install stops half way: run it again. `--force`
is how you ask for a genuinely new identity instead.

A second run also keeps the settings of the first. This is the same script the
agent runs to update itself, so a run with no arguments comes out the other side
the way it went in: anything not named on the command line is read back out of
the config and written again unchanged. Nothing is granted by silence — a
machine installed without `--allow-updates` does not acquire it by being
updated, and `--no-allow-updates` is how a permission is actually taken away.

If the schedule cannot be created at all, the installer says so plainly rather
than reporting success — the machine is enrolled, its token is saved, and
running the installer again will finish the job. It also reads the schedule
back before calling it done, because "enabled" and "will fire" are not the same
thing: a systemd timer whose every anchor is in the past sits there `active
(elapsed)` — loaded, enabled, active, and never going to run again. The timer
carries an `OnActiveSec=` anchor, which is relative to the timer itself
starting and so cannot be in the past, precisely so it cannot get into that
state.

Three commands are useful when something is not working: `--version`; `--dump`,
which prints the report the agent would send without sending it; and `--poll`,
which knocks once on the live channel and prints the answer. On Linux that is
`/usr/local/lib/monitor-agent/agent.sh --dump`.

---

## Settings for the whole fleet

**Settings → Agent** holds the two decisions that are not any one machine's:
what a machine is set up with, and what this server is willing to offer it.

The **defaults** — report interval, live channel and its cadence, log level,
and whether commands are allowed — are applied once, at enrolment, and never
consulted again. From that moment the machine's own row is the truth, which is
what lets one machine be given a different cadence without disturbing the rest.
An installer that was given `--interval` or `--poll` keeps its own answer:
somebody typing a flag meant it. Log level and whether commands are allowed have
no flags, so those are always the site's to decide.

Because the defaults only ever seed, changing one does nothing to machines that
already exist. **Apply to every machine** is the separate, deliberate act that
writes them onto all of them at once, and it says how many it changed. The new
cadence reaches each machine on its next check-in.

The **switches** are the other kind of thing. They are read on every request an
agent makes, so turning one off takes effect on the next knock, fleet-wide:

- *Offer the agent this server holds* — off means the version is simply left out
  of every answer, which every agent reads as "nothing on offer". Use it to hold
  a fleet still while a new agent is tried on one machine. The machine's own
  `--no-self-update` is unaffected and still wins where it is set.
- *Hand out queued commands* — off means nothing is collected by anybody.
  Queued commands wait where they are and expire on their own hour, so this is a
  pause and not a cancellation.

Alongside them is **how long an agent leaves it between update attempts**, which
travels the same way and is described under Updating the agent below.

Neither switch can make a machine do anything. They only decide whether it is
asked, which is this side of the two-lock arrangement described under Security.

---

## Enrolment keys

A key is a coupon, not an identity. A machine spends one, once, and is issued a
token of its own; from then on the key has nothing to do with it.

That separation is what makes a shared key safe to hand out. A key that leaks is
revoked without touching a single machine that already enrolled with it, and a
machine whose token leaks is revoked without cutting off the rest.

A key can be capped to a number of machines, given an expiry, tied to a group
(so machines enrolling on it are visible to that group), tied to a location, and
told which of the two lists its machines land on. It is shown once, when you
make it — only its hash is kept here, so it cannot be read back out.

## Server or client

The agent guesses. On Windows it does not have to: the operating system says
outright whether it is a workstation or a server. On Linux the chassis type says
it on real hardware; failing that, a battery means a laptop, anything
virtualised is taken to be a server, and only then does an actually-installed
desktop count.

Move a machine to the other list and the decision sticks — the agent stops
arguing about that machine from then on. There is a checkbox on the machine's
page to hand the decision back.

---

## Asking a machine to do something

Five things, and only five:

| | |
|---|---|
| **Report now** | Sends a fresh report at the next check-in instead of waiting out the interval |
| **Check for updates** | Goes out to the machine's update sources for a fresh list, and says what is waiting. Installs nothing |
| **Update the agent** | Reinstalls the agent from the version this server holds |
| **Install updates** | Applies the pending updates |
| **Restart** | Restarts the machine |

The command sent over the wire is a name from that list, never a command line.
The agent holds the same five names in a hard-coded branch and refuses anything
else, so the worst that a compromised server — or a tampered database row — can
ask for is one of the five.

The three that change a machine need three separate things to be true: an
administrator has to queue it, commands have to be enabled for that machine, and
the machine has to have been installed to allow that particular thing —
`--allow-updates`, `--allow-reboot`, or, for updating the agent, not having been
installed with `--no-self-update`. That last condition is decided on the
machine, by whoever installed the agent, and cannot be granted from here.

The agent reports which of them it has consented to, so the interface can say so
before you press: a button the machine is going to refuse is greyed out and
names the flag it was installed without, spelled the way that machine's
installer spells it. This is a courtesy, not the lock — the lock is on the
machine, and a request that gets there anyway is still refused there. An agent
too old to send its consent has not refused anything, so nothing is greyed out
until a machine has actually said.

With the live channel on, a queued command is collected within its cadence --
seconds, not minutes -- and the agent reports immediately afterwards, so the
outcome and the picture it produced arrive together.

Commands expire. One that nobody collected within the hour is closed off, so a
laptop coming back from a drawer does not start rebooting on the strength of a
decision made in another world.

### Check for updates, on each side

**Check for updates** is the only command that goes out to the network on the
machine's behalf, and it is the same job on both platforms:

- On Linux it runs the package manager's own refresh — `apt-get update`,
  `dnf makecache`, `zypper refresh`, `apk update`, `pacman -Sy` — and the
  manager's output is streamed to the machine's log line by line as it arrives.
  A repository that cannot be reached says so in its own words, and the exit
  code the manager gave is the one recorded.
- On Windows it asks the Windows Update agent with `Online = $true`, which is
  the same round trip. It has no line-by-line output to stream, so what arrives
  is one line before and one after.

Both finish with the same sentence: how many updates are waiting, and how many
of those are security. Then the agent reports, so the list on the machine's
page is the one that was just fetched.

The **report** deliberately does not do any of this. It asks what is already on
disk — `apt-get -s upgrade` against the lists as they stand, `Online = $false`
on Windows — because a report happens every few minutes and going out to the
network on that schedule would be the most expensive thing the agent does, for
an answer that changes about once a day. The consequence is worth knowing: a
machine whose lists have never been refreshed shows nothing waiting until
something refreshes them, which on Linux is any `apt update` and on Windows is
Windows Update's own daily scan — or this button, on either.

---

## What it is doing

A machine's page shows what it *is* — disks, updates, packages — and a timeline
of what this server has worked out about it. The log is the third thing: what
the agent says about itself, in its own words.

```
19:41:02  Running install_updates.
19:41:04  Reading package lists...
19:41:31  Unpacking linux-image-6.8.0-134-generic ...
19:43:58  install_updates finished.
```

Lines arrive **while the command runs**, not after it. Installing updates takes
minutes, and a progress line arriving as it happens is the difference between
watching and wondering. The page polls every two seconds while something is
running and every twenty when nothing is, and follows the tail unless you have
scrolled up to read something — in which case it leaves you where you are.

The agent writes every line twice: to `/var/log/monitor-agent.log` on the
machine, which is the only copy that still exists when this server is the thing
that is broken, and to an outbox beside it. The outbox is what gets posted here,
and it survives between runs — a machine that spent an hour unable to reach
Monitor sends that hour's lines when it comes back rather than losing them. It
holds the most recent 2000 lines and no more.

**How much it says** is per machine, on its edit page:

| | |
|---|---|
| `error` | Only what went wrong |
| `warn` | And what was refused |
| `info` | And what it did: commands, updates, schedule changes. The default |
| `debug` | And every knock on the live channel, which is a great deal of nothing |

The setting travels in every answer this server gives, so turning it down
reaches the machine on its next knock and takes effect there — a line below the
level is never written, rather than being written and then hidden.

---

## Updating the agent

An agent updates itself by **running the installer again**, not by downloading
`agent.sh` and swapping itself for it.

Every answer this server gives carries the version it would hand out. When that
differs from the version the agent is running, the agent fetches its own
installer — `/agent/linux/install.sh`, or `/agent/windows/install.ps1` — and
runs it with no arguments. The installer,
which is always the newest version of the whole job, does the rest: download,
sanity-check, atomic replace, re-register the schedule. Install and update are
one code path, so they cannot drift apart, and a bug in the update path is a bug
somebody would have hit installing.

Running it with no arguments is what makes this safe. The installer reads every
setting back out of the machine's own config and writes it again unchanged, so
an update cannot quietly hand back a permission the machine was deliberately
installed without.

An agent that has just tried will not try again for a while — an hour by
default, and whatever **Settings → Agent** says otherwise. The throttle is
there so that a server announcing a version that never arrives cannot have a
whole fleet reinstalling every fifteen seconds. It is a floor on retries rather
than a schedule, and it is invisible in ordinary use: an update that works makes
the versions agree, so nothing is retried. The one time it is felt is two
releases inside one window, which is a thing that happens while somebody is
working on the agent — hence the setting. It never goes below a minute, because
"try again immediately, forever" is the failure it exists to prevent, and the
value reaches a machine the same way the cadences do, with the next answer it
gets.

It happens at the end of a run, once there is nothing else to do, and the next
run — a minute later — is the new agent. **Update the agent** in the interface
does the same thing on demand, ignores the throttle outright, and reinstalls
even when the versions already agree — which is how a damaged agent gets
repaired without going to it, and how a machine is moved on to a version
released inside the retry window.

A machine installed with `--no-self-update` does none of this and says so on its
page, as does a site with *Offer the agent this server holds* switched off — in
that case nobody is offered anything, and the machine's page says so rather than
claiming an update is on its way. What protects a machine here is not a checksum — whoever controls this
server controls the agent by design, and a hash served from the same place
proves nothing against them. What protects it is the address pinned in its
config, TLS on the way, and that veto, which is set on the machine and cannot be
granted from here.

### After a restart, and after an update

A report is normally sent on the interval, and a poll in between carries no
facts about the machine at all. That leaves two moments where the interval is
the wrong answer.

A machine that has just restarted describes itself as it was before it went:
the uptime, the pending-restart flag, the kernel it is now running and anything
an update changed on the way down are all stale until the interval comes round
-- up to five minutes of a page saying something that is no longer true, at
exactly the moment somebody is most likely to be looking at it.

And an agent that has just replaced itself may be able to see things the old
one could not, and its own version on its page is wrong until it says
otherwise.

So the first run after either one reports in full before it does anything else.
Both come out of one comparison: the agent keeps the boot it last reported from
and the version it was, and a run whose pair does not match owes a report. The
stamp is written only once a report has actually got through, so a machine that
cannot reach the server keeps owing it rather than losing it.

What "in full" means is what that machine collects -- `MONITOR_COLLECT`. An
agent installed with `--collect disks` sends disks, promptly. Somebody chose
that, and a restart is not a reason to overrule them.

---

## When a machine goes quiet

Silence is the only signal there is: an agent that stops reporting looks exactly
like a machine that has been switched off, and both are worth saying out loud.

The yardstick is whichever cadence the machine is actually speaking on -- the
poll cadence when the live channel is on, the report interval when it is not.
A machine late by three times that, plus a little slack, is **late**; past eight
times it is **silent**. At the default fifteen seconds that means a machine is
called late inside a minute and a half. Both are written to its timeline, so you can see when it went and
when it came back.

## Cutting a machine off

Two different things, on the machine's page:

**Revoke the token** stops it reporting immediately and keeps everything it has
sent. Reach for this if the machine, or its config file, has gone somewhere it
should not. Running the installer again on the machine enrols it afresh.

**Switch it off** tells the agent to stop, politely: it checks in, is told it is
disabled, and does nothing further. Use it for a machine that is legitimately
going away for a while.

**Delete** removes the machine and everything it reported. It does not uninstall
anything — run the installer with `--uninstall` on the machine itself.

---

## Security

The token is 24 random bytes and is stored here only as a SHA-256 hash, so a
database dump does not let anybody speak as somebody else's server. It reaches
curl down a pipe rather than on a command line, so it does not show up in `ps`
for every other user on the machine. The config file holding it is mode 600 and
owned by root on Linux, and readable by SYSTEM and Administrators only on
Windows.

Enrolment and reporting carry a bearer token rather than a session cookie, which
is why those two endpoints are exempt from CSRF checks: there is no cookie for a
browser to be tricked into sending. Both refuse a plain HTTP connection when
`APP_URL` says the install uses HTTPS — a token crossing an unencrypted hop is a
token somebody else now has.

A report that cannot be decoded is refused with a 400 that names the reason —
"Malformed UTF-8 characters" and "Syntax error" are different faults with
different fixes — and the whole of the last such body is kept in
`storage/logs/unreadable-body.json`, capped at a megabyte and overwritten each
time. The ends of a 300 KB document are exactly where this kind of fault is
not, and the machine that sent it is usually not one anybody is sitting at.

It is refused rather than accepted and stored as the nothing it amounted to,
which is what used to happen — and it meant one bad byte in one package name
could overwrite a machine's hostname, operating system and every list with
null. The agent puts
what it collects through `iconv` before sending, because a package description
or a service unit is somebody else's text and a machine is under no obligation
to keep it in UTF-8; a stray byte is dropped rather than the report.

The agent runs under `LC_ALL=C` throughout, because JSON is not a
locale-dependent format. `mawk` — which is what `awk` is on Debian and Ubuntu —
formats `%f` through the locale, so a machine in Denmark reported its processor
load as `11,07` and made its whole report undecodable. The Windows agent avoids
the same trap with `InvariantCulture`; both were written after being caught by
it.

Everything in a report is treated as though a stranger typed it. Strings are
forced to valid UTF-8, stripped of control characters and cut to the width of
the column they land in; numbers are clamped to ranges that make physical sense;
every list has a ceiling, so a machine claiming two million packages fills a page
rather than a disk; and a report over 4 MB is refused unread. A leaked device
token buys the ability to post nonsense about one machine, and nothing else.

Which machines a person can see follows the same group rules as monitors —
one idea of sharing, so a new page cannot invent a second one.

## Three platforms, two agents

Linux and macOS share one script. It works out which it woke up on from
`uname -s` and reads the machine accordingly — `sysctl` and `vm_stat` and
`launchctl` on one side, `/proc` and `systemctl` on the other — but everything
around those readers is the same code: the logging, the outbox, the command
loop, the two-lock consent, self-update. Those are the parts that have actually
had bugs in them, and there is one copy of each to fix.

The addresses are still separate — `/agent/macos/install.sh` and
`/agent/linux/install.sh` serve the same file — because a machine asking for the
macOS agent and being handed a path called linux is a small confusion that costs
somebody an afternoon.

What differs between the two, in full:

| | Linux | macOS |
|---|---|---|
| Facts | `/proc`, DMI | `sysctl`, `ioreg`, `sw_vers` |
| Metrics | `/proc/stat`, `/proc/meminfo` | `top`, `vm_stat`, `vm.swapusage` |
| Disks | `df -T` | `df`, minus the volumes nobody can fill |
| Updates | apt, dnf, zypper, apk, pacman | `softwareupdate` |
| Software | dpkg, rpm, apk, pacman | Homebrew's Cellar, and `/Applications` |
| Services | `systemctl` | `launchctl` |
| Ports | `ss` | `lsof` |
| Schedule | systemd timer, or cron | launchd, `StartInterval` |
| Restart | `shutdown -r +1` | `shutdown -r +1` |

A Mac is guessed to be a client. The hardware barely distinguishes the two —
the same Mac mini sits under a desk and in a rack — so it guesses the common
case and stops arguing the moment anybody moves it.

Two things a Mac cannot answer. It has nowhere to read "a restart is owed", so
`reboot_required` is always false rather than guessed; an update that needs a
restart says so when it is installed. And Homebrew is read out of its Cellar
directory rather than by running `brew`, which refuses to run as root at all —
and this agent is root.

---

## One version, three platforms

The Linux and Windows agents carry the same version number and are released
together, so "this machine is on 1.9.0" means the same thing whichever one it
is running. A fix to one is a release of both, even when the other needed
nothing — otherwise the numbers drift and stop meaning anything, and this
server ends up offering a version to a platform that never got it. Both check
suites refuse to pass if the two disagree.

Not every fix applies to both. Several of the faults found on Linux cannot
occur on Windows: PowerShell strings cannot produce invalid UTF-8, a report
built from a hashtable cannot stop half way, there is no `df` to misread, and
the JSON encoder has forced `InvariantCulture` since it was written. Those
releases still bump both — the Windows agent simply had nothing to change.

What both do share is the exit code an agent answers with, because the
installer acts on it:

| | |
|---|---|
| `0` | Reported |
| `2` | The token was refused — this machine has to enrol again |
| `3` | The server could not read the report — the token is fine |
| `1` | Anything else: unreachable, or an answer nobody expected |

Only `2` makes the installer enrol again. Treating any non-zero exit as a dead
token spends a use of an enrolment key and leaves a second row for the same
computer, which is the one thing running the installer twice must never do.

## The PowerShell traps that keep happening

PowerShell variable names are case-insensitive, so `$poll` inside a script **is**
the `-Poll` parameter declared at the top of it. Assigning to one is either a
hard throw — an `Int32` into a `[switch]` — or, worse, a silent overwrite of
what somebody typed on the command line.

It has shipped twice. `$allowUpdates` quietly overrode `-AllowUpdates` in the
installer, so a machine could be reinstalled without the permission it had been
given. And `$poll = 0` threw on every `-Loop` run of the agent — which is the
only way the scheduled task ever starts it — so the live channel had never once
worked on Windows, while the installer's own `-Once` check went on succeeding
and hid it for four versions.

There is a second one of the same character, and it also shipped. PowerShell
unrolls a collection on the way out of a function, so a list built in
`Get-Disks` and returned comes back as the hashtable itself when it holds one
entry — and the encoder, which tests `IDictionary` before `IEnumerable`, writes
`"disks":{...}` where the server is reading an array. The server finds no rows
in an object and stores none, while `collected` still says disks were gathered,
so the machine's page shows no disks and nothing anywhere says why. A laptop
has one fixed disk and several hundred services, which is exactly why disks
were the only list it touched. Every list going into the report is wrapped in
`@()` for this reason.

`tools/check-agent.ps1` now walks both param blocks and every assignment in both
files and refuses to pass if one shadows the other, and checks that no list has
lost its `@()`. The installer deliberately
reassigns four of its own parameters, resolving "asked for, then already here,
then the default"; those four are named in the check so the rule can be absolute
everywhere else.

---

## Testing the Windows agent

The agent ships as PowerShell, and a self-hosted Monitor is unlikely to be
running on Windows — so the scripts most in need of testing are the ones the
server cannot execute. `tools/check-agent.ps1` closes as much of that gap as
can be closed from Linux:

```sh
pwsh tools/check-agent.ps1
```

It parses both scripts with PowerShell's own parser, runs the parts that are
pure logic (the JSON encoder, the config reader, the patterns that read the
server's answers, the guard around native commands), works out what settings a
given run would end up with, and audits every cmdlet parameter it can resolve. It cannot call the Windows APIs themselves —
scheduled tasks, CIM, the Windows Update agent — so those parameters are
listed by name and checked against Microsoft's published reference instead.

That last point is not academic: the first release of the installer passed
`-RandomDelay` to `New-ScheduledTaskSettingsSet`, where it does not exist,
and nothing on this side could have caught it by reading.

The Linux side has the same thing, and it runs anywhere:

```sh
sh tools/check-agent.sh
```

It parses both scripts with `sh` and with `dash` — which is what `/bin/sh`
actually is on Debian and Ubuntu, and stricter about what POSIX shell means —
loads the agent as a file of functions and calls them one at a time with `post`
replaced, and works out what settings a given installer run would end up with.
That last part is the one worth having: an installer that quietly resets a
machine's permissions still prints "Done".

## Retention

Polls write nothing but a timestamp, so the live channel adds no rows. Readings
are kept for 30 days, timeline entries for 180, finished commands for 30, and
the agent's own log for 14 — the shortest of the lot, because a fleet installing
updates writes a line per line of output and a fortnight-old progress message
from apt is of no interest to anybody. What was *concluded* from it is a
timeline entry, and those are kept far longer. The lists a report brings — disks, updates, packages, services, ports — are
replaced wholesale each time, because a package that was uninstalled should
disappear rather than linger. Readings, timeline entries and the agent log are
all settings, on **Settings → Agent**; all of it is pruned by the same scheduler
run that prunes checks.
