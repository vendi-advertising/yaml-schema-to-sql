<?php

declare(strict_types=1);

namespace Vendi\YamlSchemaToSql;

use Symfony\Component\Yaml\Yaml;

class table_schema_generator
{
    private $tables;

    private $column_templates;

    public function __construct(string $yaml_file)
    {
        $yaml_object = Yaml::parseFile($yaml_file);

        if (! array_key_exists('column_templates', $yaml_object)) {
            throw new \Exception('No column templates found in SQL YAML file... aborting');
        }

        if (! array_key_exists('tables', $yaml_object)) {
            throw new \Exception('No table found in SQL YAML file... aborting');
        }

        $this->tables = $yaml_object[ 'tables' ];
        $this->column_templates = $yaml_object[ 'column_templates' ];
    }

    public function get_sql(): string
    {
        $ret = [];
        foreach ($this->tables as $table_name => $table_parts) {
            $ret[] = $this->get_sql_for_table($table_name, $table_parts);
        }

        return implode("\n", $ret);
    }

    public function get_sql_for_table(string $table_name, array $table_parts): string
    {
        $sql[] = sprintf('CREATE TABLE `%1$s`', $table_name);
        $sql[] = '(';

        if (! array_key_exists('columns', $table_parts)) {
            throw new \Exception(sprintf('Table `%1$s` is missing columns... aborting', $table_name));
        }

        $lines = $this->get_column_lines($table_parts[ 'columns' ], $table_name, $table_parts);
        if (array_key_exists('constraints', $table_parts)) {
            $lines = array_merge($lines, [ '' ], $this->get_constraint_lines($table_parts[ 'constraints' ], $table_name, $table_parts));
        }

        $final_lines = [];
        for ($i = 0; $i < count($lines); $i++) {
            if ('' === trim($lines[ $i ])) {
                $final_lines[$i] = '';
                continue;
            }
            $final_lines[ $i ] = '    ' . trim($lines[ $i ]);
            if ($i < count($lines) - 1) {
                $final_lines[ $i ] = $final_lines[ $i ] . ',';
            }
        }

        $sql = array_merge($sql, $final_lines);

        $sql[] = ');';
        $sql[] = '';

        return implode("\n", $sql);
    }

    public function get_column_lines(array $columns, string $table_name, array $table_parts): array
    {
        $line_parts = [];
        foreach ($columns as $column_name => $column_parts) {
            if (array_key_exists('template', $column_parts)) {
                if (! array_key_exists($column_parts[ 'template' ], $this->column_templates)) {
                    throw new \Exception(sprintf('Requested column template %1$s not found', $column_parts[ 'template' ]));
                }
                $column_parts = array_merge($this->column_templates[ $column_parts[ 'template' ] ], $column_parts);
                unset($column_parts[ 'template' ]);
            }

            $line_parts[ $column_name ] = [
                                            'column_name'   => '`' . $column_name . '`',
                                            'type_part'     => '',
                                            'null_part'     => '',
                                            'default_part'  => '',
                                        ];


            $null_part = '';
            if (array_key_exists('not_null', $column_parts)) {
                $null_part = $column_parts[ 'not_null' ] ? 'NOT NULL' : 'NULL';
            }
            $line_parts[ $column_name ][ 'null_part' ] = $null_part;
            unset($column_parts[ 'not_null' ]);

            $type_part = $column_parts[ 'type' ];
            if (array_key_exists('length', $column_parts)) {
                $type_part .= sprintf('(%1$s)', $column_parts[ 'length' ]);
            }
            if ('enum' === mb_strtolower($type_part)) {
                if (! array_key_exists('values', $column_parts)) {
                    throw new \Exception(sprintf('Enum `%1$s` for table `%2$s` is missing a values collection... aborting', $table_name, $column_name));
                }
                $values = $column_parts[ 'values' ];
                if (! is_array($values)) {
                    $values = [ $values ];
                }
                $type_part .= "('" . implode("', '", $values) . "')";
            }
            unset($column_parts[ 'type' ]);
            unset($column_parts[ 'length' ]);
            unset($column_parts[ 'values' ]);
            $line_parts[ $column_name ][ 'type_part' ] = $type_part;

            $default_part = '';
            if (array_key_exists('default', $column_parts)) {
                $default_part .= sprintf('DEFAULT %1$s', $column_parts[ 'default' ]);
            }
            unset($column_parts[ 'default' ]);
            $line_parts[ $column_name ][ 'default_part' ] = $default_part;

            if (0 !== count($column_parts)) {
                reset($column_parts);
                // dump( $column_parts );
                // die;
                throw new \Exception(sprintf('Extra column property `%1$s` found for column `%2$s`... aborting', key($column_parts), $column_name));
            }
        }

        $table = new \Console_Table(CONSOLE_TABLE_ALIGN_LEFT, '', 1);
        $table->addData($line_parts);
        $table->setAlign(2, CONSOLE_TABLE_ALIGN_RIGHT);
        return explode("\n", rtrim($table->getTable()));
    }

    public function get_constraint_lines(array $constraints, string $table_name, array $table_parts): array
    {
        $lines = [];
        $constraint_parts = [];
        foreach ($constraints as $constraint) {
            if (! array_key_exists('type', $constraint)) {
                throw new \Exception(sprintf('Contraint for table `%1$s` is missing a type... aborting', $table_name));
            }

            switch ($constraint[ 'type' ]) {
                case 'primary':
                    if (! array_key_exists('columns', $constraint)) {
                        throw new \Exception(sprintf('PK contraint for table `%1$s` is missing a columns... aborting', $table_name));
                    }

                    $columns = $constraint[ 'columns' ];
                    if (! is_array($columns)) {
                        $columns = [ $columns ];
                    }

                    $columns_quoted = [];
                    foreach ($columns as $k => $v) {
                        $columns_quoted[ $k ] = '`' . $v . '`';
                    }

                    $constraint_parts[] = [
                                            'CONSTRAINT',
                                            sprintf(
                                                        '`pk__%1$s__%2$s`',
                                                        $table_name,
                                                        implode('__', $columns)
                                                ),
                                            'PRIMARY KEY',
                                            sprintf(
                                                        '( %1$s )',
                                                        implode(', ', $columns_quoted)
                                                ),
                                            '',
                                        ];
                    break;

                case 'foreign':
                    if (! array_key_exists('column', $constraint)) {
                        throw new \Exception(sprintf('FK contraint for table `%1$s` is missing a column... aborting', $table_name));
                    }
                    if (! array_key_exists('references_column', $constraint)) {
                        throw new \Exception(sprintf('FK contraint for table `%1$s` is missing a references_column... aborting', $table_name));
                    }
                    if (! array_key_exists('references_table', $constraint)) {
                        throw new \Exception(sprintf('FK contraint for table `%1$s` is missing a references_table... aborting', $table_name));
                    }

                    $constraint_name = sprintf(
                                                'fk__%1$s__%2$s__%3$s__%4$s',
                                                $table_name,
                                                $constraint[ 'references_table' ],
                                                $constraint[ 'column' ],
                                                $constraint[ 'references_column' ]
                                    );

                    if (mb_strlen($constraint_name) > 63) {
                        $constraint_name = mb_substr($constraint_name, 0, 63);
                    }

                    $constraint_parts[] = [
                                            'CONSTRAINT',
                                            sprintf(
                                                        '`%1$s`',
                                                        $constraint_name
                                                ),
                                            'FOREIGN KEY',
                                            sprintf(
                                                        '( `%1$s` )',
                                                        $constraint[ 'column' ]
                                                ),
                                            sprintf(
                                                        'REFERENCES `%1$s` ( `%2$s` )',
                                                        $constraint[ 'references_table' ],
                                                        $constraint[ 'references_column' ]
                                                ),
                                        ];
                    break;

                default:
                    dump($constraint);
                    die;
                    throw new \Exception(sprintf('Unknown constraint type `%2$s` found for table `%1$s`... aborting', $table_name, $constraint[ 'type' ]));
            }
        }

        $table = new \Console_Table(CONSOLE_TABLE_ALIGN_LEFT, '', 1);
        $table->addData($constraint_parts);
        return explode("\n", rtrim($table->getTable()));
    }
}
