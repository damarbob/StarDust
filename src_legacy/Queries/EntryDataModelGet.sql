SELECT
    entries.id as entry_id,
    entry_data.id,
    entry_data.fields,
    model_data.model_id as model_id,
    model_data.name as model_name,
    users.username AS created_by,
    entry_data.created_at AS date_created
FROM
    entry_data
    LEFT JOIN entries ON entry_data.entry_id = entries.id
    LEFT JOIN users ON entry_data.creator_id = users.id
    
    LEFT JOIN models ON entries.model_id = models.id
    LEFT JOIN model_data ON models.current_model_data_id = model_data.id
WHERE
    entry_data.deleted_at IS NULL