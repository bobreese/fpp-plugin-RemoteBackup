# Notes / Assumptions and License

[← Back to README](../README.md)

## Notes / assumptions

- This plugin was authored and syntax-checked outside of a live FPP system (no FPP
  install or PHP interpreter was available in the build environment), so treat it as a
  strong first cut: review `scripts/run_backup.sh` and `ajax.php` and smoke-test on a
  non-production Pi before relying on it for your show archive.
- The `/api/fppd/multiSyncSystems` response shape is parsed defensively (a few likely
  key names are tried); if your FPP version returns something different, remotes can
  always be added manually on the Config page as a fallback.

## License

[MIT](../LICENSE)
