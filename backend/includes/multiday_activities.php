<?php
// Shared helper for the multiday_activities table (migrate/015_multiday_activities.sql).

// Whether the migration has been applied yet. Mirrors absences_available()
// in includes/absences.php — lets admin/index.php's dashboard (which runs
// against any deployment, migrated or not) skip the count instead of a
// fatal "table doesn't exist" error.
function multiday_activities_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        $available = (bool) $pdo->query("SHOW TABLES LIKE 'multiday_activities'")->fetchColumn();
    }
    return $available;
}
