#!/bin/sh
# Monitor agent for Linux.
#
# Reports what this machine looks like from the inside -- what it is, how full
# it is, which updates are waiting -- to a Monitor install, and carries out the
# short list of commands that install is allowed to ask for.
#
# Written in POSIX shell against curl and nothing else, because the machines
# worth watching are the ones nobody wants to install a runtime on. It talks
# outward only: no port is opened here, and there is nothing to connect to.
#
# Configuration lives in /etc/monitor-agent/agent.conf, mode 600. The token in
# it is this machine's whole identity, so it is never passed on a command line
# -- curl reads the headers from a pipe, which keeps them out of ps.

set -eu

# JSON is not a locale-dependent format, and this machine's locale is nobody
# else's business.
#
# mawk -- which is what awk is on Debian and Ubuntu -- formats %f through the
# locale, so on a Danish desktop "11.07" comes out as "11,07" and the entire
# report becomes undecodable at the far end. Individual commands were already
# being run under LC_ALL=C for their output; this covers the formatting as
# well, and everything else that would otherwise depend on where a machine
# happens to be. The Windows agent does the same thing with InvariantCulture,
# for the same reason and after the same bug.
LC_ALL=C
export LC_ALL

# One POSIX agent for Linux and macOS, and a PowerShell one for Windows.
# They carry one version between them and move together, so that
# "this machine is on 1.13.0" means the same thing whichever it is running. A
# change to one is a release of both, even when the other needed nothing:
# tools/check-agent.sh and check-agent.ps1 both refuse to pass if they differ.
AGENT_VERSION="1.13.0"
CONF="${MONITOR_CONF:-/etc/monitor-agent/agent.conf}"

MONITOR_URL=""
MONITOR_TOKEN=""
MONITOR_DEVICE_ID=""
MONITOR_INTERVAL="300"
MONITOR_POLL="15"
MONITOR_COLLECT="disks,updates,packages,services,ports"
MONITOR_ALLOW=""
MONITOR_INSECURE="0"
MONITOR_SELF_UPDATE="1"
MONITOR_LEVEL="info"
MONITOR_UPDATE_RETRY="3600"

# Where the agent writes what it did. The outbox alongside it holds lines that
# have not reached the server yet, so a spell offline does not lose them.
MONITOR_LOG="${MONITOR_LOG:-/var/log/monitor-agent.log}"
MONITOR_STATE="${MONITOR_STATE:-/var/lib/monitor-agent}"
LOG_MAX_BYTES=1048576

# Remembered before anything can replace this file: a self-update rewrites the
# script this process is reading, and what it was asked to do is worth knowing
# on the far side of that.
MODE="${1:-}"

# How long one invocation stays alive polling before it exits and lets the
# scheduler start a fresh one. Just under a minute, so the next cron minute or
# timer tick picks straight up -- the same trick the server's own scheduler
# uses, and the reason this needs no daemon and no service to install.
LOOP_SECONDS="${MONITOR_LOOP_SECONDS:-55}"

[ -r "$CONF" ] && . "$CONF"

if [ -z "$MONITOR_URL" ]; then
    echo "monitor-agent: not configured. Expected MONITOR_URL in $CONF" >&2
    exit 1
fi

if [ -z "$MONITOR_TOKEN" ] && [ "${1:-}" != "--enroll" ] && [ "${1:-}" != "--dump" ] && [ "${1:-}" != "--version" ]; then
    echo "monitor-agent: this machine has not enrolled yet. Run the installer." >&2
    exit 1
fi

umask 077
WORK="$(mktemp -d "${TMPDIR:-/tmp}/monitor-agent.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT INT TERM

# ---------------------------------------------------------------- helpers ---

have() { command -v "$1" >/dev/null 2>&1; }

collects() {
    case ",$MONITOR_COLLECT," in
        *",$1,"*) return 0 ;;
        *) return 1 ;;
    esac
}

allows() {
    case ",$MONITOR_ALLOW," in
        *",$1,"*) return 0 ;;
        *) return 1 ;;
    esac
}

# A JSON string literal for a single-line value. Backslash and quote escaped,
# control characters dropped -- the server sanitises again, but sending
# well-formed JSON is this side's job.
jstr() {
    printf '"%s"' "$(printf '%s' "${1:-}" | LC_ALL=C tr -d '\000-\037' | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')"
}

# The same, for text that is allowed to contain newlines (command output).
# Everything below a space except the newline, which awk turns into \n below.
#
# The range used to be written out piece by piece and two fell through the
# gaps: tab, and the carriage return. Both are illegal raw inside a JSON
# string, and apt-get writes a carriage return for every percent of "Reading
# database" -- so an upgrade on a machine with two hundred thousand files
# produced a report the server could not read, and the whole thing was lost
# for the sake of the progress bar in it. The log path has always stripped the
# lot; this is the same rule, written once.
jtext() {
    printf '"%s"' "$(printf '%s' "${1:-}" \
        | LC_ALL=C tr -d '\000-\011\013-\037' \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' \
        | awk '{ printf "%s\\n", $0 }')"
}

# A number, or null. Anything that is not plainly one becomes null rather than
# a broken document.
#
# A decimal comma is turned into a point rather than rejected. LC_ALL=C above
# means nothing here should produce one, but this is the second time a decimal
# comma has broken a report, and a reading is worth more than a null.
jnum() {
    n="$(printf '%s' "${1:-}" | tr ',' '.')"

    case "$n" in
        *[!0-9.-]*|*.*.*|*-*-*) printf 'null'; return ;;
    esac

    case "$n" in
        *[0-9]*) printf '%s' "$n" ;;
        *) printf 'null' ;;
    esac
}

first_line() { head -n 1 2>/dev/null || true; }

# Shared by every awk program that emits JSON.
AWK_ESC='function esc(s) { gsub(/\\/, "\\\\", s); gsub(/"/, "\\\"", s); gsub(/[\001-\037]/, "", s); return s }'

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
# happening. That is the whole point: watching apt-get take four minutes is
# worth something, reading about it afterwards much less.

# At most this many lines in one request, which is what the server accepts, and
# at most this many held for a machine that has been unreachable for days. The
# newest are kept: old progress lines are the least interesting thing here.
SHIP_MAX=400
OUTBOX_MAX=2000

# How eagerly a line is sent. Every line would be a request per line of apt
# output; every minute would not be live at all.
SHIP_LINES=25
SHIP_SECONDS=3

OUTBOX=""
SHIPPED_AT=""
LEVEL_FLOOR=1

level_rank() {
    case "${1:-}" in
        debug) echo 0 ;;
        info)  echo 1 ;;
        warn)  echo 2 ;;
        error) echo 3 ;;
        *)     echo 1 ;;
    esac
}

# Worked out once per run rather than per line, and it is what makes 'debug'
# affordable to leave available: below the floor a line costs one comparison.
log_setup() {
    LEVEL_FLOOR="$(level_rank "$MONITOR_LEVEL")"

    if [ -n "$MONITOR_STATE" ] && mkdir -p "$MONITOR_STATE" 2>/dev/null; then
        chmod 700 "$MONITOR_STATE" 2>/dev/null || true
        OUTBOX="$MONITOR_STATE/outbox"
        SHIPPED_AT="$MONITOR_STATE/shipped-at"
    fi
}

# One old file kept, so there is something to read either side of a restart
# without asking anybody to configure logrotate for an agent this small.
log_local() {
    [ -n "$MONITOR_LOG" ] || return 0

    if [ -f "$MONITOR_LOG" ]; then
        size="$(wc -c < "$MONITOR_LOG" 2>/dev/null || echo 0)"
        if [ "$size" -gt "$LOG_MAX_BYTES" ] 2>/dev/null; then
            mv "$MONITOR_LOG" "$MONITOR_LOG.1" 2>/dev/null || true
        fi
    fi

    printf '%s\n' "$1" >> "$MONITOR_LOG" 2>/dev/null || true
}

# log LEVEL MESSAGE [COMMAND-UUID]
#
# Everything below the configured level is dropped here, before it is written
# anywhere, so turning the level down genuinely costs less rather than just
# hiding lines at the far end.
log() {
    lvl="${1:-info}"
    [ "$(level_rank "$lvl")" -ge "$LEVEL_FLOOR" ] || return 0

    # Both files are line-oriented and a package manager will hand over output
    # containing absolutely anything, so this is flattened to one line before
    # it is allowed near either of them.
    msg="$(printf '%s' "${2:-}" | LC_ALL=C tr '\011\012\015' '   ' | LC_ALL=C tr -d '\000-\037' | cut -c 1-1000)"
    [ -n "$msg" ] || return 0

    stamp="$(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || true)"
    log_local "$stamp $lvl $msg"

    [ -n "$OUTBOX" ] || return 0
    printf '%s\t%s\t%s\t%s\n' "$stamp" "$lvl" "${3:-}" "$msg" >> "$OUTBOX" 2>/dev/null || true
}

# Make a document safe to send, in the one way that cannot be done field by
# field.
#
# Everything this agent reads is somebody else's text -- a package
# description, a service unit, a mount point -- and a machine is under no
# obligation to have it in UTF-8. A single stray byte from an old changelog
# makes the whole document undecodable at the far end, and the far end is
# right to refuse it, so one bad byte in one package name would throw away the
# entire report. iconv drops just the offending sequences.
#
# There is no fallback that is better than sending it as it is: a machine
# without iconv is almost certainly a busybox one, where the text in question
# came from a package manager that only ever emits ASCII.
sanitise_json() {
    if have iconv && iconv -f UTF-8 -t UTF-8 -c < "$1" > "$1.clean" 2>/dev/null; then
        mv "$1.clean" "$1"
    else
        rm -f "$1.clean"
    fi
}

outbox_json() {
    printf '{"logs":['
    awk -F'\t' "$AWK_ESC"'
        NF >= 4 {
            msg = $4
            for (i = 5; i <= NF; i++) msg = msg " " $i
            if (n++) printf ","
            printf "{\"at\":\"%s\",\"level\":\"%s\",\"command\":\"%s\",\"message\":\"%s\"}",
                esc($1), esc($2), esc($3), esc(msg)
        }
    ' "$1"
    printf ']}'
}

# Send what is waiting, if it is worth a request yet.
#
# Called after every line of a running command and after every knock on the
# live channel, so the decision about whether to actually send has to be cheap
# and has to be made here rather than at each call site.
ship_logs() {
    [ -n "$OUTBOX" ] && [ -s "$OUTBOX" ] || return 0
    [ -n "$MONITOR_TOKEN" ] || return 0

    if [ "${1:-}" != "now" ]; then
        lines="$(wc -l < "$OUTBOX" 2>/dev/null || echo 0)"
        if [ "$lines" -lt "$SHIP_LINES" ]; then
            last="$(cat "$SHIPPED_AT" 2>/dev/null || echo 0)"
            case "$last" in ''|*[!0-9]*) last=0 ;; esac
            [ $(( $(date +%s) - last )) -ge "$SHIP_SECONDS" ] || return 0
        fi
    fi

    batch="$WORK/outbox.batch"
    head -n "$SHIP_MAX" "$OUTBOX" > "$batch" 2>/dev/null || return 0
    tail -n +$((SHIP_MAX + 1)) "$OUTBOX" > "$OUTBOX.rest" 2>/dev/null || : > "$OUTBOX.rest"
    mv "$OUTBOX.rest" "$OUTBOX" 2>/dev/null || true

    outbox_json "$batch" > "$WORK/logs.json"
    sanitise_json "$WORK/logs.json"

    # Its own response file. This is called from the middle of a command, and
    # the answer being acted on at that point is the one still sitting in
    # $WORK/response.
    code="$(post "/api/agent/log" "$WORK/logs.json" "$WORK/log-response")"

    if [ "$code" = "200" ]; then
        [ -n "$SHIPPED_AT" ] && date +%s > "$SHIPPED_AT" 2>/dev/null || true
        return 0
    fi

    # Put them back in front of whatever arrived while the request was out, and
    # keep only the newest if this machine has been talking to nobody for days.
    cat "$batch" "$OUTBOX" > "$OUTBOX.merged" 2>/dev/null || return 1
    tail -n "$OUTBOX_MAX" "$OUTBOX.merged" > "$OUTBOX" 2>/dev/null || true
    rm -f "$OUTBOX.merged"

    return 1
}

# ------------------------------------------------------------ what we are ---

# Linux or macOS. One script covers both, and the split is deliberate: the
# logging, the outbox, the command loop and self-update are identical on either
# and are the parts that have actually had bugs in them, so there is one copy
# of each to fix. What differs is a dozen readers, and every one of them says
# which platform it is reading for.
PLATFORM="linux"
[ "$(uname -s 2>/dev/null)" = "Darwin" ] && PLATFORM="macos"

read_os_release() {
    if [ "$PLATFORM" = "macos" ]; then
        OS_NAME="$(sw_vers -productName 2>/dev/null || echo macOS)"
        OS_VERSION="$(sw_vers -productVersion 2>/dev/null || true)"
        OS_ID="macos"
        return 0
    fi

    [ -r /etc/os-release ] || return 0
    # shellcheck disable=SC1091
    . /etc/os-release 2>/dev/null || return 0
    OS_NAME="${NAME:-}"
    OS_VERSION="${VERSION_ID:-${VERSION:-}}"
    OS_ID="${ID:-}"
    OS_ID_LIKE="${ID_LIKE:-}"
}

OS_NAME=""; OS_VERSION=""; OS_ID=""; OS_ID_LIKE=""
read_os_release
[ -n "$OS_NAME" ] || OS_NAME="$(uname -s)"

dmi() { [ -r "/sys/class/dmi/id/$1" ] && cat "/sys/class/dmi/id/$1" 2>/dev/null || true; }

# Server or client, guessed.
#
# Real hardware says it outright in the chassis type. Everything else is
# circumstantial, so the order matters: a battery or a laptop chassis wins,
# then anything virtualised is taken to be a server, and only then does a
# desktop actually being installed count. Reading the systemd default target
# alone is no good -- plenty of headless Ubuntu images sit on graphical.target
# with nothing graphical installed.
#
# It is a guess, and it is meant to be: whoever looks at the list can move a
# machine to the other page, and from then on the guess stops arguing.
guess_kind() {
    if [ "$PLATFORM" = "macos" ]; then
        # A Mac is somebody's computer far more often than it is a server, and
        # the hardware barely distinguishes the two -- the same Mac mini sits
        # under a desk and in a rack. So it guesses client and stops arguing
        # the moment anybody moves it.
        echo client
        return
    fi

    chassis="$(dmi chassis_type | tr -d ' ')"
    case "$chassis" in
        8|9|10|11|12|14|30|31|32) echo client; return ;;
        17|23|28|29) echo server; return ;;
    esac

    for bat in /sys/class/power_supply/BAT*; do
        [ -d "$bat" ] && { echo client; return; }
    done

    if have systemd-detect-virt; then
        virt="$(systemd-detect-virt 2>/dev/null || true)"
        [ -n "$virt" ] && [ "$virt" != "none" ] && { echo server; return; }
    fi

    # A display manager that is actually wired up, not merely a target name.
    if [ -L /etc/systemd/system/display-manager.service ] \
        || [ -e /usr/bin/gnome-shell ] || [ -e /usr/bin/plasmashell ] || [ -e /usr/bin/xfce4-session ]; then
        echo client
        return
    fi

    echo server
}

primary_ip() {
    if [ "$PLATFORM" = "macos" ]; then
        iface="$(route -n get default 2>/dev/null | awk '/interface:/ { print $2; exit }')"
        [ -n "$iface" ] && ipconfig getifaddr "$iface" 2>/dev/null && return 0
        # No default route, or a machine on Wi-Fi only with the route table
        # in an odd state: take the first address that is not the loopback.
        ifconfig 2>/dev/null | awk '/inet /  && $2 != "127.0.0.1" { print $2; exit }'
        return 0
    fi

    if have ip; then
        ip route get 1.1.1.1 2>/dev/null | awk '/src/ { for (i = 1; i < NF; i++) if ($i == "src") { print $(i+1); exit } }'
    elif have hostname; then
        hostname -I 2>/dev/null | awk '{ print $1 }'
    fi
}

# The hardware facts, each read the way its platform keeps them. Set as
# variables rather than inline, so system_json below stays one shape whichever
# machine it is running on and the JSON cannot drift between the two.
system_facts() {
    if [ "$PLATFORM" = "macos" ]; then
        # ComputerName is what the person called it and is what they will look
        # for in a list; it can be unset or contain spaces, so hostname is
        # there behind it.
        hostname="$(scutil --get ComputerName 2>/dev/null || true)"
        [ -n "$hostname" ] || hostname="$(hostname -s 2>/dev/null || uname -n)"
        fqdn="$(hostname -f 2>/dev/null || printf '%s' "$hostname")"
        cores="$(sysctl -n hw.ncpu 2>/dev/null || echo 1)"
        cpu="$(sysctl -n machdep.cpu.brand_string 2>/dev/null || true)"
        # Apple silicon does not carry a brand string, so the model of the
        # machine is the closest true thing to say about its processor.
        [ -n "$cpu" ] || cpu="$(sysctl -n hw.model 2>/dev/null || true)"
        membytes="$(sysctl -n hw.memsize 2>/dev/null || echo 0)"
        # kern.boottime reads "{ sec = 1757100000, usec = 0 } Sat Sep ...".
        #
        # Matched on the field being exactly "sec", because a regex looking for
        # "sec = " finds usec just as happily -- and the machine then reports
        # an uptime of about fifty years, or of the microsecond, depending on
        # which way the match ran.
        boot="$(sysctl -n kern.boottime 2>/dev/null \
            | awk '{ for (i = 1; i <= NF; i++) if ($i == "sec") { v = $(i + 2); gsub(/[^0-9]/, "", v); print v; exit } }')"
        uptime=0
        case "${boot:-}" in
            ''|*[!0-9]*) ;;
            *) uptime=$(( $(date +%s) - boot )) ;;
        esac
        [ "$uptime" -lt 0 ] 2>/dev/null && uptime=0
        manufacturer="Apple"
        model="$(sysctl -n hw.model 2>/dev/null || true)"
        # ioreg rather than system_profiler: the same answer, without the
        # several seconds system_profiler takes to produce it.
        serial="$(ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null \
            | awk -F'"' '/IOPlatformSerialNumber/ { print $4; exit }')"
        virt=""
        return 0
    fi

    hostname="$(hostname 2>/dev/null || cat /etc/hostname 2>/dev/null || uname -n)"
    fqdn="$(hostname -f 2>/dev/null || printf '%s' "$hostname")"
    cores="$(nproc 2>/dev/null || grep -c '^processor' /proc/cpuinfo 2>/dev/null || echo 1)"
    cpu="$(awk -F': ' '/^model name/ { print $2; exit }' /proc/cpuinfo 2>/dev/null || true)"
    [ -n "$cpu" ] || cpu="$(awk -F': ' '/^Model/ { print $2; exit }' /proc/cpuinfo 2>/dev/null || true)"
    membytes=$(( $(awk '/^MemTotal:/ { print $2; exit }' /proc/meminfo 2>/dev/null || echo 0) * 1024 ))
    uptime="$(awk '{ printf "%d", $1; exit }' /proc/uptime 2>/dev/null || echo 0)"
    manufacturer="$(dmi sys_vendor)"
    model="$(dmi product_name)"
    serial="$(dmi product_serial)"
    virt=""
    have systemd-detect-virt && virt="$(systemd-detect-virt 2>/dev/null || true)"
    [ "$virt" = "none" ] && virt=""
}

system_json() {
    system_facts

    printf '"system":{'
    printf '"hostname":%s,' "$(jstr "$hostname")"
    printf '"fqdn":%s,' "$(jstr "$fqdn")"
    printf '"kind":%s,' "$(jstr "$(guess_kind)")"
    printf '"os_family":"%s",' "$PLATFORM"
    printf '"os_name":%s,' "$(jstr "$OS_NAME")"
    printf '"os_version":%s,' "$(jstr "$OS_VERSION")"
    printf '"kernel":%s,' "$(jstr "$(uname -r)")"
    printf '"arch":%s,' "$(jstr "$(uname -m)")"
    printf '"manufacturer":%s,' "$(jstr "$manufacturer")"
    printf '"model":%s,' "$(jstr "$model")"
    printf '"serial":%s,' "$(jstr "$serial")"
    printf '"cpu_model":%s,' "$(jstr "$cpu")"
    printf '"cpu_cores":%s,' "$(jnum "$cores")"
    printf '"memory_bytes":%s,' "$(jnum "$membytes")"
    printf '"virtualisation":%s,' "$(jstr "$virt")"
    printf '"primary_ip":%s,' "$(jstr "$(primary_ip)")"
    printf '"uptime_seconds":%s,' "$(jnum "$uptime")"
    printf '"agent_version":%s' "$(jstr "$AGENT_VERSION")"
    printf '}'
}

# --------------------------------------------------------------- how we are ---

# Processor time is a rate, not a reading, so it takes two looks a second apart.
cpu_percent() {
    if [ "$PLATFORM" = "macos" ]; then
        # top takes the two looks itself. The first sample is since boot and
        # is not a reading of now, so it is the second that is kept -- which
        # is the same reason the Linux branch below sleeps between two.
        top -l 2 -n 0 -s 1 2>/dev/null \
        | awk '
            /^CPU usage:/ { idle = $0; sub(/.*, /, "", idle); sub(/% idle.*/, "", idle); last = idle }
            END { if (last == "") { print "null" } else { printf "%.2f", 100 - last } }'
        return
    fi

    [ -r /proc/stat ] || { printf 'null'; return; }
    set -- $(awk '/^cpu / { print $2, $3, $4, $5, $6, $7, $8; exit }' /proc/stat)
    idle1=$(( ${4:-0} + ${5:-0} ))
    total1=$(( ${1:-0} + ${2:-0} + ${3:-0} + ${4:-0} + ${5:-0} + ${6:-0} + ${7:-0} ))
    sleep 1
    set -- $(awk '/^cpu / { print $2, $3, $4, $5, $6, $7, $8; exit }' /proc/stat)
    idle2=$(( ${4:-0} + ${5:-0} ))
    total2=$(( ${1:-0} + ${2:-0} + ${3:-0} + ${4:-0} + ${5:-0} + ${6:-0} + ${7:-0} ))

    dt=$(( total2 - total1 ))
    di=$(( idle2 - idle1 ))
    [ "$dt" -le 0 ] && { printf 'null'; return; }
    awk -v dt="$dt" -v di="$di" 'BEGIN { printf "%.2f", (dt - di) * 100 / dt }'
}

metrics_json() {
    if [ "$PLATFORM" = "macos" ]; then
        macos_metrics_json
        return
    fi

    memtotal="$(awk '/^MemTotal:/ { print $2; exit }' /proc/meminfo 2>/dev/null || echo 0)"
    memavail="$(awk '/^MemAvailable:/ { print $2; exit }' /proc/meminfo 2>/dev/null || echo 0)"
    [ "$memavail" -eq 0 ] 2>/dev/null && memavail="$(awk '/^MemFree:/ { print $2; exit }' /proc/meminfo 2>/dev/null || echo 0)"
    swaptotal="$(awk '/^SwapTotal:/ { print $2; exit }' /proc/meminfo 2>/dev/null || echo 0)"
    swapfree="$(awk '/^SwapFree:/ { print $2; exit }' /proc/meminfo 2>/dev/null || echo 0)"
    procs="$(ls -1 /proc 2>/dev/null | grep -c '^[0-9][0-9]*$' || echo 0)"
    set -- $(cat /proc/loadavg 2>/dev/null || echo "0 0 0")

    printf '"metrics":{'
    printf '"cpu_percent":%s,' "$(jnum "$(cpu_percent)")"
    printf '"memory_used_bytes":%s,' "$(jnum "$(( (memtotal - memavail) * 1024 ))")"
    printf '"memory_total_bytes":%s,' "$(jnum "$(( memtotal * 1024 ))")"
    printf '"swap_used_bytes":%s,' "$(jnum "$(( (swaptotal - swapfree) * 1024 ))")"
    printf '"load1":%s,' "$(jnum "${1:-0}")"
    printf '"load5":%s,' "$(jnum "${2:-0}")"
    printf '"load15":%s,' "$(jnum "${3:-0}")"
    printf '"process_count":%s' "$(jnum "$procs")"
    printf '}'
}

# What macOS keeps instead of /proc.
#
# "Used" is deliberately everything that is not free, speculative or purely a
# file cache: wired, active, compressed and the rest of it. That is the number
# Activity Monitor calls memory used, and a machine's page should agree with
# the machine.
macos_metrics_json() {
    membytes="$(sysctl -n hw.memsize 2>/dev/null || echo 0)"
    pagesize="$(sysctl -n hw.pagesize 2>/dev/null || echo 4096)"

    free_pages=0
    inactive_pages=0
    speculative_pages=0
    if have vm_stat; then
        set -- $(vm_stat 2>/dev/null | awk '
            /^Pages free:/        { gsub(/\./, "", $3); f = $3 }
            /^Pages inactive:/    { gsub(/\./, "", $3); i = $3 }
            /^Pages speculative:/ { gsub(/\./, "", $3); s = $3 }
            END { printf "%d %d %d", f + 0, i + 0, s + 0 }')
        free_pages="${1:-0}"; inactive_pages="${2:-0}"; speculative_pages="${3:-0}"
    fi
    unused=$(( (free_pages + inactive_pages + speculative_pages) * pagesize ))
    used=$(( membytes - unused ))
    [ "$used" -lt 0 ] && used=0

    # vm.swapusage reads "total = 2048.00M  used = 512.25M  free = 1535.75M"
    swapused="$(sysctl -n vm.swapusage 2>/dev/null \
        | awk '{ for (i = 1; i < NF; i++) if ($i == "used") { v = $(i+2); break }
                 if (v == "") { print 0; exit }
                 unit = substr(v, length(v)); sub(/[A-Za-z]$/, "", v)
                 mult = (unit == "G") ? 1073741824 : (unit == "M") ? 1048576 : (unit == "K") ? 1024 : 1
                 printf "%d", v * mult }')"

    # vm.loadavg reads "{ 1.52 1.61 1.72 }"
    set -- $(sysctl -n vm.loadavg 2>/dev/null | tr -d '{}')
    procs="$(ps -A -o pid= 2>/dev/null | wc -l | tr -d ' ')"

    printf '"metrics":{'
    printf '"cpu_percent":%s,' "$(jnum "$(cpu_percent)")"
    printf '"memory_used_bytes":%s,' "$(jnum "$used")"
    printf '"memory_total_bytes":%s,' "$(jnum "$membytes")"
    printf '"swap_used_bytes":%s,' "$(jnum "${swapused:-0}")"
    printf '"load1":%s,' "$(jnum "${1:-0}")"
    printf '"load5":%s,' "$(jnum "${2:-0}")"
    printf '"load15":%s,' "$(jnum "${3:-0}")"
    printf '"process_count":%s' "$(jnum "${procs:-0}")"
    printf '}'
}

# ------------------------------------------------------------------- disks ---

# df is run once, and its own header says what its columns are.
#
# Both halves of that matter. df exits non-zero when a single mount cannot be
# stat'ed, and a desktop always has one -- some gvfs or flatpak thing under
# /run/user -- so its exit status says nothing about whether the output is
# usable. Reading that status as "this df has no -T" and running a second one
# put two tables in the stream and read the header of the second as though it
# were a filesystem, which is how a machine came to report a disk called
# "Mounted" while its real ones went missing.
disks_json() {
    printf '"disks":['

    # macOS df has no -T at all, so there is nothing to try and fail at; the
    # header-driven reader below already copes with the column not being there.
    if [ "$PLATFORM" = "macos" ]; then
        df -P -k > "$WORK/df" 2>/dev/null || true
    else
        df -P -T -k > "$WORK/df" 2>/dev/null || true
        [ -s "$WORK/df" ] || df -P -k > "$WORK/df" 2>/dev/null || true
    fi

    if [ -s "$WORK/df" ]; then
        awk "
            $AWK_ESC
            NR == 1 { typed = (\$2 == \"Type\"); first = typed ? 7 : 6; next }
            {
                if (typed) { src = \$1; fs = \$2; total = \$3; used = \$4 }
                else       { src = \$1; fs = \"\";  total = \$2; used = \$3 }

                # A mount point may contain spaces, and df -P does not quote
                # them, so it is everything from the last column onwards.
                mount = \$first
                for (i = first + 1; i <= NF; i++) mount = mount \" \" \$i

                if (mount == \"\" || total + 0 <= 0) next
                if (fs ~ /^(tmpfs|devtmpfs|squashfs|overlay|proc|sysfs|cgroup|cgroup2|ramfs|efivarfs|autofs|fuse.gvfsd-fuse|fuse.portal|nsfs|tracefs|debugfs)\$/) next
                if (mount ~ /^\/(proc|sys|dev|run)(\/|\$)/) next
                # macOS mounts a read-only system volume, a dozen firmlinks
                # and every Time Machine snapshot. None of them is a disk
                # somebody can run out of space on.
                if (mount ~ /^\/System\/Volumes\/(VM|Preboot|Update|xarts|iSCPreboot|Hardware|Recovery)/) next
                if (src ~ /^(map |devfs|com\.apple\.TimeMachine)/) next
                if (seen[mount]++) next
                if (n++) printf \",\"
                printf \"{\\\"mount\\\":\\\"%s\\\",\\\"source\\\":\\\"%s\\\",\\\"filesystem\\\":\\\"%s\\\",\\\"total_bytes\\\":%d,\\\"used_bytes\\\":%d}\",
                    esc(mount), esc(src), esc(fs), total * 1024, used * 1024
            }
        " "$WORK/df"
    fi

    printf ']'
}

# ----------------------------------------------------------------- updates ---

reboot_required() {
    # macOS has nothing to read here. It does not track "a restart is owed"
    # anywhere a script can see; an update that needs one says so when it is
    # installed, and that is reported as the outcome of the command that
    # installed it. Guessing would be worse than saying nothing.
    if [ "$PLATFORM" = "macos" ]; then
        echo false
        return
    fi

    [ -f /var/run/reboot-required ] && { echo true; return; }
    [ -f /run/reboot-required ] && { echo true; return; }
    if have needs-restarting; then
        needs-restarting -r >/dev/null 2>&1 || { echo true; return; }
    fi
    if [ -f /var/run/reboot-needed ] || [ -d /var/run/reboot-required.d ]; then
        echo true; return
    fi
    echo false
}

# Ubuntu hands some updates to a fraction of its machines at a time, and apt
# quietly defers the ones whose turn has not come. They are real all the same:
# they are what apt list --upgradable lists, and what somebody standing at the
# machine sees. So they are counted here and taken by the install -- the option
# is named once because both have to agree about it, and a screen showing an
# update that the button beside it will not take is the worse half of this bug.
#
# It is a config key rather than a flag, which means an apt-get too old to know
# it ignores it instead of refusing the command. Debian does not phase anything
# at all, so there it is a no-op.
APT_PHASED="APT::Get::Always-Include-Phased-Updates=true"

# Each package manager gets its own reader. They all emit the same JSON objects,
# so whatever this machine happens to run, the site sees one shape.
updates_items() {
    if [ "$PLATFORM" = "macos" ]; then
        # softwareupdate prints a stanza per update:
        #
        #   * Label: macOS Sequoia 15.6.1-24G90
        #   \tTitle: macOS Sequoia, Version: 15.6.1, Size: 6799781KiB, ...
        #
        # The Title line carries everything worth keeping. Apple does not mark
        # security updates as such in this output, so the only honest signal is
        # the name itself.
        LC_ALL=C softwareupdate -l 2>/dev/null \
        | awk "
            $AWK_ESC
            /^[[:space:]]*Title:/ {
                line = \$0
                title = line
                sub(/^[[:space:]]*Title:[[:space:]]*/, \"\", title)
                sub(/,[[:space:]]*Version:.*\$/, \"\", title)
                version = \"\"
                if (match(line, /Version:[[:space:]]*[^,]*/)) {
                    version = substr(line, RSTART, RLENGTH)
                    sub(/^Version:[[:space:]]*/, \"\", version)
                }
                sec = (tolower(line) ~ /security|rapid security/) ? \"true\" : \"false\"
                if (title == \"\") next
                if (n++) printf \",\"
                printf \"{\\\"name\\\":\\\"%s\\\",\\\"current_version\\\":\\\"\\\",\\\"available_version\\\":\\\"%s\\\",\\\"source\\\":\\\"softwareupdate\\\",\\\"is_security\\\":%s}\",
                    esc(title), esc(version), sec
            }
        "
        return
    fi

    if have apt-get && [ -f /etc/debian_version ]; then
        # --with-new-pkgs, because a plain upgrade refuses to install a package
        # that is not already there, and an update needing one is then held
        # back without a word. That is not a corner: linux-firmware was split
        # into eighteen pieces, so on every machine still carrying the old
        # single package, apt list --upgradable said one update was waiting and
        # this said none. The machine was right.
        #
        # It is what the install asks for too, so the number said here and the
        # upgrade that is meant to clear it are the same question. Not
        # dist-upgrade: that one will remove a package to get its way, and
        # nobody pressed a button for that.
        #
        # Asked for by running it, because an apt-get too old for the option --
        # it arrived in 1.1 -- would refuse the whole command over it, and
        # reporting nothing is the failure being fixed here.
        sim="$WORK/apt-sim"
        LC_ALL=C apt-get -s -o Debug::NoLocking=true -o "$APT_PHASED" --with-new-pkgs upgrade >"$sim" 2>/dev/null \
            || LC_ALL=C apt-get -s -o Debug::NoLocking=true -o "$APT_PHASED" upgrade >"$sim" 2>/dev/null \
            || true
        awk "
            $AWK_ESC
            /^Inst / {
                name = \$2
                old = \"\"; new = \"\"; src = \"\"
                line = \$0
                # The version being replaced stands before the bracket that
                # opens the new one; the first [...] after it is the
                # architecture. A line with nothing before that bracket is a
                # new package one of these upgrades is dragging in, not an
                # update of its own -- apt list --upgradable does not count
                # those, so neither does this.
                paren = index(line, \"(\")
                head = (paren > 0) ? substr(line, 1, paren - 1) : line
                if (match(head, /\[[^]]*\]/)) old = substr(head, RSTART + 1, RLENGTH - 2)
                if (old == \"\") next
                if (match(line, /\([^)]*\)/)) {
                    inner = substr(line, RSTART + 1, RLENGTH - 2)
                    split(inner, parts, \" \")
                    new = parts[1]
                    src = inner
                }
                sec = (tolower(line) ~ /security/) ? \"true\" : \"false\"
                if (n++) printf \",\"
                printf \"{\\\"name\\\":\\\"%s\\\",\\\"current_version\\\":\\\"%s\\\",\\\"available_version\\\":\\\"%s\\\",\\\"source\\\":\\\"%s\\\",\\\"is_security\\\":%s}\",
                    esc(name), esc(old), esc(new), esc(src), sec
            }
        " "$sim"
        rm -f "$sim"
        return
    fi

    if have dnf; then
        LC_ALL=C dnf -q --cacheonly check-update 2>/dev/null \
        | awk "
            $AWK_ESC
            /^[[:space:]]*\$/ { next }
            /^(Last metadata|Obsoleting|Security:)/ { next }
            NF >= 3 {
                sec = (\$3 ~ /security/) ? \"true\" : \"false\"
                if (n++) printf \",\"
                printf \"{\\\"name\\\":\\\"%s\\\",\\\"current_version\\\":\\\"\\\",\\\"available_version\\\":\\\"%s\\\",\\\"source\\\":\\\"%s\\\",\\\"is_security\\\":%s}\",
                    esc(\$1), esc(\$2), esc(\$3), sec
            }
        "
        return
    fi

    if have zypper; then
        LC_ALL=C zypper --non-interactive --quiet list-updates 2>/dev/null \
        | awk -F'|' "
            $AWK_ESC
            NR > 2 && NF >= 5 {
                gsub(/^[ \t]+|[ \t]+\$/, \"\", \$3); gsub(/^[ \t]+|[ \t]+\$/, \"\", \$4)
                gsub(/^[ \t]+|[ \t]+\$/, \"\", \$5); gsub(/^[ \t]+|[ \t]+\$/, \"\", \$2)
                if (\$3 == \"\" || \$3 == \"Name\") next
                if (n++) printf \",\"
                printf \"{\\\"name\\\":\\\"%s\\\",\\\"current_version\\\":\\\"%s\\\",\\\"available_version\\\":\\\"%s\\\",\\\"source\\\":\\\"%s\\\",\\\"is_security\\\":false}\",
                    esc(\$3), esc(\$4), esc(\$5), esc(\$2)
            }
        "
        return
    fi

    if have apk; then
        LC_ALL=C apk version -l '<' 2>/dev/null \
        | awk "
            $AWK_ESC
            NR > 1 && NF >= 3 {
                if (n++) printf \",\"
                printf \"{\\\"name\\\":\\\"%s\\\",\\\"current_version\\\":\\\"\\\",\\\"available_version\\\":\\\"%s\\\",\\\"source\\\":\\\"apk\\\",\\\"is_security\\\":false}\",
                    esc(\$1), esc(\$3)
            }
        "
        return
    fi

    if have pacman; then
        { checkupdates 2>/dev/null || pacman -Qu 2>/dev/null; } \
        | awk "
            $AWK_ESC
            NF >= 2 {
                if (n++) printf \",\"
                printf \"{\\\"name\\\":\\\"%s\\\",\\\"current_version\\\":\\\"%s\\\",\\\"available_version\\\":\\\"%s\\\",\\\"source\\\":\\\"pacman\\\",\\\"is_security\\\":false}\",
                    esc(\$1), esc(\$2), esc(\$NF)
            }
        "
        return
    fi
}

# Ask the machine's update sources what they have now.
#
# Not quiet: this is the one command somebody presses a button for and then
# watches, so every line the package manager writes is streamed to Monitor as
# it appears. -q only drops the progress bars, which are meaningless without a
# terminal to redraw.
refresh_lists() {
    # softwareupdate -l is the round trip: it asks Apple what this machine can
    # have. There is no separate index to refresh, so the check and the refresh
    # are the same command -- which is why its output is worth streaming.
    if [ "$PLATFORM" = "macos" ]; then
        softwareupdate -l 2>&1
        return $?
    fi

    if have apt-get; then apt-get -q update
    elif have dnf; then dnf -q makecache
    elif have zypper; then zypper --non-interactive refresh
    elif have apk; then apk update
    elif have pacman; then pacman -Sy --noconfirm
    else echo "No package manager this agent knows." >&2; return 1
    fi
}

# What the refreshed lists now say is waiting, in one line.
#
# Read back through the same reader the report uses, so the number said here
# and the list that arrives moments later cannot disagree.
summarise_updates() {
    items="$WORK/pending.json"
    updates_items > "$items" 2>/dev/null || true

    total="$(grep -o '{"name":' "$items" 2>/dev/null | wc -l | tr -d ' ')"
    security="$(grep -o '"is_security":true' "$items" 2>/dev/null | wc -l | tr -d ' ')"
    rm -f "$items"

    if [ "${total:-0}" -eq 0 ] 2>/dev/null; then
        echo "Lists refreshed. Nothing is waiting."
    elif [ "${security:-0}" -eq 0 ] 2>/dev/null; then
        echo "Lists refreshed. $total update(s) waiting."
    else
        echo "Lists refreshed. $total update(s) waiting, $security of them security."
    fi
}

# When this machine last started, as a number that changes only when it does.
#
# Not the uptime: an uptime is a different number every second, and what is
# wanted here is an identity for the current boot that can be compared with the
# one from the last run.
boot_epoch() {
    if [ "$PLATFORM" = "macos" ]; then
        sysctl -n kern.boottime 2>/dev/null \
            | awk '{ for (i = 1; i <= NF; i++) if ($i == "sec") { v = $(i + 2); gsub(/[^0-9]/, "", v); print v; exit } }'
        return
    fi

    # btime is the boot as a wall-clock second and does not move while the
    # machine is up. Where it is missing, the uptime subtracted from now is
    # close enough to differ across a restart and not within one.
    awk '/^btime/ { print $2; exit }' /proc/stat 2>/dev/null && return
    awk -v now="$(date +%s)" '{ printf "%d", now - $1; exit }' /proc/uptime 2>/dev/null
}

updates_json() {
    printf '"updates":{"reboot_required":%s,"security_count":0,"items":[' "$(reboot_required)"
    updates_items
    printf ']}'
}

# ---------------------------------------------------------------- packages ---

packages_json() {
    printf '"packages":['
    if [ "$PLATFORM" = "macos" ]; then
        macos_packages
        printf ']'
        return
    fi

    if have dpkg-query; then
        LC_ALL=C dpkg-query -W -f='${Package}\t${Version}\t${Maintainer}\n' 2>/dev/null \
        | awk -F'\t' "$AWK_ESC"' $1 != "" { if (n++) printf ","; printf "{\"name\":\"%s\",\"version\":\"%s\",\"publisher\":\"%s\",\"source\":\"dpkg\"}", esc($1), esc($2), esc($3) }'
    elif have rpm; then
        LC_ALL=C rpm -qa --qf '%{NAME}\t%{VERSION}-%{RELEASE}\t%{VENDOR}\n' 2>/dev/null \
        | awk -F'\t' "$AWK_ESC"' $1 != "" { if (n++) printf ","; printf "{\"name\":\"%s\",\"version\":\"%s\",\"publisher\":\"%s\",\"source\":\"rpm\"}", esc($1), esc($2), esc($3) }'
    elif have apk; then
        LC_ALL=C apk info -v 2>/dev/null \
        | awk "$AWK_ESC"' { v = $0; sub(/-[^-]*-[^-]*$/, "", v); if (n++) printf ","; printf "{\"name\":\"%s\",\"version\":\"%s\",\"publisher\":\"\",\"source\":\"apk\"}", esc(v), esc($0) }'
    elif have pacman; then
        LC_ALL=C pacman -Q 2>/dev/null \
        | awk "$AWK_ESC"' NF >= 2 { if (n++) printf ","; printf "{\"name\":\"%s\",\"version\":\"%s\",\"publisher\":\"\",\"source\":\"pacman\"}", esc($1), esc($2) }'
    fi
    printf ']'
}

# What a Mac has installed: the applications, and Homebrew if it is there.
#
# Homebrew is read out of its Cellar rather than by running brew, which refuses
# to run as root at all -- and this agent is root. The directory layout is the
# same information and needs no permission.
#
# system_profiler would list the applications with their versions in one call,
# and takes the better part of a minute to do it. Reading each Info.plist is
# less elegant and finishes while somebody is still looking at the page.
macos_packages() {
    n=0
    for prefix in /opt/homebrew /usr/local; do
        [ -d "$prefix/Cellar" ] || continue
        for formula in "$prefix"/Cellar/*/*; do
            [ -d "$formula" ] || continue
            version="$(basename "$formula")"
            name="$(basename "$(dirname "$formula")")"
            [ "$n" -eq 0 ] || printf ','
            printf '{"name":%s,"version":%s,"publisher":"Homebrew","source":"brew"}' \
                "$(jstr "$name")" "$(jstr "$version")"
            n=$(( n + 1 ))
        done
    done

    for app in /Applications/*.app /Applications/Utilities/*.app /System/Applications/*.app; do
        [ -d "$app" ] || continue
        name="$(basename "$app" .app)"
        version=""
        if have defaults; then
            version="$(defaults read "$app/Contents/Info" CFBundleShortVersionString 2>/dev/null || true)"
        fi
        publisher="$(printf '%s' "$app" | grep -q '^/System/' && printf 'Apple' || printf '')"
        [ "$n" -eq 0 ] || printf ','
        printf '{"name":%s,"version":%s,"publisher":%s,"source":"applications"}' \
            "$(jstr "$name")" "$(jstr "$version")" "$(jstr "$publisher")"
        n=$(( n + 1 ))
    done
}

# ---------------------------------------------------------------- services ---

services_json() {
    printf '"services":['
    if [ "$PLATFORM" = "macos" ]; then
        # launchctl list gives "PID Status Label". A dash for the PID means it
        # is loaded but not running, which is most of them: launchd starts a
        # daemon when something asks for it.
        LC_ALL=C launchctl list 2>/dev/null \
        | awk "
            $AWK_ESC
            NR == 1 { next }
            NF >= 3 {
                state = (\$1 ~ /^[0-9]+\$/) ? \"running\" : \"stopped\"
                if (n++) printf \",\"
                printf \"{\\\"name\\\":\\\"%s\\\",\\\"display_name\\\":\\\"\\\",\\\"state\\\":\\\"%s\\\",\\\"startup\\\":\\\"launchd\\\"}\",
                    esc(\$3), state
            }
        "
        printf ']'
        return
    fi

    if have systemctl; then
        LC_ALL=C systemctl list-units --type=service --all --no-legend --plain --no-pager 2>/dev/null \
        | awk "$AWK_ESC"'
            NF >= 4 {
                name = $1; sub(/\.service$/, "", name)
                state = ($4 == "running" || $4 == "failed" || $4 == "exited" || $4 == "dead") ? $4 : $3
                desc = ""
                for (i = 5; i <= NF; i++) desc = desc (i > 5 ? " " : "") $i
                if (n++) printf ","
                printf "{\"name\":\"%s\",\"display_name\":\"%s\",\"state\":\"%s\",\"startup\":\"%s\"}", esc(name), esc(desc), esc(state), esc($2)
            }'
    fi
    printf ']'
}

# ------------------------------------------------------------------- ports ---

ports_json() {
    printf '"ports":['
    if [ "$PLATFORM" = "macos" ]; then
        # There is no ss on macOS. lsof is there by default and says which
        # process holds the socket, which netstat -an does not.
        {
            LC_ALL=C lsof -nP -iTCP -sTCP:LISTEN 2>/dev/null
            LC_ALL=C lsof -nP -iUDP 2>/dev/null
        } | awk "
            $AWK_ESC
            NR > 0 && \$1 != \"COMMAND\" && NF >= 9 {
                proto = tolower(\$8)
                if (proto != \"tcp\" && proto != \"udp\") next
                where = \$9
                # ...->... is an established connection, not something listening.
                if (where ~ /->/) next
                port = where
                sub(/^.*:/, \"\", port)
                if (port !~ /^[0-9]+\$/) next
                addr = where
                sub(/:[^:]*\$/, \"\", addr)
                if (addr == \"*\") addr = \"0.0.0.0\"
                key = proto \":\" addr \":\" port
                if (seen[key]++) next
                if (n++) printf \",\"
                printf \"{\\\"protocol\\\":\\\"%s\\\",\\\"address\\\":\\\"%s\\\",\\\"port\\\":%d,\\\"process\\\":\\\"%s\\\"}\",
                    esc(proto), esc(addr), port, esc(\$1)
            }
        "
        printf ']'
        return
    fi

    if have ss; then
        LC_ALL=C ss -H -tulnp 2>/dev/null \
        | awk "$AWK_ESC"'
            {
                proto = $1
                local = $5
                port = local; sub(/^.*:/, "", port)
                addr = local; sub(/:[^:]*$/, "", addr)
                gsub(/^\[|\]$/, "", addr)
                proc = ""
                if (match($0, /users:\(\("[^"]+"/)) {
                    proc = substr($0, RSTART + 9, RLENGTH - 9)
                    gsub(/"/, "", proc)
                }
                if (port !~ /^[0-9]+$/) next
                key = proto ":" addr ":" port
                if (seen[key]++) next
                if (n++) printf ","
                printf "{\"protocol\":\"%s\",\"address\":\"%s\",\"port\":%d,\"process\":\"%s\"}", esc(proto), esc(addr), port, esc(proc)
            }'
    elif have netstat; then
        LC_ALL=C netstat -tulnp 2>/dev/null \
        | awk "$AWK_ESC"'
            NR > 2 && NF >= 4 {
                proto = $1
                local = $4
                port = local; sub(/^.*:/, "", port)
                addr = local; sub(/:[^:]*$/, "", addr)
                if (port !~ /^[0-9]+$/) next
                proc = $NF; sub(/^[0-9]*\//, "", proc)
                if (proc == "-") proc = ""
                key = proto ":" addr ":" port
                if (seen[key]++) next
                if (n++) printf ","
                printf "{\"protocol\":\"%s\",\"address\":\"%s\",\"port\":%d,\"process\":\"%s\"}", esc(proto), esc(addr), port, esc(proc)
            }'
    fi
    printf ']'
}

# ---------------------------------------------------------------- commands ---

# The whole vocabulary. A name that is not one of these five is refused here,
# on this machine, before anything is run -- so the worst a compromised server
# can ask for is on this list, and the three that change anything additionally
# need the machine's own consent, in MONITOR_ALLOW or MONITOR_SELF_UPDATE.
run_command() {
    name="$1"
    case "$name" in
        report_now)
            echo "Reporting."
            return 0
            ;;
        refresh_updates)
            # The one command that goes out to the machine's update sources.
            # Everything else here reads what is already on disk: the report
            # asks apt what it would upgrade, which answers from lists that may
            # be a week old. This is the equivalent of running apt-get update
            # by hand, and the package manager's own output goes on the record
            # line by line as it arrives -- a repository that cannot be reached
            # says so in its own words, which is worth more than any summary
            # this agent could write about it.
            refresh_lists || return $?
            summarise_updates
            return 0
            ;;
        update_agent)
            # Asked for rather than noticed, so it reinstalls even when the
            # versions already agree. That is the point of asking: it is how a
            # damaged agent gets repaired from the interface.
            self_update "Monitor asked for the agent to be reinstalled." force || return $?
            return 0
            ;;
        install_updates)
            allows updates || { echo "This machine was installed without --allow-updates." >&2; return 77; }
            if [ "$PLATFORM" = "macos" ]; then
                # --restart is deliberately not passed. A restart is a separate
                # permission on this machine, and softwareupdate taking one on
                # its own would go around it.
                softwareupdate -i -a 2>&1
                return $?
            fi
            # Not piped into tail, for two reasons that were both wrong here.
            #
            # A pipeline's status is the last command's, so tail succeeding
            # made a failed upgrade look like one that worked: dpkg refused to
            # set permissions on a file, apt exited 100, and Monitor recorded
            # "install_updates finished". And tail cannot print until the
            # command it is reading has finished, so nothing streamed either --
            # four minutes of silence and then the whole thing at once.
            #
            # What tail was for -- not putting a hundred thousand lines in the
            # report -- is done where the report is built instead.
            if have apt-get; then
                DEBIAN_FRONTEND=noninteractive apt-get -qq update >/dev/null 2>&1 || true
                # The same --with-new-pkgs the count is taken with, or the two
                # disagree in the way that is worst: a machine reporting an
                # update that pressing this button cannot clear. Asked for by
                # simulation first, since an apt-get without the option fails
                # the command rather than ignoring it.
                new_pkgs=
                if apt-get -s -o Debug::NoLocking=true --with-new-pkgs upgrade >/dev/null 2>&1; then
                    new_pkgs=--with-new-pkgs
                fi
                # And $APT_PHASED, for the same reason: a machine that has been
                # shown a deferred update has to be able to take it. Pressing
                # this is somebody deciding not to wait for Ubuntu's turn to
                # come round, which is theirs to decide -- nothing here installs
                # anything on its own.
                #
                # Unquoted on purpose: empty means no argument, not an empty one.
                DEBIAN_FRONTEND=noninteractive apt-get -y -qq $new_pkgs \
                    -o "$APT_PHASED" -o Dpkg::Options::=--force-confold upgrade 2>&1
            elif have dnf; then dnf -y -q upgrade 2>&1
            elif have zypper; then zypper --non-interactive update 2>&1
            elif have apk; then apk upgrade 2>&1
            elif have pacman; then pacman -Syu --noconfirm 2>&1
            else echo "No package manager this agent knows." >&2; return 1
            fi
            return $?
            ;;
        reboot)
            allows reboot || { echo "This machine was installed without --allow-reboot." >&2; return 77; }
            # A minute's grace, so this report can finish and the reason is in
            # the machine's own logs before it goes.
            if [ "$PLATFORM" = "macos" ]; then
                # macOS shutdown takes minutes, not "+1", and has no message.
                shutdown -r +1 >/dev/null 2>&1 || return 1
            else
                shutdown -r +1 "Restart requested from Monitor" >/dev/null 2>&1 \
                    || { have systemctl && systemctl reboot; }
            fi
            echo "Restarting in one minute."
            return 0
            ;;
        *)
            echo "Unknown command refused: $name" >&2
            return 1
            ;;
    esac
}

# ---------------------------------------------------------------- updating ---
#
# The agent updates itself by running the installer again, not by downloading
# agent.sh and swapping itself for it.
#
# The installer is always the newest version of the whole job -- fetch,
# sanity-check, replace atomically, re-register the schedule -- and it already
# knows how to keep this machine's settings. Doing it this way means install
# and update are one code path, so they cannot drift apart, and a bug in the
# update path is a bug somebody would have hit installing.
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
UPDATE_RETRY_FLOOR=60
UPDATE_RETRY_DEFAULT=3600

update_retry_seconds() {
    seconds="$MONITOR_UPDATE_RETRY"
    case "$seconds" in
        ''|*[!0-9]*) seconds="$UPDATE_RETRY_DEFAULT" ;;
    esac
    [ "$seconds" -lt "$UPDATE_RETRY_FLOOR" ] && seconds="$UPDATE_RETRY_FLOOR"
    printf '%s' "$seconds"
}

# The version Monitor says this machine should be running, out of the answer it
# just gave. Read from inside the "agent" object rather than by looking for
# "version" anywhere in the body.
offered_version() {
    sed -n 's/.*"agent"[[:space:]]*:[[:space:]]*{[^}]*"version"[[:space:]]*:[[:space:]]*"\([0-9][0-9.]*\)".*/\1/p' \
        "$WORK/response" 2>/dev/null | first_line
}

update_attempted_recently() {
    [ -n "$MONITOR_STATE" ] || return 1
    last="$(cat "$MONITOR_STATE/updated-at" 2>/dev/null || echo 0)"
    case "$last" in
        ''|*[!0-9]*) return 1 ;;
    esac
    [ $(( $(date +%s) - last )) -lt "$(update_retry_seconds)" ]
}

# self_update REASON [force]
#
# Returns 0 if this machine is now running the installer's idea of the agent,
# 77 if it was refused here for want of consent, and 1 if it went wrong.
self_update() {
    reason="$1"

    if [ "$MONITOR_SELF_UPDATE" != "1" ]; then
        echo "This machine was installed with --no-self-update." >&2
        log info "$reason Refused: this machine was installed with --no-self-update."
        return 77
    fi

    if [ "${2:-}" != "force" ] && update_attempted_recently; then
        log debug "$reason Not trying again yet; the last attempt was less than an hour ago."
        return 1
    fi

    have curl || { log error "$reason curl is not on this machine, so it cannot be fetched."; return 1; }

    [ -n "$MONITOR_STATE" ] && date +%s > "$MONITOR_STATE/updated-at" 2>/dev/null || true

    source="${MONITOR_URL%/}/agent/$PLATFORM/install.sh"
    installer="$WORK/install.sh"

    log info "$reason Fetching $source"
    ship_logs now || true

    insecure=""
    [ "$MONITOR_INSECURE" = "1" ] && insecure="--insecure"

    # shellcheck disable=SC2086
    if ! curl --fail --silent --show-error --location --connect-timeout 15 --max-time 120 $insecure \
        --user-agent "monitor-agent/$AGENT_VERSION" \
        --output "$installer" "$source" 2>"$WORK/curl.err"; then
        echo "Could not download the installer from $source." >&2
        log error "Could not download the installer: $(cat "$WORK/curl.err" 2>/dev/null)"
        return 1
    fi

    # A truncated download would install something that silently does half a
    # job -- the same two checks the installer makes of the agent.
    if ! head -n 1 "$installer" | grep -q '^#!/bin/sh' || ! grep -q 'monitor-agent' "$installer"; then
        echo "What came back does not look like the installer. Nothing was changed." >&2
        log error "What came back from $source does not look like the installer. Nothing was changed."
        return 1
    fi

    # No arguments. Every setting is read back out of this machine's own config
    # by the installer, so an update cannot quietly change what this machine
    # was installed to do -- least of all what it consents to being asked.
    status=0
    output="$(sh "$installer" 2>&1)" || status=$?

    # Whatever it had to say, on the record: this is the one command whose
    # output nobody can go and look at afterwards if it goes wrong.
    printf '%s\n' "$output" | while IFS= read -r line || [ -n "$line" ]; do
        [ -n "$line" ] && log info "installer: $line"
    done

    if [ "$status" -ne 0 ]; then
        echo "$output" >&2
        log error "The installer failed, exit $status. This agent is unchanged and still running."
        return 1
    fi

    # So that a run which both carried out an update_agent command and then
    # noticed the version had moved does not install the same thing twice.
    : > "$WORK/updated"

    echo "Reinstalled from $source."
    log info "Reinstalled from $source. The next run will be the new agent."
    return 0
}

# Is the agent Monitor holds a different one from this?
#
# Different, not newer: a version that went backwards is a deliberate rollback
# on the server, and a fleet that refuses to follow it is a fleet that cannot
# be rolled back.
update_if_offered() {
    offered="$(offered_version)"
    [ -n "$offered" ] || return 0
    [ "$offered" != "$AGENT_VERSION" ] || return 0
    [ "$MONITOR_SELF_UPDATE" = "1" ] || return 0

    [ -f "$WORK/updated" ] && return 0

    # Deliberately the last thing a run does. Replacing the script this process
    # is reading, and restarting the schedule that started it, are both safe
    # once there is nothing left to do -- and neither is worth the care they
    # would need in the middle of a command.
    self_update "Monitor holds $offered, this is $AGENT_VERSION." || return 0

    return 0
}

# ----------------------------------------------------------------- posting ---

# The token goes to curl down a pipe rather than in argv, so it never shows up
# in ps for every other user on the machine.
post() {
    endpoint="$1"
    body_file="$2"
    out="${3:-$WORK/response}"
    insecure=""
    [ "$MONITOR_INSECURE" = "1" ] && insecure="insecure"

    code="$(
        printf 'header = "Authorization: Bearer %s"\nheader = "X-Monitor-Token: %s"\nheader = "Content-Type: application/json"\nheader = "Accept: application/json"\n%s\n' \
            "$MONITOR_TOKEN" "$MONITOR_TOKEN" "$insecure" \
        | curl --config - \
            --silent --show-error \
            --user-agent "monitor-agent/$AGENT_VERSION" \
            --connect-timeout 15 --max-time 120 \
            --retry 2 --retry-delay 5 \
            --data-binary "@$body_file" \
            --output "$out" \
            --write-out '%{http_code}' \
            "${MONITOR_URL%/}$endpoint" 2>"$WORK/curl.err"
    )" || code="000"

    printf '%s' "$code"
}

build_report() {
    results="$1"
    {
        printf '{'
        system_json
        printf ','
        metrics_json
        collects disks    && { printf ','; disks_json; }
        collects updates  && { printf ','; updates_json; }
        collects packages && { printf ','; packages_json; }
        collects services && { printf ','; services_json; }
        collects ports    && { printf ','; ports_json; }
        printf ',"collected":['
        first=1
        for part in disks updates packages services ports; do
            if collects "$part"; then
                [ "$first" -eq 1 ] || printf ','
                printf '"%s"' "$part"
                first=0
            fi
        done
        printf ']'
        printf ',"interval_seconds":%s' "$(jnum "$MONITOR_INTERVAL")"
        printf ',"poll_seconds":%s' "$(jnum "$MONITOR_POLL")"
        # Whether this machine consents to the agent being replaced from there.
        # Monitor shows it and honours it; it cannot change it.
        printf ',"self_update":%s' "$([ "$MONITOR_SELF_UPDATE" = "1" ] && echo true || echo false)"
        # And the rest of what it consents to, so Monitor can say up front
        # which of its buttons this machine is going to refuse instead of
        # leaving somebody to find out by pressing one.
        printf ',"allow":['
        allow_first=1
        for consent in updates reboot; do
            if allows "$consent"; then
                [ "$allow_first" -eq 1 ] || printf ','
                printf '"%s"' "$consent"
                allow_first=0
            fi
        done
        printf ']'
        printf ',"results":[%s]' "$results"
        printf '}'
    } > "$WORK/report.json"

    sanitise_json "$WORK/report.json"

    if report_is_whole "$WORK/report.json"; then
        return 0
    fi

    log error "The report came out incomplete and was not sent. Run 'agent.sh --dump' on this machine to see where it stops."
    return 1
}

# Does this at least start and finish like a document?
#
# Every field in a report is gathered by running something, and a machine where
# one of those dies mid-sentence produces a file that simply stops. There is no
# JSON parser here to say more than that, and there does not need to be: the
# server refuses what it cannot read and says so, which covers everything this
# misses. What this catches is the one case worth catching on the machine --
# nothing to send at all.
report_is_whole() {
    [ -s "$1" ] || return 1

    case "$(head -c 1 "$1" 2>/dev/null)" in
        '{') ;;
        *) return 1 ;;
    esac

    case "$(tail -c 1 "$1" 2>/dev/null)" in
        '}') return 0 ;;
    esac

    return 1
}

# The schedule this agent actually runs on.
#
# Changing "report every" in Monitor has to reach the timer, or the number on
# the page is a decoration. The agent owns both files -- the installer wrote
# them -- so it edits its own schedule and nothing else.
# With the live channel on, the scheduler's only job is to keep an agent
# process alive, so it ticks every minute and the agent does its own timing
# inside. With it off there is nothing to keep alive, and the scheduler goes
# back to being the thing that decides when to report.
scheduler_cadence() {
    if [ "${MONITOR_POLL:-0}" -gt 0 ] 2>/dev/null; then
        echo 60
    else
        echo "$MONITOR_INTERVAL"
    fi
}

LAUNCHD_LABEL="dk.monitor.agent"
LAUNCHD_PLIST="/Library/LaunchDaemons/$LAUNCHD_LABEL.plist"

apply_schedule() {
    seconds="$1"

    # launchd has no equivalent of "reload with a new interval": the plist is
    # the schedule, so it is rewritten and the job put back. bootout then
    # bootstrap rather than kickstart -- StartInterval is read when the job is
    # loaded, and a running job would keep the old one.
    if [ "$PLATFORM" = "macos" ] && [ -f "$LAUNCHD_PLIST" ]; then
        tmp="$WORK/plist"
        # Only the integer on the line after StartInterval, so a plist that
        # grows another number later does not get this one written into it.
        sed "/<key>StartInterval<\/key>/{n;s|<integer>[0-9]*</integer>|<integer>${seconds}</integer>|;}" \
            "$LAUNCHD_PLIST" > "$tmp" || return 0
        cat "$tmp" > "$LAUNCHD_PLIST" 2>/dev/null || return 0
        launchctl bootout "system/$LAUNCHD_LABEL" >/dev/null 2>&1 || true
        launchctl bootstrap system "$LAUNCHD_PLIST" >/dev/null 2>&1 \
            || launchctl load -w "$LAUNCHD_PLIST" >/dev/null 2>&1 || true
        return 0
    fi

    if [ -f /etc/systemd/system/monitor-agent.timer ] && have systemctl; then
        # Restarting a timer is only safe because the unit carries an
        # OnActiveSec anchor: a restart re-arms from now. Without it, a timer
        # whose other anchors are both in the past comes back elapsed and never
        # fires again -- which is a machine that has quietly stopped reporting.
        tmp="$WORK/timer"
        sed "s/^OnUnitActiveSec=.*/OnUnitActiveSec=${seconds}s/" /etc/systemd/system/monitor-agent.timer > "$tmp" || return 0
        cat "$tmp" > /etc/systemd/system/monitor-agent.timer 2>/dev/null || return 0
        systemctl daemon-reload >/dev/null 2>&1 || true
        systemctl restart monitor-agent.timer >/dev/null 2>&1 || true
        return 0
    fi

    if [ -f /etc/cron.d/monitor-agent ]; then
        minutes=$(( seconds / 60 ))
        [ "$minutes" -lt 1 ] && minutes=1
        if [ "$minutes" -ge 60 ]; then
            schedule="0 */$(( minutes / 60 )) * * *"
        else
            schedule="*/$minutes * * * *"
        fi
        tmp="$WORK/cron"
        printf '# Monitor agent\n%s root MONITOR_CONF=%s %s --loop >/dev/null 2>&1\n' \
            "$schedule" "$CONF" "$0" > "$tmp"
        cat "$tmp" > /etc/cron.d/monitor-agent 2>/dev/null || true
    fi
}

# What came back, and what to do about it.
#
# Both cadences arrive with every answer, so a change made in the interface
# reaches the machine on its next knock instead of waiting out the old
# schedule. Anything else in the body is left alone: the agent's job is to
# report and to obey the short list, not to interpret.
handle_response() {
    body="$(cat "$WORK/response" 2>/dev/null || true)"
    changed=0

    interval="$(printf '%s' "$body" | sed -n 's/.*"interval"[[:space:]]*:[[:space:]]*\([0-9]\{1,\}\).*/\1/p' | first_line)"
    if [ -n "$interval" ] && [ "$interval" != "$MONITOR_INTERVAL" ]; then
        MONITOR_INTERVAL="$interval"
        changed=1
    fi

    poll="$(printf '%s' "$body" | sed -n 's/.*"poll"[[:space:]]*:[[:space:]]*\([0-9]\{1,\}\).*/\1/p' | first_line)"
    if [ -n "$poll" ] && [ "$poll" != "$MONITOR_POLL" ]; then
        MONITOR_POLL="$poll"
        changed=1
    fi

    if [ "$changed" -eq 1 ]; then
        log info "Monitor asked for a report every ${MONITOR_INTERVAL}s and a check every ${MONITOR_POLL}s."
        if [ -w "$CONF" ]; then
            set_setting MONITOR_INTERVAL "$MONITOR_INTERVAL"
            set_setting MONITOR_POLL "$MONITOR_POLL"
            apply_schedule "$(scheduler_cadence)"
        fi
    fi

    # How much to say is Monitor's to decide -- unlike what may be done to this
    # machine, saying less or more is not a permission -- so it arrives with
    # every answer and is kept, so a run that never reaches the server still
    # logs at the level last asked for.
    level="$(printf '%s' "$body" | sed -n 's/.*"level"[[:space:]]*:[[:space:]]*"\([a-z]\{1,8\}\)".*/\1/p' | first_line)"
    case "$level" in
        debug|info|warn|error) ;;
        *) level="" ;;
    esac
    if [ -n "$level" ] && [ "$level" != "$MONITOR_LEVEL" ]; then
        MONITOR_LEVEL="$level"
        LEVEL_FLOOR="$(level_rank "$level")"
        log info "Monitor set the log level to $level."
        [ -w "$CONF" ] && set_setting MONITOR_LEVEL "$MONITOR_LEVEL"
    fi

    # How long to leave it between update attempts is Monitor's to decide, for
    # the same reason the log level is: it changes when this machine tries, not
    # what it is willing to do. Kept in the config so a fresh process has it
    # before its first answer arrives.
    retry="$(printf '%s' "$body" | sed -n 's/.*"update_retry"[[:space:]]*:[[:space:]]*\([0-9]\{1,\}\).*/\1/p' | first_line)"
    if [ -n "$retry" ] && [ "$retry" != "$MONITOR_UPDATE_RETRY" ]; then
        MONITOR_UPDATE_RETRY="$retry"
        log info "Monitor set the update retry to $(update_retry_seconds)s."
        [ -w "$CONF" ] && set_setting MONITOR_UPDATE_RETRY "$MONITOR_UPDATE_RETRY"
    fi

    case "$body" in
        *'"status":"disabled"'*)
            echo "monitor-agent: this machine is switched off in Monitor. Nothing to do."
            log warn "Switched off in Monitor. Nothing to do."
            return 1
            ;;
    esac

    return 0
}

# Change one setting in the config file, adding it if it is not there yet.
#
# An agent upgraded in place reads a config written by an older installer, so a
# plain sed substitution would quietly do nothing for any setting that did not
# exist when the machine was first set up.
set_setting() {
    key="$1"
    value="$2"
    tmp="$WORK/conf"

    if grep -q "^${key}=" "$CONF" 2>/dev/null; then
        sed "s|^${key}=.*|${key}=\"${value}\"|" "$CONF" > "$tmp" && cat "$tmp" > "$CONF"
    else
        printf '%s="%s"\n' "$key" "$value" >> "$CONF"
    fi
}

# The queued commands, one "uuid command" per line.
queued_commands() {
    tr ',' '\n' < "$WORK/response" 2>/dev/null \
    | sed -n 's/.*"id"[[:space:]]*:[[:space:]]*"\([0-9a-fA-F-]\{36\}\)".*/ID \1/p; s/.*"command"[[:space:]]*:[[:space:]]*"\([a-z_]\{1,40\}\)".*/CMD \1/p' \
    | awk '/^ID / { id = $2 } /^CMD / { if (id != "") { print id, $2; id = "" } }'
}

# Did the server ask for a full report on this knock?
report_wanted() {
    grep -q '"report"[[:space:]]*:[[:space:]]*true' "$WORK/response" 2>/dev/null
}

# Carry out whatever the last answer was carrying, and print the results as a
# JSON fragment for the report that follows.
run_queue() {
    commands="$(queued_commands)"
    [ -n "$commands" ] || return 0

    printf '%s\n' "$commands" > "$WORK/queue"

    results=""
    while IFS=' ' read -r id name; do
        [ -n "${id:-}" ] && [ -n "${name:-}" ] || continue

        log info "Running $name." "$id"
        ship_logs now || true

        status=0
        stream_command "$id" "$name" || status=$?
        # The last of it, not all of it: every line went to the log as it
        # happened, and the report only has to carry enough to say how it
        # ended. An upgrade can print thousands.
        output="$(tail -n 40 "$WORK/cmd.out" 2>/dev/null || true)"

        ok=false
        if [ "$status" -eq 0 ]; then
            ok=true
            log info "$name finished." "$id"
        elif [ "$status" -eq 77 ]; then
            # Refused here, by this machine, for want of consent. Worth saying
            # plainly: from Monitor's side it looks the same as a failure, and
            # it is not one.
            log warn "$name refused: this machine was not installed to allow it." "$id"
        else
            log warn "$name failed, exit $status." "$id"
        fi
        ship_logs now || true

        [ -n "$results" ] && results="$results,"
        results="$results{\"id\":$(jstr "$id"),\"ok\":$ok,\"exit_code\":$status,\"output\":$(jtext "$output"),\"error\":$(jstr "$([ "$status" -eq 0 ] || printf 'exit %s' "$status")")}"
    done < "$WORK/queue"

    printf '%s' "$results"
}

# Run one command, putting every line it produces on the record as it appears,
# and keeping the whole of it for the report that follows.
#
# Streaming is the reason this exists rather than a plain command substitution:
# installing updates takes minutes, and a progress line arriving while it
# happens is the difference between watching and wondering.
#
# The pipe puts the loop in a subshell, so the command's exit status comes back
# through a file. POSIX shell has no PIPESTATUS.
stream_command() {
    : > "$WORK/cmd.out"
    : > "$WORK/cmd.status"

    # The || is what keeps set -e out of this: without it a command that
    # exits non-zero takes the subshell with it before the status is written,
    # and every failure arrives as a bare 1. apt-get returning 100 for a
    # repository it could not reach is worth more than that.
    { status=0; run_command "$2" 2>&1 || status=$?; printf '%s' "$status" > "$WORK/cmd.status"; } \
    | while IFS= read -r line || [ -n "$line" ]; do
        printf '%s\n' "$line" >> "$WORK/cmd.out"
        log info "$line" "$1"
        ship_logs || true
    done

    status="$(cat "$WORK/cmd.status" 2>/dev/null || true)"
    case "$status" in
        ''|*[!0-9]*) status=1 ;;
    esac

    return "$status"
}

# ---------------------------------------------------------------- enrolling ---

# First contact. The enrolment key is spent here and never stored: what gets
# written to the config is the token the site issues in exchange, which belongs
# to this machine alone and can be revoked without touching any other.
enroll() {
    [ -n "${MONITOR_ENROLL_KEY:-}" ] || { echo "monitor-agent: no enrolment key given." >&2; return 2; }

    MONITOR_TOKEN="$MONITOR_ENROLL_KEY"
    build_report "" || return 1
    code="$(post "/api/agent/enroll" "$WORK/report.json")"

    if [ "$code" != "201" ] && [ "$code" != "200" ]; then
        case "$code" in
            401) echo "monitor-agent: the enrolment key was refused. It may be expired, revoked or used up." >&2 ;;
            429) echo "monitor-agent: too many enrolment attempts from this address. Try again shortly." >&2 ;;
            000) echo "monitor-agent: could not reach ${MONITOR_URL%/}. $(cat "$WORK/curl.err" 2>/dev/null)" >&2 ;;
            *)   echo "monitor-agent: enrolment failed, server answered $code." >&2 ;;
        esac
        return 1
    fi

    token="$(sed -n 's/.*"token"[[:space:]]*:[[:space:]]*"\(mdt_[0-9a-f]\{1,\}\)".*/\1/p' "$WORK/response" | first_line)"
    device="$(sed -n 's/.*"device_id"[[:space:]]*:[[:space:]]*"\([0-9a-f-]\{36\}\)".*/\1/p' "$WORK/response" | first_line)"

    if [ -z "$token" ]; then
        echo "monitor-agent: enrolment answered without a token." >&2
        return 1
    fi

    MONITOR_TOKEN="$token"
    MONITOR_DEVICE_ID="$device"
    write_config
    echo "monitor-agent: enrolled as $device"
    log info "Enrolled with ${MONITOR_URL%/} as $device."
    return 0
}

# The config is rewritten whole, through a temporary file in the same
# directory, so a crash halfway cannot leave a machine holding half a token.
write_config() {
    dir="$(dirname "$CONF")"
    [ -d "$dir" ] || mkdir -p "$dir"
    tmp="$dir/.agent.conf.$$"
    (
        umask 077
        cat > "$tmp" <<CONFEOF
# Monitor agent configuration. Written by the installer.
#
# MONITOR_TOKEN is this machine's identity. Anybody holding it can post reports
# as this machine, so the file is kept at mode 600 and owned by root. If it
# leaks, revoke the machine in Monitor and run the installer again.
MONITOR_URL="$MONITOR_URL"
MONITOR_TOKEN="$MONITOR_TOKEN"
MONITOR_DEVICE_ID="$MONITOR_DEVICE_ID"
MONITOR_INTERVAL="$MONITOR_INTERVAL"

# How often to ask whether a command is waiting. This is the live channel: one
# small request, usually answered with "nothing". Set it to 0 and the agent
# only ever speaks when it reports, which is quieter but means a queued command
# waits for the next report.
MONITOR_POLL="$MONITOR_POLL"

# Which of the optional lists to gather. Drop one from here and it stops being
# collected and stops being shown -- packages is the expensive one.
MONITOR_COLLECT="$MONITOR_COLLECT"

# How much the agent has to say for itself. 'debug' includes every poll, which
# is a great deal of nothing most of the time; 'info' records what it actually
# does. Monitor can lower or raise this from the interface.
MONITOR_LEVEL="$MONITOR_LEVEL"

# What this machine consents to being asked to do. Empty means Monitor may ask
# it questions but never change it. Add 'updates' and/or 'reboot' to allow more.
MONITOR_ALLOW="$MONITOR_ALLOW"

# Whether the agent may replace itself with the version Monitor holds. This is
# the machine's own consent to running code from that server, so it is decided
# here and cannot be granted from there. Set it to 0 and updates arrive by
# running the installer again, by hand.
MONITOR_SELF_UPDATE="$MONITOR_SELF_UPDATE"

# How long to leave it between attempts at replacing this agent. Monitor sets
# this from Settings -> Agent and it arrives with every answer; what is here is
# what the last answer said, so a fresh process starts with it.
MONITOR_UPDATE_RETRY="$MONITOR_UPDATE_RETRY"

# Skip TLS verification. Only for a Monitor install using a self-signed
# certificate, and it does mean the token can be read by anything in the path.
MONITOR_INSECURE="$MONITOR_INSECURE"
CONFEOF
    )
    chmod 600 "$tmp"
    mv "$tmp" "$CONF"
}

# Whether a full report is owed regardless of the schedule.
#
# Two things make everything this agent knows worth saying again straight away
# rather than at the next interval: the machine has restarted, so the uptime,
# the kernel and whatever was pending across the reboot are all stale; and the
# agent has been replaced, so what it can see may have changed and the version
# on its own page is wrong until it says otherwise.
#
# Both come out of one comparison. The stamp is written after a report gets
# through, not before, so a machine that cannot reach the server keeps owing
# the report rather than losing it.
RUN_STAMP=""
[ -n "$MONITOR_STATE" ] && RUN_STAMP="$MONITOR_STATE/last-run"

report_owed() {
    [ -n "$RUN_STAMP" ] || return 1

    want="$(boot_epoch) $AGENT_VERSION"
    last="$(cat "$RUN_STAMP" 2>/dev/null || true)"
    [ "$last" = "$want" ] && return 1

    case "$last" in
        '') echo "this agent has not reported from here before" ;;
        "${want% *} "*) echo "the agent is now $AGENT_VERSION" ;;
        *) echo "this machine has restarted" ;;
    esac
}

mark_reported() {
    [ -n "$RUN_STAMP" ] || return 0
    printf '%s %s\n' "$(boot_epoch)" "$AGENT_VERSION" > "$RUN_STAMP" 2>/dev/null || true
}

# ------------------------------------------------------------------- main ---

# Exit codes are how the installer tells "this machine is not who it says it
# is" from "this did not get through". They are not the same thing, and acting
# on the second as though it were the first spends a use of an enrolment key
# and leaves a second row in Monitor for the same computer.
#
#   0  reported
#   2  the token was refused -- this machine has to enrol again
#   3  the server could not read the report -- the token is fine
#   1  anything else: unreachable, or an answer nobody expected
run_once() {
    results="$1"
    build_report "$results" || return 1
    code="$(post "/api/agent/report" "$WORK/report.json")"

    case "$code" in
        200)
            log debug "Reported."
            mark_reported
            handle_response || return 0
            ;;
        400)
            # The server could not read what was sent. Almost always this
            # agent's fault rather than the machine's, and worth saying so in
            # the words that lead somewhere.
            echo "monitor-agent: the server could not read this report. Run 'agent.sh --dump' here and check it is whole." >&2
            log error "The server could not read this report: $(cut -c 1-200 < "$WORK/response" 2>/dev/null)"
            return 3
            ;;
        401)
            echo "monitor-agent: this machine's token was refused. It may have been revoked; re-run the installer to enrol again." >&2
            log error "Reporting was refused: this machine's token is not accepted. Re-run the installer."
            return 2
            ;;
        000)
            echo "monitor-agent: could not reach ${MONITOR_URL%/}. $(cat "$WORK/curl.err" 2>/dev/null)" >&2
            log warn "Could not reach ${MONITOR_URL%/} to report."
            return 1
            ;;
        *)
            echo "monitor-agent: server answered $code." >&2
            log warn "Reporting answered $code."
            return 1
            ;;
    esac

    return 0
}

# One knock on the live channel. Cheap enough to do every few seconds: an
# empty body out, a sentence back.
poll_once() {
    printf '{}' > "$WORK/poll.json"
    code="$(post "/api/agent/poll" "$WORK/poll.json")"

    case "$code" in
        200) return 0 ;;
        401)
            echo "monitor-agent: this machine's token was refused. It may have been revoked; re-run the installer to enrol again." >&2
            log error "The live channel was refused: this machine's token is not accepted. Re-run the installer."
            return 2
            ;;
        000)
            echo "monitor-agent: could not reach ${MONITOR_URL%/}. $(cat "$WORK/curl.err" 2>/dev/null)" >&2
            log debug "Could not reach ${MONITOR_URL%/} on the live channel."
            return 1
            ;;
        *)
            echo "monitor-agent: server answered $code." >&2
            log debug "The live channel answered $code."
            return 1
            ;;
    esac
}

# Do whatever the answer on the live channel called for.
act_on_answer() {
    handle_response || return 1          # switched off here: stop for this run

    results="$(run_queue)"
    if [ -n "$results" ]; then
        # Something ran. Report straight away, so the outcome and the picture
        # it produced arrive together rather than a poll apart.
        run_once "$results" || true
    elif report_wanted; then
        run_once "" || true
    fi

    return 0
}

# Stay alive for just under a minute, asking every few seconds whether anything
# is waiting, then exit and let the scheduler start a fresh one.
#
# This is the same trick the server's own scheduler uses, and it is what makes
# the live channel need no daemon, no service and no supervisor: a crash costs
# at most one minute, and the process is never long-lived enough to leak.
loop() {
    # Live channel off. There is nothing to keep alive, so behave the way this
    # agent always did: one report if one is due, then leave.
    if [ "${MONITOR_POLL:-0}" -le 0 ] 2>/dev/null; then
        run_once ""
        return $?
    fi

    # Before any of the knocking: if this is the first run since the machine
    # started or since the agent changed, say everything now. On the live
    # channel the alternative is a machine that has just come back describing
    # itself as it was before it went.
    owed="$(report_owed || true)"
    if [ -n "$owed" ]; then
        log info "Reporting in full: $owed."
        run_once "" || true
    fi

    deadline=$(( $(date +%s) + LOOP_SECONDS ))

    while :; do
        poll_once
        outcome=$?

        # A refused token will not start working within the minute. Anything
        # else might, so keep knocking until the deadline.
        [ "$outcome" -eq 2 ] && return 1
        [ "$outcome" -eq 0 ] && { act_on_answer || return 0; }

        # The answer may have switched the live channel off. Leave now rather
        # than spending the rest of the minute knocking -- with a cadence of
        # zero the wait below would floor to one second, which would turn
        # "stop polling" into a burst of it.
        [ "${MONITOR_POLL:-0}" -le 0 ] 2>/dev/null && break

        remaining=$(( deadline - $(date +%s) ))
        [ "$remaining" -le 0 ] && break

        wait="$MONITOR_POLL"
        [ "$wait" -gt "$remaining" ] && wait="$remaining"
        [ "$wait" -lt 1 ] && wait=1
        sleep "$wait"
    done

    return 0
}

log_setup
log debug "monitor-agent $AGENT_VERSION starting${MODE:+ }$MODE."

STATUS=0

case "${1:-}" in
    --version) echo "monitor-agent $AGENT_VERSION"; exit 0 ;;
    --enroll) enroll || STATUS=$? ;;
    # Prints the report instead of sending it. Nothing leaves the machine, and
    # it is the fastest way to see what this agent can actually read here.
    --dump) build_report "" || true; cat "$WORK/report.json"; echo; exit 0 ;;
    # One knock on the live channel, printed. For working out why a command is
    # not arriving.
    --poll) if poll_once; then cat "$WORK/response"; echo; else STATUS=$?; fi ;;
    --once) run_once "" || STATUS=$? ;;
    --loop|"") loop || STATUS=$? ;;
    *) echo "usage: $0 [--loop|--once|--poll|--enroll|--dump|--version]" >&2; exit 2 ;;
esac

# Only now, with the work of this run behind it. The installer re-registers the
# schedule and replaces this very file, so the agent that comes back a minute
# from now is the new one; nothing here has to survive being swapped out.
update_if_offered || true

# Whatever is still in the outbox goes now rather than waiting for the next
# run: a machine that reports every five minutes should not take five minutes
# to say that something went wrong. If this fails too, the lines keep.
ship_logs now || true

exit "$STATUS"
