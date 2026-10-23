<?php

namespace Importright\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Converts one WP All Import field template into Total Import's syntax.
 *
 * Simple references ({title[1]}, {./price[1]/@currency}, {dimensions[1]/width[1]})
 * become flat column references. Anything that needs an XPath engine (predicates,
 * axes, functions, wildcards) is dropped and reported. Square-bracket code
 * ([func(...)], [IF]…[ENDIF], [FOREACH]) is never run: it is removed and reported.
 */
final class Expression {

	private const KEYWORDS = '/^(if|elseif|else|endif|foreach|endforeach)\b/i';

	/**
	 * @var array<string, string>|null
	 */
	private ?array $columns;

	/**
	 * @var array<string, bool>|null
	 */
	private ?array $sample;

	private bool $csv;

	private string $root;

	/**
	 * @var string[]
	 */
	private array $notes = array();

	private string $status = Report::MAPPED;

	/**
	 * @param bool                       $csv     The source is a CSV/XLSX file that WP All Import turned into <node> records.
	 * @param array<string, string>|null $columns For CSV: WP All Import's element name => the file's column name. Null when the file isn't available.
	 * @param string[]|null              $sample  For XML: record keys seen in the file, used to spot repeated elements.
	 */
	public function __construct( bool $csv = false, ?array $columns = null, string $root = '', ?array $sample = null ) {
		$this->csv     = $csv;
		$this->columns = $columns;
		$this->root    = $root;
		$this->sample  = null === $sample ? null : array_fill_keys( $sample, true );
	}

	/**
	 * @return array{template: string, status: string, notes: string[]}
	 */
	public function convert( string $wpai ): array {
		$this->notes  = array();
		$this->status = Report::MAPPED;
		$template     = $this->text( $wpai );

		if ( '' === trim( $template ) && '' !== trim( $wpai ) ) {
			$this->status = Report::UNSUPPORTED;
			$template     = '';
		}
		return array(
			'template' => $template,
			'status'   => $this->status,
			'notes'    => array_values( array_unique( $this->notes ) ),
		);
	}

	/**
	 * WP All Import's element names for a CSV header, mapped to the column names Total Import's reader gives.
	 * WP All Import strips everything but letters, digits and underscores, lower-cases, prefixes
	 * a leading digit with el_, names blanks undefinedN and numbers repeats name_2, name_3.
	 *
	 * @param string[] $columns Column names as Total Import's reader returns them.
	 * @return array<string, string>
	 */
	public static function wpai_columns( array $columns ): array {
		$map    = array();
		$counts = array();
		foreach ( array_values( $columns ) as $i => $column ) {
			$column = (string) $column;
			$name   = 'column_' . ( $i + 1 ) === $column ? '' : strtolower( (string) preg_replace( '/[^a-z0-9_]/i', '', $column ) );
			if ( preg_match( '/^[0-9]/', $name ) ) {
				$name = 'el_' . $name;
			}
			if ( '' === $name ) {
				$name = 'undefined' . $i;
			}
			$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
			$key             = 1 === $counts[ $name ] ? $name : $name . '_' . $counts[ $name ];
			$map[ $key ]     = $column;
		}
		return $map;
	}

	/**
	 * Whether WP All Import would have treated the header row as data and named the columns column_1…,
	 * which it does when a header cell is a number or ends in a URL scheme.
	 *
	 * @param string[] $columns
	 */
	public static function wpai_numbers_columns( array $columns ): bool {
		foreach ( $columns as $column ) {
			if ( is_numeric( $column ) || preg_match( '%\W(http:|https:|ftp:)$%i', (string) $column ) ) {
				return true;
			}
		}
		return false;
	}

	private function text( string $input ): string {
		$output = '';
		$length = strlen( $input );
		$i      = 0;
		while ( $i < $length ) {
			$char = $input[ $i ];
			$next = $input[ $i + 1 ] ?? '';

			if ( '\\' === $char && in_array( $next, array( '[', '{', '}' ), true ) ) {
				$output .= self::literal( $next );
				$i      += 2;
				continue;
			}
			if ( '{' === $char ) {
				$close = self::closing_brace( $input, $i + 1 );
				if ( null === $close ) {
					$this->approximate( __( 'An unclosed { was kept as text.', 'total-import' ) );
					$output .= self::literal( substr( $input, $i ) );
					break;
				}
				$output .= $this->reference( str_replace( '\\}', '}', substr( $input, $i + 1, $close - $i - 1 ) ) );
				$i       = $close + 1;
				continue;
			}
			if ( '[' === $char ) {
				$close = self::closing_bracket( $input, $i + 1 );
				if ( null === $close ) {
					$output .= self::literal( $char );
					++$i;
					continue;
				}
				$output .= $this->code( substr( $input, $i + 1, $close - $i - 1 ) );
				$i       = $close + 1;
				continue;
			}
			$output .= self::literal( $char );
			++$i;
		}
		return $output;
	}

	private function code( string $inner ): string {
		$trimmed = trim( $inner );
		if ( preg_match( self::KEYWORDS, $trimmed ) ) {
			/* translators: %s: the WP All Import code that was removed */
			$this->approximate( sprintf( __( 'Removed WP All Import logic [%s]; conditions and loops aren\'t supported.', 'total-import' ), $trimmed ) );
			return '';
		}
		if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_\\\\]*\s*\(/', $trimmed ) ) {
			/* translators: %s: the PHP call that was removed */
			$this->approximate( sprintf( __( 'Removed the PHP call [%s]. Total Import never runs PHP from an import; rebuild it with a transform.', 'total-import' ), $trimmed ) );
			return '';
		}
		return '[' . $this->text( $inner ) . ']';
	}

	private function reference( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			$this->approximate( __( 'Dropped an empty {} reference.', 'total-import' ) );
			return '';
		}

		$relative = (string) preg_replace( '#^\./#', '', $path );
		$absolute = '/' === ( $relative[0] ?? '' ) && 0 !== strpos( $relative, '//' );
		if ( $absolute ) {
			$relative = substr( $relative, 1 );
		}

		$segments = '' === $relative ? array() : explode( '/', $relative );
		$parsed   = array();
		foreach ( $segments as $n => $segment ) {
			if ( 'text()' === $segment && count( $segments ) - 1 === $n && $n > 0 ) {
				continue;
			}
			if ( ! preg_match( '/^(@?[A-Za-z_][A-Za-z0-9_.:\-]*)(?:\[(\d+)\])?$/', $segment, $m ) || ( '@' === $m[1][0] && ( isset( $m[2] ) || count( $segments ) - 1 !== $n ) ) ) {
				/* translators: %s: XPath expression */
				$this->approximate( sprintf( __( 'Dropped {%s}: Total Import reads plain element paths, not XPath expressions.', 'total-import' ), $path ) );
				return '';
			}
			$parsed[] = array(
				'name'  => $m[1],
				'index' => isset( $m[2] ) ? (int) $m[2] : 0,
			);
		}
		if ( array() === $parsed ) {
			/* translators: %s: XPath expression */
			$this->approximate( sprintf( __( 'Dropped {%s}: Total Import reads plain element paths, not XPath expressions.', 'total-import' ), $path ) );
			return '';
		}

		if ( $absolute ) {
			if ( '' !== $this->root && $parsed[0]['name'] === $this->root && count( $parsed ) > 1 ) {
				array_shift( $parsed );
			}
			/* translators: %s: XPath expression */
			$this->approximate( sprintf( __( '{%s} starts at the document root; it was read relative to each record.', 'total-import' ), $path ) );
		}

		return $this->csv ? $this->csv_reference( $parsed, $path ) : $this->xml_reference( $parsed, $path );
	}

	/**
	 * @param array<int, array{name: string, index: int}> $parsed
	 */
	private function csv_reference( array $parsed, string $path ): string {
		if ( count( $parsed ) > 1 && 'node' === $parsed[0]['name'] ) {
			array_shift( $parsed );
		}
		if ( count( $parsed ) > 1 || '@' === $parsed[0]['name'][0] ) {
			/* translators: %s: XPath expression */
			$this->approximate( sprintf( __( 'Dropped {%s}: a CSV record has no nested elements.', 'total-import' ), $path ) );
			return '';
		}
		$name = $parsed[0]['name'];
		if ( $parsed[0]['index'] > 1 ) {
			/* translators: %s: XPath expression */
			$this->approximate( sprintf( __( '{%s} asks for a repeated CSV value; the column\'s value is used.', 'total-import' ), $path ) );
		}

		if ( null === $this->columns ) {
			/* translators: %s: column name */
			$this->approximate( sprintf( __( 'Check the column {%s}: WP All Import lower-cases CSV headers and strips spaces and punctuation, and the file wasn\'t available to match it.', 'total-import' ), $name ) );
			return '{' . $name . '}';
		}
		$column = $this->columns[ $name ] ?? null;
		if ( null === $column ) {
			foreach ( $this->columns as $wpai => $original ) {
				if ( 0 === strcasecmp( (string) $wpai, $name ) ) {
					$column = $original;
					break;
				}
			}
		}
		if ( null === $column ) {
			/* translators: %s: column name */
			$this->approximate( sprintf( __( 'The file has no column matching {%s}.', 'total-import' ), $name ) );
			return '{' . $name . '}';
		}
		if ( preg_match( '#[{}/]|^\.#', $column ) ) {
			/* translators: %s: column name */
			$this->approximate( sprintf( __( 'The column "%s" contains characters a template reference can\'t hold; rename it in the file.', 'total-import' ), $column ) );
			return '';
		}
		return '{' . $column . '}';
	}

	/**
	 * @param array<int, array{name: string, index: int}> $parsed
	 */
	private function xml_reference( array $parsed, string $path ): string {
		$key = '';
		foreach ( $parsed as $segment ) {
			if ( '@' === $segment['name'][0] ) {
				$key .= $segment['name'];
				continue;
			}
			$part = $segment['name'] . ( $segment['index'] > 1 ? '[' . $segment['index'] . ']' : '' );
			$key .= '' === $key ? $part : '.' . $part;

			if ( 0 === $segment['index'] && null !== $this->sample && isset( $this->sample[ $key . '[2]' ] ) ) {
				/* translators: %s: XPath expression */
				$this->approximate( sprintf( __( '{%s} matches a repeated element. WP All Import joined every value with ", "; Total Import uses the first.', 'total-import' ), $path ) );
			}
		}
		return '{' . $key . '}';
	}

	private function approximate( string $note ): void {
		$this->notes[] = $note;
		if ( Report::MAPPED === $this->status ) {
			$this->status = Report::APPROXIMATED;
		}
	}

	private static function literal( string $text ): string {
		return str_replace( array( '{', '}' ), array( '{{', '}}' ), $text );
	}

	private static function closing_brace( string $input, int $from ): ?int {
		$length = strlen( $input );
		for ( $i = $from; $i < $length; $i++ ) {
			if ( '}' === $input[ $i ] && '\\' !== $input[ $i - 1 ] ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * The ] that closes a [ at depth zero, skipping "strings" and {references}.
	 */
	private static function closing_bracket( string $input, int $from ): ?int {
		$length = strlen( $input );
		$depth  = 0;
		for ( $i = $from; $i < $length; $i++ ) {
			$char = $input[ $i ];
			if ( '"' === $char ) {
				$end = strpos( $input, '"', $i + 1 );
				if ( false === $end ) {
					return null;
				}
				$i = $end;
				continue;
			}
			if ( '{' === $char ) {
				$end = self::closing_brace( $input, $i + 1 );
				if ( null === $end ) {
					return null;
				}
				$i = $end;
				continue;
			}
			if ( '[' === $char ) {
				++$depth;
			} elseif ( ']' === $char ) {
				if ( 0 === $depth ) {
					return $i;
				}
				--$depth;
			}
		}
		return null;
	}
}
