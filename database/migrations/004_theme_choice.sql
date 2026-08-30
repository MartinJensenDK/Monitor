-- The theme is now a single toggle: light or dark, nothing else. Installs
-- made before that could store 'system', which no screen can produce any
-- more, so both places that held it are settled on light -- the theme the
-- stylesheet paints when nothing says otherwise.
--
-- Anyone who preferred dark just clicks once; the choice lands in their own
-- cookie, and an administrator can move the whole site with Settings ->
-- General -> Default theme.

UPDATE {{settings}}
   SET `value` = 'light'
 WHERE `key` = 'theme_default'
   AND `value` NOT IN ('light', 'dark');

-- users.theme is written when an account is created and read nowhere, but a
-- column should not keep a value the product no longer has.
ALTER TABLE {{users}}
    MODIFY `theme` VARCHAR(8) NOT NULL DEFAULT 'light';

UPDATE {{users}}
   SET `theme` = 'light'
 WHERE `theme` NOT IN ('light', 'dark');
