# Microsoft Entra ID

Two things this gives you, and you can have either without the other:

- **Sign in with Microsoft** — people use their work account instead of another password.
- **Group sync** — membership is maintained in Entra ID and mirrored here, read-only,
  along with each person’s profile picture.

Together they mean the answer to "who can see the production monitors" lives in
one place, and stops being something to remember to update when someone joins or
leaves.

---

## 1. Register the application

In the [Azure portal](https://portal.azure.com) → **Microsoft Entra ID** →
**App registrations** → **New registration**.

| Field | Value |
|---|---|
| Name | Anything — `Monitor` does fine |
| Supported account types | *Accounts in this organizational directory only* |
| Redirect URI | **Web**, and the exact URI shown under Settings → Microsoft Entra ID |

The redirect URI is `https://your-site/auth/entra/callback`. Copy it from the
settings page rather than typing it — a trailing slash or `http` instead of
`https` is the single most common reason sign-in fails.

From the **Overview** page, copy:

- **Directory (tenant) ID**
- **Application (client) ID**

## 2. Create a client secret

**Certificates & secrets** → **New client secret**. Copy the **Value** — not the
Secret ID — immediately; Azure hides it as soon as you leave the page.

Note the expiry date. When the secret expires, sign-in and sync both stop, and
the error will say the secret was rejected.

## 3. Grant the Graph permissions

Only needed for group sync. Skip if you want sign-in alone.

**API permissions** → **Add a permission** → **Microsoft Graph** →
**Application permissions**:

- `User.Read.All`
- `GroupMember.Read.All`

Then **Grant admin consent** for the directory. Application permissions do
nothing until an administrator consents — without it, every sync fails with
"Insufficient privileges".

Both are read-only. Monitor never writes to your directory.

## 4. Fill it in

Settings → **Microsoft Entra ID**: tenant ID, client ID, client secret. Save,
then press **Test the connection** — it reads one group and tells you what
Microsoft said if it could not.

Then choose what you want:

| Setting | What it does |
|---|---|
| Show "Sign in with Microsoft" | Adds the button to the sign-in page |
| Keep password sign-in | Leave **on** until Microsoft sign-in has worked at least once |
| Create accounts on first sign-in | A directory member who signs in gets an account with the default role. Off means only people already here, or synced from a group, can get in |
| Sync groups every hour | Mirrors the groups you pick, and their members |
| Role for new accounts | Unless a group grants a role — see below |

## 5. Pick the groups to mirror

Press **Load groups from Entra**, tick the ones you want, and save. Only the
groups you pick are touched.

Press **Sync now** to run it immediately, or leave it to the scheduler, which
runs it hourly.

Mirrored groups are read-only here: their name and members come from the
directory, and the interface marks them with a lock. Local groups continue to
work alongside them.

### Letting a group grant a role

Open a group and set **What membership grants**. Everyone the directory puts in
that group gets that role, and the most permissive mapped group wins. That is
how you keep "who is an administrator" in Entra ID rather than in two places.

Leave it at *No opinion* and people keep whatever role they were given here.

---

## What the sync does, exactly

Run as often as you like — every step is an upsert keyed on the directory's
object id, so a second run changes nothing.

- **Groups** you selected are created or updated, and marked as coming from Entra.
  A local group with the same name is adopted rather than duplicated.
- **People** in those groups are created or updated. Someone who already had a
  local account keeps it, and their history — the account is linked, not replaced.
- **Membership** mirrors the directory. Rows the sync created are marked as such,
  so local membership of the same group is left alone.
- **Profile pictures** are mirrored too, and shown wherever a person appears. A
  picture is only asked about once a day, and the sync sends back the ETag it
  already holds, so an unchanged photo answers 304 and costs nothing. Someone who
  removes their picture in Entra loses it here on the next check; someone who has
  none simply keeps their initials. Photos are scaled to 120px and re-encoded on
  arrival, stored in the database, and served only to people who are signed in —
  never written into the webroot. No extra Graph permission is needed:
  `User.Read.All` already covers them.
- **Someone who leaves a mirrored group** is disabled here, not deleted, so their
  history and audit trail survive. They are re-enabled if they come back.
- **Someone disabled in the directory** is disabled here too.
- **A group you stop syncing** stays as an ordinary local group. Nothing it gave
  access to disappears.
- **The last active administrator** is never disabled or demoted by a sync,
  whatever the directory says.
- **If Graph fails mid-run**, nobody is disabled. A permissions error must not
  read as "everybody left the company".

Every run is written to the activity log with what it changed.

## From the command line

```bash
php bin/entra-sync.php --status   # configuration, last result, and a live Graph check
php bin/entra-sync.php            # sync now
```

---

## When something is wrong

**"The redirect URI is not registered on the app registration."**
The URI in Azure differs from the one on the settings page. They must match
exactly, including scheme and any trailing path.

**"The client secret is wrong or has expired."**
You copied the Secret ID instead of the Value, or the secret expired. Create a
new one and paste the Value.

**"Microsoft Graph refused the request."**
`User.Read.All` and `GroupMember.Read.All` are missing, or admin consent was
never granted. Both are needed, as *Application* permissions rather than
delegated ones.

**"There is no account here for … yet."**
Auto-provisioning is off and that person has not been synced from a group. Turn
it on, add them to a mirrored group, or add the account by hand.

**Sign-in works but the person sees nothing.**
Signing in is not access. They also need to be in a group that a monitor is
shared with — that is a separate decision, made on the monitor.

**You locked yourself out.**
Turn password sign-in back on directly in the database:

```sql
UPDATE settings SET `value` = '1' WHERE `key` = 'entra_allow_local_login';
```

Monitor tries to prevent this — it refuses to switch password sign-in off while
Microsoft sign-in is disabled — but a secret that expires later can still leave
you stuck.

## Testing without a tenant

`ENTRA_AUTHORITY_URL` and `ENTRA_GRAPH_URL` in `.env` point the integration at a
different host, so it can be exercised against a stand-in directory. Leave both
unset in production; when they are absent, Microsoft's own endpoints are used.
