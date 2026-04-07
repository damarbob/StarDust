-- File: app/Queries/EntriesModelGetDeleted.sql
SELECT
    entries.id,
    model_data.model_id as model_id,
    model_data.name as model_name,
    entry_data.fields,
    users.username AS created_by,
    editors.username AS edited_by,
    entries.created_at,
    entry_data.created_at AS date_modified,
    entry_data.id as data_id,
    deleters.username AS deleted_by,
    entries.deleted_at AS date_deleted
FROM
    entries
    LEFT JOIN entry_data ON entries.current_entry_data_id = entry_data.id
    
    LEFT JOIN models ON entries.model_id = models.id
    LEFT JOIN model_data ON models.current_model_data_id = model_data.id

    LEFT JOIN users ON entries.creator_id = users.id
    LEFT JOIN users AS editors ON entry_data.creator_id = editors.id
    LEFT JOIN users AS deleters ON entries.deleter_id = deleters.id
WHERE
    entries.deleted_at IS NOT NULL