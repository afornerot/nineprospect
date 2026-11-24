-- Config applicative : interrupteur de l'intégration schedule
-- (client ETP de /app/src/Service/ScheduleApi.php, env SCHEDULE_URL /
-- SCHEDULE_SECRET).
--
-- Type `ouinon`, valeur par défaut « non » : l'intégration reste désactivée
-- tant que la valeur n'est pas passée à « oui » dans /admin/config.
--
-- Idempotent : relançable sans dupliquer la ligne.

INSERT INTO config (code, title, value, default_value, type, config_group, config_order)
SELECT 'appActivateSchedule',
       'Intégration schedule (planning ETP)',
       'non',
       'non',
       'ouinon',
       'Intégrations',
       1
WHERE NOT EXISTS (SELECT 1 FROM config WHERE code = 'appActivateSchedule');
