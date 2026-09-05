-- Splitting "are you there" from "tell me everything".
--
-- A full report is expensive at both ends: the agent reads the package
-- manager, the service list and every listening socket, and the server writes
-- five lists back to disk. Doing that often enough for a queued command to
-- feel immediate would be absurd.
--
-- So the two are separated. The agent keeps sending a full report on its own
-- interval, and in between it knocks on a much smaller door every few seconds:
-- one request, one row touched, and an answer that is usually empty. That
-- makes a command land in seconds instead of minutes, and it makes silence
-- detectable in seconds too -- which is the more valuable half, because a
-- machine that has gone away is the thing you actually want to hear about.

ALTER TABLE {{devices}}
    -- How often the agent checks in for orders. Zero switches the live
    -- channel off entirely, and the machine goes back to being heard from
    -- only when it reports.
    --
    -- This is also what "is it still there" is measured against, so a machine
    -- polling every 15 seconds is called quiet within a minute rather than
    -- within half an hour.
    ADD COLUMN `poll_seconds` INT UNSIGNED NOT NULL DEFAULT 15
        COMMENT 'Seconds between command checks; 0 turns the live channel off'
        AFTER `interval_seconds`,

    -- Kept apart from last_seen_at so the two questions stay answerable
    -- separately: when did this machine last say anything, and when did it
    -- last say something substantial.
    ADD COLUMN `last_poll_at` DATETIME NULL AFTER `last_report_at`;

-- Machines enrolled before this migration have never polled. Treating that as
-- "silent" would light up the dashboard for every one of them at once, so they
-- start from where they were last heard.
UPDATE {{devices}} SET `last_poll_at` = `last_seen_at` WHERE `last_seen_at` IS NOT NULL;
