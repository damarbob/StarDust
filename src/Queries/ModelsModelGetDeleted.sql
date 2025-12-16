-- File: app/Queries/ModelsModelGetDeleted.sql
SELECT
    models.id,
    model_data.name,
    model_data.fields,
    model_data.icon,
    users.username AS created_by,
    editors.username AS edited_by,
    models.created_at,
    model_data.created_at AS date_modified,
    model_data.id as data_id,
    deleters.username AS deleted_by,
    models.deleted_at AS date_deleted
FROM
    models
    LEFT JOIN model_data ON models.current_model_data_id = model_data.id
    
    LEFT JOIN users ON models.creator_id = users.id
    LEFT JOIN users AS editors ON model_data.creator_id = editors.id
    LEFT JOIN users AS deleters ON models.deleter_id = deleters.id
WHERE
    models.deleted_at IS NOT NULL