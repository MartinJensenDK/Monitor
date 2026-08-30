# Monitor types

What each type asks for, what it does with it, and what it reports back.

Every type shares the settings on the **Schedule** panel — interval, timeout,
retries before an incident opens, and a response-time threshold that marks a
monitor *degraded* rather than down. Everything below is what a type adds on
top of those.

---

## Website

**Target:** a URL.

Fetches the page and judges it on the status code, an optional keyword, and how
long it took. Records the TLS certificate's expiry as it goes, so a website
monitor also warns before its certificate lapses.

For more than one keyword, or an error phrase that must *not* appear, use a
keyword monitor instead.

---

## Keyword

**Target:** a URL.

For pages that answer `200` while showing something nobody wants to see. Give
it the words that prove the page is really working — a price, a product name,
the heading of a section that comes out of the database — and it fails when
they go missing.

| Setting | What it does |
|---|---|
| Words and phrases | One per line. A phrase may contain spaces and punctuation. |
| How many must match | All of them, at least one, or **none of them** — the last catches an error page rather than the working one. |
| Read the page as a visitor sees it | Strips scripts, styles and tags, resolves entities, collapses whitespace. A phrase split across `<b>` tags still matches; a word hiding in a class name does not. On by default. |
| Match upper and lower case exactly | Off by default. |

A failure names the words that were missing, not just "keyword check failed".

---

## Endpoint

**Target:** a URL.

One request, with assertions about the JSON that comes back. `HTTP 200` is a
weak promise for an API; this is how you say what the body has to contain.

Paths are dotted, and a number indexes into a list: `data.queue.depth`,
`items.0.status`. The operators cover equality, containment, numeric
comparison, presence and truthiness.

---

## API

**Target:** a base URL. Every step is called against it.

Most APIs cannot be checked with one request: you sign in, you are handed a
token, and only then can you call the thing that matters. An API monitor runs
up to eight steps in order and carries values between them.

Each step has a method, a path, expected status, headers, a body, its own
assertions, and its own **captures**. A capture reads a value out of that
step's JSON and gives it a name; later steps write it as `{{name}}` in a path,
a header, or a body.

```
Step 1  POST /v1/session          capture token ← data.access_token
Step 2  GET  /v1/orders           header: Authorization: Bearer {{token}}
                                  assert  status equals ok
                                  assert  queue.depth is less than 100
```

The timeout is the budget for the **whole sequence**, not for each step. A
failure names the step it happened in: *"Step 2 (Read orders): queue.depth
should be less than 100, but it is 9000."*

---

## Ping

**Target:** a host name or IP address.

Sends an ICMP echo where the server is allowed to, and times a TCP connect
where it is not — reporting which of the two it used rather than pretending.
See the note in `docs/INSTALL.md` about enabling unprivileged ICMP.

---

## Port

**Target:** a host name or IP address, plus a port.

Times the TCP handshake. An optional expected greeting means an open port that
answers with the wrong thing also counts as down — a port being open is not the
same as the service behind it working.

---

## SSL certificate

**Target:** a host name. The port is separate, so this works on anything that
speaks TLS, not only web servers — 993 for IMAP, 587 for submission, 5432 for
Postgres.

A website check notices a certificate once it has already broken the site. This
one watches the weeks before that.

| Setting | Default | What it does |
|---|---|---|
| Port | 443 | Where to open the TLS connection. |
| Name to ask for | the host | SNI, for one address serving several names. |
| Warn this many days ahead | 14 | The monitor turns **amber** and stays up. |
| Fail this many days ahead | 3 | The monitor goes **down** while there is still time to renew. |
| The chain must be trusted | on | Catches a missing intermediate — which breaks some clients and not others, so it is easy to miss. |
| The certificate must cover this host | on | Common name and subject alternative names, with one leading wildcard label. |
| Expected issuer | — | Fails if the certificate is suddenly signed by someone else. |

Reported back: days remaining, issuer, subject, the names it covers, chain
length, TLS version and cipher.

The certificate is always read on a permissive handshake first, so a failure can
say *why* — expired on this date, self-signed by this issuer, issued for these
names — instead of only "verification failed".

---

## Domain

**Target:** a registrable domain, such as `example.com`. No subdomain, no
`https://`.

The outage nobody sees coming: the site is fine, the certificate is fine, and
the domain lapses because the renewal notice went to an inbox that no longer
exists.

Monitor reads the registry over **RDAP**, and falls back to **WHOIS** on port 43
for the registries that publish no RDAP service — `.dk` among them. The WHOIS
server for a TLD is looked up at IANA and cached for a month.

| Setting | Default | What it does |
|---|---|---|
| Warn this many days ahead | 30 | Amber. |
| Fail this many days ahead | 7 | Down. |
| Fail on a registry hold | on | `clientHold`, `serverHold`, `pendingDelete` and `redemptionPeriod` stop a domain resolving hours before it formally expires. |
| Expected registrar | — | A transfer you did not start is worth hearing about. |
| Expected nameservers | — | One per line; each must still be in the registry's delegation. |

**Registries rate-limit lookups, so a domain is checked once an hour at most** —
the interval field enforces it. Once a day is plenty.

Reported back: expiry date and days remaining, registrar, registry statuses,
delegated nameservers, registration date, and which source answered.

---

## DNS

**Target:** the name to look up. Subdomains and names with underscores are fine
— `_dmarc.example.com`, `_sip._tcp.example.com`.

Two questions: does the name still resolve, and does it still resolve to what
you published? A record that quietly changes breaks things that look fine from
the outside for hours.

| Setting | What it does |
|---|---|
| Record type | A, AAAA, CNAME, MX, TXT, NS, SOA, SRV, PTR or CAA. |
| The expected values | Must all be in the answer, must be exactly the answer, or must not be in the answer. |
| Expected values | One per line. Leave empty to only require that the record exists. |
| Resolvers to ask | IP addresses, one per line. Empty asks this server's own resolver. |

A value matches if the answer *contains* it, so an MX record of
`10 mail.example.com` is matched by typing the host name alone, and an SPF
include is matched inside the whole TXT record.

**Naming more than one resolver** also checks that they agree. Order and case
are ignored, so only a genuine difference is reported. Note that a name served
by a CDN or by geo-DNS legitimately answers differently in different places —
for those, ask one resolver.

Monitor builds and parses the DNS packets itself rather than using PHP's
`dns_get_record()`, which can only ask the system resolver. UDP first, then TCP
when an answer comes back truncated.

---

## A note on the expiry column

SSL and domain monitors both store their countdown in the same place
(`monitor_status.cert_expires_at`), which is why one notification setting —
*"Before it expires"* — covers both, and why the reminder email words itself
from the monitor's type. The detail page labels the tile **TLS certificate** or
**Domain registration** accordingly.

---

## Adding another type

One class in `app/Checks/` implementing `CheckerInterface`, one line in
`CheckerFactory`, one entry in `Monitors::TYPES`, and one panel in
`resources/views/pages/monitor-form.php` tagged `data-type-fields="yourtype"`.

Type-specific settings live in the `monitors.config` JSON column, so a new type
needs no migration unless it adds a value to the `type` enum.

Implement `BatchableChecker` instead if the check is an HTTP request — the
scheduler will then run it alongside the others in one `curl_multi` round.
