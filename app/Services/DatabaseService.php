<?php

namespace App\Services;

use PDO;

class DatabaseService
{
    protected ?PDO $db = null;

    protected string $dbPath;

    public function __construct(?string $dbPath = null)
    {
        $this->dbPath = $dbPath ?? $this->getDefaultDbPath();
        $this->initDatabase();
    }

    protected function getDefaultDbPath(): string
    {
        // Allow override via environment variable for testing
        if ($testDbPath = getenv('LAUNCHPAD_TEST_DB')) {
            return $testDbPath;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        return "{$home}/.config/launchpad/database.sqlite";
    }

    protected function initDatabase(): void
    {
        try {
            $configDir = dirname($this->dbPath);

            if (! is_dir($configDir)) {
                @mkdir($configDir, 0755, true);
            }

            if (! file_exists($this->dbPath)) {
                @touch($this->dbPath);
            }

            $this->db = new PDO("sqlite:{$this->dbPath}");
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->db->exec('
                CREATE TABLE IF NOT EXISTS projects (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    slug VARCHAR(255) NOT NULL UNIQUE,
                    path VARCHAR(500) NOT NULL,
                    php_version VARCHAR(10) NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_projects_slug ON projects(slug)');
        } catch (\Exception) {
            // If database initialization fails, we continue without it
            $this->db = null;
        }
    }

    public function getProjectOverride(string $slug): ?array
    {
        if ($this->db === null) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM projects WHERE slug = ?');
        $stmt->execute([$slug]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function setProjectPhpVersion(string $slug, string $path, ?string $version): void
    {
        if ($this->db === null) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            INSERT INTO projects (slug, path, php_version, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(slug) DO UPDATE SET
                path = excluded.path,
                php_version = excluded.php_version,
                updated_at = excluded.updated_at
        ');
        $stmt->execute([$slug, $path, $version, $now, $now]);
    }

    public function removeProjectOverride(string $slug): void
    {
        if ($this->db === null) {
            return;
        }

        $stmt = $this->db->prepare('DELETE FROM projects WHERE slug = ?');
        $stmt->execute([$slug]);
    }

    public function getAllOverrides(): array
    {
        if ($this->db === null) {
            return [];
        }

        $stmt = $this->db->query('SELECT * FROM projects WHERE php_version IS NOT NULL');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPhpVersion(string $slug): ?string
    {
        $override = $this->getProjectOverride($slug);

        return $override['php_version'] ?? null;
    }

    /**
     * Get the database path (useful for testing).
     */
    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    /**
     * Clear all data from the database (useful for testing).
     */
    public function truncate(): void
    {
        if ($this->db === null) {
            return;
        }

        $this->db->exec('DELETE FROM projects');
    }
}
