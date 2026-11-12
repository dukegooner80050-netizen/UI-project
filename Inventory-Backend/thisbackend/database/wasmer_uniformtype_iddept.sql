-- Run ONCE in Wasmer's DB Explorer. Mirrors migration 2026_11_04_000001.

-- 1. Add the column and the foreign key (same as the local XAMPP database)
ALTER TABLE `uniformtype`
  ADD COLUMN `iddept` int NULL AFTER `idUniftype`,
  ADD CONSTRAINT `uniformtype_iddept_foreign` FOREIGN KEY (`iddept`) REFERENCES `dept` (`iddept`);

-- 2. Backfill: a type used by exactly one department becomes that department's type
--    (College Polo stays shared; types with no variants stay shared)
UPDATE `uniformtype` t
JOIN (
  SELECT `idUniftype`, MIN(`iddept`) AS d
  FROM `uniformvariant`
  GROUP BY `idUniftype`
  HAVING COUNT(DISTINCT `iddept`) = 1
) v ON v.`idUniftype` = t.`idUniftype`
SET t.`iddept` = v.d
WHERE t.`uniform_name` <> 'College Polo';

-- 3. Department-specific names from the seed (1 BSIT, 2 BSMT, 3 BSHM, 4 BSCrim, 5 Senior High School)
--    Black Pants, College Blouse, Nameplate, Belt (Black), ROTC Uniform and College Polo stay shared.
UPDATE `uniformtype` SET `iddept` = 2 WHERE `uniform_name` IN
  ('White Pants','Marine Polo','Shoulder Board','Peaked Cap','Gloves','Belt (White)','BSMT Green Collar');
UPDATE `uniformtype` SET `iddept` = 3 WHERE `uniform_name` = 'BSHM Green Collar';
UPDATE `uniformtype` SET `iddept` = 4 WHERE `uniform_name` IN
  ('GOA Top','GOA Pants','Shoulder Loop','BSCrim Green Collar');
UPDATE `uniformtype` SET `iddept` = 5 WHERE `uniform_name` IN
  ('SHS Polo','SHS Blouse','SHS Vest','SHS Pants','SHS Skirt','SHS Uniform');

-- 4. Tell Laravel the migration already ran, so a later `php artisan migrate` does not repeat it
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_04_000001_add_iddept_to_uniformtype_table', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
