#!/usr/bin/env php
<?php
/**
 * Verify the structure and data in multiple tables. Useful for verifying ETL changes.
 *
 * @author Steve Gallo <smgallo@buffalo.edu>
 */

require __DIR__ . '/../../configuration/linker.php';
restore_exception_handler();

define("DEFAULT_TRUNCATE_DIGITS", 0);
define("DEFAULT_COALESCE_VALUE", 0);

// Percent error is calculated as:
// PE = ABS((Expected - Observed) / Expected) * 100
// We will remove the 100 from the equation by dividing the allowed pct error by 100:
// PE / 100 = ABS((Expected - Observed) / Expected)
define("DEFAULT_ERROR_PERECENT", 0.01);

use CCR\Log;
use CCR\DB;

// ==========================================================================================
// Script options with defaults

// Allow initialization of some options from the configuration file

$scriptOptions = array(
    // Attempt to auto-detect how columns should be compared
    'autodetect-column-comparison' => false,
    // Tables for comparison
    'compare-tables'   => array(),
    // Coalesce these columns before comparing
    'coalesce-columns' => array(),
    // Configuration section to use when connecting to the database
    'database-config'  => "datawarehouse",
    // Destination table schema, defaults to source table schema if not set
    'dest-schema'      => null,
    // Exclude these columns from tables
    'exclude-columns'  => array(),
    // Ignore the column count between tables as long as the source columns are present in
    // the destination
    'ignore-column-count' => false,
    // Ignore the column types between tables
    'ignore-column-type'  => false,
    // Map these column names from source to destination tables
    'map-columns'      => array(),
    // Number of missing rows to display, all rows if NULL
    'num-missing-rows' => null,
    // Use a percent of experimental error when comparing these columns
    'pct-error-columns'    => array(),
    // Source table schema
    'source-schema'    => null,
    // Truncate these columns before comparing
    'truncate-columns' => array(),
    // Apply where clauses to query
    'wheres'           => array(),
    'verbosity'        => Log::NOTICE
);

// ==========================================================================================
// Process command line arguments

$options = array(
    'a'   => 'autodetect-column-comparison',
    '3:'  => 'coalesce-column:',
    'c:'  => 'database-config:',
    'd:'  => 'dest-schema:',
    'x:'  => 'exclude-column:',
    'h'   => 'help',
    '1'   => 'ignore-column-count',
    '2'   => 'ignore-column-type',
    'n:'  => 'num-missing-rows:',
    'p:'  => 'pct-error-column:',
    's:'  => 'source-schema:',
    't:'  => 'table:',
    '4:'  => 'truncate-column:',
    'v:'  => 'verbosity:',
    'w:'  => 'where:'
    );

$args = getopt(implode('', array_keys($options)), $options);

foreach ($args as $arg => $value) {
    switch ($arg) {

        case 'a':
        case 'autodetect-column-comparison':
            $scriptOptions['autodetect-column-comparison'] = true;
            break;

        case 'c':
        case 'database-config':
            $scriptOptions['database-config'] = $value;
            break;

        case 'coalesce-column':
            // Merge array because long and short options are grouped separately
            $scriptOptions['coalesce-columns'] = array_merge(
                $scriptOptions['coalesce-columns'],
                ( is_array($value) ? $value : array($value) )
            );
            break;

        case 'd':
        case 'dest-schema':
            $scriptOptions['dest-schema'] = $value;
            break;

        case 'ignore-column-count':
            $scriptOptions['ignore-column-count'] = true;
            break;

        case 'ignore-column-type':
            $scriptOptions['ignore-column-type'] = true;
            break;

        case 'n':
        case 'num-missing-rows':
            $scriptOptions['num-missing-rows'] = $value;
            break;

        case 'p':
        case 'pct-error-column':
            // Merge array because long and short options are grouped separately
            $scriptOptions['pct-error-columns'] = array_merge(
                $scriptOptions['pct-error-columns'],
                ( is_array($value) ? $value : array($value) )
            );
            break;

        case 's':
        case 'source-schema':
            $scriptOptions['source-schema'] = $value;
            break;

        case 't':
        case 'table':
            $value = ( is_array($value) ? $value : array($value) );
            foreach ( $value as $option ) {
                $parts = explode('=', $option);
                if ( 1 == count($parts) ) {
                    $scriptOptions['compare-tables'][] = array($parts[0], $parts[0]);
                } elseif ( 2 == count($parts) ) {
                    $scriptOptions['compare-tables'][] = array($parts[0], $parts[1]);
                } else {
                    usage_and_exit("Tables must be in the form 'table' or 'source_table=dest_table'");
                }
            }
            break;

        case 'truncate-column':
            // Merge array because long and short options are grouped separately
            $scriptOptions['truncate-columns'] = array_merge(
                $scriptOptions['truncate-columns'],
                ( is_array($value) ? $value : array($value) )
            );
            break;

        case 'x':
        case 'exclude-column':
            // Merge array because long and short options are grouped separately
            $scriptOptions['exclude-columns'] = array_merge(
                $scriptOptions['exclude-columns'],
                ( is_array($value) ? $value : array($value) )
            );
            break;

        case 'w':
        case 'where':
            // Merge array because long and short options are grouped separately
            $scriptOptions['wheres'] = array_merge(
                $scriptOptions['wheres'],
                ( is_array($value) ? $value : array($value) )
            );
            break;

        case 'v':
        case 'verbosity':
            switch ( $value ) {
                case 'debug':
                    $scriptOptions['verbosity'] = Log::DEBUG;
                    break;
                case 'info':
                    $scriptOptions['verbosity'] = Log::INFO;
                    break;
                case 'notice':
                    $scriptOptions['verbosity'] = Log::NOTICE;
                    break;
                case 'warning':
                    $scriptOptions['verbosity'] = Log::WARNING;
                    break;
                case 'quiet':
                    $scriptOptions['verbosity'] = Log::EMERG;
                    break;
                default:
                    usage_and_exit("Invalid verbosity level: $value");
                    break;
            }  // switch ( $value )
            break;

        case 'h':
        case 'help':
            usage_and_exit();
            break;

        default:
            usage_and_exit("Invalid option: $arg");
            break;
    }
}  // foreach ($args as $arg => $value)

if ( null === $scriptOptions['source-schema'] ) {
    usage_and_exit("Source schema not specified");
}

if ( 0 == count($scriptOptions['compare-tables']) ) {
    usage_and_exit("No tables specified for comparison");
}

if ( null === $scriptOptions['dest-schema'] ) {
    $scriptOptions['dest-schema'] = $scriptOptions['source-schema'];
}

// ------------------------------------------------------------------------------------------
// Set up the logger

$conf = array(
    'mail' => false
);

if ( null !== $scriptOptions['verbosity'] ) {
    $conf['consoleLogLevel'] = $scriptOptions['verbosity'];
}

$logger = Log::factory('ETLv2', $conf);

try {
    $dbh = DB::factory($scriptOptions['database-config']);
} catch (Exception $e) {
    exit("Error connecting to database: " . $e->getMessage() . "\n");
}

// ------------------------------------------------------------------------------------------
// Verify the tables

$success = true;

foreach ($scriptOptions['compare-tables'] as $table ) {
    list($srcTable, $destTable) = $table;
    $retval = compareTables($srcTable, $destTable);
    $success = $success && $retval;
}

exit($success ? 0 : 1);

/* ------------------------------------------------------------------------------------------
 * Compare the structure and contents of 2 tables.
 * -------------------------------------------------------------------------------------------
 */

function compareTables($srcTable, $destTable)
{
    global $scriptOptions, $logger;

    $srcSchema = $scriptOptions['source-schema'];
    $destSchema = $scriptOptions['dest-schema'];

    // Tables may already contain a schema specification. If it does, override the defdault schema.

    if ( false !== strpos($srcTable, '.') ) {
        $parts = explode('.', $srcTable);
        if ( 2 != count($parts) ) {
            $logger->err("Too many dots in source table name: '$srcTable'");
            return false;
        }
        list($srcSchema, $srcTable) = $parts;
    }

    if ( false !== strpos($destTable, '.') ) {
        $parts = explode('.', $destTable);
        if ( 2 != count($parts) ) {
            $logger->err("Too many dots in destination table name: '$destTable'");
            return false;
        }
        list($destSchema, $destTable) = $parts;
    }

    $qualifiedSrcTable = sprintf("%s.%s", $srcSchema, $srcTable);
    $qualifiedDestTable = sprintf("%s.%s", $destSchema, $destTable);
    $logger->notice(sprintf("Compare tables src=%s, dest=%s", $qualifiedSrcTable, $qualifiedDestTable));
    if ( 0 != count($scriptOptions['exclude-columns']) ) {
        $logger->info("Exclude columns: " . implode(', ', $scriptOptions['exclude-columns']));
    }

    if ( $qualifiedSrcTable == $qualifiedDestTable ){
        $logger->warning(sprintf(
            "Cannot compare a table to itself: %s.%s == %s.%s",
            $qualifiedSrcTable,
            $qualifiedDestTable
        ));
        return false;
    }

    // Verify number and type of columns

    $srcTableColumns = getTableColumns($srcTable, $srcSchema, $scriptOptions['exclude-columns']);
    $destTableColumns = getTableColumns($destTable, $destSchema, $scriptOptions['exclude-columns']);

    if ( false === $srcTableColumns || false === $destTableColumns ) {
        return false;
    }

    $numSrcColumns = count($srcTableColumns);
    $numDestColumns = count($destTableColumns);

    if ( ! $scriptOptions['ignore-column-count'] && $numSrcColumns != $numDestColumns ) {
        $logger->err(sprintf(
            "Column number mismatch %s (%d); dest %s (%d)",
            $qualifiedSrcTable,
            $numSrcColumns,
            $qualifiedDestTable,
            $numDestColumns
        ));
        return false;
    }

    $missing = array_diff(array_keys($srcTableColumns), array_keys($destTableColumns));
    if ( 0 != count($missing) ) {
        $logger->err(sprintf("%s missing columns: %s", $qualifiedDestTable, implode(', ', $missing)));
        return false;
    }

    $logger->info(sprintf("%d columns", $numSrcColumns));

    $mismatch = false;
    foreach ( $srcTableColumns as $k => $v ) {
        if ( ! array_key_exists($k, $destTableColumns) ) {
            $logger->warning(
                sprintf("Dest missing %s type=%s key=%s", $k, $v['type'], $v['key_type'])
            );
            $mismatch = true;
        } elseif ( $v != $destTableColumns[$k] && ! $scriptOptions['ignore-column-type'] ) {
            $logger->err(sprintf(
                "Column mismatch %s: src type=%s is_nullable=%s %s, dest type=%s is_nullable=%s %s",
                $k,
                $v['type'],
                $v['is_nullable'],
                ( "" != $v['key_type'] ? "key=" . $v['key_type'] : "" ),
                $destTableColumns[$k]['type'],
                $destTableColumns[$k]['is_nullable'],
                ( "" != $destTableColumns[$k]['key_type'] ? "key=" . $destTableColumns[$k]['key_type'] : "" )
            ));
            $mismatch = true;
        }
    }
    if ( $mismatch ) {
        return false;
    }

    $numSrcRows = getTableRows($srcTable, $srcSchema);
    $numDestRows = getTableRows($destTable, $destSchema);
    $logger->info(sprintf(
        "Row counts: %s = %s; %s = %s",
        $qualifiedSrcTable,
        number_format($numSrcRows),
        $qualifiedDestTable,
        number_format($numDestRows)
    ));

    return compareTableData(
        $srcTable,
        $destTable,
        $srcSchema,
        $destSchema,
        $srcTableColumns,
        $destTableColumns
    );

}  // compareTables()

/* ------------------------------------------------------------------------------------------
 * Query the information schema for table column information.
 * -------------------------------------------------------------------------------------------
 */

function getTableColumns($table, $schema, array $excludeColumns)
{
    global $dbh, $logger;
    $tableName = "`$schema`.`$table`";

    $where = array(
        "table_schema = :schema",
        "table_name = :tablename",
    );

    if ( 0 != count($excludeColumns) ) {
        $excludeColumns = array_map(
            function ($c) {
                return "'$c'";
            },
            $excludeColumns
        );
        $where[] = "column_name NOT IN (" . implode(',', $excludeColumns) . ")";
    }

    $sql = "SELECT
column_name as name,
column_type as type,
column_key as key_type,
UPPER(data_type) as data_type,
UPPER(is_nullable) as is_nullable
FROM information_schema.columns
" . ( 0 != count($where) ? "WHERE " . implode(' AND ', $where) : "" ) ."
ORDER BY ordinal_position ASC";

    $params = array(
        ":schema" => $schema,
        ":tablename"  => $table
    );

    try {
        $stmt = $dbh->prepare($sql);
        $stmt->execute($params);
    } catch ( Exception $e ) {
        $logger->err("Error retrieving column names for '$tableName': " . $e->getMessage());
        exit();
    }

    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ( 0 == count($result) ) {
        $logger->err("Table '$tableName' does not exist");
        return false;
    }

    $retval = array();

    foreach ( $result as $row) {
        $retval[$row['name']] = $row;
    }

    // Sort the columns because there is no guarantee the order they are returned
    ksort($retval);

    return $retval;
}  // getTableColumns()

/* ------------------------------------------------------------------------------------------
 * Query the information schema for the number of table rows
 * -------------------------------------------------------------------------------------------
 */

function getTableRows($table, $schema)
{
    global $dbh, $logger;
    $tableName = "`$schema`.`$table`";

    $sql = "SELECT COUNT(*) AS table_rows FROM $tableName";

    try {
        $stmt = $dbh->prepare($sql);
        $stmt->execute();
    } catch ( Exception $e ) {
        $logger->err("Error retrieving table information for '$tableName': " . $e->getMessage());
        exit();
    }

    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ( 0 == count($result) ) {
        $logger->err("Table '$tableName' does not exist");
        exit();
    }

    $row = array_shift($result);

    return $row['table_rows'];

}  // getTableRows()

/* ------------------------------------------------------------------------------------------
 * Compare the data in two tables
 * -------------------------------------------------------------------------------------------
 */

function compareTableData(
    $srcTable,
    $destTable,
    $srcSchema,
    $destSchema,
    array $srcTableColumnInfo,
    array $destTableColumnInfo
) {
    global $dbh, $logger, $scriptOptions;
    $srcTableName = "`$srcSchema`.`$srcTable`";
    $destTableName = "`$destSchema`.`$destTable`";

    $srcTableColumns = array_keys($srcTableColumnInfo);
    $firstCol = current($srcTableColumns);

    // Try to automatically determine the comparison based on the column type.
    //
    // 1. If either column is nullable, the values need to be coalesced to a non-null
    //    value before comparing.
    // 2. If at least one column is a double/float/decimal and differs from the other
    //    column, truncate the columns before comparison and add the percent-difference
    //    calculation. Often times, the value of a double that has been calculated in an
    //    aggregate function may differ after several digits in the mantissa or MySQL may
    //    use scientific notation to show an approximate-value numeric literal.

    $truncateColumns = array();
    $errorColumns = array();
    $coalesceColumns = array();

    if ( $scriptOptions['autodetect-column-comparison'] ) {
        $dataTypes = array('DECIMAL', 'NUMERIC', 'DOUBLE', 'FLOAT');

        foreach ( $srcTableColumnInfo as $key => $srcColInfo ) {
            if ( ! array_key_exists($key, $destTableColumnInfo) ) {
                continue;
            }
            $destColInfo = $destTableColumnInfo[$key];

            if ( 'YES' == $srcColInfo['is_nullable'] || 'YES' == $destColInfo['is_nullable'] ) {
                $coalesceColumns[$key] = DEFAULT_COALESCE_VALUE;
            }

            if ( in_array($srcColInfo['data_type'], $dataTypes) || in_array($destColInfo['data_type'], $dataTypes) ) {
                $truncateColumns[$key] = DEFAULT_TRUNCATE_DIGITS;
                // Divide by 100 to remove the "* 100" from the pct error formula.
                $errorColumns[$key] = DEFAULT_ERROR_PERECENT / 100;
            }
        }
    }

    // Find the first non-nullable column to use for the comparison. Otherwise, a column
    // might have a valid value of NULL and give false positives.

    $comparisonColumn = null;
    foreach ( $destTableColumnInfo as $colName => $colInfo ) {
        if ( 'NO' == $colInfo['is_nullable'] ) {
            $comparisonColumn = $colName;
            break;
        }
    }

    if ( null === $comparisonColumn ) {
        $comparisonColumn = $firstCol;
        print "WARNING: No non-nullable columns, potential for false positives." . PHP_EOL;
    }

    // Determine the columns to compute the percent error for, if any.

    foreach ( $scriptOptions['pct-error-columns'] as $column ) {
        $parts = explode(',', $column);
        // Divide by 100 to remove the "* 100" from the pct error formula
        $errorColumns[$parts[0]] = ( 2 == count($parts) ? $parts[1] / 100 : DEFAULT_ERROR_PERECENT / 100 );
    }

    // Determine the columns to coalesce prior to comparison, if any.

    foreach ( $scriptOptions['coalesce-columns'] as $column ) {
        $parts = explode(',', $column);
        $coalesceColumns[$parts[0]] = ( 2 == count($parts) ? $parts[1] : DEFAULT_COALESCE_VALUE );
    }

    // Determine the columns to truncate prior to comparison, if any.

    foreach ( $scriptOptions['truncate-columns'] as $column ) {
        $parts = explode(',', $column);
        $truncateColumns[$parts[0]] = ( 2 == count($parts) ? $parts[1] : DEFAULT_TRUNCATE_DIGITS );
    }

    // Generate the ON clause using on the source table columns. This ignores columns
    // present in the destination table that do not exist in the source table.

    $constraints = array_map(
        function ($col) use ($errorColumns, $coalesceColumns, $truncateColumns) {

            $srcCol = sprintf('src.%s', $col);
            $destCol = sprintf('dest.%s', $col);

            if ( array_key_exists($col, $coalesceColumns) ) {
                $srcCol = sprintf('COALESCE(%s, %s)', $srcCol, $coalesceColumns[$col]);
                $destCol = sprintf('COALESCE(%s, %s)', $destCol, $coalesceColumns[$col]);
            }

            if ( array_key_exists($col, $truncateColumns) ) {
                $srcCol = sprintf('TRUNCATE(%s, %d)', $srcCol, $truncateColumns[$col]);
                $destCol = sprintf('TRUNCATE(%s, %d)', $destCol, $truncateColumns[$col]);
            }

            $onStr = sprintf('%s <=> %s', $srcCol, $destCol);

            if ( array_key_exists($col, $errorColumns) ) {
                $onStr = sprintf(
                    '(%s OR ABS((%s - %s) / %s) <= %.15f)',
                    $onStr,
                    $destCol,
                    $srcCol,
                    $srcCol,
                    $errorColumns[$col]
                );
            }

            return $onStr;
        },
        $srcTableColumns
    );

    $where = array(
        "dest.$comparisonColumn IS NULL"
    );

    if ( 0 != count($scriptOptions['wheres']) ) {
        $where = array_merge($where, $scriptOptions['wheres']);
    }

    $sql = "
SELECT src.*
FROM $srcTableName src
LEFT OUTER JOIN $destTableName dest ON (" . join("\nAND ", $constraints) . ")"
        . ( 0 != count($where) ? "\nWHERE " . implode("\nAND ", $where) : "" )
        . ( null !== $scriptOptions['num-missing-rows']
            ? "\nLIMIT " . $scriptOptions['num-missing-rows']
            : "" );

    $logger->debug($sql);

    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $numRows = $stmt->rowCount();

    if ( 0 != $numRows ) {
        $logger->warning(sprintf("Missing %d rows in %s.%s", $numRows, $destSchema, $destTable));
        while ( $row = $stmt->fetch(PDO::FETCH_ASSOC) ) {
            $logger->warning(sprintf("Missing row: %s", print_r($row, 1)));
        }
    } else {
        $logger->notice("Identical");
    }

    return (0 == $numRows);

}  // compareTableData()

/* ------------------------------------------------------------------------------------------
 * Display usage text and exit with error status.
 * ------------------------------------------------------------------------------------------
 */

function usage_and_exit($msg = null)
{
    global $argv, $scriptOptions;

    if ($msg !== null) {
        fwrite(STDERR, "\n$msg\n\n");
    }

    $defaultPctError = DEFAULT_ERROR_PERECENT;
    $defaultCoalesce = DEFAULT_COALESCE_VALUE;
    $defaultTruncate = DEFAULT_TRUNCATE_DIGITS;

    fwrite(
        STDERR,
        <<<"EOMSG"
Usage: {$argv[0]}

    -h, --help
    Display this help

    -a, --autodetect-column-comparison
    Attempt to auto-detect how columns should be compared based on the source and destination column type and whether or not they are nullable.

    --coalesce-column <column>[,<value>]
    Coalesce <column> to <value> (default $defaultCoalesce) before comparing. This is useful when comparing values that may be NULL.

    -c, --database-config
    The portal_settings.ini section to use for database configuration parameters

    -d, --dest-schema <destination_schema>
    The schema for the destination tables. If not specified the source schema will be used.

    --ignore-column-count
    Ignore the column count between tables as long as the source columns are present in the destination.

    --ignore-column-type
    Ignore the column types between tables, useful for comparing the effect of data type changes.

    -n, --num-missing-rows <number_of_rows>
    Display this number of missing rows. If not specified, all missing rows are displayed.

    -p, --pct=error-column <column>[,error>]
        Compute the percent error between the source and destination columns and ensure that it is less than <error> (default {$defaultPctError}). This is useful when comparing doubles or values that have been computed and may differ in decimal precision. See --truncate-column.

    -s, --source-schema <source_schema>
    The schema for the source tables.

    -t, --table <table_name>
    -t, --table <source_table_name>=<dest_table_name>
    A table to compare between the source and destination schemas. Use the 2nd form to specify different names for the source and destination tables. Table names may also include a schema designation, in which case the default schema will not be added. May be specified multiple times.

    --truncate-column <column>[,<digits>]
    Truncate <column> to <digits> (default $defaultTruncate) before comparing. This is useful when comparing fractional values or squares of fractional values.

    -w, --where <where_clause_fragment>
    Add a WHERE clause to the table comparison. The table aliass "src" and "dest" refer to the source and destination tables, respectively.

    -x, --exclude-column <column>
    Exclude this column from the comparison. May be specified multiple times.

    -v, --verbosity {debug, info, notice, warning, quiet} [default notice]
    Level of verbosity to output from the ETL process

EOMSG
    );

    exit(1);
}
?>
