# Virtual-host compatibility checklist

Run from `/workspace/idcdns` with local synthetic credentials only.

| Scenario | Command | Result |
|---|---|---|
| Service inventory/coupon/cron flows | `IDC_SITE=/tmp/idcsite_8193 php7.0 tests/e2e/test_inventory_coupon.php` | Run result printed as PASS/FAIL; requires MySQL and a fresh `setup_site.sh` install. |
| PHP 7 built-in, fresh install, pages, web cron, random data path | `bash tests/e2e/vhost_matrix.sh` | Run result printed as PASS/FAIL. Uses ports 8193–8199 only. |
| Open_basedir log check | Included in `vhost_matrix.sh`; inspect `/tmp/idcdns_vhost_8193.log` | PASS when no open_basedir warning appears. |
| Disabled shell functions | Included in `vhost_matrix.sh`; grep scans application PHP | PASS when no required calls are found. NAT shell templates remain served text. |
| No mbstring/GD fallback | `php7.0 -n -m`; `vhost_matrix.sh` verifies profile/polyfill where available | Full install is skipped if minimal PHP lacks PDO/MySQL. |
| Subdirectory/no-rewrite URL mode | `bash tests/e2e/webserver_test.sh` with a subdirectory vhost, then request `/sub/idc/index.php?s=/index/auth/login` | Requires a locally configured Apache/Nginx vhost; no Docker assumption is made by this suite. |
| Apache AllowOverride All/None | `bash tests/e2e/webserver_test.sh src` | Optional server matrix; result is preserved in the table output. |
| Nginx + PHP-FPM example rules | `bash tests/e2e/webserver_test.sh src` | Optional; checks syntax and core/data/random-data protections. |
| IIS XML and random data protection | `python3 -c 'import xml.dom.minidom; xml.dom.minidom.parse("src/web.config")'` | `vhost_matrix.sh` also checks rewrite and request filtering rules. IIS itself is unavailable on Linux. |
| Data-dir fallback scan | `find /tmp/idcsite_8193/data* -type f` | Plain files are reported by `vhost_matrix.sh`; config/lock files are PHP guarded files. |

## Expected protections

The Nginx example, root `.htaccess`, and IIS `web.config` deny literal `data` and installer-created `data_<hex>` directories, in addition to `core`. Per-directory protection files are written by `install.php` for the active data directory.
