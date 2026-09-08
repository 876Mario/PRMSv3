-- Global toggle for original signed document submission confirmation

INSERT INTO system_config (config_key, config_value, description, created_at)
VALUES (
    'confirmation_submit_to_procurement_enabled',
    COALESCE(
        (SELECT config_value FROM system_config WHERE config_key = 'signed_document_upload_notice_enabled' LIMIT 1),
        '1'
    ),
    'Enable/disable original signed document confirmation before submission (1=enabled, 0=disabled)',
    NOW()
)
ON DUPLICATE KEY UPDATE config_value = config_value;
