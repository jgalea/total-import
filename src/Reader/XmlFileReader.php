<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reader messages are plain text, escaped where they're displayed.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XMLReader and DOM properties.

namespace Importright\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * XML read with XMLReader. Each element matching the record path is expanded
 * on its own and flattened, so memory stays at one record.
 *
 * Record paths: "/catalog/product" matches from the root, "product" or
 * "products/product" match the end of the element path at any depth. Empty
 * means detect the shallowest element that repeats.
 *
 * Keys inside a record: "price", "dimensions.width", "image", "image[2]"
 * for repeats, "@id" for the record's attributes, "price@currency" for a child's.
 */
final class XmlFileReader implements Reader {

	private const SAMPLE_ELEMENTS = 5000;
	private const SAMPLE_RECORDS  = 50;

	private string $path;

	/**
	 * @var string[]
	 */
	private array $segments;

	private bool $absolute;

	private ?\XMLReader $xml = null;

	private int $max_record_bytes;

	/**
	 * @var string[]|null
	 */
	private ?array $columns = null;

	/**
	 * @var array<string, int>
	 */
	private array $cursor = array( 'index' => 0 );

	/**
	 * @param array<string, mixed> $options record_path, max_record_bytes.
	 */
	public function __construct( string $path, array $options = array() ) {
		$this->max_record_bytes = max( 1, (int) ( $options['max_record_bytes'] ?? self::DEFAULT_MAX_RECORD_BYTES ) );
		if ( ! class_exists( \XMLReader::class ) ) {
			throw new ReaderException( __( 'Reading XML needs the PHP xmlreader extension. Ask your host to enable it.', 'total-import' ) );
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new ReaderException( __( 'The file could not be opened.', 'total-import' ) );
		}
		$this->path = $path;

		$record_path = trim( (string) ( $options['record_path'] ?? '' ) );
		if ( '' === $record_path ) {
			$record_path = $this->detect_record_path();
		}
		$this->absolute = 0 === strpos( $record_path, '/' ) && 0 !== strpos( $record_path, '//' );
		$this->segments = array_values( array_filter( explode( '/', $record_path ), 'strlen' ) );
		if ( array() === $this->segments ) {
			throw new ReaderException( __( 'No repeating element was found to import as records. Set the record element path.', 'total-import' ) );
		}
	}

	public function record_path(): string {
		return ( $this->absolute ? '/' : '' ) . implode( '/', $this->segments );
	}

	public function records( ?array $cursor = null ): \Generator {
		$skip  = max( 0, (int) ( $cursor['index'] ?? 0 ) );
		$index = 0;
		$this->open();
		$this->cursor = array( 'index' => $skip );

		$xml   = $this->xml;
		$stack = array();
		$moved = $xml->read();
		while ( $moved ) {
			if ( \XMLReader::ELEMENT !== $xml->nodeType ) {
				$moved = $xml->read();
				continue;
			}
			$stack   = array_slice( $stack, 0, $xml->depth );
			$stack[] = $xml->name;
			if ( ! $this->matches( $stack ) ) {
				$moved = $xml->read();
				continue;
			}
			if ( $index < $skip ) {
				++$index;
				$moved = $xml->next();
				continue;
			}
			$node = $this->copy_record( $xml, $index );
			if ( null === $node ) {
				break;
			}
			$record       = self::flatten( $node );
			$moved        = $xml->read();
			$this->cursor = array( 'index' => $index + 1 );
			yield $index => $record;
			++$index;
		}
		$this->check_errors();
	}

	public function cursor(): array {
		return $this->cursor;
	}

	public function columns(): array {
		if ( null === $this->columns ) {
			$keys = array();
			foreach ( $this->sample() as $record ) {
				$keys += array_fill_keys( array_keys( $record ), true );
			}
			$this->columns = array_map( 'strval', array_keys( $keys ) );
		}
		return $this->columns;
	}

	public function total(): ?int {
		return null;
	}

	public function progress(): ?float {
		return null;
	}

	public function close(): void {
		if ( null !== $this->xml ) {
			$this->xml->close();
			$this->xml = null;
		}
	}

	/**
	 * Flattens an element's children and attributes into path keys.
	 *
	 * @return array<string, string>
	 */
	public static function flatten( \DOMElement $element ): array {
		$record = array();
		foreach ( $element->attributes as $attribute ) {
			$record[ '@' . $attribute->nodeName ] = (string) $attribute->value;
		}
		if ( ! self::flatten_children( $element, '', $record ) ) {
			$text = trim( $element->textContent );
			if ( '' !== $text && ! isset( $record['value'] ) ) {
				$record['value'] = $text;
			}
		}
		return $record;
	}

	/**
	 * @param array<string, string> $record
	 * @return bool Whether the element has child elements.
	 */
	private static function flatten_children( \DOMElement $element, string $prefix, array &$record ): bool {
		$totals = array();
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$totals[ $child->nodeName ] = ( $totals[ $child->nodeName ] ?? 0 ) + 1;
			}
		}
		if ( array() === $totals ) {
			return false;
		}

		$seen = array();
		$text = '';
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				$text .= $child->nodeValue;
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$name          = $child->nodeName;
			$seen[ $name ] = ( $seen[ $name ] ?? 0 ) + 1;
			if ( 1 === $totals[ $name ] ) {
				$keys = array( $prefix . $name );
			} elseif ( 1 === $seen[ $name ] ) {
				$keys = array( $prefix . $name, $prefix . $name . '[1]' );
			} else {
				$keys = array( $prefix . $name . '[' . $seen[ $name ] . ']' );
			}

			foreach ( $keys as $key ) {
				foreach ( $child->attributes as $attribute ) {
					$record[ $key . '@' . $attribute->nodeName ] = (string) $attribute->value;
				}
				if ( ! self::flatten_children( $child, $key . '.', $record ) ) {
					$record[ $key ] = trim( $child->textContent );
				}
			}
		}

		$text = trim( $text );
		if ( '' !== $prefix && '' !== $text ) {
			$record[ rtrim( $prefix, '.' ) ] = $text;
		}
		return true;
	}

	/**
	 * @param string[] $stack
	 */
	private function matches( array $stack ): bool {
		$count = count( $this->segments );
		if ( $this->absolute ? count( $stack ) !== $count : count( $stack ) < $count ) {
			return false;
		}
		return array_slice( $stack, -$count ) === $this->segments;
	}

	private function open(): void {
		$this->close();
		libxml_use_internal_errors( true );
		libxml_clear_errors();
		$xml = new \XMLReader();
		if ( ! $xml->open( $this->path, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
			throw new ReaderException( __( 'The XML file could not be opened.', 'total-import' ) );
		}
		$this->xml = $xml;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function sample(): array {
		$records = array();
		$cursor  = $this->cursor;
		foreach ( $this->records() as $record ) {
			$records[] = $record;
			if ( count( $records ) >= self::SAMPLE_RECORDS ) {
				break;
			}
		}
		$this->close();
		$this->cursor = $cursor;
		return $records;
	}

	/**
	 * The element path that repeats most often in the first few thousand elements, shallowest first on a tie.
	 */
	private function detect_record_path(): string {
		$this->open();
		$counts = self::count_elements( $this->xml );
		if ( null === $counts ) {
			$this->check_errors();
		}
		$this->close();
		libxml_clear_errors();
		if ( null === $counts ) {
			throw new ReaderException( __( 'The XML file is empty.', 'total-import' ) );
		}

		$best       = '';
		$best_score = null;
		foreach ( $counts as $path => $count ) {
			$score = array( -substr_count( $path, '/' ), $count );
			if ( $count > 1 && ( null === $best_score || $score > $best_score ) ) {
				$best_score = $score;
				$best       = $path;
			}
		}
		return '' === $best && array() !== $counts ? (string) array_key_first( $counts ) : $best;
	}

	/**
	 * Element paths that repeat in the first few thousand elements, as path => count,
	 * shallowest first and then most frequent. The admin's record element picker lists them.
	 *
	 * @return array<string, int>
	 */
	public static function elements( string $path ): array {
		if ( ! class_exists( \XMLReader::class ) || ! is_file( $path ) ) {
			return array();
		}
		libxml_use_internal_errors( true );
		$xml = new \XMLReader();
		if ( ! $xml->open( $path, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
			return array();
		}
		$counts = (array) self::count_elements( $xml );
		$xml->close();
		libxml_clear_errors();

		$counts = array_filter(
			$counts,
			static function ( int $count ): bool {
				return $count > 1;
			}
		);
		uksort(
			$counts,
			static function ( string $a, string $b ) use ( $counts ): int {
				return array( substr_count( $a, '/' ), $counts[ $b ] ) <=> array( substr_count( $b, '/' ), $counts[ $a ] );
			}
		);
		return $counts;
	}

	/**
	 * @return array<string, int>|null Null when the document has no elements at all.
	 */
	private static function count_elements( \XMLReader $xml ): ?array {
		$stack  = array();
		$counts = array();
		$seen   = 0;
		while ( $seen < self::SAMPLE_ELEMENTS && $xml->read() ) {
			if ( \XMLReader::ELEMENT !== $xml->nodeType ) {
				continue;
			}
			++$seen;
			$stack   = array_slice( $stack, 0, $xml->depth );
			$stack[] = $xml->name;
			if ( count( $stack ) > 1 ) {
				$path            = '/' . implode( '/', $stack );
				$counts[ $path ] = ( $counts[ $path ] ?? 0 ) + 1;
			}
		}
		return 0 === $seen ? null : $counts;
	}

	private function check_errors(): void {
		$error = libxml_get_last_error();
		libxml_clear_errors();
		if ( false !== $error && $error->level >= LIBXML_ERR_ERROR ) {
			/* translators: 1: parser message, 2: line number */
			throw new ReaderException( sprintf( __( 'The XML is malformed: %1$s (line %2$d)', 'total-import' ), trim( $error->message ), $error->line ) );
		}
	}

	/**
	 * Copies the record element at the cursor into a DOM element node by node, so a record over
	 * max_record_bytes is refused before it's all in memory (XMLReader::expand() would build it
	 * first). Leaves the reader on the record's end tag; null when the XML breaks off inside it.
	 *
	 * @throws ReaderException When the record is too large.
	 */
	private function copy_record( \XMLReader $xml, int $index ): ?\DOMElement {
		$doc   = new \DOMDocument();
		$root  = null;
		$open  = array();
		$bytes = 0;
		do {
			$type = $xml->nodeType;
			if ( \XMLReader::ELEMENT === $type ) {
				$empty   = $xml->isEmptyElement;
				$element = $doc->createElement( $xml->name );
				$bytes  += strlen( $xml->name ) + 16;
				if ( $xml->hasAttributes ) {
					while ( $xml->moveToNextAttribute() ) {
						if ( 'xmlns' !== $xml->name && 0 !== strpos( $xml->name, 'xmlns:' ) ) {
							$element->setAttribute( $xml->name, $xml->value );
							$bytes += strlen( $xml->name ) + strlen( $xml->value );
						}
					}
					$xml->moveToElement();
				}
				if ( null === $root ) {
					$root = $doc->appendChild( $element );
				} else {
					end( $open )->appendChild( $element );
				}
				if ( ! $empty ) {
					$open[] = $element;
				}
			} elseif ( \XMLReader::END_ELEMENT === $type ) {
				array_pop( $open );
			} elseif ( array() !== $open && in_array( $type, array( \XMLReader::TEXT, \XMLReader::CDATA, \XMLReader::WHITESPACE, \XMLReader::SIGNIFICANT_WHITESPACE ), true ) ) {
				end( $open )->appendChild( $doc->createTextNode( $xml->value ) );
				$bytes += strlen( $xml->value );
			}
			if ( $bytes > $this->max_record_bytes ) {
				/* translators: 1: record number, 2: size limit, e.g. "1 MB" */
				throw new ReaderException( sprintf( __( 'Record %1$d is larger than the %2$s limit for one record. If the file is right, raise the limit with the importright_max_record_bytes filter.', 'total-import' ), $index + 1, Text::size( $this->max_record_bytes ) ) );
			}
			if ( array() === $open ) {
				return $root instanceof \DOMElement ? $root : null;
			}
		} while ( $xml->read() );
		return null;
	}
}
