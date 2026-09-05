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
| **Check for updates** | Re-reads what the package manager has available. Installs nothing |
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

With the live channel on, a queued command is collected within its cadence --
seconds, not minutes -- and the agent reports immediately afterwards, so the
outcome and the picture it produced arrive together.

Commands expire. One that nobody collected within the hour is closed off, so a
laptop coming back from a drawer does not start rebooting on the strength of a
decision made in another world.

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

It happens at the end of a run, once there is nothing else to do, and the next
run — a minute later — is the new agent. **Update the agent** in the interface
does the same thing on demand, and reinstalls even when the versions already
agree, which is how a damaged agent gets repaired without going to it.

A machine installed with `--no-self-update` does none of this and says so on its
page. What protects a machine here is not a checksum — whoever controls this
server controls the agent by design, and a hash served from the same place
proves nothing against them. What protects it is the address pinned in its
config, TLS on the way, and that veto, which is set on the machine and cannot be
granted from here.

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
disappear rather than linger. The first two are settings; all of it is pruned by
the same scheduler run that prunes checks.
