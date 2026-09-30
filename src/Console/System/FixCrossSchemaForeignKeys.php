<?php

namespace WCAA\Console\System;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Console\AbstractCommand;

/**
 * Repairs foreign keys that point at a database other than the current one.
 *
 * InnoDB stores the schema name inside a foreign key definition. Restoring a
 * dump into a differently named database - which is what bin/a.sh does when the
 * source and target DATABASE_NAME differ - leaves every constraint pointing at
 * the old schema. The referenced tables are then unreachable and *any* insert
 * into the child table fails with errno 1452, which silently disables whole
 * components (links, events, attachments, notifications...).
 *
 * Detection and repair are driven from information_schema, so the command is
 * idempotent: on a healthy install it finds nothing and exits successfully.
 */
class FixCrossSchemaForeignKeys extends AbstractCommand
{
    /**
     * @Inject
     * @var \PDO
     */
    protected $pdo;

    protected function configure()
    {
        $this->setName("system:fix-cross-schema-fks")
            ->addOption("dry-run", "d", InputOption::VALUE_NONE, "Only report what would change")
            ->setDescription("Repair foreign keys that reference a different database (after a restore or rename)");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $broken = $this->findBrokenConstraints();

        if (!$broken) {
            $output->writeln("<info>No cross-schema foreign keys found - nothing to repair.</info>");
            return self::SUCCESS;
        }

        $output->writeln(sprintf(
            "<comment>Found %d foreign key(s) referencing another database:</comment>",
            count($broken)
        ));

        $table = new Table($output);
        $table->setHeaders(['Constraint', 'Table', 'Columns', 'Currently references', 'Orphan rows']);
        $blocked = [];
        foreach ($broken as $constraint) {
            $orphans = $this->countOrphans($constraint);
            if ($orphans > 0) {
                $blocked[] = $constraint['name'];
            }
            $table->addRow([
                $constraint['name'],
                $constraint['table'],
                join(', ', $constraint['columns']),
                $constraint['ref_schema'] . '.' . $constraint['ref_table'],
                $orphans > 0 ? "<error>{$orphans}</error>" : '0',
            ]);
        }
        $table->render();

        if ($blocked) {
            $output->writeln("");
            $output->writeln("<error>Cannot repair: rows exist whose referenced record is missing.</error>");
            $output->writeln("<error>Clean up the orphan rows first, then re-run. Affected: " . join(', ', $blocked) . "</error>");
            return self::FAILURE;
        }

        if ($dryRun) {
            $output->writeln("");
            $output->writeln("<comment>Dry run - no changes applied.</comment>");
            return self::SUCCESS;
        }

        $output->writeln("");
        $repaired = 0;
        foreach ($broken as $constraint) {
            try {
                $this->repair($constraint);
                $output->writeln("<info>repaired {$constraint['table']}.{$constraint['name']}</info>");
                $repaired++;
            } catch (\Throwable $e) {
                //Report and continue: one unrepairable constraint should not stop the rest.
                $output->writeln("<error>failed {$constraint['table']}.{$constraint['name']}: {$e->getMessage()}</error>");
            }
        }

        $output->writeln("");
        $output->writeln("<info>Repaired {$repaired} of " . count($broken) . " constraint(s).</info>");
        $remaining = $this->findBrokenConstraints();
        if ($remaining) {
            $output->writeln("<error>" . count($remaining) . " constraint(s) still reference another schema.</error>");
            return self::FAILURE;
        }
        return self::SUCCESS;
    }

    /**
     * Group information_schema rows into one entry per constraint, since a
     * composite key spans several rows.
     *
     * @return array
     */
    private function findBrokenConstraints()
    {
        $sql = "
            SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME,
                   k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                   k.ORDINAL_POSITION,
                   r.UPDATE_RULE, r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
             AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             AND r.TABLE_NAME = k.TABLE_NAME
            WHERE k.TABLE_SCHEMA = DATABASE()
              AND k.REFERENCED_TABLE_SCHEMA IS NOT NULL
              AND k.REFERENCED_TABLE_SCHEMA <> DATABASE()
            ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION
        ";

        $grouped = [];
        foreach ($this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $key = $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'name' => $row['CONSTRAINT_NAME'],
                    'table' => $row['TABLE_NAME'],
                    'ref_schema' => $row['REFERENCED_TABLE_SCHEMA'],
                    'ref_table' => $row['REFERENCED_TABLE_NAME'],
                    'columns' => [],
                    'ref_columns' => [],
                    'on_update' => $row['UPDATE_RULE'],
                    'on_delete' => $row['DELETE_RULE'],
                ];
            }
            $grouped[$key]['columns'][] = $row['COLUMN_NAME'];
            $grouped[$key]['ref_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }
        return array_values($grouped);
    }

    /**
     * Rows whose referenced record does not exist in the *current* schema.
     * Re-adding the constraint would fail on these.
     *
     * @return int
     */
    private function countOrphans(array $constraint)
    {
        $conditions = [];
        $joins = [];
        foreach ($constraint['columns'] as $i => $column) {
            $refColumn = $constraint['ref_columns'][$i];
            $joins[] = sprintf('r.%s = c.%s', $this->quote($refColumn), $this->quote($column));
            $conditions[] = sprintf('c.%s IS NOT NULL', $this->quote($column));
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s c LEFT JOIN %s r ON %s WHERE (%s) AND r.%s IS NULL',
            $this->quote($constraint['table']),
            $this->quote($constraint['ref_table']),
            join(' AND ', $joins),
            join(' AND ', $conditions),
            $this->quote($constraint['ref_columns'][0])
        );

        try {
            return (int)$this->pdo->query($sql)->fetchColumn();
        } catch (\Throwable $e) {
            //A missing referenced table in this schema means everything is orphaned.
            $this->logger->error("Orphan check failed for {$constraint['name']}: {$e->getMessage()}");
            return -1;
        }
    }

    /**
     * Drop the constraint and re-add it against the current schema, preserving
     * the original ON UPDATE / ON DELETE behaviour.
     */
    private function repair(array $constraint)
    {
        $columns = join(', ', array_map([$this, 'quote'], $constraint['columns']));
        $refColumns = join(', ', array_map([$this, 'quote'], $constraint['ref_columns']));

        $this->pdo->exec(sprintf(
            'ALTER TABLE %s DROP FOREIGN KEY %s',
            $this->quote($constraint['table']),
            $this->quote($constraint['name'])
        ));

        $this->pdo->exec(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON UPDATE %s ON DELETE %s',
            $this->quote($constraint['table']),
            $this->quote($constraint['name']),
            $columns,
            $this->quote($constraint['ref_table']),
            $refColumns,
            $this->sanitizeRule($constraint['on_update']),
            $this->sanitizeRule($constraint['on_delete'])
        ));
    }

    /**
     * Identifiers come from information_schema, not user input, but they are
     * still concatenated into DDL - backtick-quote them rather than trusting.
     */
    private function quote($identifier)
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Referential actions are keywords and cannot be bound; accept only the
     * fixed set InnoDB reports.
     */
    private function sanitizeRule($rule)
    {
        $allowed = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'];
        $rule = strtoupper(trim((string)$rule));
        return in_array($rule, $allowed, true) ? $rule : 'RESTRICT';
    }
}
