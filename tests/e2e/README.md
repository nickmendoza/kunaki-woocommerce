# Real-install checks

Unit tests mock WooCommerce, so they can't catch behaviour that only shows up on a real install. For example, WooCommerce ignores `meta_query` on order queries unless HPOS is enabled, and that passed every unit test. These checks run the plugin on a real WordPress and WooCommerce, under both HPOS and legacy order storage.

## Run

Needs Docker and Python 3.

```sh
tests/e2e/run.sh
```

It takes a few minutes. It builds fresh containers, installs the plugin from your checkout, runs every scenario, prints the pass and fail counts, and deletes the containers. The exit code is non-zero if any check fails.

The full output goes to `tests/e2e/out.txt`, and WP-CLI's stderr to `tests/e2e/err-full.txt`. Both are git-ignored.

## What's real and what's faked

- **Real:** WordPress, WooCommerce (pinned in `setup.sh`), MariaDB, Action Scheduler, the Store API, the block cart and checkout pages, and the admin screens.
- **Faked:** Kunaki's API. `kunaki-mock.php` is a must-use plugin that intercepts requests to kunaki.com. So these checks never place a real Kunaki order. Test that by hand in Kunaki's Test mode before a release.

All passwords and emails in these files are throwaway values for the disposable containers.

## Files

| File | What it does |
|---|---|
| `run.sh` | Starts the containers, runs setup and the checks, and cleans up |
| `docker-compose.yml` | WordPress, WP-CLI and MariaDB. Mounts the repo read-only at `/src` |
| `setup.sh` | Installs WordPress and WooCommerce, copies the plugin in, and creates the test products |
| `kunaki-mock.php` | Fakes Kunaki's API. The `mock_kunaki_mode` option set to `down` simulates an outage |
| `s.php` | Server-side scenarios, run with `wp eval-file` |
| `run.py` | Runs the scenarios under both storage modes, and drives the Store API, block pages and admin over HTTP |

## Notes

- Run the whole thing on a fresh environment, which `run.sh` always does. The last step uninstalls the plugin and deletes product IDs, so rerunning scenarios afterwards gives false failures.
- WordPress is served on `127.0.0.1:18088` while the checks run.
- To switch order storage, use `wp wc hpos enable` and `wp wc hpos disable`. `wp option update` doesn't switch it.
