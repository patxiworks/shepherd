<?php
// Bulk delete for the admin list tables. The table itself needs no markup: the
// "select rows" icon (layout_bottom.php) finds the rows that have a Delete form,
// lets the admin tick some and posts action=bulk_delete with ids[] to the
// page. A page handles that with bulk_run() around the same closure its single
// Delete uses, so both paths apply the same rules (scope, "in use" checks).
// Tables that show only the latest N rows also set data-bulk-total (how many
// rows exist) and data-bulk-extra (JSON of extra fields to post); the page then
// answers "select all N matching the filters" with bulk_delete_matching().
require_once __DIR__ . '/flash.php';

const BULK_MAX_IDS = 2000;

// The ticked ids posted as ids[].
function bulk_ids(): array
{
    return array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))))), 0, BULK_MAX_IDS);
}

function bulk_flash(int $deleted, array $errors, string $noun, string $plural): void
{
    $msg = $deleted ? "Deleted $deleted " . ($deleted === 1 ? $noun : $plural) . '.' : '';
    if ($errors) {
        $parts = [];
        foreach ($errors as $text => $n) {
            $parts[] = $text . ($n > 1 ? " (×$n)" : '');
        }
        $msg .= ($msg ? ' ' : '') . array_sum($errors) . ' not deleted: ' . implode(' ', array_slice($parts, 0, 3)) . (count($parts) > 3 ? ' …' : '');
    }
    flash($errors ? ($deleted ? 'warning' : 'error') : 'success', $msg ?: 'Nothing was deleted.');
}

// Deletes each posted id with $deleteOne(int $id): ?string (null = deleted,
// otherwise the reason it wasn't), then flashes one summary.
function bulk_run(callable $deleteOne, string $noun, string $plural): void
{
    $ids = bulk_ids();
    if (!$ids) {
        flash('error', 'Select at least one row.');
        return;
    }
    $deleted = 0;
    $errors = [];
    foreach ($ids as $id) {
        $err = $deleteOne($id);
        if ($err === null) {
            $deleted++;
        } else {
            $errors[$err] = ($errors[$err] ?? 0) + 1;
        }
    }
    bulk_flash($deleted, $errors, $noun, $plural);
}

// "Select all N matching the filters": deletes every row of $table (alias `a`)
// matching $whereSql, but only if there are still exactly the posted `expected`
// of them, so a list that changed since the admin looked isn't wiped. The ids
// are selected first because a WHERE that reads the same table (the duplicate
// checks) can't be used in a DELETE. $after($ids) runs once afterwards.
function bulk_delete_matching(PDO $pdo, string $table, string $whereSql, array $args, string $noun, string $plural, ?callable $after = null): void
{
    $stmt = $pdo->prepare("SELECT a.id FROM $table a WHERE $whereSql");
    $stmt->execute($args);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) !== (int) ($_POST['expected'] ?? -1)) {
        flash('error', 'The list changed since you selected it, so nothing was deleted. Please try again.');
        return;
    }
    $pdo->beginTransaction();
    try {
        foreach (array_chunk($ids, 500) as $chunk) {
            $pdo->prepare("DELETE FROM $table WHERE id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')')->execute($chunk);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if ($after) {
        $after($ids);
    }
    bulk_flash(count($ids), [], $noun, $plural);
}
