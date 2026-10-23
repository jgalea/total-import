<?php

namespace Importright\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * A streaming source of flat records. Readers never load the whole file,
 * except JsonReader, which is capped by size.
 */
interface Reader {

	/**
	 * Largest CSV row or XML record read, in bytes (option max_record_bytes, filter importright_max_record_bytes).
	 * A larger one is a reader error instead of a memory fatal; it usually means an unclosed quote or a broken file.
	 */
	public const DEFAULT_MAX_RECORD_BYTES = 1048576;

	/**
	 * Yields records from the cursor on, keyed by zero-based record index.
	 * While a record is being handled, cursor() already points past it.
	 *
	 * @param array<string, int|string>|null $cursor A value returned by cursor(), or null to start at the top.
	 * @return \Generator<int, array<string, string>>
	 * @throws ReaderException When the file is unreadable or malformed.
	 */
	public function records( ?array $cursor = null ): \Generator;

	/**
	 * Position after the last record yielded. Safe to store as JSON.
	 *
	 * @return array<string, int|string>
	 */
	public function cursor(): array;

	/**
	 * Column names: the header row for spreadsheets, a sample of record keys for XML and JSON.
	 *
	 * @return string[]
	 */
	public function columns(): array;

	/**
	 * Record count, when it's known without reading the whole file.
	 */
	public function total(): ?int;

	/**
	 * Fraction of the input consumed, 0 to 1, when known.
	 */
	public function progress(): ?float;

	public function close(): void;
}
