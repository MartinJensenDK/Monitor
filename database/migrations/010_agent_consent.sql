-- What each machine has consented to being asked to do.
--
-- The agent has always known this -- it is MONITOR_ALLOW in its own config,
-- decided by whoever installed it -- but it never said so, and this side had
-- no way of finding out except by asking for something and being refused. That
-- made the two commands that change a machine into buttons you press to
-- discover whether you were allowed to press them.
--
-- Deliberately NULL-able rather than defaulting to 0. An agent too old to send
-- this has not refused anything; it has said nothing, and "has not said" must
-- not be shown as "will refuse". The interface leaves those alone.

ALTER TABLE {{devices}}
    ADD COLUMN `allow_updates` TINYINT(1) NULL
        COMMENT 'The machine allows updates to be installed from here; NULL means it has not said'
        AFTER `self_update`,

    ADD COLUMN `allow_reboot` TINYINT(1) NULL
        COMMENT 'The machine allows restarts from here; NULL means it has not said'
        AFTER `allow_updates`;
