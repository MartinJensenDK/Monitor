#!/bin/sh
# Installs the Monitor agent on this machine.
#
#   sh install.sh --key mek_...
#
# Puts the agent in /usr/local/lib/monitor-agent, writes a config readable only
# by root, exchanges the enrolment key for a token belonging to this machine,
# and schedules a report every few minutes with systemd or cron.
#
# By default the agent only reports. Nothing on this machine can be changed
# from Monitor unless you pass --allow-updates or --allow-reboot below, and
# that decision is recorded here, on the machine, not there.
#
#   --key KEY           the enrolment key from Monitor (first time only)
#   --url URL           the Monitor address (default: the site this came from)
#   --interval SECONDS  how often to send a full report (default 300, min 60)
#   --poll SECONDS      how often to check for queued commands (default 15)
#   --no-live           do not check between reports; commands wait for one
#   --collect LIST      of disks,updates,packages,services,ports
#   --level LEVEL       how much the agent says: error, warn, info or debug
#   --allow-updates     let Monitor install updates when asked
#   --allow-reboot      let Monitor restart this machine when asked
#   --no-allow-updates  take that permission back
#   --no-allow-reboot   take that permission back
#   --no-self-update    do not let the agent replace itself from Monitor
#   --self-update       let it again
#   --insecure          skip TLS verification (self-signed certificates only)
#   --no-insecure       verify certificates again
#   --force             enrol again even if this machine already has a token
#   --uninstall         remove the agent and stop reporting
#
# Safe to run twice. If this machine already holds a token that still works, the
# installer keeps it and repairs whatever else is missing rather than enrolling
# the same computer a second time.
#
# A second run also keeps the settings of the first. This is the same script the
# agent runs to update itself, so a run with no arguments has to come out the
# other side the way it went in: anything not named on the command line is read
# back out of the config and written again unchanged. Otherwise an update would
# quietly hand back a permission this machine was deliberately installed
# without. Say --no-allow-updates to actually take one away.

set -eu

# Where a machine that has never been installed starts from. The address is the
# site this script was downloaded from, baked in as it was served.
DEFAULT_URL="__MONITOR_URL__"
DEFAULT_INTERVAL="300"
DEFAULT_POLL="15"
DEFAULT_COLLECT="disks,updates,packages,services,ports"
DEFAULT_LEVEL="info"
DEFAULT_SELF_UPDATE="1"

# What was asked for on the command line. Empty means "not asked for", which is
# a different thing from "asked for nothing" -- the difference between leaving a
# setting alone and clearing it, which is the whole point of the exercise. The
# two ARG_..._SET flags carry that distinction for the values where empty is
# itself a legitimate answer.
KEY=""
ARG_URL=""
ARG_INTERVAL=""
ARG_POLL=""
ARG_COLLECT=""
ARG_COLLECT_SET="0"
ARG_LEVEL=""
ARG_UPDATES=""
ARG_REBOOT=""
ARG_SELF_UPDATE=""
ARG_INSECURE=""
FORCE="0"
UNINSTALL="0"

LIB_DIR="/usr/local/lib/monitor-agent"
CONF_DIR="/etc/monitor-agent"
CONF="$CONF_DIR/agent.conf"
AGENT="$LIB_DIR/agent.sh"
UNIT="/etc/systemd/system/monitor-agent.service"
TIMER="/etc/systemd/system/monitor-agent.timer"
CRON="/etc/cron.d/monitor-agent"

say() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --key) KEY="${2:-}"; shift 2 ;;
        --key=*) KEY="${1#--key=}"; shift ;;
        --url) ARG_URL="${2:-}"; shift 2 ;;
        --url=*) ARG_URL="${1#--url=}"; shift ;;
        --interval) ARG_INTERVAL="${2:-}"; shift 2 ;;
        --interval=*) ARG_INTERVAL="${1#--interval=}"; shift ;;
        --poll) ARG_POLL="${2:-}"; shift 2 ;;
        --poll=*) ARG_POLL="${1#--poll=}"; shift ;;
        --no-live) ARG_POLL="0"; shift ;;
        --collect) ARG_COLLECT="${2:-}"; ARG_COLLECT_SET="1"; shift 2 ;;
        --collect=*) ARG_COLLECT="${1#--collect=}"; ARG_COLLECT_SET="1"; shift ;;
        --level) ARG_LEVEL="${2:-}"; shift 2 ;;
        --level=*) ARG_LEVEL="${1#--level=}"; shift ;;
        --allow-updates) ARG_UPDATES="1"; shift ;;
        --allow-reboot) ARG_REBOOT="1"; shift ;;
        --no-allow-updates) ARG_UPDATES="0"; shift ;;
        --no-allow-reboot) ARG_REBOOT="0"; shift ;;
        --self-update) ARG_SELF_UPDATE="1"; shift ;;
        --no-self-update) ARG_SELF_UPDATE="0"; shift ;;
        --insecure) ARG_INSECURE="1"; shift ;;
        --no-insecure) ARG_INSECURE="0"; shift ;;
        --force) FORCE="1"; shift ;;
        --uninstall) UNINSTALL="1"; shift ;;
        # Printed from the comment block at the top, so there is one copy of it
        # rather than a duplicate here that drifts out of date.
        -h|--help) awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"; exit 0 ;;
        *) die "unknown option: $1" ;;
    esac
done

[ "$(id -u)" = "0" ] || die "run this as root: the config must be readable by root only, and the scheduler needs to be installed."

remove_schedule() {
    if command -v systemctl >/dev/null 2>&1; then
        systemctl disable --now monitor-agent.timer >/dev/null 2>&1 || true
    fi
    rm -f "$UNIT" "$TIMER" "$CRON"
    command -v systemctl >/dev/null 2>&1 && systemctl daemon-reload >/dev/null 2>&1 || true
}

if [ "$UNINSTALL" = "1" ]; then
    remove_schedule
    rm -rf "$LIB_DIR"
    say "Agent removed. The config is left at $CONF in case you want the token back;"
    say "delete it yourself, and revoke the machine in Monitor so the token stops working."
    exit 0
fi

# ------------------------------------------------------- what is already here ---

# One setting, read out of the config as it stands.
conf_value() {
    sed -n "s/^$1=\"\(.*\)\"[[:space:]]*\$/\1/p" "$CONF" 2>/dev/null | head -n 1
}

# A config may have been hand-edited, or written by an installer old enough not
# to have known about a setting. A value there that makes no sense is dropped in
# favour of the default rather than being fatal -- this script is how a machine
# updates itself, and it must not be stopped by a setting it is about to
# rewrite. A nonsensical value on the command line still stops it: that is
# somebody making a mistake, and they should hear about it.
sane_seconds() {
    case "${1:-}" in
        ''|*[!0-9]*) return 0 ;;
    esac
    [ "$1" -ge "$2" ] || return 0
    printf '%s' "$1"
}

sane_level() {
    case "${1:-}" in
        error|warn|info|debug) printf '%s' "$1" ;;
    esac
}

sane_flag() {
    case "${1:-}" in
        0|1) printf '%s' "$1" ;;
    esac
}

# The token is what makes a second run a repair rather than a duplicate:
# everything after enrolment can fail, and a machine left enrolled but
# unscheduled has to be fixable without spending another use of the key.
EXISTING_TOKEN=""
EXISTING_DEVICE=""
if [ "$FORCE" = "0" ] && [ -r "$CONF" ]; then
    EXISTING_TOKEN=$(sed -n 's/^MONITOR_TOKEN="\(mdt_[0-9a-f]\{1,\}\)"$/\1/p' "$CONF" | head -n 1)
    EXISTING_DEVICE=$(sed -n 's/^MONITOR_DEVICE_ID="\([0-9a-f-]\{36\}\)"$/\1/p' "$CONF" | head -n 1)
fi

# The settings are read whether or not --force was given: a new identity is
# still the same machine, with the same opinion about what may be done to it.
CONF_SEEN="0"
CONF_URL=""; CONF_INTERVAL=""; CONF_POLL=""; CONF_COLLECT=""
CONF_LEVEL=""; CONF_ALLOW=""; CONF_SELF_UPDATE=""; CONF_INSECURE=""
if [ -r "$CONF" ] && [ -n "$(conf_value MONITOR_URL)" ]; then
    CONF_SEEN="1"
    CONF_URL="$(conf_value MONITOR_URL)"
    CONF_INTERVAL="$(sane_seconds "$(conf_value MONITOR_INTERVAL)" 60)"
    CONF_POLL="$(conf_value MONITOR_POLL)"
    CONF_COLLECT="$(conf_value MONITOR_COLLECT)"
    CONF_LEVEL="$(sane_level "$(conf_value MONITOR_LEVEL)")"
    CONF_ALLOW="$(conf_value MONITOR_ALLOW)"
    CONF_SELF_UPDATE="$(sane_flag "$(conf_value MONITOR_SELF_UPDATE)")"
    CONF_INSECURE="$(sane_flag "$(conf_value MONITOR_INSECURE)")"

    # Zero is a legitimate poll -- it means the live channel is off -- so it
    # cannot go through sane_seconds with a floor of 5.
    if [ "$CONF_POLL" != "0" ]; then
        CONF_POLL="$(sane_seconds "$CONF_POLL" 5)"
    fi
fi

# Asked for, then already here, then the default.
MONITOR_URL="${ARG_URL:-${CONF_URL:-$DEFAULT_URL}}"
INTERVAL="${ARG_INTERVAL:-${CONF_INTERVAL:-$DEFAULT_INTERVAL}}"
POLL="${ARG_POLL:-${CONF_POLL:-$DEFAULT_POLL}}"
LEVEL="${ARG_LEVEL:-${CONF_LEVEL:-$DEFAULT_LEVEL}}"
SELF_UPDATE="${ARG_SELF_UPDATE:-${CONF_SELF_UPDATE:-$DEFAULT_SELF_UPDATE}}"
INSECURE="${ARG_INSECURE:-${CONF_INSECURE:-0}}"

# Collecting nothing is a real answer, so this one cannot lean on emptiness.
if [ "$ARG_COLLECT_SET" = "1" ]; then
    COLLECT="$ARG_COLLECT"
elif [ "$CONF_SEEN" = "1" ]; then
    COLLECT="$CONF_COLLECT"
else
    COLLECT="$DEFAULT_COLLECT"
fi

# Neither permission is ever granted by the absence of an argument. Each one is
# whatever this machine already consented to, unless this run says otherwise.
UPDATES="0"
REBOOT="0"
case ",$CONF_ALLOW," in *,updates,*) UPDATES="1" ;; esac
case ",$CONF_ALLOW," in *,reboot,*) REBOOT="1" ;; esac
[ -n "$ARG_UPDATES" ] && UPDATES="$ARG_UPDATES"
[ -n "$ARG_REBOOT" ] && REBOOT="$ARG_REBOOT"

ALLOW=""
[ "$UPDATES" = "1" ] && ALLOW="updates"
[ "$REBOOT" = "1" ] && ALLOW="${ALLOW:+$ALLOW,}reboot"

if [ -z "$KEY" ] && [ -z "$EXISTING_TOKEN" ]; then
    die "no enrolment key. Get one from Monitor under Servers or Clients, then pass --key mek_..."
fi
[ -n "$MONITOR_URL" ] || die "no Monitor address. Pass --url https://monitor.example.com"

command -v curl >/dev/null 2>&1 || die "curl is required and was not found. Install it and run this again."

case "$INTERVAL" in
    ''|*[!0-9]*) die "--interval must be a number of seconds." ;;
esac
[ "$INTERVAL" -ge 60 ] || die "--interval must be at least 60 seconds."

case "$POLL" in
    ''|*[!0-9]*) die "--poll must be a number of seconds, or 0 to switch the live channel off." ;;
esac
if [ "$POLL" -gt 0 ] && [ "$POLL" -lt 5 ]; then
    die "--poll must be at least 5 seconds. Anything faster is more load than it is worth."
fi

case "$LEVEL" in
    error|warn|info|debug) ;;
    *) die "--level must be one of error, warn, info or debug." ;;
esac

case "$MONITOR_URL" in
    https://*) ;;
    http://*) say "warning: $MONITOR_URL is not encrypted. This machine's token will cross the network in the clear." ;;
    *) die "--url must start with http:// or https://" ;;
esac

say "Installing the Monitor agent from ${MONITOR_URL%/}"

mkdir -p "$LIB_DIR" "$CONF_DIR"
chmod 755 "$LIB_DIR"
chmod 700 "$CONF_DIR"

curl_opts="--fail --silent --show-error --location --connect-timeout 15 --max-time 60"
[ "$INSECURE" = "1" ] && curl_opts="$curl_opts --insecure"

# shellcheck disable=SC2086
curl $curl_opts --output "$AGENT.new" "${MONITOR_URL%/}/agent/linux/agent.sh" \
    || die "could not download the agent from ${MONITOR_URL%/}/agent/linux/agent.sh"

# A truncated download would install something that silently does half a job.
head -n 1 "$AGENT.new" | grep -q '^#!/bin/sh' || die "the downloaded agent does not look like a shell script."
grep -q 'monitor-agent' "$AGENT.new" || die "the downloaded agent does not look right."

mv "$AGENT.new" "$AGENT"
chmod 755 "$AGENT"
chown root:root "$AGENT" 2>/dev/null || true

# The config is rewritten whole, from the values settled on above: whatever was
# asked for on the command line, and whatever this machine already had for
# everything that was not. The enrolment key is passed in the environment and
# never written down -- it is spent once and has no business surviving here.
#
# The same file the agent writes at enrolment, comments and all, so a config
# looks the same however it was last written.
umask 077
cat > "$CONF.new" <<CONFEOF
# Monitor agent configuration. Written by the installer.
#
# MONITOR_TOKEN is this machine's identity. Anybody holding it can post reports
# as this machine, so the file is kept at mode 600 and owned by root. If it
# leaks, revoke the machine in Monitor and run the installer again.
MONITOR_URL="${MONITOR_URL%/}"
MONITOR_TOKEN="$EXISTING_TOKEN"
MONITOR_DEVICE_ID="$EXISTING_DEVICE"
MONITOR_INTERVAL="$INTERVAL"

# How often to ask whether a command is waiting. This is the live channel: one
# small request, usually answered with "nothing". Set it to 0 and the agent
# only ever speaks when it reports, which is quieter but means a queued command
# waits for the next report.
MONITOR_POLL="$POLL"

# Which of the optional lists to gather. Drop one from here and it stops being
# collected and stops being shown -- packages is the expensive one.
MONITOR_COLLECT="$COLLECT"

# How much the agent has to say for itself. 'debug' includes every poll, which
# is a great deal of nothing most of the time; 'info' records what it actually
# does. Monitor can lower or raise this from the interface.
MONITOR_LEVEL="$LEVEL"

# What this machine consents to being asked to do. Empty means Monitor may ask
# it questions but never change it. Add 'updates' and/or 'reboot' to allow more.
MONITOR_ALLOW="$ALLOW"

# Whether the agent may replace itself with the version Monitor holds. This is
# the machine's own consent to running code from that server, so it is decided
# here and cannot be granted from there. Set it to 0 and updates arrive by
# running the installer again, by hand.
MONITOR_SELF_UPDATE="$SELF_UPDATE"

# Skip TLS verification. Only for a Monitor install using a self-signed
# certificate, and it does mean the token can be read by anything in the path.
MONITOR_INSECURE="$INSECURE"
CONFEOF
chmod 600 "$CONF.new"
mv "$CONF.new" "$CONF"
chown root:root "$CONF" 2>/dev/null || true

ENROLLED="0"
if [ -n "$EXISTING_TOKEN" ]; then
    say "Found an existing token. Checking whether it still works..."

    CHECK="0"
    MONITOR_CONF="$CONF" "$AGENT" --once >/dev/null 2>&1 || CHECK=$?

    case "$CHECK" in
        0)
            ENROLLED="1"
            REPORTED="1"
            say "  it does. Keeping this machine as it already is in Monitor."
            ;;
        2)
            say "  it does not. Enrolling afresh."
            ;;
        *)
            # The report did not get through, which is not the same as the
            # token being refused: the machine may be off the network, or the
            # server may not have been able to read what it sent. Enrolling on
            # the strength of that would spend a use of the key and leave a
            # second row in Monitor for this same computer.
            ENROLLED="1"
            say "  it could not say -- the report did not get through."
            say "  Keeping the token this machine already has rather than enrolling it twice."
            say "  If it never reports, run:  $AGENT --once"
            ;;
    esac
fi

if [ "$ENROLLED" = "0" ] && [ -z "$KEY" ]; then
    die "this machine's saved token no longer works, and no enrolment key was given. Get one from Monitor and run this again with --key mek_..."
fi

if [ "$ENROLLED" = "0" ]; then
    say "Enrolling..."
    MONITOR_ENROLL_KEY="$KEY" MONITOR_CONF="$CONF" "$AGENT" --enroll \
        || die "enrolment failed. Nothing was scheduled; fix the problem and run this again."
fi

remove_schedule

# With the live channel on, the scheduler only has to keep an agent process
# alive, so it ticks every minute and the agent does its own timing inside.
# With it off there is nothing to keep alive, and the scheduler goes back to
# deciding when to report.
if [ "$POLL" -gt 0 ]; then
    CADENCE=60
    JITTER=5
else
    CADENCE="$INTERVAL"
    JITTER=30
fi

if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
    cat > "$UNIT" <<UNITEOF
[Unit]
Description=Monitor agent
Documentation=${MONITOR_URL%/}
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
# Named rather than left to the agent's default, so the unit says which config
# it runs against instead of relying on the two paths happening to agree.
Environment=MONITOR_CONF=$CONF
ExecStart=$AGENT --loop
# The agent reads hardware details and the package manager, so it runs as root.
# Everything below narrows what that actually means: it cannot write anywhere
# except the places it needs, and it cannot gain privileges it was not given.
NoNewPrivileges=yes
PrivateTmp=yes
ProtectHome=yes
ProtectControlGroups=yes
ProtectKernelTunables=yes
RestrictSUIDSGID=yes
ReadWritePaths=$CONF_DIR
TimeoutStartSec=300
UNITEOF

    cat > "$TIMER" <<TIMEREOF
[Unit]
Description=Report to Monitor every $INTERVAL seconds

[Timer]
OnBootSec=30
OnUnitActiveSec=${CADENCE}s
# Spread the fleet out, so a hundred machines do not all arrive at once. Kept
# small while the live channel is on, because the gap between one agent process
# exiting and the next starting is silence, and silence is what this is
# measured by.
RandomizedDelaySec=${JITTER}
AccuracySec=1s
Persistent=true

[Install]
WantedBy=timers.target
TIMEREOF

    chmod 644 "$UNIT" "$TIMER"
    systemctl daemon-reload
    systemctl enable --now monitor-agent.timer >/dev/null
    say "Scheduled with systemd. Check it with: systemctl list-timers monitor-agent.timer"
else
    # Cron has a one-minute floor, so a shorter interval is rounded up to it.
    minutes=$(( CADENCE / 60 ))
    [ "$minutes" -lt 1 ] && minutes=1
    if [ "$minutes" -ge 60 ]; then
        schedule="0 */$(( minutes / 60 )) * * *"
    else
        schedule="*/$minutes * * * *"
    fi
    printf '# Monitor agent\n%s root MONITOR_CONF=%s %s --loop >/dev/null 2>&1\n' \
        "$schedule" "$CONF" "$AGENT" > "$CRON"
    chmod 644 "$CRON"
    say "Scheduled with cron in $CRON"
fi

if [ "${REPORTED:-0}" = "0" ]; then
    say "Sending the first report..."
    MONITOR_CONF="$CONF" "$AGENT" --once \
        || say "The first report did not go through. The schedule is in place; it will try again."
fi

say ""
say "Done. This machine now appears in Monitor."
say "  config     $CONF (root only)"
say "  agent      $AGENT"
say "  reports    every ${INTERVAL}s"
if [ "$POLL" -gt 0 ]; then
    say "  commands   checked for every ${POLL}s"
else
    say "  commands   only collected when it reports (--no-live)"
fi
if [ -z "$ALLOW" ]; then
    say "  commands   report and refresh only; nothing here can be changed from Monitor"
else
    say "  commands   report, refresh, and: $ALLOW"
fi
if [ "$SELF_UPDATE" = "1" ]; then
    say "  agent      updates itself when Monitor holds a newer one"
else
    say "  agent      stays as it is; run this installer again to update it"
fi
say ""
say "To remove it later:  sh $0 --uninstall"
