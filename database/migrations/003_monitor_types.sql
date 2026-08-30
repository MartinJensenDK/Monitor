-- Five more things worth watching: the page's wording, a sequence of API
-- calls, a TLS certificate, a domain registration, and a DNS record.
--
-- All five keep their settings in monitors.config, so this migration only has
-- to widen two columns.

ALTER TABLE {{monitors}}
    MODIFY `type` ENUM(
        'http',
        'keyword',
        'endpoint',
        'api',
        'ping',
        'port',
        'ssl',
        'domain',
        'dns'
    ) NOT NULL DEFAULT 'http';

-- A domain is checked every few hours, not every minute, and a day in seconds
-- does not fit in a SMALLINT.
ALTER TABLE {{monitors}}
    MODIFY `interval_seconds` INT UNSIGNED NOT NULL DEFAULT 60;

-- monitor_status.cert_expires_at now holds whichever expiry the monitor
-- watches: the certificate's for an SSL monitor, the registration's for a
-- domain monitor. Monitors::expiryLabel() decides how it is worded.
ALTER TABLE {{monitor_status}}
    MODIFY `cert_issuer` VARCHAR(190) NULL COMMENT 'Certificate issuer, or registrar for a domain monitor';
