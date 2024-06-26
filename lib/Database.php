<?php

namespace Gust;

class Database {

	public static function create_tables($charset, $engine) {
		$sqls = [];
	
		// iterate through each model and create sql table
		foreach (KYTE_MODELS as $model) {
			$tbl_name = $model['name'];
			$sqls[$tbl_name] = self::create_table($model, $charset, $engine);
		}

		return $sqls;
	}

	public static function create_table($model, $charset, $engine) {
		$tbl_name = $model['name'];
		$cols = $model['struct'];
		$pk_name = '';	// store col struct for primary key

		echo "Creating table for $tbl_name...";

		$tbl_sql = <<<EOT
DROP TABLE IF EXISTS `$tbl_name`;
CREATE TABLE `$tbl_name` (
EOT;

		// table columns
		foreach ($cols as $name => $attrs) {

			// check if required attrs are set
			if (!isset($attrs['date'])) {
				echo "\n";
				echo "\e[0;31m\033[1mdate attribute must be declared for column $name of table $tbl_name.\033[0m\n";
				exit(-1);
			}

			if (!isset($attrs['required'])) {
				echo "\n";
				echo "\e[0;31m\033[1mrequired attribute must be declared for column $name of table $tbl_name.\033[0m\n";
				exit(-1);
			}

			if (!isset($attrs['type'])) {
				echo "\n";
				echo "\e[0;31m\033[1mtype attribute must be declared for column $name of table $tbl_name.\033[0m\n";
				exit(-1);
			}

			$field = "`$name`";	// column name
			
			// type, size and if signed or not
			if ($attrs['date']) {
				$field .= ' bigint unsigned';
			} else {

				if ($attrs['type'] == 'i') {
					$field .= ' int';
					if (array_key_exists('size', $attrs)) {
						$field .= '('.$attrs['size'].')';
					}
					if (array_key_exists('unsigned', $attrs)) {
						$field .= ' unsigned';
					}
				} elseif ($attrs['type'] == 's') {
					$field .= ' varchar';
					if (array_key_exists('size', $attrs)) {
						$field .= '('.$attrs['size'].')';
					} else {
						echo "\n";
						echo "\e[0;31m\033[1mvarchar requires size to be declared for column $name of table $tbl_name.\033[0m\n";
						print_usage();
					}
				} elseif ($attrs['type'] == 'd' && array_key_exists('precision', $attrs) && array_key_exists('scale', $attrs)) {
					$field .= ' decimal('.$attrs['precision'].','.$attrs['scale'].')';
				} elseif ($attrs['type'] == 't') {
					$field .= ' text';
				} elseif ($attrs['type'] == 'tt') {
					$field .= ' tinytext';
				} elseif ($attrs['type'] == 'mt') {
					$field .= ' mediumtext';
				} elseif ($attrs['type'] == 'lt') {
					$field .= ' longtext';
				} elseif ($attrs['type'] == 'b') {
					$field .= ' blob';
				} elseif ($attrs['type'] == 'tb') {
					$field .= ' tinyblob';
				} elseif ($attrs['type'] == 'mb') {
					$field .= ' mediumblob';
				} elseif ($attrs['type'] == 'lb') {
					$field .= ' longblob';
				} else {
					echo "\n";
					echo "\e[0;31m\033[1mUnknown type ".$attrs['type']." for column $name of table $tbl_name.\033[0m\n";
					print_usage();
				}
			}
			if (array_key_exists('default', $attrs)) {
				// default value?
				$field .= ' DEFAULT ';
				$field .= (is_string($attrs['default']) ? "'".$attrs['default']."'" : $attrs['default']);
			}
			$field .= ($attrs['required'] ? ' NOT NULL' : '');		// required?

			if (array_key_exists('pk', $attrs)) {
				// primary key?
				if ($attrs['pk']) {
					$field .= ' AUTO_INCREMENT';
					$pk_name = $name;
				}
			}

			$field .= ",\n";

			$tbl_sql .= <<<EOT
$field
EOT;

		}

		// primary key
		$tbl_sql .= <<<EOT
PRIMARY KEY (`$pk_name`)
) ENGINE=$engine DEFAULT CHARSET=$charset;
EOT;

		echo "OK\n";

		return $tbl_sql;
	}
}


?>