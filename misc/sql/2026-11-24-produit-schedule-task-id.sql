-- Migration : id de tâche schedule associé au produit (planning ETP).
--
-- `produit.schedule_task_id` : identifiant de la tâche schedule liée au
-- produit, renseigné seulement si l'intégration schedule est active
-- (SCHEDULE_URL + SCHEDULE_SECRET). Colonne nullable : aucun backfill.
ALTER TABLE produit ADD COLUMN schedule_task_id VARCHAR(64) NULL;
