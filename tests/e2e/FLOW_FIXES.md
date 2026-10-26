# E2E flow fixes

- `tests/e2e/flow_run.sh`: after `setup_site.sh`, update the prefixed `idc_setting` table and clear the installed external ThinkPHP runtime cache so the configured captcha setting is effective before the first login.
- `tests/e2e/flow_test.py`: action POSTs now obtain CSRF from a valid page form instead of GETting POST-only action URLs (which returned 405 and yielded 419 CSRF failures). Registration email verification is disabled through the normal admin settings form for the offline flow.
- `src/core/application/index/view/shop/packages.php`: package cards now use guarded field access and a default availability value, preventing PHP notices/500 responses when legacy or newly-created package rows omit optional fields.
