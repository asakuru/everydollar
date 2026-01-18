# Storage Directory

This directory contains application logs and cache files.

## Contents

- `logs/` - Application log files
- `cache/` - Template cache (Twig)

## Permissions

On the server, ensure these directories are writable by the web server:

```bash
chmod 755 storage/logs storage/cache
```

## .gitignore

Log files and cache are excluded from git.
