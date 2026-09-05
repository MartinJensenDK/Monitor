#!/bin/sh
# Checks the Linux agent and its installer without a Linux machine to install
# them on.
#
#   sh tools/check-agent.sh
#
# The installer needs root and a Monitor to talk to, so what can be exercised
# here is the half that decides things: which settings a run ends up with, and
# which arguments it refuses. That half is where a mistake is quiet -- an
# installer that silently resets a machine's permissions still prints "Done" --
# so it is the half worth a test.
#
# Run this before handing over any change to resources/agent/*.sh. Its
# PowerShell sibling, tools/check-agent.ps1, does the same for the Windows side.
set -eu

ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
AGENT_DIR="$ROOT/resources/agent"

WORK=$(mktemp -d "${TMPDIR:-/tmp}/check-agent.XXXXXX")
trap 'rm -rf "$WORK"' EXIT INT TERM

pass=0
fail=0

ok()   { pass=$((pass + 1)); printf '  ok   %s\n' "$1"; }
bad()  { fail=$((fail + 1)); printf '  FAIL %s\n       wanted: %s\n       got:    %s\n' "$1" "$2" "$3"; }

# ------------------------------------------------------------- 1. it parses ---

echo 'The scripts parse'

for script in agent.sh install.sh; do
    if sh -n "$AGENT_DIR/$script" 2>"$WORK/err"; then
        ok "$script"
    else
        bad "$script" 'no syntax errors' "$(cat "$WORK/err")"
    fi
done

# dash is what /bin/sh actually is on Debian and Ubuntu, and it is stricter
# than bash about what POSIX shell means. Checking against it here is what
# stops a bashism reaching a fleet.
if command -v dash >/dev/null 2>&1; then
    for script in agent.sh install.sh; do
        if dash -n "$AGENT_DIR/$script" 2>"$WORK/err"; then
            ok "$script under dash"
        else
            bad "$script under dash" 'no syntax errors' "$(cat "$WORK/err")"
        fi
    done
fi

# --------------------------------------------- 2. what a run settles on ---

# Everything above the first line that needs a network, with the root check
# taken out and the served copy's address put in, then a line that prints what
# it worked out.
STUB="$WORK/settings.sh"
awk '/^say "Installing the Monitor agent/ { exit } { print }' "$AGENT_DIR/install.sh" \
    | sed -e 's#^\[ "$(id -u)" = "0" \].*#:#' \
          -e 's#__MONITOR_URL__#https://monitor.example.com#' \
          -e "s#^CONF_DIR=.*#CONF_DIR=\"$WORK\"#" > "$STUB"

cat >> "$STUB" <<'TAIL'
printf 'url=%s interval=%s poll=%s collect=%s level=%s allow=%s self=%s insecure=%s token=%s\n' \
    "$MONITOR_URL" "$INTERVAL" "$POLL" "$COLLECT" "$LEVEL" "${ALLOW:-(none)}" \
    "$SELF_UPDATE" "$INSECURE" "${EXISTING_TOKEN:-(none)}"
TAIL

CONF="$WORK/agent.conf"

check() {
    label="$1"; expected="$2"; shift 2
    actual=$(sh "$STUB" "$@" 2>&1 || true)
    case "$actual" in
        *"$expected"*) ok "$label" ;;
        *) bad "$label" "$expected" "$actual" ;;
    esac
}

echo
echo 'A machine that has never been installed'
rm -f "$CONF"
check 'takes the defaults' \
    'url=https://monitor.example.com interval=300 poll=15 collect=disks,updates,packages,services,ports level=info allow=(none) self=1 insecure=0' \
    --key mek_x
check 'and the flags it was given' \
    'interval=600 poll=0 collect=disks,updates,packages,services,ports level=debug allow=updates,reboot self=0 insecure=1' \
    --key mek_x --interval 600 --no-live --level debug --allow-updates --allow-reboot --no-self-update --insecure

echo
echo 'A machine installed carefully, then updated with no arguments at all'
cat > "$CONF" <<'CONFEOF'
MONITOR_URL="https://monitor.example.com"
MONITOR_TOKEN="mdt_aabbccddeeff00112233445566778899"
MONITOR_DEVICE_ID="3a40e8a6-c537-49bc-8da7-9e20374d6808"
MONITOR_INTERVAL="900"
MONITOR_POLL="0"
MONITOR_COLLECT="disks,updates"
MONITOR_LEVEL="warn"
MONITOR_ALLOW="updates"
MONITOR_SELF_UPDATE="1"
MONITOR_INSECURE="1"
CONFEOF
check 'keeps every setting it had' \
    'url=https://monitor.example.com interval=900 poll=0 collect=disks,updates level=warn allow=updates self=1 insecure=1'
check 'keeps its token, so it does not enrol twice' 'token=mdt_aabbccddeeff00112233445566778899'
check 'and one argument changes that one thing only' \
    'interval=120 poll=0 collect=disks,updates level=warn allow=updates self=1 insecure=1' --interval 120

echo
echo "Permissions are the machine's to keep, and to withdraw"
check 'never handed back by silence'          'allow=updates'
check 'added to rather than replaced'         'allow=updates,reboot' --allow-reboot
check 'taken away when actually asked'        'allow=(none)' --no-allow-updates
check 'self-update refused, and stays so'     'self=0' --no-self-update

echo
echo 'A config from an installer that never heard of half these settings'
cat > "$CONF" <<'CONFEOF'
MONITOR_URL="https://monitor.example.com"
MONITOR_TOKEN="mdt_aabbccddeeff00112233445566778899"
MONITOR_INTERVAL="600"
MONITOR_POLL="15"
MONITOR_COLLECT="disks"
MONITOR_ALLOW="reboot"
MONITOR_INSECURE="0"
CONFEOF
check 'the gaps fill with defaults' 'interval=600 poll=15 collect=disks level=info allow=reboot self=1 insecure=0'

echo
echo 'A config somebody edited by hand'
cat > "$CONF" <<'CONFEOF'
MONITOR_URL="https://monitor.example.com"
MONITOR_TOKEN="mdt_aabbccddeeff00112233445566778899"
MONITOR_INTERVAL="ten minutes"
MONITOR_POLL="2"
MONITOR_LEVEL="chatty"
MONITOR_SELF_UPDATE="yes"
MONITOR_COLLECT=""
CONFEOF
check 'nonsense there falls back instead of stopping the install' \
    'interval=300 poll=15 collect= level=info allow=(none) self=1'

echo
echo 'Nonsense on the command line still stops it'
rm -f "$CONF"
check 'a short interval' 'error: --interval must be at least 60 seconds' --key mek_x --interval 5
check 'a fast poll'      'error: --poll must be at least 5 seconds'      --key mek_x --poll 2
check 'an unknown level' 'error: --level must be one of'                 --key mek_x --level chatty
check 'an unknown flag'  'error: unknown option: --wat'                  --key mek_x --wat
check 'an address that is not one' 'error: --url must start with'        --key mek_x --url monitor.example.com

# --------------------------------------------- 3. logging and the outbox ---
#
# The agent itself, up to the point where it would start doing something, is a
# file of function definitions -- so it can be loaded, handed a config, and had
# its parts called one at a time. post() is replaced, because what is being
# tested is what the agent does with an answer, not curl.

echo
echo 'Logging'

AGENT_STUB="$WORK/agent-stub.sh"
awk '/^log_setup$/ { exit } { print }' "$AGENT_DIR/agent.sh" > "$AGENT_STUB"

cat > "$WORK/agent.conf" <<'CONFEOF'
MONITOR_URL="https://monitor.example.com"
MONITOR_TOKEN="mdt_aabbccddeeff00112233445566778899"
MONITOR_DEVICE_ID="3a40e8a6-c537-49bc-8da7-9e20374d6808"
MONITOR_LEVEL="info"
MONITOR_SELF_UPDATE="1"
CONFEOF

# One scenario: the stub, then whatever is being tested, run with a fresh log
# and outbox each time.
agent_case() {
    rm -rf "$WORK/state" "$WORK/agent.log"
    {
        cat "$AGENT_STUB"
        echo 'set +e'
        echo 'log_setup'
        cat
    } > "$WORK/case.sh"
    MONITOR_CONF="$WORK/agent.conf" MONITOR_LOG="$WORK/agent.log" \
        MONITOR_STATE="$WORK/state" sh "$WORK/case.sh" 2>&1
}

got=$(agent_case <<'CASEEOF'
log debug "not at info"
log info  "plain"
log warn  "two
lines and a	tab"
cut -f2,4 < "$MONITOR_STATE/outbox"
CASEEOF
)
expected='info	plain
warn	two lines and a tab'
if [ "$got" = "$expected" ]; then
    ok 'the level floor holds, and a line is a line'
else
    bad 'the level floor holds, and a line is a line' "$expected" "$got"
fi

got=$(agent_case <<'CASEEOF'
log info "written here too"
cut -d' ' -f2- < "$MONITOR_LOG"
CASEEOF
)
if [ "$got" = "info written here too" ]; then
    ok 'and it is on this machine as well as in the outbox'
else
    bad 'and it is on this machine as well as in the outbox' 'info written here too' "$got"
fi

got=$(agent_case <<'CASEEOF'
log info "output of a command" "3a40e8a6-c537-49bc-8da7-9e20374d6808"
outbox_json "$MONITOR_STATE/outbox"
CASEEOF
)
case "$got" in
    '{"logs":[{"at":"'*'","level":"info","command":"3a40e8a6-c537-49bc-8da7-9e20374d6808","message":"output of a command"}]}')
        ok 'a line belonging to a command says so' ;;
    *) bad 'a line belonging to a command says so' 'the command uuid in the JSON' "$got" ;;
esac

if command -v php8.5 >/dev/null 2>&1 || command -v php >/dev/null 2>&1; then
    php=$(command -v php8.5 || command -v php)
    got=$(agent_case <<'CASEEOF'
log info 'quotes " and \ backslashes'
log error "$(printf 'a\tb\001c')"
outbox_json "$MONITOR_STATE/outbox"
CASEEOF
)
    if printf '%s' "$got" | "$php" -r 'exit(json_decode(stream_get_contents(STDIN), true) === null ? 1 : 0);'; then
        ok 'awkward output still comes out as JSON'
    else
        bad 'awkward output still comes out as JSON' 'valid JSON' "$got"
    fi
fi

echo
echo 'The outbox'

got=$(agent_case <<'CASEEOF'
post() { echo 200; }
log info "one"
log info "two"
ship_logs now
printf 'left=%s\n' "$(wc -l < "$MONITOR_STATE/outbox")"
CASEEOF
)
if [ "$got" = "left=0" ]; then
    ok 'empties when the server takes it'
else
    bad 'empties when the server takes it' 'left=0' "$got"
fi

got=$(agent_case <<'CASEEOF'
post() { echo 000; }
log info "one"
log info "two"
ship_logs now
cut -f2,4 < "$MONITOR_STATE/outbox"
CASEEOF
)
if [ "$got" = "info	one
info	two" ]; then
    ok 'and keeps them, in order, when it does not'
else
    bad 'and keeps them, in order, when it does not' 'both lines still there' "$got"
fi

# The first line after a quiet spell goes at once -- that is what makes a
# machine's first word arrive quickly. It is the second and the twentieth that
# have to wait, or streaming a package manager would be a request per line.
got=$(agent_case <<'CASEEOF'
post() { echo 200; }
log info "one"
ship_logs
log info "two"
ship_logs
printf 'left=%s\n' "$(wc -l < "$MONITOR_STATE/outbox")"
CASEEOF
)
if [ "$got" = "left=1" ]; then
    ok 'the first line goes at once, the next one waits its turn'
else
    bad 'the first line goes at once, the next one waits its turn' 'left=1' "$got"
fi

got=$(agent_case <<'CASEEOF'
post() { echo 200; }
i=0
while [ "$i" -lt 30 ]; do log info "line $i"; i=$((i + 1)); done
ship_logs
printf 'left=%s\n' "$(wc -l < "$MONITOR_STATE/outbox")"
CASEEOF
)
if [ "$got" = "left=0" ]; then
    ok 'but a command talking steadily is sent without waiting'
else
    bad 'but a command talking steadily is sent without waiting' 'left=0' "$got"
fi

got=$(agent_case <<'CASEEOF'
post() { echo 000; }
i=0
while [ "$i" -lt 2100 ]; do log info "line $i"; i=$((i + 1)); done
ship_logs now
printf 'left=%s first=%s\n' "$(wc -l < "$MONITOR_STATE/outbox")" "$(head -n 1 "$MONITOR_STATE/outbox" | cut -f4)"
CASEEOF
)
if [ "$got" = "left=2000 first=line 100" ]; then
    ok 'a week offline is capped, and it is the newest that are kept'
else
    bad 'a week offline is capped, and it is the newest that are kept' 'left=2000 first=line 100' "$got"
fi

echo
echo 'What gets sent'

# Everything the agent reads is somebody else's text, and a machine is under no
# obligation to have it in UTF-8. One stray byte makes the whole document
# undecodable at the far end -- so one bad byte in one package name would throw
# away an entire report.
got=$(agent_case <<'CASEEOF'
printf '{"system":{"os_name":"caf\351 edition"},"metrics":{}}' > "$WORK/dirty.json"
sanitise_json "$WORK/dirty.json"
cat "$WORK/dirty.json"
CASEEOF
)
if [ "$got" = '{"system":{"os_name":"caf edition"},"metrics":{}}' ]; then
    ok 'a byte that is not UTF-8 is dropped, not the report'
else
    bad 'a byte that is not UTF-8 is dropped, not the report' \
        '{"system":{"os_name":"caf edition"},"metrics":{}}' "$got"
fi

got=$(agent_case <<'CASEEOF'
printf '{"system":{"os_name":"Ubuntu 24.04 LTS – Ørestad"},"metrics":{}}' > "$WORK/clean.json"
sanitise_json "$WORK/clean.json"
cat "$WORK/clean.json"
CASEEOF
)
if [ "$got" = '{"system":{"os_name":"Ubuntu 24.04 LTS – Ørestad"},"metrics":{}}' ]; then
    ok 'and text that is UTF-8 goes through untouched'
else
    bad 'and text that is UTF-8 goes through untouched' 'the accents intact' "$got"
fi

got=$(agent_case <<'CASEEOF'
system_json() { printf '"system":{"hostname":"box","agent_version":"1.1.0"}'; }
metrics_json() { printf '"metrics":{}'; }
MONITOR_COLLECT=""
build_report ""
printf 'exit=%s ' "$?"
cat "$WORK/report.json"
CASEEOF
)
case "$got" in
    'exit=0 {"system":{"hostname":"box","agent_version":"1.1.0"},"metrics":{},"collected":[],'*'"self_update":true,"results":[]}')
        ok 'a whole report is built and says what it consents to' ;;
    *) bad 'a whole report is built and says what it consents to' 'a complete document, exit 0' "$got" ;;
esac

got=$(agent_case <<'CASEEOF'
printf '{"system":{"hostname":"box"}}' > "$WORK/whole"
printf '{"system":{"hostname":"bo'      > "$WORK/cut"
: > "$WORK/empty"
printf 'monitor-agent: not configured\n' > "$WORK/prose"
for f in whole cut empty prose; do
    if report_is_whole "$WORK/$f"; then printf '%s=whole ' "$f"; else printf '%s=no ' "$f"; fi
done
CASEEOF
)
if [ "$got" = 'whole=whole cut=no empty=no prose=no ' ]; then
    ok 'and one that stopped part way is not sent'
else
    bad 'and one that stopped part way is not sent' 'whole=whole cut=no empty=no prose=no' "$got"
fi

echo
echo 'Numbers, wherever the machine thinks it is'

# This is the bug that made a whole fleet's worth of report undecodable from
# one desktop: mawk -- which is what awk is on Debian and Ubuntu -- formats %f
# through the locale, so "11.07" came out of a Danish machine as "11,07".
got=$(agent_case <<'CASEEOF'
printf '%s ' "$(jnum 11.07)" "$(jnum 11,07)" "$(jnum 0)" "$(jnum -3.5)"
printf '| '
printf '%s ' "$(jnum '')" "$(jnum null)" "$(jnum 1.2.3)" "$(jnum '1,234,567')" "$(jnum -)" "$(jnum 'nan')"
CASEEOF
)
if [ "$got" = "11.07 11.07 0 -3.5 | null null null null null null " ]; then
    ok 'a decimal comma is a number; nonsense is null'
else
    bad 'a decimal comma is a number; nonsense is null' \
        '11.07 11.07 0 -3.5 | null null null null null null' "$got"
fi

# And the whole report, built the way that desktop builds it. Skipped where
# neither mawk nor a comma locale is installed, which is most servers -- and is
# exactly why this went unnoticed.
comma_locale=""
for candidate in da_DK.utf8 de_DE.utf8 fr_FR.utf8 da_DK.UTF-8 de_DE.UTF-8; do
    if locale -a 2>/dev/null | grep -qx "$candidate"; then comma_locale="$candidate"; break; fi
done

if [ -n "$comma_locale" ]; then
    shim="$WORK/shim"
    mkdir -p "$shim"
    if command -v mawk >/dev/null 2>&1; then ln -sf "$(command -v mawk)" "$shim/awk"; fi

    cat > "$WORK/dump.conf" <<CONFEOF
MONITOR_URL="https://monitor.example.com"
MONITOR_TOKEN="mdt_aabbccddeeff00112233445566778899"
MONITOR_COLLECT="disks"
MONITOR_LEVEL="error"
CONFEOF

    LC_ALL="$comma_locale" PATH="$shim:$PATH" \
        MONITOR_CONF="$WORK/dump.conf" MONITOR_LOG="$WORK/dump.log" MONITOR_STATE="$WORK/dump-state" \
        sh "$AGENT_DIR/agent.sh" --dump > "$WORK/dump.json" 2>/dev/null || true

    if grep -q '"cpu_percent":[0-9-]*,[0-9]' "$WORK/dump.json" 2>/dev/null; then
        bad "a whole report under $comma_locale" 'a decimal point' "$(grep -o '\"cpu_percent\":[^,]*,[0-9]*' "$WORK/dump.json")"
    elif grep -q '"cpu_percent":' "$WORK/dump.json" 2>/dev/null; then
        ok "a whole report under $comma_locale keeps its decimal points"
    else
        bad "a whole report under $comma_locale" 'a report with metrics in it' "$(head -c 120 "$WORK/dump.json")"
    fi
fi

echo
echo 'Reading the disks'

# df exits non-zero when a single mount cannot be stat'ed, which on a desktop
# is always -- some gvfs or flatpak thing under /run/user. Its status therefore
# says nothing about whether its output is usable, and treating it as "this df
# has no -T" is how a machine came to report a disk called "Mounted".
got=$(agent_case <<'CASEEOF'
df() {
    if [ "$2" = "-T" ]; then
        printf 'Filesystem     Type 1024-blocks    Used Available Capacity Mounted on\n'
        printf '/dev/sda2      ext4    50000000 2000000  45000000      5%% /\n'
        printf 'tmpfs          tmpfs    1000000       0   1000000      0%% /run\n'
        printf '/dev/sdb1      ext4   200000000 1000000 190000000      1%% /media/My Book\n'
        return 1
    fi
    printf 'the fallback should not have been reached\n'
}
disks_json
CASEEOF
)
expected='"disks":[{"mount":"/","source":"/dev/sda2","filesystem":"ext4","total_bytes":51200000000,"used_bytes":2048000000},{"mount":"/media/My Book","source":"/dev/sdb1","filesystem":"ext4","total_bytes":204800000000,"used_bytes":1024000000}]'
if [ "$got" = "$expected" ]; then
    ok 'a df that reports a mount it cannot read is still read'
else
    bad 'a df that reports a mount it cannot read is still read' "$expected" "$got"
fi

got=$(agent_case <<'CASEEOF'
df() {
    # An older or smaller df, with no -T at all.
    if [ "$2" = "-T" ]; then return 1; fi
    printf 'Filesystem     1024-blocks    Used Available Capacity Mounted on\n'
    printf '/dev/sda2         50000000 2000000  45000000      5%% /\n'
}
disks_json
CASEEOF
)
expected='"disks":[{"mount":"/","source":"/dev/sda2","filesystem":"","total_bytes":51200000000,"used_bytes":2048000000}]'
if [ "$got" = "$expected" ]; then
    ok 'and a df with no -T falls back to six columns'
else
    bad 'and a df with no -T falls back to six columns' "$expected" "$got"
fi

got=$(agent_case <<'CASEEOF'
df() { return 1; }
disks_json
CASEEOF
)
if [ "$got" = '"disks":[]' ]; then
    ok 'and one that says nothing at all is an empty list, not a broken one'
else
    bad 'and one that says nothing at all is an empty list, not a broken one' '"disks":[]' "$got"
fi

echo
echo 'Running a command'

# run_command is replaced with something that talks, slowly, and fails: what is
# being tested is that its output reaches the log line by line while it is
# still going, and that the result the report carries is still the whole of it.
got=$(agent_case <<'CASEEOF'
# Nothing is taken off the outbox, so what the run wanted to say is all still
# there to be read at the end of it.
post() { echo 000; }
run_command() {
    echo "Reading package lists..."
    echo "Unpacking, which takes a while"
    echo "done."
    return 3
}
printf '%s' '{"ok":true,"commands":[{"id":"3a40e8a6-c537-49bc-8da7-9e20374d6808","command":"install_updates"}]}' > "$WORK/response"
run_queue
echo
grep -c '3a40e8a6-c537-49bc-8da7-9e20374d6808' "$MONITOR_STATE/outbox"
CASEEOF
)
case "$got" in
    *'"exit_code":3'*'"output":"Reading package lists...\nUnpacking, which takes a while\ndone.\n"'*)
        ok 'the whole of the output goes back with the result' ;;
    *) bad 'the whole of the output goes back with the result' 'output and exit 3 in the result' "$got" ;;
esac
case "$got" in
    *'"ok":false'*) ok 'and a command that failed is not called a success' ;;
    *) bad 'and a command that failed is not called a success' '"ok":false' "$got" ;;
esac
# Three lines of output, plus "Running" and "failed", all against the command.
case "$got" in
    *"$(printf '\n')5"*) ok 'every line was logged against the command as it appeared' ;;
    *) bad 'every line was logged against the command as it appeared' '5 lines carrying the uuid' "$got" ;;
esac

got=$(agent_case <<'CASEEOF'
post() { echo 000; }
run_command() { echo "This machine was installed without --allow-updates." >&2; return 77; }
printf '%s' '{"ok":true,"commands":[{"id":"3a40e8a6-c537-49bc-8da7-9e20374d6808","command":"install_updates"}]}' > "$WORK/response"
run_queue > /dev/null
cut -f2,4 < "$MONITOR_STATE/outbox" | tail -n 1
CASEEOF
)
case "$got" in
    'warn	install_updates refused: this machine was not installed to allow it.')
        ok 'a refusal reads as one, not as a failure' ;;
    *) bad 'a refusal reads as one, not as a failure' 'a warn line saying it was refused' "$got" ;;
esac

echo
echo 'Telling a dead token from a report that did not get through'

# The installer acts on these. Reading "the server could not read that" as "you
# are not who you say you are" spends a use of an enrolment key and leaves a
# second row in Monitor for the same computer -- which is exactly what happened
# to the first machine that hit it.
for pair in '200 0' '400 3' '401 2' '503 1' '000 1'; do
    code=${pair% *}
    want=${pair#* }
    got=$(agent_case <<CASEEOF
build_report() { printf '{}' > "\$WORK/report.json"; }
post() { printf '{"ok":true}' > "\$WORK/response"; echo $code; }
run_once "" >/dev/null 2>&1
printf 'exit=%s\n' "\$?"
CASEEOF
)
    if [ "$got" = "exit=$want" ]; then
        ok "the server answering $code is exit $want"
    else
        bad "the server answering $code is exit $want" "exit=$want" "$got"
    fi
done

echo
echo 'What the server offers'

got=$(agent_case <<'CASEEOF'
printf '%s' '{"ok":true,"poll":15,"level":"info","agent":{"version":"1.4.2","url":"/agent/linux/agent.sh","sha256":"abc"},"commands":[]}' > "$WORK/response"
offered_version
CASEEOF
)
if [ "$got" = "1.4.2" ]; then
    ok 'the version it wants this machine on is read'
else
    bad 'the version it wants this machine on is read' '1.4.2' "$got"
fi

got=$(agent_case <<'CASEEOF'
printf '%s' '{"ok":true,"commands":[{"id":"3a40e8a6-c537-49bc-8da7-9e20374d6808","command":"report_now"}]}' > "$WORK/response"
printf 'version=[%s]\n' "$(offered_version)"
CASEEOF
)
if [ "$got" = "version=[]" ]; then
    ok 'and an answer without one offers nothing'
else
    bad 'and an answer without one offers nothing' 'version=[]' "$got"
fi

got=$(agent_case <<'CASEEOF'
MONITOR_SELF_UPDATE="0"
self_update "Asked." force
printf 'exit=%s\n' "$?"
CASEEOF
)
case "$got" in
    *'--no-self-update'*'exit=77'*) ok 'a machine that refuses updates refuses them' ;;
    *) bad 'a machine that refuses updates refuses them' 'refused, exit 77' "$got" ;;
esac

# ------------------------------------------------- 4. the two agree on names ---

echo
echo 'The installer and the agent write the same config'

installer_keys=$(sed -n 's/^\(MONITOR_[A-Z_]*\)=".*"$/\1/p' "$AGENT_DIR/install.sh" | sort -u)
agent_keys=$(sed -n 's/^\(MONITOR_[A-Z_]*\)="\$MONITOR_[A-Z_]*"$/\1/p' "$AGENT_DIR/agent.sh" | sort -u)

printf '%s\n' "$agent_keys" > "$WORK/agent-keys"
missing=$(printf '%s\n' "$installer_keys" | comm -23 - "$WORK/agent-keys")

if [ -z "$missing" ]; then
    ok 'every setting the installer writes survives enrolment'
else
    bad 'every setting the installer writes survives enrolment' \
        'the agent to write them all back' \
        "dropped by the agent: $(printf '%s' "$missing" | tr '\n' ' ')"
fi

echo
echo 'The two agents move together'

# One version between them, so "this machine is on 1.4.0" means the same thing
# whichever agent it is running. A fix to one is a release of both, even when
# the other needed nothing -- otherwise the numbers drift and stop meaning
# anything, and Monitor offers a version to a platform that never got it.
sh_version=$(grep -o '^AGENT_VERSION="[0-9][0-9.]*"' "$AGENT_DIR/agent.sh" | head -n 1 | tr -dc '0-9.')
ps_version=$(grep -o "AgentVersion = '[0-9][0-9.]*'" "$AGENT_DIR/agent.ps1" | head -n 1 | tr -dc '0-9.')

if [ -n "$sh_version" ] && [ "$sh_version" = "$ps_version" ]; then
    ok "both agents say $sh_version"
else
    bad 'both agents carry the same version' "the same number in both" \
        "agent.sh=${sh_version:-none} agent.ps1=${ps_version:-none}"
fi

echo
echo 'The schedule it writes'

# A systemd timer whose every anchor is in the past ends up "active (elapsed)"
# -- loaded, enabled, active, and never going to run again. OnActiveSec is
# relative to the timer itself starting, so it cannot be in the past, and it is
# the only thing standing between a fleet and that state. Do not remove it.
timer_block=$(sed -n '/^\[Timer\]$/,/^TIMEREOF$/p' "$AGENT_DIR/install.sh")

for anchor in OnActiveSec OnBootSec OnUnitActiveSec; do
    if printf '%s\n' "$timer_block" | grep -q "^$anchor="; then
        ok "the timer is anchored on $anchor"
    else
        bad "the timer is anchored on $anchor" "an $anchor= line" 'it is not there'
    fi
done

# Persistent= only does anything for OnCalendar= timers. On a monotonic one it
# is noise that reads like a guarantee.
if printf '%s\n' "$timer_block" | grep -q '^Persistent='; then
    bad 'no Persistent= on a monotonic timer' 'no Persistent= line' 'it is back'
else
    ok 'and carries no Persistent=, which would do nothing here'
fi

# Registering a timer is not the same as having one that will fire, and the
# elapse time cannot tell the difference -- a monotonic timer reports it as
# "infinity" while armed and counting down. SubState can.
if grep -q 'systemctl show -p SubState --value monitor-agent.timer' "$AGENT_DIR/install.sh" \
   && grep -q '^        waiting|running)' "$AGENT_DIR/install.sh"; then
    ok 'and the installer reads its state back before calling it scheduled'
else
    bad 'and the installer reads its state back before calling it scheduled' \
        'a SubState check accepting waiting|running' 'it is not there'
fi

echo
echo 'Each script recognises the other'

# Both directions of a download are sanity-checked against a couple of strings
# that must appear in the file. Renaming one of them is a two-line change that
# would silently brick every install and every self-update in the fleet.
if head -n 1 "$AGENT_DIR/agent.sh" | grep -q '^#!/bin/sh' && grep -q 'monitor-agent' "$AGENT_DIR/agent.sh"; then
    ok 'the installer would accept the agent it downloads'
else
    bad 'the installer would accept the agent it downloads' 'a #!/bin/sh line and the word monitor-agent' 'one of them is gone'
fi

if head -n 1 "$AGENT_DIR/install.sh" | grep -q '^#!/bin/sh' && grep -q 'monitor-agent' "$AGENT_DIR/install.sh"; then
    ok 'and the agent would accept the installer it downloads'
else
    bad 'and the agent would accept the installer it downloads' 'a #!/bin/sh line and the word monitor-agent' 'one of them is gone'
fi

echo
printf '%s passed, %s failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
