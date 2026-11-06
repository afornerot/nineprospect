-- Correctif de données : valeurs de pipeline rattachées à une étape qui
-- n'appartient plus au pipeline de leur vague.
--
-- Cause : changer le pipeline d'une vague ne purgeait pas les valeurs saisies
-- pour les étapes de l'ancien pipeline. Ces lignes ne sont affichées nulle part
-- (l'UI itère sur les étapes du pipeline courant) mais bloquent la suppression
-- de ces étapes via la FK `pipeline_valeur.etape_id`.
--
-- Cas mesuré : vague 22 « EOLE » (id 76) passée sur le pipeline 2 alors que ses
-- valeurs portent des étapes du pipeline 4 (Contact/RDV/Refus).
--
-- Suppression ciblée : uniquement les valeurs dont l'étape sort du pipeline de
-- la vague. Aucune donnée cohérente n'est touchée.

-- Avant : lignes incohérentes.
SELECT e.pipeline_id AS etape_pipeline, s.pipeline_id AS vague_pipeline, COUNT(*) AS total
  FROM pipeline_valeur pv
  JOIN pipeline_etape e ON e.id = pv.etape_id
  JOIN prospect_sprint ps ON ps.id = pv.prospect_sprint_id
  JOIN sprint s ON s.id = ps.sprint_id
 WHERE (e.pipeline_id IS NULL OR s.pipeline_id IS NULL OR e.pipeline_id <> s.pipeline_id)
 GROUP BY e.pipeline_id, s.pipeline_id;

DELETE pv
  FROM pipeline_valeur pv
  JOIN pipeline_etape e ON e.id = pv.etape_id
  JOIN prospect_sprint ps ON ps.id = pv.prospect_sprint_id
  JOIN sprint s ON s.id = ps.sprint_id
 WHERE (e.pipeline_id IS NULL OR s.pipeline_id IS NULL OR e.pipeline_id <> s.pipeline_id);

-- Après : la requête ne doit plus rien renvoyer.
SELECT e.pipeline_id AS etape_pipeline, s.pipeline_id AS vague_pipeline, COUNT(*) AS total
  FROM pipeline_valeur pv
  JOIN pipeline_etape e ON e.id = pv.etape_id
  JOIN prospect_sprint ps ON ps.id = pv.prospect_sprint_id
  JOIN sprint s ON s.id = ps.sprint_id
 WHERE (e.pipeline_id IS NULL OR s.pipeline_id IS NULL OR e.pipeline_id <> s.pipeline_id)
 GROUP BY e.pipeline_id, s.pipeline_id;
