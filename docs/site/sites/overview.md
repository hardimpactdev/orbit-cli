# sites overview

Lists sites that have a public folder (real web sites), including domain, PHP version, and path.

- Uses SiteScanner::scanSites to filter to sites with a public/ directory.
- Resolves default PHP version from configuration.
- Outputs JSON with display name, repo, domain, path, and PHP version, or renders a table.

Failure and recovery paths

- If no sites are found, warns and exits successfully.

Inputs and options

- --json

Key integrations

- SiteScanner (filesystem + config + database)
- ConfigManager (default PHP version)
